#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be executed from the command line." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/upgrade.php';

$options = getopt('', [
    'action:',
    'repo::',
    'ref::',
    'latest-release',
    'preserve::',
    'backup-id::',
    'restore-db',
]);

$action = isset($options['action']) ? strtolower((string)$options['action']) : '';

switch ($action) {
    case 'backup':
        runBackup($pdo);
        break;
    case 'upgrade':
        runUpgrade(upgrade_engine(), $pdo, $options);
        break;
    case 'downgrade':
    case 'restore':
        runRestore(upgrade_engine(), $pdo, $options);
        break;
    case 'list-backups':
        runList(upgrade_engine());
        break;
    default:
        printUsage();
        exit($action === '' ? 0 : 1);
}

function runBackup(PDO $pdo): void
{
    info('Creating and verifying full manual backup...');
    $backup = system_backup_manager()->createManualBackup($pdo);
    info('Verified full backup created: ' . (string)$backup['relative_path']);
    try {
        $removed = system_backup_manager()->cleanupRetention();
        if ($removed !== []) {
            info('Retention cleanup removed ' . count($removed) . ' stale item(s).');
        }
    } catch (Throwable $cleanupError) {
        info('Retention cleanup warning: ' . $cleanupError->getMessage());
    }
}

function runUpgrade(UpgradeEngine $engine, PDO $pdo, array $options): void
{
    $repo = upgrade_normalize_source((string)($options['repo'] ?? ''));
    if ($repo === '') {
        $cfg = get_site_config($pdo);
        $repo = upgrade_effective_source($cfg);
    }

    if (!upgrade_is_valid_source($repo)) {
        fwrite(STDERR, 'Enter a valid release source such as owner/repository or an HTTPS Git URL.' . PHP_EOL);
        exit(1);
    }

    $latest = isset($options['latest-release']);
    $reference = trim((string)($options['ref'] ?? ''));

    $token = trim((string)(getenv('GITHUB_TOKEN') ?: ''));
    $releaseUrl = null;
    if ($latest) {
        $release = $engine->fetchLatestRelease($repo, $token !== '' ? $token : null);
        if ($release === null) {
            fwrite(STDERR, 'Unable to determine the latest release for ' . $repo . PHP_EOL);
            exit(1);
        }
        $targetRef = (string)$release['tag'];
        $targetLabel = (string)($release['name'] ?? $targetRef);
        $releaseUrl = isset($release['url']) ? (string)$release['url'] : null;
    } else {
        $targetRef = $reference !== '' ? $reference : 'main';
        $targetLabel = $targetRef;
    }

    $preserveInput = (string)($options['preserve'] ?? '');
    $extraPreserve = array_filter(array_map('trim', explode(',', $preserveInput)));
    $preservePaths = array_values(array_unique(array_merge([
        '.git',
        '.env',
        'config.php',
        'assets/uploads',
        'storage',
        'storage/upgrades',
    ], $extraPreserve)));

    $run = $engine->beginRun('upgrade', [
        'repo' => $repo,
        'ref' => $targetRef,
        'version_label' => $targetLabel,
        'release_url' => $releaseUrl,
        'preserve' => $preservePaths,
    ]);

    $backupSet = null;
    $candidate = null;
    $tempDir = null;
    $maintenanceEnabled = false;
    $liveApplicationChanged = false;
    $pointerSwitched = false;
    $previousCutoverState = system_backup_manager()->captureCutoverState();

    info('Starting upgrade run ' . $run['id']);
    info('Source repository: ' . $repo . ' @ ' . $targetRef);

    try {
        info('Creating and validating full pre-upgrade backup set...');
        $backupSet = system_backup_manager()->createUpgradeBackup(
            $pdo,
            $run['id'],
            $run['dir'],
            [
                'label' => 'Pre-upgrade snapshot before ' . $targetLabel,
                'target_repo' => $repo,
                'target_ref' => $targetRef,
                'target_label' => $targetLabel,
                'target_release_url' => $releaseUrl,
            ],
            $preservePaths
        );
        $engine->updateRun($run, [
            'backup_set' => $backupSet['relative_path'],
            'backup_format_version' => SYSTEM_BACKUP_FORMAT_VERSION,
        ]);
        info('Verified backup set stored at ' . $backupSet['relative_path']);

        info('Restoring pre-upgrade database into an isolated candidate database...');
        $candidate = system_backup_manager()->stageCandidateFromBackupSet(
            (string)$backupSet['absolute_path'],
            'upgrade'
        );
        info('Verified candidate database: ' . (string)$candidate['db_name']);

        info('Fetching release package...');
        $download = $engine->downloadReleaseArchive($repo, $targetRef, $token !== '' ? $token : null);
        $tempDir = $download['tmp_dir'];
        $engine->updateRun($run, [
            'package_path' => $engine->relativeToRoot($download['archive']),
        ]);
        info('Checking for database migrations against the isolated candidate database before touching live application files...');
        if (!is_array($candidate) || !($candidate['pdo'] ?? null) instanceof PDO) {
            throw new RuntimeException('Verified candidate database connection is unavailable.');
        }
        $migrationFile = applyDatabaseMigration($candidate, $download['extract_root']);
        if ($migrationFile === null) {
            info('No migration script was present in the release package.');
        } else {
            info('Applied candidate database migration using native client: ' . basename($migrationFile));
        }

        $criticalUsers = $candidate['pdo']->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
        );
        if (!$criticalUsers || (int)$criticalUsers->fetchColumn() !== 1) {
            throw new RuntimeException('Candidate database failed post-migration validation: users table is missing.');
        }
        $candidate['pdo']->query('SELECT COUNT(*) FROM users')->fetchColumn();
        info('Candidate database passed post-migration critical-table validation.');

        info('Candidate is ready. Entering controlled maintenance mode for cutover...');
        system_backup_manager()->setMaintenanceMode(true, 'upgrade');
        $maintenanceEnabled = true;

        info('Applying release files while preserving server-local state...');
        $engine->deployRelease($download['extract_root'], $preservePaths);
        $liveApplicationChanged = true;
        info('Release deployed successfully.');
        upgradeTestFailpoint('after_app_deploy');

        upgrade_store_installed_release([
            'tag' => $targetRef,
            'name' => $targetLabel,
            'repo' => $repo,
            'url' => $releaseUrl,
            'installed_at' => date(DATE_ATOM),
        ]);

        info('Switching CAS to the verified candidate database...');
        system_backup_manager()->switchActiveDatabase((string)$candidate['db_name']);
        $pointerSwitched = true;
        upgradeTestFailpoint('after_pointer_switch');

        system_backup_manager()->setMaintenanceMode(false);
        $maintenanceEnabled = false;
        $engine->completeRun($run, 'success');
        try {
            $removed = system_backup_manager()->cleanupRetention();
            if ($removed !== []) {
                info('Retention cleanup removed ' . count($removed) . ' stale item(s).');
            }
        } catch (Throwable $cleanupError) {
            info('Retention cleanup warning: ' . $cleanupError->getMessage());
        }

        info('Upgrade completed successfully.');
    } catch (Throwable $e) {
        $rollbackMessage = null;
        if (is_array($backupSet) && !empty($backupSet['absolute_path']) && ($liveApplicationChanged || $pointerSwitched)) {
            try {
                info('Upgrade failed during cutover. Restoring only the previous application and runtime pointers; the original live database is not restored or rewritten.');
                system_backup_manager()->restoreCutoverState($previousCutoverState);
                $pointerSwitched = false;
                system_backup_manager()->restoreApplicationFromBackupSet(
                    (string)$backupSet['absolute_path'],
                    $preservePaths,
                    false
                );
                $liveApplicationChanged = false;
                $rollbackMessage = 'Automatic cutover rollback completed using backup ' . (string)($backupSet['id'] ?? $run['id']) . '. Original live database remained untouched.';
                info($rollbackMessage);
            } catch (Throwable $rollbackError) {
                $rollbackMessage = 'Automatic cutover rollback failed: ' . $rollbackError->getMessage();
                fwrite(STDERR, $rollbackMessage . PHP_EOL);
            }
        } elseif (!$liveApplicationChanged && !$pointerSwitched) {
            $rollbackMessage = 'No live application or database cutover occurred; original live system remained untouched.';
            info($rollbackMessage);
        }

        $engine->updateRun($run, [
            'rollback_status' => $rollbackMessage,
        ]);
        $engine->completeRun($run, 'failed', $e->getMessage());
        fwrite(STDERR, 'Upgrade failed: ' . $e->getMessage() . PHP_EOL);
        if (is_array($backupSet)) {
            fwrite(STDERR, 'Verified backup set: ' . (string)$backupSet['relative_path'] . PHP_EOL);
        }
        exit(1);
    } finally {
        if ($maintenanceEnabled) {
            try {
                system_backup_manager()->setMaintenanceMode(false);
            } catch (Throwable $maintenanceError) {
                fwrite(STDERR, 'Unable to disable maintenance mode: ' . $maintenanceError->getMessage() . PHP_EOL);
            }
        }
        if ($tempDir !== null) {
            $engine->removeDirectory($tempDir);
        }
    }
}

