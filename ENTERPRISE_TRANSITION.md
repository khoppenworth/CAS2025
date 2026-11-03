# CAS one-time enterprise updater transition runbook

This runbook is for a CAS server still running release **0.5.95 through 0.5.98**.

The legacy updater cannot safely install the enterprise updater by itself because its old pre-upgrade database validation runs before the newer updater code is installed. This is a **one-time bridge only**. After it succeeds, future CAS upgrades/backups/restores use the normal web interface.

## Safety rules

- Do **not** use the legacy CAS Upgrade button before completing this bridge.
- Do **not** manually replace individual PHP files.
- Do **not** restore the database as part of this bridge.
- The bridge never modifies the active application database.
- The bridge first runs in check-only mode.
- If any command below reports an error, stop. Do not improvise another workaround.
- The apply step creates a rollback snapshot and restores the original updater files automatically if any post-install verification fails.

## 1. Go to the CAS application root

Replace the example path with the actual CAS root:

```bash
cd /path/to/CAS
```

Confirm that this is the application root:

```bash
test -f admin/upgrade.php \
  && test -f lib/system_backup.php \
  && test -f scripts/system_upgrade.php \
  && echo "CAS ROOT CONFIRMED" \
  || echo "STOP - WRONG DIRECTORY"
```

Continue only if the output is:

```text
CAS ROOT CONFIRMED
```

## 2. Download the complete official release archive

Use the version of this release page:

```bash
VERSION="0.5.101"
TMP="$(mktemp -d)"

curl -fL \
  "https://github.com/khoppenworth/CAS2025/archive/refs/tags/${VERSION}.tar.gz" \
  -o "$TMP/cas-${VERSION}.tar.gz"

tar -xzf "$TMP/cas-${VERSION}.tar.gz" -C "$TMP"

SRC="$(find "$TMP" -mindepth 1 -maxdepth 1 -type d -name 'CAS2025-*' | head -1)"

test -n "$SRC" \
  && test -f "$SRC/scripts/bootstrap_enterprise_transition.php" \
  && echo "RELEASE PACKAGE READY" \
  || echo "STOP - RELEASE PACKAGE INVALID"
```

Continue only if the output is:

```text
RELEASE PACKAGE READY
```

## 3. Run the non-destructive preflight

```bash
php "$SRC/scripts/bootstrap_enterprise_transition.php" \
  --root="$PWD" \
  --source-root="$SRC" \
  --check
```

The bridge checks:

- current updater files are recognized 0.5.95-0.5.98 files;
- no unknown/local updater modification would be overwritten;
- no maintenance flag, active DB pointer, background job, or pending upgrade run is active;
- native MySQL/MariaDB dump and restore utilities are installed;
- staging database privileges work by creating and removing only a temporary throwaway database;
- the target enterprise updater files match their immutable Git blob hashes;
- the target PHP files pass syntax checks.

The required final message is:

```text
CHECK PASSED. No live application files were changed.
```

If the check does not finish with that exact message, **stop**. Do not run `--apply`.

## 4. Apply the one-time bridge

Only after the check passes:

```bash
php "$SRC/scripts/bootstrap_enterprise_transition.php" \
  --root="$PWD" \
  --source-root="$SRC" \
  --apply
```

The apply step:

- creates a rollback snapshot under `storage/upgrades/bootstrap/`;
- replaces only the updater/runtime files required for the enterprise path;
- preserves each existing file's owner, group, and mode;
- verifies installed file hashes and PHP syntax;
- verifies that the enterprise updater runtime loads;
- leaves the active database unchanged;
- leaves `storage/upgrades/installed.json` unchanged;
- rolls all updater files back exactly if any post-install verification fails.

The required final message is:

```text
BRIDGE INSTALLATION PASSED.
```

If it reports an error, stop and read the message. The bridge is designed to restore the original updater files automatically.

## 5. Verify CAS before performing the normal upgrade

Open CAS in the browser and confirm:

- login works;
- dashboard loads normally;
- images/assets load normally;
- Admin > System Upgrade opens;
- the displayed installed release is still the old version, for example **0.5.95**.

That is expected. The bridge does not pretend that the application itself has been upgraded.

## 6. Upgrade through the normal CAS web interface

In CAS:

1. Go to **Admin > System Upgrade**.
2. Click **Check for Updates**.
3. Confirm the target release is the current release.
4. Start the upgrade.
5. Do not start another backup/upgrade/restore job while one is active.

From this point the enterprise updater performs long-running work as a background job rather than holding the Apache request open.

The upgrade path is:

```text
live application + live database remain active
        |
        v
native verified backup
        |
        v
isolated candidate database restore
        |
        v
candidate validation
        |
        v
new release staging
        |
        v
short maintenance window
        |
        v
candidate migration
        |
        v
atomic active-database pointer cutover
        |
        v
maintenance ends
```

The previous database is retained for rollback instead of being dropped and reconstructed.

## 7. Post-upgrade checks

After the job reports success, verify:

```text
[ ] CAS reports the new release version
[ ] login works
[ ] dashboard loads
[ ] images/assets load
[ ] users are present
[ ] assessments load
[ ] analytics loads
[ ] Admin > System Upgrade loads
[ ] no active maintenance message remains
```

Then create a **new Full Backup** from the System Upgrade page. It should run as a background job. After it completes, download the already-created validated backup separately.

## If anything fails

Do not immediately retry a failed system operation.

Check the System Upgrade job status/message first. The enterprise path is designed so failures before cutover leave the active database untouched and failures during the bridge restore the original updater files.

The independent server/application and database backups should continue to be retained until the new upgrade/backup/restore path has been successfully exercised on the test server.
