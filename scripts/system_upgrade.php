#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be executed from the command line." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../lib/system_cli_bootstrap.php';
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

function systemUpgradeSourceFromDatabase(PDO $pdo): string
{
    try {
        $stmt = $pdo->query("SELECT upgrade_repo FROM site_config WHERE id = 1");
        $value = $stmt ? trim((string)$stmt->fetchColumn()) : '';
        if ($value !== '') {
            return $value;
        }
    } catch (Throwable $e) {
        // Fall through to the repository default without mutating the database.
    }

    return ADMIN_UPGRADE_DEFAULT_REPO;
}

function runUpgrade(UpgradeEngine $engine, PDO $pdo, array $options): void
{
    $manager = system_backup_manager();
    $repo = upgrade_normalize_source((string)($options['repo'] ?? ''));
    if ($repo === '') {
        $repo = systemUpgradeSourceFromDatabase($pdo);
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
        '.htaccess',
        '.user.ini',
        '.well-known',
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
    $applicationMutationStarted = false;
    $pointerState = $manager->activeDatabasePointerState();
    $installedState = $manager->installedReleaseState();
    $originalDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

    if ($originalDatabase === '' || preg_match('/^[A-Za-z0-9_]+$/', $originalDatabase) !== 1) {
        $engine->completeRun($run, 'failed', 'Unable to determine current database safely.');
        fwrite(STDERR, 'Upgrade failed: unable to determine current database safely.' . PHP_EOL);
        exit(1);
    }

    info('Starting upgrade run ' . $run['id']);
    info('Source repository: ' . $repo . ' @ ' . $targetRef);

    try {
        info('Fetching release package before maintenance mode...');
        $download = $engine->downloadReleaseArchive($repo, $targetRef, $token !== '' ? $token : null);
        $tempDir = $download['tmp_dir'];
        $engine->updateRun($run, [
            'package_path' => $engine->relativeToRoot($download['archive']),
        ]);

        info('Validating release package before touching live application/database state...');
        $releaseValidation = $engine->validateReleasePackage($download['extract_root']);
        info('Release package passed PHP syntax validation for '
            . (int)($releaseValidation['php_files_linted'] ?? 0) . ' file(s).');

        info('Entering maintenance mode before the authoritative database snapshot...');
        $manager->setMaintenanceMode(true, 'upgrade');
        $maintenanceEnabled = true;

        info('Waiting for pre-maintenance application DB sessions to drain...');
        $manager->waitForApplicationConnectionsToDrain($pdo, 30);
        info('Application DB sessions drained. No new CAS web request can enter the database while maintenance is active.');

        info('Creating and validating the authoritative pre-upgrade backup set...');
        $backupSet = $manager->createUpgradeBackup(
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
        info('Verified authoritative backup set stored at ' . $backupSet['relative_path']);

        info('Restoring the frozen backup into an isolated candidate database...');
        $candidate = $manager->stageCandidateFromBackupSet(
            (string)$backupSet['absolute_path'],
            'upgrade'
        );
        info('Verified candidate database: ' . (string)$candidate['db_name']);

        if (!is_array($candidate) || !($candidate['pdo'] ?? null) instanceof PDO) {
            throw new RuntimeException('Verified candidate database connection is unavailable.');
        }

        info('Applying database migrations only to the isolated candidate...');
        $appliedMigrations = applyDatabaseMigrations(
            $engine,
            $candidate['pdo'],
            $download['extract_root']
        );
        info($appliedMigrations === 0
            ? 'No candidate database migration scripts were executed.'
            : 'Applied ' . $appliedMigrations . ' candidate database migration statement'
                . ($appliedMigrations === 1 ? '' : 's') . '.');

        assertCandidatePreservesBackupData(
            $candidate['pdo'],
            is_array($backupSet['manifest']['database']['objects'] ?? null)
                ? $backupSet['manifest']['database']['objects']
                : []
        );
        info('Candidate migration preserved every pre-upgrade base table and row count.');

        $criticalUsers = $candidate['pdo']->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
        );
        if (!$criticalUsers || (int)$criticalUsers->fetchColumn() !== 1) {
            throw new RuntimeException('Candidate database failed validation: users table is missing.');
        }
        $candidate['pdo']->query('SELECT COUNT(*) FROM users')->fetchColumn();
        info('Candidate database passed post-migration validation.');

        info('Applying the validated release as an exact application deployment...');
        $applicationMutationStarted = true;
        $engine->deployRelease($download['extract_root'], $preservePaths);
        info('Release files deployed. Deployment-specific config.php/.env/uploads/storage were untouched.');

        info('Switching the database pointer to the verified candidate...');
        $manager->switchActiveDatabase((string)$candidate['db_name']);

        upgrade_store_installed_release([
            'tag' => $targetRef,
            'name' => $targetLabel,
            'repo' => $repo,
            'url' => $releaseUrl,
            'installed_at' => date(DATE_ATOM),
        ]);

        info('Running the new application health check before reopening CAS...');
        runSystemHealthcheck($engine, (string)$candidate['db_name'], true);

        $manager->setMaintenanceMode(false);
        $maintenanceEnabled = false;
        $engine->completeRun($run, 'success');

        try {
            $removed = $manager->cleanupRetention();
            if ($removed !== []) {
                info('Retention cleanup removed ' . count($removed) . ' stale item(s).');
            }
        } catch (Throwable $cleanupError) {
            info('Retention cleanup warning: ' . $cleanupError->getMessage());
        }

        info('Upgrade completed successfully.');
    } catch (Throwable $e) {
        $rollbackErrors = [];

        if ($maintenanceEnabled || $applicationMutationStarted) {
            try {
                $manager->restoreActiveDatabasePointerState($pointerState);
            } catch (Throwable $rollbackError) {
                $rollbackErrors[] = 'database pointer: ' . $rollbackError->getMessage();
            }

            try {
                $manager->restoreInstalledReleaseState($installedState);
            } catch (Throwable $rollbackError) {
                $rollbackErrors[] = 'installed release metadata: ' . $rollbackError->getMessage();
            }

            if ($applicationMutationStarted && is_array($backupSet) && !empty($backupSet['absolute_path'])) {
                try {
                    info('Upgrade failed after application cutover began. Restoring the previous application snapshot...');
                    $manager->restoreApplicationFromBackupSet(
                        (string)$backupSet['absolute_path'],
                        $preservePaths
                    );
                } catch (Throwable $rollbackError) {
                    $rollbackErrors[] = 'application files: ' . $rollbackError->getMessage();
                }
            }

            if ($rollbackErrors === []) {
                try {
                    runSystemHealthcheck($engine, $originalDatabase);
                } catch (Throwable $rollbackError) {
                    $rollbackErrors[] = 'rollback health check: ' . $rollbackError->getMessage();
                }
            }

            if ($rollbackErrors === [] && $maintenanceEnabled) {
                try {
                    $manager->setMaintenanceMode(false);
                    $maintenanceEnabled = false;
                } catch (Throwable $rollbackError) {
                    $rollbackErrors[] = 'maintenance exit: ' . $rollbackError->getMessage();
                }
            }
        }

        $rollbackMessage = $rollbackErrors === []
            ? 'Rollback verified. The original application and database remain active.'
            : 'Rollback incomplete; CAS remains in maintenance mode. ' . implode(' | ', $rollbackErrors);

        $engine->updateRun($run, ['rollback_status' => $rollbackMessage]);
        $engine->completeRun($run, 'failed', $e->getMessage());
        fwrite(STDERR, 'Upgrade failed: ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $rollbackMessage . PHP_EOL);
        if (is_array($backupSet)) {
            fwrite(STDERR, 'Verified backup set: ' . (string)$backupSet['relative_path'] . PHP_EOL);
        }
        exit(1);
    } finally {
        // Do not automatically clear maintenance here. If rollback did not verify,
        // keeping maintenance enabled is the fail-closed behavior.
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
                '.git',
                '.env',
                'config.php',
                '.htaccess',
                '.user.ini',
                '.well-known',
                'assets/uploads',
                'storage',

                // Recovery control plane stays on the current verified enterprise
                // implementation even when restoring an older application backup.
                'admin/upgrade.php',
                'lib/upgrade.php',
                'lib/system_backup.php',
                'lib/path.php',
                'lib/enterprise_runtime.php',
                'lib/system_cli_bootstrap.php',
                'scripts/system_upgrade.php',
                'scripts/system_job.php',
                'scripts/system_healthcheck.php',
            ]
        );
        info('Application and database restored and verified successfully.');
        info('Emergency pre-restore backup retained as ' . (string)($result['emergency_backup_id'] ?? 'unknown') . '.');
        return;
    }

    // Pre-v2 snapshots do not provide the integrity/manifest contract required
    // for isolated candidate restoration. Never replay those database dumps into
    // the active schema. Keep the legacy artifacts for manual forensic recovery,
    // but refuse them through the enterprise Restore command.
    fwrite(
        STDERR,
        'Backup ' . $backupId
        . ' predates verified format-v2 recovery and cannot be restored safely through CAS.'
        . PHP_EOL
    );
    exit(1);
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
  php scripts/{$script} --action=downgrade [--backup-id=<id>]
  php scripts/{$script} --action=list-backups

Options:
  --repo             Repository slug or URL used to download releases. Defaults to the configured upgrade source.
  --ref              Branch, tag, or commit reference to deploy when --latest-release is not supplied.
  --latest-release   Resolve the newest GitHub release tag automatically.
  --preserve         Comma separated list of paths to keep untouched during deployment.
  --backup-id        Identifier of the backup set to restore. Defaults to the most recent ready backup.
  Verified format-v2 backup sets restore application and database through isolated candidates.
  Pre-v2 legacy snapshots are retained for forensic/manual recovery but are refused by this command.

USAGE;
}

