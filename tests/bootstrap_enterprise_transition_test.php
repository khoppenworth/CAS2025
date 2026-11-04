<?php
declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

$dsn = getenv('BACKUP_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = getenv('BACKUP_DB_USER') ?: 'root';
$pass = getenv('BACKUP_DB_PASS') ?: 'root';

preg_match('/host=([^;]+)/', $dsn, $hostMatch);
preg_match('/port=([^;]+)/', $dsn, $portMatch);
$host = $hostMatch[1] ?? '127.0.0.1';
$port = $portMatch[1] ?? '3306';

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$baseDb = 'cas_bridge_' . bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE `' . $baseDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tempRoot = sys_get_temp_dir() . '/cas-enterprise-bridge-' . bin2hex(random_bytes(6));
mkdir($tempRoot, 0750, true);

$run = static function (array $command, array $env = []) use ($repoRoot): array {
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $repoRoot, array_merge($_ENV, $env));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch test command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
};

$blob = static function (string $path): string {
    $content = file_get_contents($path);
    if (!is_string($content)) throw new RuntimeException('Unable to read ' . $path);
    return sha1('blob ' . strlen($content) . "\0" . $content);
};

$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $remove($path . DIRECTORY_SEPARATOR . $name);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};

$makeFixture = static function (string $name) use ($repoRoot, $tempRoot, $host, $port, $user, $pass, $baseDb, $run): string {
    $fixture = $tempRoot . '/' . $name;
    mkdir($fixture, 0750, true);

    $cmd = 'git -C ' . escapeshellarg($repoRoot)
        . ' archive 0.5.95 | tar -x -C ' . escapeshellarg($fixture);
    $result = $run(['/bin/bash', '-lc', $cmd]);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('Unable to extract 0.5.95 fixture: ' . $result['stderr']);
    }

    mkdir($fixture . '/storage/upgrades', 0750, true);
    file_put_contents(
        $fixture . '/storage/upgrades/installed.json',
        json_encode([
            'tag' => '0.5.95',
            'name' => 'Release UAT 0.5.95',
            'installed_at' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT) . "\n"
    );

    file_put_contents(
        $fixture . '/.env',
        "DB_HOST={$host}\n"
        . "DB_PORT={$port}\n"
        . "DB_NAME={$baseDb}\n"
        . "DB_USER={$user}\n"
        . "DB_PASS={$pass}\n"
        . "DB_ADMIN_USER={$user}\n"
        . "DB_ADMIN_PASS={$pass}\n"
        . "BASE_URL=/\n"
    );

    file_put_contents(
        $fixture . '/config.php',
        "\n// SITE-LOCAL CUSTOMIZATION: must survive every bridge/upgrade operation byte-for-byte.\n",
        FILE_APPEND
    );
    chmod($fixture . '/config.php', 0640);
    chmod($fixture . '/scripts/system_upgrade.php', 0750);

    return $fixture;
};

try {
    $script = $repoRoot . '/scripts/bootstrap_enterprise_transition.php';

    // 1) Check-only must prove prerequisites and make no updater changes.
    $fixture = $makeFixture('success');
    $beforeConfig = $blob($fixture . '/config.php');
    $beforeUpgrade = $blob($fixture . '/admin/upgrade.php');
    $beforeInstalled = file_get_contents($fixture . '/storage/upgrades/installed.json');
    $configMode = fileperms($fixture . '/config.php') & 0777;
    $upgradeMode = fileperms($fixture . '/scripts/system_upgrade.php') & 0777;

    $check = $run([
        PHP_BINARY, $script,
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--check',
    ]);
    $assert($check['exit'] === 0, 'Bridge check failed: ' . $check['stderr']);
    $assert(str_contains($check['stdout'], 'CHECK PASSED'), 'Bridge check did not report success.');
    $assert($blob($fixture . '/config.php') === $beforeConfig, 'Check-only mode changed config.php.');
    $assert($blob($fixture . '/admin/upgrade.php') === $beforeUpgrade, 'Check-only mode changed admin/upgrade.php.');
    $assert(!file_exists($fixture . '/scripts/system_job.php'), 'Check-only mode created system_job.php.');

    // 2) Apply must install exact enterprise updater files and preserve metadata.
    $apply = $run([
        PHP_BINARY, $script,
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--apply',
    ]);
    $assert($apply['exit'] === 0, 'Bridge apply failed: ' . $apply['stderr']);
    $assert(str_contains($apply['stdout'], 'BRIDGE INSTALLATION PASSED'), 'Bridge apply did not report success.');

    $paths = [
        'admin/upgrade.php',
        'lib/system_backup.php',
        'lib/upgrade.php',
        'lib/path.php',
        'lib/enterprise_runtime.php',
        'scripts/system_upgrade.php',
        'scripts/system_job.php',
    ];
    foreach ($paths as $path) {
        $assert(
            $blob($fixture . '/' . $path) === $blob($repoRoot . '/' . $path),
            'Installed bridge target differs from repository source: ' . $path
        );
    }
    $assert($blob($fixture . '/config.php') === $beforeConfig, 'Bridge apply changed site-owned config.php.');
    $assert((fileperms($fixture . '/config.php') & 0777) === $configMode, 'config.php mode changed unexpectedly.');
    $assert((fileperms($fixture . '/scripts/system_upgrade.php') & 0777) === $upgradeMode, 'system_upgrade.php mode was not preserved.');
    $assert((fileperms($fixture . '/scripts/system_job.php') & 0777) === $upgradeMode, 'New system_job.php did not inherit script mode.');
    $assert(
        file_get_contents($fixture . '/storage/upgrades/installed.json') === $beforeInstalled,
        'Bridge changed installed-release metadata.'
    );

    // 3) Re-running apply must be idempotent.
    $second = $run([
        PHP_BINARY, $script,
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--apply',
    ]);
    $assert($second['exit'] === 0, 'Idempotent bridge rerun failed: ' . $second['stderr']);

    // 4) Site-local config.php is accepted, but unknown updater-code changes are refused.
    $modified = $makeFixture('modified');
    $modifiedConfigBefore = $blob($modified . '/config.php');
    file_put_contents($modified . '/lib/upgrade.php', "\n// unknown local updater modification\n", FILE_APPEND);
    $modifiedAdminBefore = $blob($modified . '/admin/upgrade.php');
    $refusal = $run([
        PHP_BINARY, $script,
        '--root=' . $modified,
        '--source-root=' . $repoRoot,
        '--apply',
    ]);
    $assert($refusal['exit'] !== 0, 'Bridge unexpectedly accepted an unknown updater-code modification.');
    $assert(
        $blob($modified . '/admin/upgrade.php') === $modifiedAdminBefore,
        'Bridge changed files before refusing unknown updater code.'
    );
    $assert($blob($modified . '/config.php') === $modifiedConfigBefore, 'Refused bridge changed config.php.');
    $assert(!file_exists($modified . '/scripts/system_job.php'), 'Refused bridge left new system_job.php behind.');
    $assert(!file_exists($modified . '/lib/enterprise_runtime.php'), 'Refused bridge left enterprise_runtime.php behind.');

    // 5) Every installation position must roll back exactly on injected failure.
    for ($failAfter = 1; $failAfter <= 7; $failAfter++) {
        $rollback = $makeFixture('rollback-' . $failAfter);
        $rollbackConfigBefore = $blob($rollback . '/config.php');
        $original = [];
        foreach (['admin/upgrade.php','lib/system_backup.php','lib/upgrade.php','lib/path.php','scripts/system_upgrade.php'] as $path) {
            $original[$path] = [
                'blob' => $blob($rollback . '/' . $path),
                'mode' => fileperms($rollback . '/' . $path) & 0777,
            ];
        }

        $injected = $run([
            PHP_BINARY, $script,
            '--root=' . $rollback,
            '--source-root=' . $repoRoot,
            '--apply',
            '--test-fail-after=' . $failAfter,
        ], ['CAS_BOOTSTRAP_TEST_MODE' => '1']);

        $assert($injected['exit'] !== 0, 'Injected bridge failure at position ' . $failAfter . ' unexpectedly succeeded.');
        foreach ($original as $path => $state) {
            $assert($blob($rollback . '/' . $path) === $state['blob'], 'Rollback hash mismatch for ' . $path . ' at position ' . $failAfter);
            $assert((fileperms($rollback . '/' . $path) & 0777) === $state['mode'], 'Rollback mode mismatch for ' . $path . ' at position ' . $failAfter);
        }
        $assert(!file_exists($rollback . '/scripts/system_job.php'), 'Rollback left system_job.php at position ' . $failAfter);
        $assert(!file_exists($rollback . '/lib/enterprise_runtime.php'), 'Rollback left enterprise_runtime.php at position ' . $failAfter);
        $assert($blob($rollback . '/config.php') === $rollbackConfigBefore, 'Injected rollback changed config.php at position ' . $failAfter);
    }

    echo "Enterprise bootstrap transition test passed.\n";
} finally {
    try {
        $server->exec('DROP DATABASE IF EXISTS `' . $baseDb . '`');
    } catch (Throwable $e) {
    }
    $remove($tempRoot);
}
