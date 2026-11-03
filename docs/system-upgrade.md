# System upgrade, backup, and restore

CAS uses a staged recovery model designed around one rule:

> A failed backup, restore, or upgrade must not destructively modify the currently active database.

Format-v3 backup sets and all automated restore/upgrade operations follow this rule. Legacy destructive database restore is disabled.

## Prerequisites

* PHP 8.1+
* PHP `zip` extension
* PDO MySQL driver
* MySQL or MariaDB
* Native database clients installed on the application host:
  * MySQL: `mysql` and `mysqldump`
  * MariaDB: `mariadb` and `mariadb-dump` (MySQL-compatible client names are also accepted)
* A dedicated recovery database account configured with `DB_MAINT_*` variables.
* Network access to the GitHub release repository.

The recovery account must be able to create/drop isolated CAS staging databases and grant the normal application database account access to a verified candidate database. The normal application account remains the account used by CAS itself.

Example:

```dotenv
DB_HOST=127.0.0.1
DB_NAME=epss_v917
DB_USER=epss_user
DB_PASS=...

DB_MAINT_HOST=127.0.0.1
DB_MAINT_PORT=3306
DB_MAINT_USER=cas_recovery
DB_MAINT_PASS=...
```

## Backup format

A format-v3 full backup set contains:

* `manifest.json` – format, release, preserved paths, database objects/row counts and SHA-256 checksums.
* `application.zip` – application code/files excluding server-local preserved paths.
* `database/backup.sql` – native MySQL/MariaDB dump.
* `meta/summary.json` – compact backup summary.

CAS still recognizes format-v2 backup sets, but before a v2 or v3 backup can be restored it must pass the same isolated native database-restore verification.

### Preserved server-local paths

Application restore deliberately preserves:

* `.git`
* `.env`
* `config.php`
* `assets/uploads`
* `storage`

These contain deployment/runtime state and are not replaced by application snapshots.

## What "verified backup" means

A backup is not marked ready simply because a ZIP or SQL file exists.

CAS:

1. Creates the application snapshot.
2. Creates the database dump with `mysqldump`/`mariadb-dump` using a single-transaction streaming dump.
3. Hashes both components.
4. Builds the backup ZIP.
5. Reopens the completed ZIP and verifies required components/checksums/path safety.
6. Creates a temporary `cas_stage_...` database.
7. Restores the SQL dump into that database with the native database client.
8. Confirms the restored object list and row counts.
9. Confirms the normal CAS application database account can connect to the staged database.
10. Drops the temporary verification database.

Only then is the backup considered verified.

PHP no longer parses or replays full database dump SQL.

## Background jobs and availability

Backup, upgrade, and restore operations started from **Admin → System Upgrade** run as detached server-side jobs.

The browser request only queues the operation and returns. The page displays job status/log output and refreshes while the job is active.

Only one system operation may run at a time. CAS uses both a job-start lock and a process-level operation lock to prevent overlapping backup/restore/upgrade work.

This prevents a long database backup/validation from holding an Apache/PHP request open and causing reverse-proxy timeouts.

## Upgrade flow

An upgrade uses the following sequence:

1. Determine the currently active database.
2. Create and fully prove a v3 App + DB backup.
3. Download the new release to a staging directory.
4. Verify required release files and PHP-lint every staged PHP file.
5. Restore the current DB backup into a new isolated candidate database.
6. Execute release migration SQL **only against the candidate database**, using the native MySQL/MariaDB client.
7. Verify critical CAS tables and candidate connectivity.
8. Enter maintenance mode.
9. Deploy the already-validated application package.
10. Atomically update the CAS active-database pointer to the verified candidate.
11. Re-run critical DB checks and record installed-release metadata.
12. Exit maintenance mode.

The original database is never dropped or migrated during the upgrade.

### Upgrade failure behavior

If failure happens before step 8, the live application and live database have not been changed.

If failure happens during cutover:

1. CAS switches the active-database pointer back to the untouched previous database.
2. CAS restores application files from the verified pre-upgrade application snapshot.
3. CAS exits maintenance mode only after rollback is proven.
4. The failed candidate database is discarded.

If rollback itself cannot be proven, CAS deliberately leaves maintenance mode active instead of serving a potentially mixed application/database state.

## Restore flow

System Restore accepts verified v2/v3 full backup sets. Legacy in-place database restore is disabled.

Restore sequence:

