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
    $tempDir = null;

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

        info('Fetching release package...');
        $download = $engine->downloadReleaseArchive($repo, $targetRef, $token !== '' ? $token : null);
        $tempDir = $download['tmp_dir'];
        $engine->updateRun($run, [
            'package_path' => $engine->relativeToRoot($download['archive']),
        ]);
        info('Package downloaded. Applying files...');
        $engine->deployRelease($download['extract_root'], $preservePaths);
        info('Release deployed successfully.');
        applyConfigMigrations($engine);

        info('Checking for database migrations...');
        $appliedMigrations = applyDatabaseMigrations($engine, $pdo);
        if ($appliedMigrations === 0) {
            info('No migration scripts were executed.');
        } else {
            info('Applied ' . $appliedMigrations . ' database migration statement' . ($appliedMigrations === 1 ? '' : 's') . '.');
        }

        $engine->completeRun($run, 'success');
        upgrade_store_installed_release([
            'tag' => $targetRef,
            'name' => $targetLabel,
            'repo' => $repo,
            'url' => $releaseUrl,
            'installed_at' => date(DATE_ATOM),
        ]);

        info('Upgrade completed successfully.');
    } catch (Throwable $e) {
        $rollbackMessage = null;
        if (is_array($backupSet) && !empty($backupSet['absolute_path'])) {
            try {
                info('Upgrade failed. Automatically restoring the verified pre-upgrade backup set...');
                $rollback = system_backup_manager()->restoreBackupSet(
                    $pdo,
                    (string)$backupSet['absolute_path'],
                    $preservePaths
                );
                $rollbackMessage = 'Automatic rollback completed using backup ' . (string)($rollback['restored_id'] ?? $run['id']) . '.';
                info($rollbackMessage);
            } catch (Throwable $rollbackError) {
                $rollbackMessage = 'Automatic rollback failed: ' . $rollbackError->getMessage();
                fwrite(STDERR, $rollbackMessage . PHP_EOL);
            }
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
