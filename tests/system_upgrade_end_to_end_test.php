<?php
declare(strict_types=1);

/**
 * Full transition regression:
 * real 0.5.95 tree -> customized config -> bridge -> real upgrade command ->
 * successful cutover -> backup -> restore, plus a forced post-cutover failure
 * that must roll back exactly.
 */

$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

$dsn = getenv('UPGRADE_E2E_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = getenv('UPGRADE_E2E_DB_USER') ?: 'root';
$pass = getenv('UPGRADE_E2E_DB_PASS') ?: 'root';
$suffix = preg_replace('/[^A-Za-z0-9_]/', '_', (string)(getenv('UPGRADE_E2E_SUFFIX') ?: '1'));

preg_match('/host=([^;]+)/', $dsn, $hostMatch);
preg_match('/port=([^;]+)/', $dsn, $portMatch);
$host = $hostMatch[1] ?? '127.0.0.1';
$port = $portMatch[1] ?? '3306';

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$tempRoot = sys_get_temp_dir() . '/cas-upgrade-e2e-' . $suffix . '-' . bin2hex(random_bytes(5));
mkdir($tempRoot, 0750, true);

$run = static function (array $command, ?string $cwd = null, array $env = []): array {
    $process = proc_open(
        $command,
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
        $pipes,
        $cwd,
        array_merge($_ENV, $env)
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to start: ' . implode(' ', $command));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
};

$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $remove($path . '/' . $name);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};

$sha256 = static function (string $path): string {
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) throw new RuntimeException('Unable to hash ' . $path);
    return $hash;
};

$extractGitTree = static function (string $ref, string $destination) use ($repoRoot, $run): void {
    mkdir($destination, 0750, true);
    $cmd = 'git -C ' . escapeshellarg($repoRoot)
        . ' archive ' . escapeshellarg($ref)
        . ' | tar -x -C ' . escapeshellarg($destination);
    $result = $run(['/bin/bash', '-lc', $cmd], $repoRoot);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('Unable to extract ' . $ref . ': ' . $result['stderr']);
    }
};

$customizeConfig = static function (string $fixture): string {
    $path = $fixture . '/config.php';
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new RuntimeException('Unable to read fixture config.php.');
    $marker = "\n// E2E DEPLOYMENT LOCAL CONFIG MARKER - EXPECT ROLLBACK TO RESTORE THIS EXACTLY\n";
    $closing = strrpos($contents, '?>');
    $contents = $closing === false
        ? $contents . $marker
        : substr($contents, 0, $closing) . $marker . substr($contents, $closing);
    file_put_contents($path, $contents);
    chmod($path, 0640);
    return hash_file('sha256', $path);
};

$writeEnv = static function (string $fixture, string $dbName) use ($host, $port, $user, $pass): void {
    file_put_contents(
        $fixture . '/.env',
        "DB_HOST={$host}\n"
        . "DB_PORT={$port}\n"
        . "DB_NAME={$dbName}\n"
        . "DB_USER={$user}\n"
        . "DB_PASS={$pass}\n"
        . "DB_ADMIN_USER={$user}\n"
        . "DB_ADMIN_PASS={$pass}\n"
        . "BASE_URL=/\n"
        . "APP_DEBUG=0\n"
    );
};