1. Validate backup structure/checksums.
2. Create and fully verify a fresh emergency pre-restore backup of the current state.
3. Restore the selected backup database into a new isolated candidate DB.
4. Verify exact manifest object/row counts and application-account connectivity.
5. Enter maintenance mode.
6. Restore the selected application snapshot.
7. Verify the application tree hash.
8. Atomically activate the verified candidate DB.
9. Verify the database again.
10. Restore installed-release metadata.
11. Exit maintenance mode.

The database that was active before the restore remains intact as a rollback generation.

A restore failure during cutover switches back to that untouched database and restores the pre-restore application snapshot. It does **not** replay another SQL dump over the live DB.

## Database generation retention

CAS-generated candidate databases use the `cas_stage_*` namespace.

After a successful operation CAS keeps a conservative rolling set of the three most recent managed database generations, including the active generation. It never automatically deletes:

* the active DB;
* the original non-`cas_stage_*` baseline DB;
* an unregistered/unknown database;
* any database outside the controlled `cas_stage_*` namespace.

This prevents candidate databases from accumulating indefinitely while retaining rollback generations.

Filesystem retention keeps the most recent upgrade runs and emergency backups. Manual backups are not automatically deleted.

## Manual backup and download

**Create Full Backup** queues a background backup job.

When the verified backup appears in the catalog, **Download selected backup** streams that existing ZIP in a separate request. Retrying a download never creates another backup.

Imported backup ZIPs must pass:

* ZIP/path/checksum validation; and
* a real isolated native database restore

before becoming available for System Restore.

## One-time transition from pre-v3 installations

Existing installations such as 0.5.95 preserve `config.php` during normal upgrades and still contain the old recovery runtime. They therefore require a one-time bootstrap.

The release includes:

```text
scripts/bootstrap_enterprise_recovery.php
```

The bootstrap is run from outside the live application directory and is pinned to the exact verified release commit.

It:

1. downloads the exact commit, not a moving branch;
2. PHP-lints the validated recovery files;
3. saves pre-bootstrap copies and metadata;
4. installs the maintenance gate and active-DB pointer into the existing preserved `config.php`;
5. preserves owner/group/mode for existing files;
6. gives newly introduced recovery files metadata matching trusted sibling files;
7. installs only the new recovery subsystem under maintenance mode;
8. clears the bootstrap maintenance gate;
9. invokes the **new staged CLI updater**, never the old synchronous browser updater.

If the staged updater fails and rollback is proven, exact pre-bootstrap recovery files are restored. If rollback cannot be proven, the maintenance gate and recovery subsystem are intentionally retained for diagnosis.

The exact command/commit is published with the release after CI verifies the final merge commit.

## CLI

Normal post-v3 operations may be run directly:

```bash
# Native verified backup
php scripts/system_upgrade.php --action=backup

# Upgrade to a specific release
php scripts/system_upgrade.php --action=upgrade --repo=khoppenworth/CAS2025 --ref=0.6.0

# Upgrade to latest GitHub release
php scripts/system_upgrade.php --action=upgrade --repo=khoppenworth/CAS2025 --latest-release

# List backups
php scripts/system_upgrade.php --action=list-backups

# Restore a verified v2/v3 full backup set
php scripts/system_upgrade.php --action=restore --backup-id=manual_20261103_090000
```

The Admin UI uses the detached job wrapper around the same CLI operations.

## Automated release gates

The CI release gate runs on both MySQL 8 and MariaDB 10.11.

It includes:

* PHP lint and existing dataset regressions.
* Bootstrap permission-preservation/idempotency test.
* Detached-job/non-overlap test.
* Three complete backup/restore stress cycles per database engine.
* Complex SQL/data corpus including apostrophes, backslashes, semicolons, multiline text, Unicode, SQL-looking content and quoted views.
* Repeat-backup test proving `storage/upgrades` is not recursively archived.
* Separate-download byte-for-byte validation.
* Imported-backup isolated restore verification.
* ZIP path-traversal rejection.
* Exact application restore and preserved-path checks.
* Explicit assertion that the original live DB remains mutated and untouched after a successful restore.
* Two forced late-cutover failures per database engine, proving:
  * DB pointer rolls back to the original DB;
  * pre-restore application state is recovered;
  * the original DB remains unchanged;
  * failed candidate databases are removed;
  * maintenance clears only after proven rollback.

A reliability release is published only from an exact main-branch commit that has passed the complete gate.
