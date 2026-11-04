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
        '.htaccess',
        '.user.ini',
        'assets/uploads',
        'storage',
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
    $applicationMutated = false;
    $cutoverState = system_backup_manager()->captureCutoverState();

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
        info('Package downloaded. Entering controlled maintenance mode...');
        system_backup_manager()->setMaintenanceMode(true, 'upgrade');
        $maintenanceEnabled = true;

        info('Applying exact release files while preserving site-owned runtime paths...');
        // deployRelease can fail after partially changing live files, so rollback
        // responsibility begins before the first deployment mutation.
        $applicationMutated = true;
        $engine->deployRelease($download['extract_root'], $preservePaths);
        info('Release deployed successfully. Server-owned runtime files were preserved.');
        upgradeTestFailurePoint('after-deploy');

        info('Checking for database migrations against the isolated candidate database...');
        if (!is_array($candidate) || !($candidate['pdo'] ?? null) instanceof PDO) {
            throw new RuntimeException('Verified candidate database connection is unavailable.');
        }
        $appliedMigrations = applyDatabaseMigrations($engine, $candidate['pdo']);
        if ($appliedMigrations === 0) {
            info('No migration scripts were executed.');
        } else {
            info('Applied ' . $appliedMigrations . ' candidate database migration statement' . ($appliedMigrations === 1 ? '' : 's') . '.');
        }

        $criticalUsers = $candidate['pdo']->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
        );
        if (!$criticalUsers || (int)$criticalUsers->fetchColumn() !== 1) {
            throw new RuntimeException('Candidate database failed post-migration validation: users table is missing.');
        }
        $candidate['pdo']->query('SELECT COUNT(*) FROM users')->fetchColumn();
        info('Candidate database passed post-migration critical-table validation.');

        info('Switching CAS to the verified candidate database...');
        system_backup_manager()->switchActiveDatabase((string)$candidate['db_name']);
        upgradeTestFailurePoint('after-pointer');

        // Prove a fresh PHP process resolves the new runtime pointer through the
        // preserved site config before users are allowed back in.
        verifyRuntimeDatabase((string)$candidate['db_name']);

        upgrade_store_installed_release([
            'tag' => $targetRef,
            'name' => $targetLabel,
            'repo' => $repo,
            'url' => $releaseUrl,
            'installed_at' => date(DATE_ATOM),
        ]);
        upgradeTestFailurePoint('after-installed');

        system_backup_manager()->setMaintenanceMode(false);
        $maintenanceEnabled = false;

        try {
            $engine->completeRun($run, 'success');
        } catch (Throwable $runLogError) {
            info('Upgrade completed, but run-status logging failed: ' . $runLogError->getMessage());
        }

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
        $rollbackErrors = [];

        // Once live files may have changed, keep users out until every rollback
        // component has been verified. A failed rollback intentionally leaves
        // maintenance mode enabled rather than exposing a mixed state.
        if ($applicationMutated && !$maintenanceEnabled) {
            try {
                system_backup_manager()->setMaintenanceMode(true, 'upgrade-rollback');
                $maintenanceEnabled = true;
            } catch (Throwable $maintenanceError) {
                $rollbackErrors[] = 'unable to enable rollback maintenance: ' . $maintenanceError->getMessage();
            }
        }

        try {
            system_backup_manager()->restoreCutoverState($cutoverState);
        } catch (Throwable $stateError) {
            $rollbackErrors[] = 'runtime state: ' . $stateError->getMessage();
        }

        if ($applicationMutated && is_array($backupSet) && !empty($backupSet['absolute_path'])) {
            try {
                info('Upgrade failed. Restoring the previous application snapshot only; the original database is not rewritten...');
                system_backup_manager()->restoreApplicationFromBackupSet(
                    (string)$backupSet['absolute_path'],
                    $preservePaths
                );
            } catch (Throwable $applicationRollbackError) {
                $rollbackErrors[] = 'application files: ' . $applicationRollbackError->getMessage();
            }
        }

        if (is_array($candidate) && !empty($candidate['db_name'])) {
            try {
                system_backup_manager()->dropCandidateDatabase((string)$candidate['db_name']);
            } catch (Throwable $candidateCleanupError) {
                $rollbackErrors[] = 'candidate cleanup: ' . $candidateCleanupError->getMessage();
            }
        }

        $rollbackMessage = '';
        if ($rollbackErrors === []) {
            if ($maintenanceEnabled) {
                try {
                    system_backup_manager()->setMaintenanceMode(false);
                    $maintenanceEnabled = false;
                } catch (Throwable $maintenanceError) {
                    $rollbackErrors[] = 'unable to leave maintenance mode: ' . $maintenanceError->getMessage();
                }
            }
        }

        if ($rollbackErrors === []) {
            $rollbackMessage = 'Automatic rollback completed: previous application files, database pointer, and release metadata were restored; the original database was never rewritten.';
            info($rollbackMessage);
        } else {
            $rollbackMessage = 'Automatic rollback is incomplete; CAS remains in maintenance mode. ' . implode(' | ', $rollbackErrors);
            fwrite(STDERR, $rollbackMessage . PHP_EOL);
        }

        try {
            $engine->updateRun($run, ['rollback_status' => $rollbackMessage]);
            $engine->completeRun($run, 'failed', $e->getMessage());
        } catch (Throwable $runLogError) {
            fwrite(STDERR, 'Unable to record failed upgrade run: ' . $runLogError->getMessage() . PHP_EOL);
        }

        fwrite(STDERR, 'Upgrade failed: ' . $e->getMessage() . PHP_EOL);
        if (is_array($backupSet)) {
            fwrite(STDERR, 'Verified backup set: ' . (string)$backupSet['relative_path'] . PHP_EOL);
        }
        exit(1);
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