$prepareFixture = static function (string $name, string $dbName) use (
    $server, $extractGitTree, $customizeConfig, $writeEnv, $run, $repoRoot, $tempRoot, $assert
): array {
    $quoted = chr(96) . $dbName . chr(96);
    $server->exec('DROP DATABASE IF EXISTS ' . $quoted);
    $server->exec('CREATE DATABASE ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $fixture = $tempRoot . '/' . $name;
    $extractGitTree('0.5.95', $fixture);
    mkdir($fixture . '/storage/upgrades', 0750, true);
    file_put_contents(
        $fixture . '/storage/upgrades/installed.json',
        json_encode([
            'tag' => '0.5.95',
            'name' => 'Release UAT 0.5.95',
            'repo' => 'khoppenworth/CAS2025',
            'installed_at' => '2026-11-01T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    $writeEnv($fixture, $dbName);
    $configHash = $customizeConfig($fixture);

    // One normal 0.5.95 application bootstrap initializes the fixture schema.
    $bootstrap = $run([
        PHP_BINARY,
        '-r',
        'chdir(' . var_export($fixture, true) . '); require ' . var_export($fixture . '/config.php', true)
            . '; echo isset($pdo) && $pdo instanceof PDO ? "APP_BOOT_OK\\n" : "NO_PDO\\n";',
    ], $fixture);
    $assert($bootstrap['exit'] === 0 && str_contains($bootstrap['stdout'], 'APP_BOOT_OK'),
        '0.5.95 fixture bootstrap failed: ' . $bootstrap['stderr'] . $bootstrap['stdout']);

    $server->exec('USE ' . $quoted);
    $usersExists = (int)$server->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
    )->fetchColumn();
    $assert($usersExists === 1, 'Fixture bootstrap did not create users table.');

    $server->exec('CREATE TABLE IF NOT EXISTS e2e_upgrade_sentinel (id INT PRIMARY KEY, marker VARCHAR(100) NOT NULL)');
    $server->exec("DELETE FROM e2e_upgrade_sentinel");
    $server->exec("INSERT INTO e2e_upgrade_sentinel (id, marker) VALUES (1, 'original-live-db')");

    $bridge = $repoRoot . '/scripts/bootstrap_enterprise_transition.php';
    $check = $run([
        PHP_BINARY, $bridge,
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--check',
    ], $fixture);
    $assert($check['exit'] === 0 && str_contains($check['stdout'], 'CHECK PASSED'),
        'Bridge preflight failed in E2E fixture: ' . $check['stderr'] . $check['stdout']);
    $assert(hash_file('sha256', $fixture . '/config.php') === $configHash,
        'Bridge preflight changed customized config.php.');

    $apply = $run([
        PHP_BINARY, $bridge,
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--apply',
    ], $fixture);
    $assert($apply['exit'] === 0 && str_contains($apply['stdout'], 'BRIDGE INSTALLATION PASSED'),
        'Bridge apply failed in E2E fixture: ' . $apply['stderr'] . $apply['stdout']);
    $assert(hash_file('sha256', $fixture . '/config.php') === $configHash,
        'Bridge apply changed customized config.php.');

    return ['root' => $fixture, 'db' => $dbName, 'bridge_config_hash' => $configHash];
};

$latestManualBackup = static function (string $fixture): array {
    $files = glob($fixture . '/storage/upgrades/manual/*.zip') ?: [];
    if ($files === []) throw new RuntimeException('No manual backup was created.');
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $path = $files[0];
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Manual backup ZIP is invalid.');
    try {
        $raw = $zip->getFromName('manifest.json');
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
    } finally {
        $zip->close();
    }
    if (!is_array($manifest) || trim((string)($manifest['id'] ?? '')) === '') {
        throw new RuntimeException('Manual backup manifest is invalid.');
    }
    return ['path' => $path, 'manifest' => $manifest];
};

$dropTestDatabases = static function () use ($server): void {
    $names = $server->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names ?: [] as $name) {
        $name = (string)$name;
        if (preg_match('/^(cas_upgrade_|cas_restore_|cas_verify_|cas_bridge_preflight_|cas_e2e_)/', $name) === 1) {
            $server->exec('DROP DATABASE IF EXISTS ' . chr(96) . str_replace(chr(96), chr(96) . chr(96), $name) . chr(96));
        }
    }
};

try {
    // Release tree used by the real upgrade command.
    $releaseSuccess = $tempRoot . '/release-success';
    $extractGitTree('HEAD', $releaseSuccess);
    $releaseConfigHash = $sha256($releaseSuccess . '/config.php');

    $releaseFailure = $tempRoot . '/release-failure';
    $extractGitTree('HEAD', $releaseFailure);
    file_put_contents(
        $releaseFailure . '/scripts/system_healthcheck.php',
        "#!/usr/bin/env php\n<?php\ndeclare(strict_types=1);\nfwrite(STDERR, \"INJECTED_POST_CUTOVER_FAILURE\\n\");\nexit(99);\n"
    );

    // ---------------------------------------------------------------------
    // Successful real upgrade, then real backup + restore.
    // ---------------------------------------------------------------------
    $successDb = 'cas_e2e_success_' . $suffix;
    $success = $prepareFixture('success-fixture', $successDb);
    $fixture = $success['root'];
    file_put_contents($fixture . '/obsolete_before_upgrade.php', "<?php echo 'obsolete';\n");
    $envHash = $sha256($fixture . '/.env');

    $upgrade = $run([
        PHP_BINARY,
        $fixture . '/scripts/system_upgrade.php',
        '--action=upgrade',
        '--repo=' . $releaseSuccess,
        '--ref=0.5.102-e2e-success',
    ], $fixture);
    $assert($upgrade['exit'] === 0,
        'Real successful upgrade command failed: ' . $upgrade['stderr'] . $upgrade['stdout']);

    $assert($sha256($fixture . '/config.php') === $releaseConfigHash,
        'Successful upgrade did not install the tracked release config.php.');
    $assert($sha256($fixture . '/.env') === $envHash, 'Successful upgrade changed .env.');
    $assert(!file_exists($fixture . '/obsolete_before_upgrade.php'),
        'Exact deployment did not remove obsolete application file.');

    $pointerPath = $fixture . '/storage/upgrades/active_database.json';
    $pointer = json_decode((string)file_get_contents($pointerPath), true);
    $activeDb = is_array($pointer) ? (string)($pointer['db_name'] ?? '') : '';
    $assert($activeDb !== '' && $activeDb !== $successDb, 'Successful upgrade did not cut over to a candidate DB.');

    $server->exec('USE ' . chr(96) . $successDb . chr(96));
    $originalMarker = (string)$server->query('SELECT marker FROM e2e_upgrade_sentinel WHERE id=1')->fetchColumn();
    $assert($originalMarker === 'original-live-db', 'Original live DB was modified by successful upgrade.');

    $installed = json_decode((string)file_get_contents($fixture . '/storage/upgrades/installed.json'), true);
    $assert(($installed['tag'] ?? '') === '0.5.102-e2e-success', 'Installed release metadata not updated.');

    // Background-safe backup command on the active candidate DB.
    $backupRun = $run([PHP_BINARY, $fixture . '/scripts/system_upgrade.php', '--action=backup'], $fixture);
    $assert($backupRun['exit'] === 0, 'Post-upgrade backup failed: ' . $backupRun['stderr'] . $backupRun['stdout']);
    $backup = $latestManualBackup($fixture);
    $backupId = (string)$backup['manifest']['id'];
    $assert((int)($backup['manifest']['format_version'] ?? 0) === 3, 'New backup did not use format v3.');

    $indexHashBeforeMutation = $sha256($fixture . '/index.php');
    file_put_contents($fixture . '/index.php', "\n<?php // mutation after backup ?>\n", FILE_APPEND);
    $server->exec('USE ' . chr(96) . $activeDb . chr(96));
    $server->exec("UPDATE e2e_upgrade_sentinel SET marker='mutated-after-backup' WHERE id=1");

    $restore = $run([
        PHP_BINARY,
        $fixture . '/scripts/system_upgrade.php',
        '--action=restore',
        '--backup-id=' . $backupId,
    ], $fixture);
    $assert($restore['exit'] === 0, 'Real restore command failed: ' . $restore['stderr'] . $restore['stdout']);
    $assert($sha256($fixture . '/index.php') === $indexHashBeforeMutation,
        'Restore did not recover application file exactly.');
    $assert($sha256($fixture . '/config.php') === $releaseConfigHash,
        'Restore changed tracked release config unexpectedly.');
    $assert($sha256($fixture . '/.env') === $envHash, 'Restore changed .env.');

    $restoredPointer = json_decode((string)file_get_contents($pointerPath), true);
    $restoredDb = is_array($restoredPointer) ? (string)($restoredPointer['db_name'] ?? '') : '';
    $assert($restoredDb !== '' && $restoredDb !== $activeDb, 'Restore did not cut over to a new verified DB.');
    $server->exec('USE ' . chr(96) . $restoredDb . chr(96));
    $restoredMarker = (string)$server->query('SELECT marker FROM e2e_upgrade_sentinel WHERE id=1')->fetchColumn();
    $assert($restoredMarker === 'original-live-db', 'Restore did not recover backed-up DB data.');
    $server->exec('USE ' . chr(96) . $activeDb . chr(96));
    $oldCandidateMarker = (string)$server->query('SELECT marker FROM e2e_upgrade_sentinel WHERE id=1')->fetchColumn();
    $assert($oldCandidateMarker === 'mutated-after-backup',
        'Restore overwrote the previously active DB instead of preserving it.');
    $assert(!is_file($fixture . '/storage/upgrades/maintenance.flag'), 'Maintenance flag remained after successful restore.');

    // ---------------------------------------------------------------------
    // Forced failure after pointer/metadata cutover must fully roll back.
    // ---------------------------------------------------------------------
    $failureDb = 'cas_e2e_failure_' . $suffix;
    $failure = $prepareFixture('failure-fixture', $failureDb);
    $fixtureFail = $failure['root'];
    $configBeforeFailure = $sha256($fixtureFail . '/config.php');
    $envBeforeFailure = $sha256($fixtureFail . '/.env');
    $installedBeforeFailure = $sha256($fixtureFail . '/storage/upgrades/installed.json');
    $systemUpgradeBeforeFailure = $sha256($fixtureFail . '/scripts/system_upgrade.php');
    file_put_contents($fixtureFail . '/legacy_only_before_failure.txt', "must return after rollback\n");
    $legacyHash = $sha256($fixtureFail . '/legacy_only_before_failure.txt');

    $failedUpgrade = $run([
        PHP_BINARY,
        $fixtureFail . '/scripts/system_upgrade.php',
        '--action=upgrade',
        '--repo=' . $releaseFailure,
        '--ref=0.5.102-e2e-failure',
    ], $fixtureFail);
    $assert($failedUpgrade['exit'] !== 0, 'Injected post-cutover failure unexpectedly succeeded.');
    $assert(str_contains($failedUpgrade['stderr'] . $failedUpgrade['stdout'], 'Rollback verified'),
        'Failed upgrade did not report verified rollback.');

    $assert($sha256($fixtureFail . '/config.php') === $configBeforeFailure,
        'Failed upgrade did not restore customized config.php byte-identically.');
    $assert($sha256($fixtureFail . '/.env') === $envBeforeFailure, 'Failed upgrade changed .env.');
    $assert($sha256($fixtureFail . '/storage/upgrades/installed.json') === $installedBeforeFailure,
        'Failed upgrade did not restore installed metadata exactly.');
    $assert($sha256($fixtureFail . '/scripts/system_upgrade.php') === $systemUpgradeBeforeFailure,
        'Failed upgrade did not restore bridged updater exactly.');
    $assert($sha256($fixtureFail . '/legacy_only_before_failure.txt') === $legacyHash,
        'Failed exact deployment did not restore removed legacy application file.');
    $assert(!is_file($fixtureFail . '/storage/upgrades/active_database.json'),
        'Failed upgrade did not restore the original absent DB-pointer state.');
    $assert(!is_file($fixtureFail . '/storage/upgrades/maintenance.flag'),
        'Maintenance flag remained after verified rollback.');

    $server->exec('USE ' . chr(96) . $failureDb . chr(96));
    $failureMarker = (string)$server->query('SELECT marker FROM e2e_upgrade_sentinel WHERE id=1')->fetchColumn();
    $assert($failureMarker === 'original-live-db', 'Failed upgrade modified the original live DB.');

    fwrite(
        STDOUT,
        "Full 0.5.95 bridge -> upgrade -> backup -> restore -> forced rollback E2E passed against "
        . $server->getAttribute(PDO::ATTR_SERVER_VERSION)
        . ".\n"
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Enterprise transition E2E failed: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    try { $dropTestDatabases(); } catch (Throwable $cleanupError) {
        fwrite(STDERR, "DB cleanup warning: " . $cleanupError->getMessage() . "\n");
    }
    $remove($tempRoot);
}
