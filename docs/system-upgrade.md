# System upgrade, backup, and restore

CAS uses verified full backup sets plus isolated candidate databases for recovery and upgrades. Long-running backup, upgrade, and restore operations run outside the Apache request so the web application remains responsive except during the short controlled cutover window.

## Required server components

- PHP 8.1+
- PHP zip extension
- PDO MySQL driver
- MySQL or MariaDB
- Native database client tools:
  - `mysqldump` + `mysql`, or
  - `mariadb-dump` + `mariadb`
- Network access to the GitHub release source
- Database credentials capable of creating/dropping staging databases and granting the normal CAS database account access to them.

The normal CAS application account remains configured through `DB_USER` / `DB_PASS`. For staging operations, configure `DB_ADMIN_USER` / `DB_ADMIN_PASS`. If the admin variables are omitted, CAS falls back to the normal DB account and fails safely if that account lacks the required privileges.

## Full backup set

A verified full backup ZIP contains:

- `manifest.json`
- `application.zip`
- `database/backup.sql`
- `meta/summary.json`

Application snapshots exclude server-local paths that must survive deployments and restores:

- `.git`
- `.env`
- `config.php`
- `assets/uploads`
- `storage`

## Native database backup verification

CAS no longer accepts its own PHP SQL parser as proof that a database dump is restorable.

For every new backup:

1. The native MySQL/MariaDB dump utility creates the SQL snapshot.
2. CAS records the expected database objects and row counts.
3. CAS creates a temporary verification database.
4. The native MySQL/MariaDB client restores the dump into that isolated database.
5. CAS verifies the object list and row counts.
6. CAS verifies that the critical `users` table exists.
7. Only after all checks pass is the backup marked ready.
8. The temporary verification database is removed.

If any step fails, the live database is untouched and the backup is rejected.

## Background jobs

The System Upgrade page does not wait for long-running work inside the browser request.

Backup, upgrade, and restore actions:

1. Create a job record under `storage/upgrades/jobs`.
2. Launch the CLI worker detached from Apache.
3. Return the admin page immediately.
4. Show the current job state and automatically refresh while it is active.

Only one system operation can be active at a time. A job still marked queued/running after six hours is treated as stale and may be superseded by a new operation.

## Manual backup flow

1. Click **Create Full Backup**.
2. CAS starts a background backup job.
3. Native dump + staging restore verification run outside the web request.
4. When complete, the backup appears in the restore list.
5. Use **Download selected backup** to download the existing validated ZIP. Downloading does not generate another backup.

## Upgrade flow

Before changing the running application, CAS:

1. Creates and fully verifies a pre-upgrade App + DB backup.
2. Restores that database dump into an isolated candidate database.
3. Verifies the normal CAS DB account can connect to the candidate.
4. Downloads the target application release.
5. Enters a short maintenance window.
6. Deploys the release files.
7. Runs database migrations against the candidate database, not the active database.
8. Switches the active database pointer atomically to the verified candidate.
9. Records the installed release.
10. Leaves maintenance mode.

If deployment or candidate migration fails before cutover, the previous live database remains unchanged. The verified pre-upgrade backup remains available for application rollback.

## Restore flow

Format-v2 full restore no longer drops tables from the active database.

Instead CAS:

1. Validates the backup ZIP and checksums.
2. Creates and verifies an emergency pre-restore backup.
3. Restores the selected database dump into a new isolated candidate database.
4. Verifies objects, row counts, critical tables, and application DB-account access.
5. Verifies the application snapshot before live cutover.
6. Enters maintenance mode.
7. Restores and verifies the application tree.
8. Atomically switches the preserved active-database pointer to the verified candidate.
9. Restores installed-release metadata.
10. Leaves maintenance mode.

The previous database is retained. Rollback therefore means switching back to the previous database pointer rather than reconstructing the old database from a dump.

The active database pointer is stored under:

`storage/upgrades/active_database.json`

This file is preserved across application restores and upgrades.

## Retention

CAS performs conservative cleanup after successful operations:

- retain the newest 5 upgrade run directories;
- retain the newest 3 emergency backup ZIPs;
- retain the newest 3 inactive candidate databases;
- manual downloaded backups are not automatically deleted.

Cleanup failures are warnings and do not turn an otherwise successful backup/upgrade into a failure.

## CLI

```bash
# Create and verify a full manual backup
php scripts/system_upgrade.php --action=backup

# Upgrade to latest GitHub release
php scripts/system_upgrade.php --action=upgrade --repo=owner/repository --latest-release

# Restore a verified full backup
php scripts/system_upgrade.php --action=downgrade --backup-id=manual_20261103_120000

# List backups
php scripts/system_upgrade.php --action=list-backups
```

## Automated regression testing

The destructive backup/restore test runs repeatedly against both MySQL 8 and MariaDB 10.11. It verifies:

- native dump creation;
- actual staging restore of every generated backup;
- application DB-account access to candidate databases;
- complex strings, quotes, backslashes, Unicode, semicolons and multiline content;
- relational tables and database views;
- separate backup download/copy validation;
- repeated backup exclusion of `storage/upgrades`;
- exact application rollback;
- preserved `config.php`, uploads and runtime storage;
- candidate DB cutover without overwriting the original database;
- emergency backup creation and validation.

The release process must not publish a new UAT release unless the PR and the exact merged main commit are both green.