function upgradeTestFailurePoint(string $stage): void
{
    if (getenv('CAS_UPGRADE_TEST_MODE') === '1'
        && trim((string)(getenv('CAS_UPGRADE_TEST_FAIL_STAGE') ?: '')) === $stage
    ) {
        throw new RuntimeException('Injected upgrade failure at ' . $stage);
    }
}

function verifyRuntimeDatabase(string $expectedDatabase): void
{
    if ($expectedDatabase === '' || preg_match('/^[A-Za-z0-9_]+$/', $expectedDatabase) !== 1) {
        throw new RuntimeException('Runtime database verification received an unsafe database name.');
    }

    $php = PHP_BINARY ?: 'php';
    $config = base_path('config.php');
    $code = 'require ' . var_export($config, true)
        . '; $name=(string)$pdo->query("SELECT DATABASE()")->fetchColumn();'
        . ' $users=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();'
        . ' echo $name, "\\n", $users, "\\n";';

    $process = proc_open([$php, '-r', $code], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, base_path(''));

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start post-cutover runtime verification.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $lines = preg_split('/\\R/', trim($stdout)) ?: [];
    $actualDatabase = trim((string)($lines[0] ?? ''));

    if ($exit !== 0 || $actualDatabase !== $expectedDatabase) {
        throw new RuntimeException(
            'Fresh-process runtime verification failed'
            . ($stderr !== '' ? ': ' . trim($stderr) : '')
            . ($actualDatabase !== '' ? ' (resolved database ' . $actualDatabase . ')' : '')
        );
    }

    info('Fresh-process runtime verification passed for ' . $expectedDatabase . '.');
}

function applyDatabaseMigrations(UpgradeEngine $engine, PDO $pdo): int
{
    $totalStatements = 0;
    $candidates = [
        base_path('upgrade_to_v3.sql'),
        base_path('migration.sql'),
    ];

    foreach ($candidates as $path) {
        if ($path === '' || !is_file($path)) {
            continue;
        }

        $statements = readSqlStatements($path);
        if ($statements === []) {
            continue;
        }

        $relative = $engine->relativeToRoot($path);
        info('Running ' . count($statements) . ' SQL statement' . (count($statements) === 1 ? '' : 's') . ' from ' . $relative . '...');
        $totalStatements += executeSqlStatements($pdo, $statements, $relative);
    }

    return $totalStatements;
}

function readSqlStatements(string $filePath): array
{
    $contents = file_get_contents($filePath);
    if ($contents === false) {
        throw new RuntimeException('Unable to read SQL file at ' . $filePath);
    }

    $withoutBlockComments = preg_replace('~/\*.*?\*/~s', '', $contents);
    if ($withoutBlockComments === null) {
        $withoutBlockComments = $contents;
    }

    $lines = [];
    foreach (preg_split('/\R/', $withoutBlockComments) as $line) {
        if (preg_match('/^\s*(--|#)/', $line)) {
            continue;
        }
        $lines[] = $line;
    }

    $sanitized = implode("\n", $lines);
    return splitSqlStatements($sanitized);
}

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inString = false;
    $stringChar = '';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($inString) {
            if ($char === $stringChar) {
                $backslashes = 0;
                for ($j = $i - 1; $j >= 0 && $sql[$j] === '\\'; $j--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 0) {
                    $inString = false;
                    $stringChar = '';
                }
            }
            $buffer .= $char;
            continue;
        }

        if ($char === '\'' || $char === '"') {
            $inString = true;
            $stringChar = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function executeSqlStatements(PDO $pdo, array $statements, string $context): int
{
    $count = 0;
    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
            $count++;
        } catch (PDOException $e) {
            if (upgrade_should_ignore_sql_error($e, $statement)) {
                $preview = trim(preg_replace('/\s+/', ' ', $statement));
                if (strlen($preview) > 120) {
                    $preview = substr($preview, 0, 117) . '...';
                }
                error_log('Ignored SQL warning from ' . $context . ' while running "' . $preview . '": ' . $e->getMessage());
                continue;
            }
            throw new RuntimeException('Failed to execute statement from ' . $context . ': ' . $e->getMessage(), 0, $e);
        }
    }

    return $count;
}