function info(string $message): void
{
    fwrite(STDOUT, '[INFO] ' . $message . PHP_EOL);
}

function runSystemHealthcheck(UpgradeEngine $engine, string $expectedDatabase, bool $full = false): void
{
    $script = $engine->absoluteFromRoot('scripts/system_healthcheck.php');
    if (!is_file($script)) {
        throw new RuntimeException('System health check script is missing after deployment.');
    }

    $process = proc_open(
        array_values(array_filter([
            PHP_BINARY ?: 'php',
            $script,
            '--expect-db=' . $expectedDatabase,
            $full ? '--full' : null,
        ])),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $engine->absoluteFromRoot('')
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start CAS post-cutover health check.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || strpos($stdout, 'CAS_HEALTHCHECK_OK') === false) {
        throw new RuntimeException(
            'CAS post-cutover health check failed'
            . ($stderr !== '' ? ': ' . trim($stderr) : ($stdout !== '' ? ': ' . trim($stdout) : '.'))
        );
    }
}

function assertCandidatePreservesBackupData(PDO $pdo, array $backupObjects): void
{
    foreach ($backupObjects as $object) {
        if (strtoupper((string)($object['type'] ?? '')) !== 'BASE TABLE') {
            continue;
        }

        $name = (string)($object['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $exists = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND table_type = "BASE TABLE"'
        );
        $exists->execute([$name]);
        if ((int)$exists->fetchColumn() !== 1) {
            throw new RuntimeException(
                'Candidate migration removed pre-upgrade table ' . $name . '. Refusing cutover.'
            );
        }

        $quoted = '`' . str_replace('`', '``', $name) . '`';
        $after = (int)$pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        $before = (int)($object['row_count'] ?? 0);
        if ($after < $before) {
            throw new RuntimeException(
                'Candidate migration reduced row count for ' . $name
                . ' from ' . $before . ' to ' . $after . '. Refusing cutover.'
            );
        }
    }
}

function applyDatabaseMigrations(UpgradeEngine $engine, PDO $pdo, ?string $releaseRoot = null): int
{
    $totalStatements = 0;
    $root = $releaseRoot !== null ? rtrim($releaseRoot, DIRECTORY_SEPARATOR) : rtrim(base_path(''), DIRECTORY_SEPARATOR);
    $candidates = [
        $root . DIRECTORY_SEPARATOR . 'upgrade_to_v3.sql',
        $root . DIRECTORY_SEPARATOR . 'migration.sql',
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
