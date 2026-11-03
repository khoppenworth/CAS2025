#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-time enterprise recovery bootstrap for pre-v3 CAS installations.
 *
 * This script is designed to be run FROM OUTSIDE the live application tree.
 * It installs only the already-validated recovery subsystem under maintenance,
 * preserves existing file ownership/modes, then invokes the NEW staged updater
 * from the live application root. It never invokes the old destructive updater.
 *
 * Production example (exact values are supplied in the release notes):
 *   php bootstrap_enterprise_recovery.php \
 *     --root=/var/www/cas \
 *     --target-ref=0.5.99 \
 *     --target-commit=<40-char verified release commit>
 *
 * CI uses --source-dir and --prepare-only against an isolated fixture.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'root::',
    'source-dir::',
    'target-ref::',
    'target-commit::',
    'prepare-only',
]);

$root = rtrim((string)($options['root'] ?? getcwd()), DIRECTORY_SEPARATOR);
$sourceDirOption = trim((string)($options['source-dir'] ?? ''));
$targetRef = trim((string)($options['target-ref'] ?? ''));
$targetCommit = strtolower(trim((string)($options['target-commit'] ?? '')));
$prepareOnly = isset($options['prepare-only']);
$testMode = (string)(getenv('CAS_BOOTSTRAP_TEST') ?: '') === '1';

$fail = static function (string $message, int $code = 1): void {
    fwrite(STDERR, '[ERROR] ' . $message . PHP_EOL);
    exit($code);
};

if ($root === '' || !is_dir($root)) {
    $fail('Application root does not exist: ' . $root);
}

foreach (['config.php', 'lib/upgrade.php', 'lib/system_backup.php', 'scripts/system_upgrade.php'] as $required) {
    if (!is_file($root . DIRECTORY_SEPARATOR . $required)) {
        $fail('Application root is missing required pre-upgrade file: ' . $required);
    }
}

if (!$prepareOnly) {
    if ($targetRef === '') {
        $fail('--target-ref is required for a real bootstrap upgrade.');
    }
    if (preg_match('/^[0-9a-f]{40}$/', $targetCommit) !== 1) {
        $fail('--target-commit must be the exact 40-character verified release commit.');
    }
}

if ($sourceDirOption !== '' && !$testMode) {
    $fail('--source-dir is restricted to the isolated CI bootstrap test.');
}

$storageRoot = $root . '/storage/upgrades';
if (!is_dir($storageRoot) && !mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
    $fail('Unable to create upgrade storage directory.');
}

$bootstrapId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
$backupDir = $storageRoot . '/bootstrap/' . $bootstrapId;
if (!mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
    $fail('Unable to create bootstrap recovery directory.');
}

$tempRoot = $storageRoot . '/tmp/bootstrap-source-' . $bootstrapId;
if (!mkdir($tempRoot, 0750, true) && !is_dir($tempRoot)) {
    $fail('Unable to create bootstrap source staging directory.');
}

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
};

$runCommand = static function (array $command, ?string $cwd = null, bool $capture = false): array {
    $descriptors = [
        0 => ['file', '/dev/null', 'rb'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd ?: null);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(
            'Command failed (' . $exit . '): ' . implode(' ', $command)
            . ($stderr !== '' ? ' :: ' . trim($stderr) : '')
        );
    }
    return ['stdout' => $capture ? $stdout : '', 'stderr' => $stderr, 'exit_code' => $exit];
};

$findExecutable = static function (array $names): string {
    foreach ($names as $name) {
        foreach (['/usr/bin', '/usr/local/bin', '/bin'] as $dir) {
            $path = $dir . '/' . $name;
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
    }
    throw new RuntimeException('Required executable is missing: ' . implode(' or ', $names));
};

$lint = static function (string $path) use ($runCommand): void {
    $runCommand([PHP_BINARY ?: 'php', '-l', $path]);
};

$extractMarkedBlock = static function (string $contents, string $begin, string $end): string {
    $start = strpos($contents, $begin);
    $finish = strpos($contents, $end);
    if ($start === false || $finish === false || $finish < $start) {
        throw new RuntimeException('Validated release config is missing bootstrap marker block: ' . $begin);
    }
    $finish += strlen($end);
    return substr($contents, $start, $finish - $start);
};

$snapshot = static function (string $path): array {
    if (!file_exists($path)) {
        return ['exists' => false];
    }
    return [
        'exists' => true,
        'uid' => fileowner($path),
        'gid' => filegroup($path),
        'mode' => fileperms($path) & 0777,
        'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
    ];
};

$writeExistingInPlace = static function (string $path, string $contents, array $before): void {
    $handle = fopen($path, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Unable to open existing file safely: ' . $path);
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock existing file: ' . $path);
        }
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
            throw new RuntimeException('Unable to truncate existing file safely: ' . $path);
        }
        $written = fwrite($handle, $contents);
        if ($written === false || $written !== strlen($contents)) {
            throw new RuntimeException('Incomplete write to existing file: ' . $path);
        }
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    clearstatcache(true, $path);
    if (fileowner($path) !== $before['uid']
        || filegroup($path) !== $before['gid']
        || (fileperms($path) & 0777) !== $before['mode']) {
        throw new RuntimeException('Ownership or mode changed unexpectedly for existing file: ' . $path);
    }
};