function runRestore(UpgradeEngine $engine, PDO $pdo, array $options): void
{
    $backups = upgrade_list_backups();
    if ($backups === []) {
        fwrite(STDERR, 'No backups are available to restore.' . PHP_EOL);
        exit(1);
    }

    $backupId = trim((string)($options['backup-id'] ?? ''));
    if ($backupId === '') {
        foreach ($backups as $backup) {
            if (($backup['status'] ?? '') === 'ready' || ($backup['status'] ?? '') === 'success') {
                $backupId = (string)($backup['id'] ?? '');
                if ($backupId !== '') {
                    break;
                }
            }
        }
    }

    if ($backupId === '') {
        fwrite(STDERR, 'No valid backup is available to restore.' . PHP_EOL);
        exit(1);
    }

    $backupSet = upgrade_find_backup_set($backupId);
    if ($backupSet !== null && !empty($backupSet['backup_set_path'])) {
        info('Running backup-set integrity preflight for ' . $backupId . '...');
        $result = system_backup_manager()->restoreBackupSet(
            $pdo,
            (string)$backupSet['backup_set_path'],
            [
                'config.php',
                'assets/uploads',
                'storage',
                'storage/upgrades',
            ]
        );
        info('Application and database restored and verified successfully.');
        info('Emergency pre-restore backup retained as ' . (string)($result['emergency_backup_id'] ?? 'unknown') . '.');
        return;
    }

    // Backward compatibility for snapshots created before backup-set format v2.
    $runs = $engine->listUpgradeRuns();
    $target = null;
    foreach ($runs as $run) {
        $runId = (string)($run['id'] ?? ($run['timestamp'] ?? ''));
        if ($runId === $backupId) {
            $target = $run;
            break;
        }
    }

    if ($target === null) {
        fwrite(STDERR, 'Backup ' . $backupId . ' was not found.' . PHP_EOL);
        exit(1);
    }

    $snapshotRelative = (string)($target['app_snapshot'] ?? '');
    if ($snapshotRelative === '') {
        fwrite(STDERR, 'The selected legacy backup does not include an application snapshot.' . PHP_EOL);
        exit(1);
    }

    $restoreDb = isset($options['restore-db']);
    $snapshotAbsolute = $engine->absoluteFromRoot($snapshotRelative);
    info('Restoring legacy application snapshot from ' . $snapshotRelative . '...');
    $engine->restoreApplicationSnapshot($snapshotAbsolute, [
        'config.php',
        'assets/uploads',
        'storage',
        'storage/upgrades',
    ]);
    info('Legacy application files restored.');

    if ($restoreDb) {
        $dbRelative = (string)($target['db_backup'] ?? '');
        if ($dbRelative === '') {
            fwrite(STDERR, 'The selected legacy backup does not include a database dump.' . PHP_EOL);
            exit(1);
        }
        info('Restoring legacy database from ' . $dbRelative . '...');
        $engine->restoreDatabaseFromDump($pdo, $engine->absoluteFromRoot($dbRelative));
        info('Legacy database restored.');
    } else {
        info('Legacy database restore skipped. Use --restore-db to enable it.');
    }

    info('Legacy restore operation completed.');
}

