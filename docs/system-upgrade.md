# System upgrade, backup, and restore

The CAS upgrade utility uses verified **full backup sets** as the recovery point for upgrades and manual system backups.

A format-v2 backup set contains:

* `manifest.json` – backup metadata, release metadata, preserved paths, object counts, and SHA-256 checksums.
* `application.zip` – application files excluding environment/runtime paths that must remain local to the server.
* `database/backup.sql` – a complete database schema and data snapshot.
* `meta/summary.json` – a small human-readable summary.

## Prerequisites

* PHP 8.1+
* PHP `zip` extension (`ext-zip`)
* PDO MySQL driver
* MySQL or MariaDB
* Network access to the GitHub repository that hosts releases

## Preserved server-local paths

Full application restore is exact for application code, but the following server-local paths are intentionally preserved:

* `.git`
* `.env`
* `config.php`
* `assets/uploads`
* `storage`

Additional preserve paths may be supplied to the CLI upgrade command.

## Upgrade flow

Before CAS applies an upgrade it now:

1. Creates a full App + DB backup set in `storage/upgrades/runs/<run-id>/backup-set.zip`.
2. Generates SHA-256 checksums for the application and database components.
3. Reopens and validates the completed backup set.
4. Only then downloads and deploys the requested release.
5. Applies database migrations.
6. Records the installed release.

If the upgrade fails after the verified backup exists, the utility automatically attempts to restore that pre-upgrade backup set.

## Manual backup

The System Upgrade page can create and download a manual full backup set. Manual backup sets are also retained under `storage/upgrades/manual` and immediately become available in System Restore.

A downloaded format-v2 backup ZIP can later be uploaded through **Import downloaded backup set**. CAS validates the archive and checksums before adding it to the restore catalog.

## Restore flow

Format-v2 System Restore always restores **application + database together**. It does not offer a database-only or files-only mode because that can create mixed-version states.

Before modifying the live system CAS:

1. Validates the selected backup ZIP and required components.
2. Verifies component SHA-256 checksums.
3. Rejects unsafe ZIP paths before extraction.
4. Validates the SQL statement stream.
5. Creates and validates a fresh **emergency pre-restore backup**.

The restore then:

1. Removes database tables/views that are not part of the selected snapshot.
2. Recreates the database from the selected dump.
3. Verifies the exact database object list and row counts.
4. Removes application files that are not part of the selected snapshot, except preserved paths.
5. Restores the application snapshot.
6. Verifies the application tree SHA-256 hash.
7. Restores installed-release metadata.

If any restore step fails, CAS automatically attempts to restore the emergency pre-restore backup. Emergency backups are retained under `storage/upgrades/emergency` so administrators have an additional recovery point.

## CLI usage

```bash
# Deploy a specific tag or branch
php scripts/system_upgrade.php --action=upgrade --repo=owner/repository --ref=v0.6.0

# Deploy the latest GitHub release
php scripts/system_upgrade.php --action=upgrade --repo=owner/repository --latest-release

# List all format-v2 and legacy backups
php scripts/system_upgrade.php --action=list-backups

# Restore a format-v2 backup set (always App + DB)
php scripts/system_upgrade.php --action=downgrade --backup-id=manual_20261102_090000

# Legacy snapshots created before format v2 can still be restored.
# Use --restore-db to include the legacy database dump.
php scripts/system_upgrade.php --action=downgrade --backup-id=20260909_120000 --restore-db
```

## Automated regression testing

`tests/system_backup_restore_test.php` performs a complete round trip against both MySQL and MariaDB in CI:

1. Creates baseline application files and relational database data.
2. Creates and validates a full backup set.
3. Tests import of a downloaded backup set.
4. Confirms ZIP path traversal is rejected.
5. Changes/deletes/adds application files.
6. Changes database rows, adds a column, and adds a new table.
7. Runs System Restore.
8. Confirms the application and database match the backup point exactly.
9. Confirms preserved paths remain untouched.
10. Confirms the emergency pre-restore backup exists and validates.

This test is the release gate for changes to the backup/restore implementation.