$createWithReferenceMetadata = static function (
    string $path,
    string $contents,
    string $reference
): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create target directory: ' . $directory);
    }

    $uid = fileowner($reference);
    $gid = filegroup($reference);
    $mode = fileperms($reference) & 0777;

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to create recovery subsystem file: ' . $path);
    }

    clearstatcache(true, $path);
    if (fileowner($path) !== $uid && !@chown($path, $uid)) {
        throw new RuntimeException('Unable to match file owner from ' . $reference . ' onto ' . $path);
    }
    if (filegroup($path) !== $gid && !@chgrp($path, $gid)) {
        throw new RuntimeException('Unable to match file group from ' . $reference . ' onto ' . $path);
    }
    if (!@chmod($path, $mode)) {
        throw new RuntimeException('Unable to match file mode from ' . $reference . ' onto ' . $path);
    }

    clearstatcache(true, $path);
    if (fileowner($path) !== $uid || filegroup($path) !== $gid || (fileperms($path) & 0777) !== $mode) {
        throw new RuntimeException('Recovery subsystem file metadata verification failed: ' . $path);
    }
};

$restoreBootstrapFiles = static function (array $records) use ($writeExistingInPlace): void {
    foreach (array_reverse($records) as $record) {
        $path = $record['path'];
        $before = $record['before'];
        if (($before['exists'] ?? false) === true) {
            $contents = (string)file_get_contents($record['backup']);
            $writeExistingInPlace($path, $contents, $before);
        } elseif (file_exists($path) && !@unlink($path)) {
            throw new RuntimeException('Unable to remove bootstrap-created file during rollback: ' . $path);
        }
    }
};

$sourceDir = null;
$records = [];
$maintenanceFile = $storageRoot . '/maintenance.json';
$maintenanceCreatedByBootstrap = false;
$preserveRecoverySubsystem = false;

