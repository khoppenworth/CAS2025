#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-time bootstrap for servers still running the 0.5.95 recovery code.
 *
 * It does NOT perform the application upgrade. It only:
 * 1. installs the early maintenance/active-database hooks into the preserved config.php;
 * 2. installs the parser-fixed 0.5.97 system_backup.php so the legacy 0.5.95
 *    pre-upgrade safety backup can complete;
 * 3. preserves original file ownership/mode by writing the existing inode in place;
 * 4. stores exact pre-bootstrap copies under storage/upgrades/bootstrap/.
 *
 * After this succeeds, run the normal upgrade from the CLI, not through the old
 * synchronous browser request.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$configPath = $root . '/config.php';
$backupLibPath = $root . '/lib/system_backup.php';
$storage = $root . '/storage/upgrades/bootstrap/' . gmdate('Ymd_His');

$fail = static function (string $message): void {
    fwrite(STDERR, '[ERROR] ' . $message . PHP_EOL);
    exit(1);
};

if (!is_file($configPath) || !is_file($backupLibPath)) {
    $fail('Run this script from an intact CAS application tree.');
}

if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
    $fail('Unable to create bootstrap backup directory.');
}

$phpLint = static function (string $contents, string $label) use ($root, $fail): void {
    $tmp = tempnam($root . '/storage/upgrades', 'bootstrap-lint-');
    if ($tmp === false) {
        $fail('Unable to create temporary lint file for ' . $label);
    }
    try {
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            $fail('Unable to write temporary lint file for ' . $label);
        }
        $cmd = [PHP_BINARY ?: 'php', '-l', $tmp];
        $descriptors = [
            0 => ['file', '/dev/null', 'rb'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $root);
        if (!is_resource($process)) {
            $fail('Unable to run PHP lint for ' . $label);
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $fail('PHP lint failed for ' . $label . ': ' . trim($stdout . ' ' . $stderr));
        }
    } finally {
        @unlink($tmp);
    }
};

$snapshot = static function (string $path): array {
    return [
        'uid' => fileowner($path),
        'gid' => filegroup($path),
        'mode' => fileperms($path) & 0777,
        'sha256' => hash_file('sha256', $path),
    ];
};

$writeInPlace = static function (string $path, string $contents, array $before, string $label) use ($fail): void {
    $handle = fopen($path, 'c+b');
    if ($handle === false) {
        $fail('Unable to open ' . $label . ' for safe in-place update.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            $fail('Unable to lock ' . $label . '.');
        }
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
            $fail('Unable to prepare ' . $label . ' for update.');
        }
        $written = fwrite($handle, $contents);
        if ($written === false || $written !== strlen($contents)) {
            $fail('Incomplete write while updating ' . $label . '.');
        }
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    clearstatcache(true, $path);
    $afterUid = fileowner($path);
    $afterGid = filegroup($path);
    $afterMode = fileperms($path) & 0777;
    if ($afterUid !== $before['uid'] || $afterGid !== $before['gid'] || $afterMode !== $before['mode']) {
        $fail($label . ' ownership or mode changed unexpectedly. Restore from ' . dirname($path) . ' backup before proceeding.');
    }
};

$configBefore = $snapshot($configPath);
$libBefore = $snapshot($backupLibPath);

if (!copy($configPath, $storage . '/config.php.pre-bootstrap')
    || !copy($backupLibPath, $storage . '/system_backup.php.pre-bootstrap')) {
    $fail('Unable to save pre-bootstrap file copies.');
}

$config = (string)file_get_contents($configPath);

$maintenanceMarker = 'CAS_ENTERPRISE_MAINTENANCE_GATE';
if (strpos($config, $maintenanceMarker) === false) {
    $anchor = "declare(strict_types=1);\n";
    if (strpos($config, $anchor) === false) {
        $fail('config.php does not contain the expected strict-types anchor; no changes were made.');
    }
    $maintenanceSnippet = <<<'PHP'

/* CAS_ENTERPRISE_MAINTENANCE_GATE */
if (PHP_SAPI !== 'cli') {
    $maintenancePath = __DIR__ . '/storage/upgrades/maintenance.json';
    if (is_file($maintenancePath)) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Cache-Control: no-store, max-age=0');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Maintenance</title></head><body>';
        echo '<main style="max-width:720px;margin:10vh auto;font-family:system-ui,sans-serif;padding:2rem">';
        echo '<h1>System maintenance in progress</h1>';
        echo '<p>The competency assessment system is completing a verified upgrade or recovery operation. Please retry shortly.</p>';
        echo '</main></body></html>';
        exit;
    }
}

PHP;
    $config = str_replace($anchor, $anchor . $maintenanceSnippet, $config, $count);
    if ($count !== 1) {
        $fail('Unable to add maintenance gate deterministically.');
    }
}

$pointerMarker = 'CAS_ENTERPRISE_ACTIVE_DATABASE_POINTER';
if (strpos($config, $pointerMarker) === false) {
    $anchor = "    \$dbName = getenv('DB_NAME') ?: 'epss_v300';\n";
    if (strpos($config, $anchor) === false) {
        $fail('config.php does not contain the expected DB_NAME anchor; no live files were updated.');
    }
    $pointerSnippet = <<<'PHP'
    /* CAS_ENTERPRISE_ACTIVE_DATABASE_POINTER */
    $activeDatabasePath = __DIR__ . '/storage/upgrades/active_database.json';
    if (is_readable($activeDatabasePath)) {
        $activeDatabase = json_decode((string)file_get_contents($activeDatabasePath), true);
        $candidateName = is_array($activeDatabase) ? trim((string)($activeDatabase['database'] ?? '')) : '';
        if ($candidateName !== '' && preg_match('/^[A-Za-z0-9_]+$/', $candidateName) === 1) {
            $dbName = $candidateName;
        } elseif ($candidateName !== '') {
            error_log('Ignoring unsafe active database pointer value.');
        }
    }

PHP;
    $config = str_replace($anchor, $anchor . $pointerSnippet, $config, $count);
    if ($count !== 1) {
        $fail('Unable to add active database pointer deterministically.');
    }
}

$phpLint($config, 'patched config.php');

// Use the already-tested parser-only 0.5.97 file as the compatibility bridge.
// Git blob SHA is verified before any live write.
$url = 'https://raw.githubusercontent.com/khoppenworth/CAS2025/0.5.97/lib/system_backup.php';
$context = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1]]);
$parserFixed = @file_get_contents($url, false, $context);
if (!is_string($parserFixed) || $parserFixed === '') {
    $fail('Unable to download the parser-fixed compatibility file from GitHub.');
}
$gitBlobSha = sha1('blob ' . strlen($parserFixed) . "\0" . $parserFixed);
$expectedBlobSha = '705af28f16565dfc97acf0142cefb0daf69419fa';
if (!hash_equals($expectedBlobSha, $gitBlobSha)) {
    $fail('Downloaded compatibility file failed Git blob verification. Expected ' . $expectedBlobSha . ', got ' . $gitBlobSha);
}
$phpLint($parserFixed, 'parser-fixed system_backup.php');

$writeInPlace($configPath, $config, $configBefore, 'config.php');
$writeInPlace($backupLibPath, $parserFixed, $libBefore, 'lib/system_backup.php');

fwrite(STDOUT, "Bootstrap completed successfully.\n");
fwrite(STDOUT, "Pre-bootstrap copies: " . $storage . "\n");
fwrite(STDOUT, "Ownership and file modes were preserved and both PHP files passed syntax validation.\n");
fwrite(STDOUT, "Next: run the CAS upgrade from the CLI so the old browser request is not held open.\n");
