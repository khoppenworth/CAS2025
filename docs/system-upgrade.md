# System upgrade and restore utility

The `scripts/system_upgrade.php` CLI script manages CAS application upgrades,
pre-upgrade snapshots, database backups, and rollback.

## Prerequisites

* PHP 8.1+
* PHP `zip` extension (`ext-zip`)
* PDO MySQL/MariaDB support
* Network access to the GitHub repository that hosts releases
* Write access to `storage/upgrades/` and to the application files being upgraded

The backup/restore engine uses PDO for database export and restore; it does not
depend on external `mysqldump` or `mysql` binaries.

## Usage

```bash
# Deploy a specific tag or branch
php scripts/system_upgrade.php --action=upgrade --repo=owner/repository --ref=0.5.93

# Deploy the latest GitHub release
php scripts/system_upgrade.php --action=upgrade --repo=owner/repository --latest-release

# List available upgrade restore points
php scripts/system_upgrade.php --action=list-backups

# Restore application files from a specific restore point
php scripts/system_upgrade.php --action=downgrade --backup-id=20261020_120000

# Restore application files and database (recommended for a full rollback)
php scripts/system_upgrade.php --action=downgrade --backup-id=20261020_120000 --restore-db
```

## Upgrade restore-point layout

Every upgrade creates a restore point before application changes begin:

```text
storage/upgrades/runs/<backup-id>/
├── application.zip
├── database.sql
└── manifest.json
```

The manifest records the target release, the release installed before the
upgrade, preserved paths, and the snapshot/database locations.

The application snapshot intentionally excludes environment/runtime paths that
must survive an application rollback, including:

* `config.php`
* `assets/uploads`
* `storage`
* `storage/upgrades`

## Restore behavior

A restore is an **exact rollback for non-preserved application files**, not an
overlay.

Before restoring, the utility:

1. verifies that the selected application snapshot exists;
2. verifies the selected database dump when database restore is requested;
3. creates a fresh **pre-restore safety snapshot** of the currently running
   application and database.

During application restore, current non-preserved files are removed before the
snapshot is copied back. This means files introduced by a newer release do not
survive an older restore.

During database restore, the dump is validated before destructive work starts.
The current database tables/views are then removed and the backed-up schema and
data are recreated. This prevents tables, columns, views, and rows introduced
after the backup from remaining after rollback.

If the requested restore fails after mutation begins, CAS automatically
attempts to recover from the pre-restore safety snapshot.

For a normal administrator-initiated system rollback, restore **application +
database** together. The Administration → System Upgrade page selects database
restore by default.

## Failed-upgrade protection

An upgrade takes both the application snapshot and database dump before applying
the new release.

If an upgrade fails after application changes have begun, the utility
automatically attempts to restore both snapshots and records the rollback result
in the run manifest.

If the automatic rollback itself encounters an error, the upgrade exits
non-zero and records the rollback errors in the manifest/log rather than
reporting a successful recovery.

## Database backup safeguards

Database export is fail-fast:

* failure to enumerate database objects aborts backup creation;
* failure to export any table definition or table data aborts backup creation;
* an empty database backup is rejected;
* restore rejects a dump that contains no schema definitions before changing
  the current database.

Database restore tests run in CI against both MySQL 8 and MariaDB 10.11.

## Manual downloadable backup

The **Download backup** action creates a separate portable ZIP containing
application/data exports, a SQL backup, uploads, and metadata.

Upgrade rollback restore points under `storage/upgrades/runs/` remain the
source used by the System Restore selector. Keep downloaded manual backup ZIPs
as an additional off-server recovery copy.

## Preserved paths

Additional environment-specific paths can be preserved during an upgrade with
`--preserve`:

```bash
php scripts/system_upgrade.php --action=upgrade \
  --repo=owner/repository \
  --ref=0.5.93 \
  --preserve=.env,local-config
```

Preserve matching is path-boundary aware. Preserving `storage`, for example,
does not also preserve an unrelated path such as `storage2`.

## Verification before major changes

Before a high-impact release, verify at least one full round trip on the test
server:

1. create/confirm an upgrade restore point;
2. create a test file/table/column/row after that restore point;
3. restore application + database;
4. confirm the newer-only objects disappeared and the backed-up state returned;
5. confirm `config.php`, uploads, and required runtime storage were preserved.

The automated `tests/system_restore_test.php` regression covers these core
application and database rollback behaviors in CI.
