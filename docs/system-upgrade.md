# System upgrade utility

The `scripts/system_upgrade.php` CLI script automates application and database
upgrades by downloading release archives, capturing snapshots, staging
deployments, and applying bundled SQL migrations in a repeatable fashion.

## Prerequisites

* PHP 8.1+
* PHP `zip` extension (`ext-zip`)
* `git`, `mysqldump`, and `mysql` binaries available in `PATH`
* `tar` binary (only required when restoring backups created with HRassess v3.0 or earlier)
* Network access to the GitHub repository that hosts your releases

## Usage

```bash
# Deploy a specific tag or branch
php scripts/system_upgrade.php --action=upgrade --repo=https://github.com/your-org/HRassessv300.git --ref=v3.1.0

# Deploy the latest GitHub release
php scripts/system_upgrade.php --action=upgrade --repo=https://github.com/your-org/HRassessv300.git --latest-release

# Restore from the most recent successful backup (files only)
php scripts/system_upgrade.php --action=downgrade

# Restore a specific backup including the database
php scripts/system_upgrade.php --action=downgrade --backup-id=20240211_101112 --restore-db

# List available backups
php scripts/system_upgrade.php --action=list-backups
```

### Options

| Option | Description |
|--------|-------------|
| `--repo` | Git repository URL. If omitted the script tries to read `origin` from the local clone. |
| `--ref` | Branch, tag, or commit to deploy (defaults to `main`). |
| `--latest-release` | Resolve the latest GitHub release tag and deploy it. Requires `--repo`. |
| `--backup-dir` | Directory for storing backups (defaults to `<app>/backups`). |
| `--preserve` | Comma-separated paths to keep untouched during upgrades (defaults to `config.php`, `backups`, `assets/backups`, `assets/uploads`, and `storage`). |
| `--backup-id` | Timestamp of the backup to restore (shown in `list-backups`). |
| `--restore-db` | Restore the database when downgrading. |

## Backup layout

Each upgrade captures the pre-upgrade state and stores it in the backup
directory:

* `app-<timestamp>.zip` – snapshot of the application files before the upgrade
  (excludes preserved paths)
* `db-<timestamp>.sql` – SQL dump of the database before the upgrade
* `upgrade-<timestamp>.json` – manifest describing release metadata, snapshot
  paths, and deployment status

If an upgrade fails, the script automatically restores the previous state using
the snapshot and database dump before marking the manifest status as `failed`.

## Config migrations

The upgrade utility preserves `config.php` to protect environment-specific
settings such as database credentials. To ensure new helper functions are still
available, the upgrade flow injects a `require_once __DIR__ . '/config.migrations.php';`
include into `config.php` (if it is missing). The `config.migrations.php` file
is versioned with the application and safely defines forward-compatible helper
functions only when they do not already exist.

## Customising preserved paths

Environment-specific files can be shielded from upgrades by passing a
comma-separated list through `--preserve`. For example, to keep a local `.env`
file untouched:

```bash
php scripts/system_upgrade.php --action=upgrade --repo=... --ref=main --preserve=.env
```

The list always includes `config.php` and the backup directory to prevent them
from being overwritten or removed.
