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

$operationLock = null;
if (in_array($action, ['upgrade', 'downgrade', 'restore', 'backup'], true)) {
    $operationLock = acquireOperationLock();
}

switch ($action) {
    case 'upgrade':
        runUpgrade(upgrade_engine(), $pdo, $options);
        break;
    case 'downgrade':
    case 'restore':
        runRestore(upgrade_engine(), $pdo, $options);
        break;
    case 'backup':
        runBackup($pdo);
        break;
    case 'list-backups':
        runList(upgrade_engine());
        break;
    default:
        printUsage();
        exit($action === '' ? 0 : 1);
}

function runUpgrade(UpgradeEngine $engine, PDO $pdo, array $options): void
{
    $repo = upgrade_normalize_source((string)($options['repo'] ?? ''));
    if ($repo === '') {
        $cfg = get_site_config($pdo);
        $repo = upgrade_effective_source($cfg);
    }

    if (!upgrade_is_valid_source($repo)) {
        failOperation('Enter a valid release source such as owner/repository or an HTTPS Git URL.');
    }

    $latest = isset($options['latest-release']);
    $reference = trim((string)($options['ref'] ?? ''));
    $token = trim((string)(getenv('GITHUB_TOKEN') ?: ''));
    $releaseUrl = null;

    if ($latest) {
        $release = $engine->fetchLatestRelease($repo, $token !== '' ? $token : null);
        if ($release === null) {
            failOperation('Unable to determine the latest release for ' . $repo);
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
        'safety_model' => 'staged-database-maintenance-cutover',
    ]);

    $runtime = new EnterpriseRecovery(base_path(''));
    $sourceDatabase = $runtime->activeDatabaseName($pdo);
    $originalPointer = $runtime->activeDatabasePointer();
    $backupSet = null;
    $preflightCandidate = null;
    $candidate = null;
    $tempDir = null;
    $maintenanceActive = false;
    $applicationChanged = false;

    info('Starting enterprise-safe upgrade run ' . $run['id']);
    info('Source repository: ' . $repo . ' @ ' . $targetRef);
    info('Current database will remain untouched: ' . $sourceDatabase);

    try {
        info('Creating native full pre-upgrade backup and proving it by isolated restore...');
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
            'source_database' => $sourceDatabase,
        ]);
        info('Verified backup set stored at ' . $backupSet['relative_path']);

        info('Downloading release into staging...');
        $download = $engine->downloadReleaseArchive($repo, $targetRef, $token !== '' ? $token : null);
        $tempDir = $download['tmp_dir'];
        $releaseValidation = $runtime->validateReleaseTree($download['extract_root']);
        $engine->updateRun($run, [
            'package_path' => $engine->relativeToRoot($download['archive']),
            'release_validation' => $releaseValidation,
        ]);
        info('Release staging passed PHP lint and required-file validation.');

        info('Running candidate/migration preflight while the live system remains available...');
        $preflightCandidate = system_backup_manager()->stageDatabaseFromBackupSet(
            $pdo,
            (string)$backupSet['absolute_path']
        );
        /** @var PDO $preflightPdo */
        $preflightPdo = $preflightCandidate['pdo'];

        $preflightMigrations = applyDatabaseMigrationsFromRoot(
            (string)$download['extract_root'],
            $pdo,
            (string)$preflightCandidate['database']
        );
        verifyUpgradeCandidate($preflightPdo);
        $engine->updateRun($run, [
            'preflight_candidate_database' => (string)$preflightCandidate['database'],
            'preflight_candidate_migrations' => $preflightMigrations,
            'preflight_verified_at' => date(DATE_ATOM),
        ]);
        info('Preflight candidate migrations and critical-table checks passed.');

        $runtime->discardStagedDatabase($pdo, (string)$preflightCandidate['database']);
        $preflightCandidate = null;

        info('Entering maintenance mode for final zero-write-loss cutover snapshot...');
        $runtime->enterMaintenance('Verified application/database upgrade cutover', [
            'run_id' => $run['id'],
            'target_ref' => $targetRef,
        ]);
        $maintenanceActive = true;

        // New requests are blocked by the maintenance gate. A short drain allows
        // already-running web requests to finish; the native dump then acquires a
        // global read lock, so the cutover snapshot cannot miss a committed write.
        usleep(2000000);

        $cutoverDumpPath = rtrim((string)$run['dir'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'cutover-database.sql';
        info('Creating final native database snapshot under read lock...');
        $cutoverDatabase = $runtime->dumpDatabaseForCutover($pdo, $cutoverDumpPath);
        $engine->updateRun($run, [
            'cutover_database_dump' => $engine->relativeToRoot($cutoverDumpPath),
            'cutover_database_sha256' => (string)($cutoverDatabase['sha256'] ?? ''),
            'cutover_read_lock' => (bool)($cutoverDatabase['cutover_read_lock'] ?? false),
        ]);

        info('Restoring final cutover snapshot into a fresh isolated candidate...');
        $candidate = $runtime->stageDatabase(
            $pdo,
            $cutoverDumpPath,
            is_array($cutoverDatabase['objects'] ?? null) ? $cutoverDatabase['objects'] : [],
            true
        );
        /** @var PDO $candidatePdo */
        $candidatePdo = $candidate['pdo'];

        info('Applying migrations to final candidate only...');
        $appliedMigrations = applyDatabaseMigrationsFromRoot(
            (string)$download['extract_root'],
            $pdo,
            (string)$candidate['database']
        );
        verifyUpgradeCandidate($candidatePdo);
        $engine->updateRun($run, [
            'candidate_database' => (string)$candidate['database'],
            'candidate_migrations' => $appliedMigrations,
            'candidate_verified_at' => date(DATE_ATOM),
        ]);
        info('Final candidate is current through the maintenance boundary and fully verified.');

        info('Deploying previously validated application package...');
        $applicationChanged = true;
        $engine->deployRelease($download['extract_root'], $preservePaths);
        applyConfigMigrations($engine);

        info('Atomically activating verified candidate database...');
        $runtime->switchActiveDatabase((string)$candidate['database'], [
            'operation' => 'upgrade',
            'run_id' => $run['id'],
            'previous_database' => $sourceDatabase,
            'release' => $targetRef,
        ]);

        verifyUpgradeCandidate($candidatePdo);
        upgrade_store_installed_release([
            'tag' => $targetRef,
            'name' => $targetLabel,
            'repo' => $repo,
            'url' => $releaseUrl,
            'installed_at' => date(DATE_ATOM),
        ]);

        $engine->completeRun($run, 'success');
        $runtime->exitMaintenance();
        $maintenanceActive = false;
        $runtime->cleanupRetention();
        $runtime->cleanupDatabaseGenerations($candidatePdo, 3);

        info('Upgrade completed successfully. Previous database remains retained as rollback generation: ' . $sourceDatabase);
    } catch (Throwable $e) {
        $rollbackError = null;

        // Before maintenance/cutover the live app and live DB have not been touched.
        // After cutover begins, rollback switches the DB pointer back to the original
        // untouched DB and restores only application files from the proven backup.
        if ($maintenanceActive || $applicationChanged) {
            try {
                info('Upgrade failed during cutover. Restoring the exact previous database pointer...');
                $runtime->restoreActiveDatabasePointer($originalPointer);

                if ($applicationChanged && is_array($backupSet) && !empty($backupSet['absolute_path'])) {
                    info('Restoring previous application snapshot...');
                    system_backup_manager()->restoreApplicationFromBackupSet(
                        (string)$backupSet['absolute_path'],
                        $preservePaths
                    );
                }

                if ($maintenanceActive) {
                    $runtime->exitMaintenance();
                    $maintenanceActive = false;
                }
                info('Rollback completed without replaying the database.');
            } catch (Throwable $rollbackFailure) {
                $rollbackError = $rollbackFailure;
                fwrite(
                    STDERR,
                    'Automatic rollback could not be proven. Maintenance mode remains active: '
                    . $rollbackFailure->getMessage() . PHP_EOL
                );
            }
        }

        if ($preflightCandidate !== null && !$maintenanceActive) {
            try {
                if ((string)($preflightCandidate['database'] ?? '') !== '') {
                    $runtime->discardStagedDatabase($pdo, (string)$preflightCandidate['database']);
                }
            } catch (Throwable $cleanupError) {
                error_log('Preflight candidate cleanup warning: ' . $cleanupError->getMessage());
            }
        }

        if ($candidate !== null && !$maintenanceActive) {
            try {
                // Failed candidates are disposable. Successful old DB generations are not.
                if ((string)($candidate['database'] ?? '') !== '') {
                    $runtime->discardStagedDatabase($pdo, (string)$candidate['database']);
                }
            } catch (Throwable $cleanupError) {
                error_log('Failed candidate database cleanup warning: ' . $cleanupError->getMessage());
            }
        }

        $rollbackMessage = $rollbackError === null
            ? 'Live database remained intact; application rollback completed when required.'
            : 'Rollback verification failed; maintenance mode retained: ' . $rollbackError->getMessage();

        $engine->updateRun($run, ['rollback_status' => $rollbackMessage]);
        $engine->completeRun($run, 'failed', $e->getMessage());
        failOperation('Upgrade failed: ' . $e->getMessage());
    } finally {
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

    failOperation(
        'Legacy in-place restore is disabled by the enterprise recovery policy. '
        . 'Import or select a verified full backup set (format v2/v3), or use the documented manual disaster-recovery procedure.'
    );
}

function runBackup(PDO $pdo): void
{
    info('Creating native full backup and proving it by isolated restore...');
    $backup = system_backup_manager()->createManualBackup($pdo);
    (new EnterpriseRecovery(base_path('')))->cleanupRetention();
    info('Verified full backup created: ' . (string)$backup['relative_path']);
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
  php scripts/{$script} --action=upgrade [--repo=<repository>] [--ref=<branch|tag>] [--latest-release] [--preserve=<paths>]
  php scripts/{$script} --action=restore --backup-id=<id>
  php scripts/{$script} --action=backup
  php scripts/{$script} --action=list-backups

Options:
  --repo             Repository slug or URL used to download releases. Defaults to the configured upgrade source.
  --ref              Branch, tag, or commit reference to deploy when --latest-release is not supplied.
  --latest-release   Resolve the newest GitHub release tag automatically.
  --preserve         Comma separated list of paths to keep untouched during deployment.
  --backup-id        Identifier of the backup set to restore. Defaults to the most recent ready backup.
  Restore policy     Only verified full backup sets (v2/v3) are automated. Legacy destructive
                     in-place database restores are intentionally disabled.

USAGE;
}

function info(string $message): void
{
    fwrite(STDOUT, '[INFO] ' . $message . PHP_EOL);
    $jobId = trim((string)(getenv('CAS_UPGRADE_JOB_ID') ?: ''));
    if ($jobId !== '') {
        try {
            upgrade_job_manager()->update($jobId, [
                'phase' => 'running',
                'phase_message' => $message,
            ]);
        } catch (Throwable $e) {
            error_log('Unable to update background job phase: ' . $e->getMessage());
        }
    }
}

function failOperation(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function acquireOperationLock()
{
    $directory = base_path('storage/upgrades');
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        failOperation('Unable to create system operation lock directory.');
    }

    $handle = fopen($directory . '/operation.lock', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        failOperation('Another backup, restore, or upgrade operation is already running.');
    }
    return $handle;
}

function verifyUpgradeCandidate(PDO $pdo): void
{
    $rows = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $names = array_map(static fn(array $row): string => (string)($row[0] ?? ''), $rows);
    foreach (['users', 'site_config', 'questionnaire', 'questionnaire_response'] as $required) {
        if (!in_array($required, $names, true)) {
            throw new RuntimeException('Candidate database is missing critical table: ' . $required);
        }
    }
    $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $pdo->query('SELECT COUNT(*) FROM questionnaire')->fetchColumn();
    $pdo->query('SELECT COUNT(*) FROM questionnaire_response')->fetchColumn();
}

function applyConfigMigrations(UpgradeEngine $engine): void
{
    $configPath = $engine->absoluteFromRoot('config.php');
    if (!is_file($configPath)) {
        info('Skipping config.php migrations (config.php not found).');
        return;
    }

    $contents = file_get_contents($configPath);
    if ($contents === false) {
        info('Skipping config.php migrations (unable to read config.php).');
        return;
    }

    if (strpos($contents, 'config.migrations.php') !== false) {
        info('config.php already references config.migrations.php.');
        return;
    }

    $snippet = "\nif (is_readable(__DIR__ . '/config.migrations.php')) {\n    require_once __DIR__ . '/config.migrations.php';\n}\n";
    $pos = strrpos($contents, '?>');
    if ($pos !== false) {
        $contents = substr($contents, 0, $pos) . $snippet . substr($contents, $pos);
    } else {
        $contents .= $snippet;
    }

    $result = file_put_contents($configPath, $contents);
    if ($result === false) {
        info('Unable to update config.php to include config.migrations.php.');
        return;
    }

    info('Added config.migrations.php include to config.php.');
}

function applyDatabaseMigrations(UpgradeEngine $engine, PDO $pdo): int
{
    throw new RuntimeException(
        'Direct live-database migration is disabled. Migrations must run against a staged candidate database.'
    );
}

function applyDatabaseMigrationsFromRoot(string $root, PDO $livePdo, string $candidateDatabase): int
{
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    $runtime = new EnterpriseRecovery(base_path(''));
    $appliedFiles = 0;

    foreach ([
        $root . DIRECTORY_SEPARATOR . 'upgrade_to_v3.sql',
        $root . DIRECTORY_SEPARATOR . 'migration.sql',
    ] as $path) {
        if (!is_file($path) || (filesize($path) ?: 0) <= 0) {
            continue;
        }

        info('Executing staged migration file with native database client: ' . basename($path));
        $runtime->executeSqlFileOnDatabase($livePdo, $candidateDatabase, $path);
        $appliedFiles++;
    }

    return $appliedFiles;
}