try {
    if ($sourceDirOption !== '') {
        $sourceDir = rtrim($sourceDirOption, DIRECTORY_SEPARATOR);
    } else {
        $curl = $findExecutable(['curl']);
        $tar = $findExecutable(['tar']);
        $archive = $tempRoot . '/release.tar.gz';
        $url = 'https://api.github.com/repos/khoppenworth/CAS2025/tarball/' . $targetCommit;
        $runCommand([
            $curl,
            '-fsSL',
            '--retry', '3',
            '--connect-timeout', '20',
            '-H', 'Accept: application/vnd.github+json',
            $url,
            '-o', $archive,
        ]);
        $extract = $tempRoot . '/release';
        mkdir($extract, 0750, true);
        $runCommand([$tar, '-xzf', $archive, '-C', $extract]);

        $dirs = array_values(array_filter(glob($extract . '/*') ?: [], 'is_dir'));
        if (count($dirs) !== 1) {
            throw new RuntimeException('Unable to identify the downloaded release root.');
        }
        $sourceDir = $dirs[0];

        // Verify that the published tag resolves to the exact commit supplied by the
        // release process before the updater is allowed to install it.
        $tagUrl = 'https://api.github.com/repos/khoppenworth/CAS2025/git/ref/tags/' . rawurlencode($targetRef);
        $tagResult = $runCommand([
            $curl,
            '-fsSL',
            '--retry', '3',
            '--connect-timeout', '20',
            '-H', 'Accept: application/vnd.github+json',
            $tagUrl,
        ], null, true);
        $tagJson = json_decode($tagResult['stdout'], true);
        $tagSha = is_array($tagJson) ? strtolower((string)($tagJson['object']['sha'] ?? '')) : '';
        if (!hash_equals($targetCommit, $tagSha)) {
            throw new RuntimeException(
                'Release tag does not resolve to the expected verified commit. Expected '
                . $targetCommit . ', got ' . ($tagSha !== '' ? $tagSha : 'unknown')
            );
        }
    }

    if ($sourceDir === null || !is_dir($sourceDir)) {
        throw new RuntimeException('Validated source directory is unavailable.');
    }

    $sourceConfigPath = $sourceDir . '/config.php';
    if (!is_file($sourceConfigPath)) {
        throw new RuntimeException('Validated release source is missing config.php.');
    }
    $sourceConfig = (string)file_get_contents($sourceConfigPath);
    $maintenanceBlock = $extractMarkedBlock(
        $sourceConfig,
        '/* CAS_ENTERPRISE_MAINTENANCE_GATE_BEGIN */',
        '/* CAS_ENTERPRISE_MAINTENANCE_GATE_END */'
    );
    $pointerBlock = $extractMarkedBlock(
        $sourceConfig,
        '/* CAS_ENTERPRISE_ACTIVE_DATABASE_POINTER_BEGIN */',
        '/* CAS_ENTERPRISE_ACTIVE_DATABASE_POINTER_END */'
    );

    $recoveryFiles = [
        'lib/enterprise_recovery.php' => 'lib/upgrade.php',
        'lib/upgrade_jobs.php' => 'lib/upgrade.php',
        'lib/system_backup.php' => 'lib/system_backup.php',
        'lib/upgrade.php' => 'lib/upgrade.php',
        'scripts/system_upgrade_worker.php' => 'scripts/system_upgrade.php',
        'scripts/system_upgrade.php' => 'scripts/system_upgrade.php',
    ];

    foreach (array_keys($recoveryFiles) as $relative) {
        $source = $sourceDir . '/' . $relative;
        if (!is_file($source)) {
            throw new RuntimeException('Validated release is missing recovery file: ' . $relative);
        }
        $lint($source);
    }

    $configPath = $root . '/config.php';
    $configContents = (string)file_get_contents($configPath);

    if (strpos($configContents, '/* CAS_ENTERPRISE_MAINTENANCE_GATE_BEGIN */') === false) {
        $anchor = "declare(strict_types=1);\n";
        if (substr_count($configContents, $anchor) !== 1) {
            throw new RuntimeException('Existing config.php does not contain the unique strict-types bootstrap anchor.');
        }
        $configContents = str_replace($anchor, $anchor . "\n" . $maintenanceBlock . "\n", $configContents);
    }

    if (strpos($configContents, '/* CAS_ENTERPRISE_ACTIVE_DATABASE_POINTER_BEGIN */') === false) {
        $anchor = "    \$dbName = getenv('DB_NAME') ?: 'epss_v300';\n";
        if (substr_count($configContents, $anchor) !== 1) {
            throw new RuntimeException('Existing config.php does not contain the unique DB_NAME bootstrap anchor.');
        }
        $configContents = str_replace($anchor, $anchor . "\n" . $pointerBlock . "\n", $configContents);
    }

    $configCandidate = $tempRoot . '/config.php.candidate';
    file_put_contents($configCandidate, $configContents, LOCK_EX);
    $lint($configCandidate);

    $targets = array_merge(['config.php' => null], $recoveryFiles);
    foreach ($targets as $relative => $referenceRelative) {
        $path = $root . '/' . $relative;
        $before = $snapshot($path);
        $backupPath = $backupDir . '/' . str_replace('/', '__', $relative) . '.pre-bootstrap';
        if (($before['exists'] ?? false) === true && !copy($path, $backupPath)) {
            throw new RuntimeException('Unable to save pre-bootstrap copy: ' . $relative);
        }
        $records[] = [
            'relative' => $relative,
            'path' => $path,
            'before' => $before,
            'backup' => $backupPath,
            'reference' => $referenceRelative,
        ];
    }

    file_put_contents(
        $backupDir . '/manifest.json',
        json_encode([
            'created_at' => date(DATE_ATOM),
            'root' => $root,
            'target_ref' => $targetRef,
            'target_commit' => $targetCommit,
            'files' => array_map(static fn(array $r): array => [
                'relative' => $r['relative'],
                'before' => $r['before'],
            ], $records),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );

    // Install the early maintenance gate first without changing ownership/mode.
    $configRecord = $records[0];
    $writeExistingInPlace($configPath, $configContents, $configRecord['before']);
    $lint($configPath);

    file_put_contents(
        $maintenanceFile,
        json_encode([
            'active' => true,
            'reason' => 'Installing verified enterprise recovery subsystem',
            'bootstrap_id' => $bootstrapId,
            'started_at' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
    $maintenanceCreatedByBootstrap = true;

    foreach ($recoveryFiles as $relative => $referenceRelative) {
        $source = $sourceDir . '/' . $relative;
        $contents = (string)file_get_contents($source);
        $path = $root . '/' . $relative;
        $record = null;
        foreach ($records as $candidateRecord) {
            if ($candidateRecord['relative'] === $relative) {
                $record = $candidateRecord;
                break;
            }
        }
        if ($record === null) {
            throw new RuntimeException('Internal bootstrap record missing for ' . $relative);
        }

        if (($record['before']['exists'] ?? false) === true) {
            $writeExistingInPlace($path, $contents, $record['before']);
        } else {
            $reference = $root . '/' . $referenceRelative;
            if (!is_file($reference)) {
                throw new RuntimeException('Reference file for ownership/mode is missing: ' . $referenceRelative);
            }
            $createWithReferenceMetadata($path, $contents, $reference);
        }
        $lint($path);
    }

    if ($maintenanceCreatedByBootstrap) {
        if (!@unlink($maintenanceFile)) {
            throw new RuntimeException('Unable to clear bootstrap maintenance gate.');
        }
        $maintenanceCreatedByBootstrap = false;
    }

    fwrite(STDOUT, "Verified enterprise recovery subsystem installed.\n");
    fwrite(STDOUT, "Pre-bootstrap copies: " . $backupDir . "\n");

    if ($prepareOnly) {
        fwrite(STDOUT, "Prepare-only bootstrap completed successfully.\n");
        exit(0);
    }

    // Execute the NEW updater now present in the live tree. This is intentionally
    // a CLI operation; the legacy synchronous browser path is not used.
    $upgradeCommand = [
        PHP_BINARY ?: 'php',
        $root . '/scripts/system_upgrade.php',
        '--action=upgrade',
        '--repo=khoppenworth/CAS2025',
        '--ref=' . $targetRef,
    ];

    fwrite(STDOUT, "Starting staged enterprise upgrade to " . $targetRef . "...\n");
    $stdoutPath = $tempRoot . '/upgrade.stdout.log';
    $stderrPath = $tempRoot . '/upgrade.stderr.log';
    $descriptors = [
        0 => ['file', '/dev/null', 'rb'],
        1 => ['file', $stdoutPath, 'wb'],
        2 => ['file', $stderrPath, 'wb'],
    ];
    $process = proc_open($upgradeCommand, $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the staged enterprise updater.');
    }

    // The bootstrap itself runs from the CLI, so waiting here cannot consume a
    // web worker or cause reverse-proxy timeouts. File-backed logs also avoid
    // stdout/stderr pipe deadlocks during a long native backup/restore.
    $exitCode = proc_close($process);
    $upgradeStdout = is_file($stdoutPath) ? (string)file_get_contents($stdoutPath) : '';
    $upgradeStderr = is_file($stderrPath) ? (string)file_get_contents($stderrPath) : '';
    if ($upgradeStdout !== '') {
        fwrite(STDOUT, $upgradeStdout);
    }
    if ($upgradeStderr !== '') {
        fwrite(STDERR, $upgradeStderr);
    }

    if ($exitCode !== 0) {
        if (is_file($maintenanceFile)) {
            $preserveRecoverySubsystem = true;
            throw new RuntimeException(
                'The staged updater failed and deliberately left maintenance mode active because rollback could not be proven. '
                . 'The bootstrap will preserve the recovery subsystem and maintenance gate for diagnosis.'
            );
        }

        $restoreBootstrapFiles($records);
        fwrite(STDERR, "Upgrade failed before/after a proven rollback. Exact pre-bootstrap recovery files were restored.\n");
        exit($exitCode > 0 ? $exitCode : 1);
    }

    fwrite(STDOUT, "Enterprise bootstrap and staged upgrade completed successfully.\n");
    fwrite(STDOUT, "Recovery copies remain at: " . $backupDir . "\n");
} catch (Throwable $e) {
    if ($maintenanceCreatedByBootstrap && is_file($maintenanceFile)) {
        @unlink($maintenanceFile);
        $maintenanceCreatedByBootstrap = false;
    }

    if ($records !== [] && !$preserveRecoverySubsystem) {
        try {
            $restoreBootstrapFiles($records);
        } catch (Throwable $rollbackError) {
            fwrite(
                STDERR,
                '[CRITICAL] Bootstrap rollback failed: ' . $rollbackError->getMessage() . PHP_EOL
            );
        }
    }

    fwrite(STDERR, '[ERROR] Enterprise bootstrap failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $removeDirectory($tempRoot);
}