function runList(UpgradeEngine $engine): void
{
    $backups = upgrade_list_backups();
    if ($backups === []) {
        echo 'No backup history found.' . PHP_EOL;
        return;
    }

    printf("%-24s %-16s %-12s %-25s %s\n", 'Backup ID', 'Kind', 'Status', 'Release', 'Created At');
    echo str_repeat('-', 110) . PHP_EOL;
    foreach ($backups as $backup) {
        printf(
            "%-24s %-16s %-12s %-25s %s\n",
            (string)($backup['id'] ?? ''),
            (string)($backup['kind'] ?? 'backup'),
            (string)($backup['status'] ?? 'unknown'),
            (string)($backup['version_label'] ?? ($backup['ref'] ?? '')),
            (string)($backup['timestamp'] ?? '')
        );
    }
}

function printUsage(): void
{
    $script = basename(__FILE__);
    echo <<<USAGE
System upgrade utility
Usage:
  php scripts/{$script} --action=backup
  php scripts/{$script} --action=upgrade [--repo=<repository>] [--ref=<branch|tag>] [--latest-release] [--preserve=<paths>]
  php scripts/{$script} --action=downgrade [--backup-id=<id>] [--restore-db]
  php scripts/{$script} --action=list-backups

Options:
  --repo             Repository slug or URL used to download releases. Defaults to the configured upgrade source.
  --ref              Branch, tag, or commit reference to deploy when --latest-release is not supplied.
  --latest-release   Resolve the newest GitHub release tag automatically.
  --preserve         Comma separated list of paths to keep untouched during deployment.
  --backup-id        Identifier of the backup set to restore. Defaults to the most recent ready backup.
  --restore-db       Legacy snapshots only: restore the database dump alongside application files.
                     Format-v2 backup sets always restore and verify both application and database.

USAGE;
}

function info(string $message): void
{
    fwrite(STDOUT, '[INFO] ' . $message . PHP_EOL);
}

function applyDatabaseMigration(array $candidate, string $sourceRoot): ?string
{
    $dbName = trim((string)($candidate['db_name'] ?? ''));
    if ($dbName === '') {
        throw new RuntimeException('Candidate database name is unavailable.');
    }

    $root = rtrim($sourceRoot, DIRECTORY_SEPARATOR);
    $preferred = $root . DIRECTORY_SEPARATOR . 'migration.sql';
    $fallback = $root . DIRECTORY_SEPARATOR . 'upgrade_to_v3.sql';
    $path = is_file($preferred) ? $preferred : (is_file($fallback) ? $fallback : null);

    if ($path === null) {
        return null;
    }

    system_backup_manager()->applyNativeSqlFile($dbName, $path);
    return $path;
}

function upgradeTestFailpoint(string $name): void
{
    if (getenv('CAS_UPGRADE_TEST_MODE') !== '1') {
        return;
    }
    if ((string)getenv('CAS_UPGRADE_FAIL_AT') === $name) {
        throw new RuntimeException('Injected upgrade failure at ' . $name);
    }
}

