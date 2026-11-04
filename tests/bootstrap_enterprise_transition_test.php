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

$fixtureDatabases = [];

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

$makeFixture = static function (string $name) use ($repoRoot, $tempRoot, $host, $port, $user, $pass, $server, &$fixtureDatabases, $run): string {
    $fixture = $tempRoot . '/' . $name;
    mkdir($fixture, 0750, true);

    // Every simulated CAS server gets its own database. Reusing one database
    // across fixtures would make the second real 0.5.95 init.sql collide with
    // objects created by the first fixture and would not model separate servers.
    $baseDb = 'cas_bridge_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $name)
        . '_' . bin2hex(random_bytes(4));
    $server->exec('CREATE DATABASE `' . $baseDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $fixtureDatabases[] = $baseDb;

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

    // Use the actual 0.5.95 bootstrap schema so bridge preflight performs
    // a real dump/temporary-restore round-trip against a representative database.
    $client = is_executable('/usr/bin/mysql')
        ? '/usr/bin/mysql'
        : (is_executable('/usr/bin/mariadb') ? '/usr/bin/mariadb' : '');
    if ($client === '') {
        throw new RuntimeException('Native mysql/mariadb client is unavailable in bridge fixture.');
    }
    $schemaCommand = 'exec ' . escapeshellarg($client)
        . ' --host=' . escapeshellarg($host)
        . ' --port=' . escapeshellarg((string)$port)
        . ' --user=' . escapeshellarg($user)
        . ' --default-character-set=utf8mb4 '
        . escapeshellarg($baseDb)
        . ' < ' . escapeshellarg($fixture . '/init.sql');
    $schema = $run(
        ['/bin/bash', '-lc', $schemaCommand],
        ['MYSQL_PWD' => $pass]
    );
    if ($schema['exit'] !== 0) {
        throw new RuntimeException('Unable to initialize bridge fixture from 0.5.95 init.sql: ' . $schema['stderr']);
    }

    // Simulate a real deployed server where config.php has legitimate local changes.
    $configPath = $fixture . '/config.php';
    $configContents = file_get_contents($configPath);
    if (!is_string($configContents)) {
        throw new RuntimeException('Unable to customize fixture config.php.');
    }
    $marker = "\n// DEPLOYMENT-SPECIFIC LOCAL CONFIG MARKER - MUST NEVER BE OVERWRITTEN\n";
    $closing = strrpos($configContents, '?>');
    $configContents = $closing === false
        ? $configContents . $marker
        : substr($configContents, 0, $closing) . $marker . substr($configContents, $closing);
    file_put_contents($configPath, $configContents);

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
        'lib/system_cli_bootstrap.php',
        'scripts/system_upgrade.php',
        'scripts/system_job.php',
        'scripts/system_healthcheck.php',
    ];
    foreach ($paths as $path) {
        $assert(
            $blob($fixture . '/' . $path) === $blob($repoRoot . '/' . $path),
            'Installed bridge target differs from repository source: ' . $path
        );
    }
    $assert($blob($fixture . '/config.php') === $beforeConfig, 'Bridge changed customized config.php.');
    $assert((fileperms($fixture . '/config.php') & 0777) === $configMode, 'config.php mode was not preserved.');
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

    // 4) config.php customization is allowed; unknown updater-code modification is not.
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
    $assert($refusal['exit'] !== 0, 'Bridge unexpectedly accepted locally modified updater code.');
    $assert(
        $blob($modified . '/admin/upgrade.php') === $modifiedAdminBefore,
        'Bridge changed files before refusing unknown updater code.'
    );
    $assert(
        $blob($modified . '/config.php') === $modifiedConfigBefore,
        'Refused bridge changed customized config.php.'
    );
    $assert(!file_exists($modified . '/scripts/system_job.php'), 'Refused bridge left new system_job.php behind.');

    // 5) Injected mid-install failure must restore exact 0.5.95 files and remove new files.
    $rollback = $makeFixture('rollback');
    $original = [];
    $rollbackConfigBefore = $blob($rollback . '/config.php');
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
        '--test-fail-after=3',
    ], ['CAS_BOOTSTRAP_TEST_MODE' => '1']);
    $assert($injected['exit'] !== 0, 'Injected bridge failure unexpectedly succeeded.');
    foreach ($original as $path => $state) {
        $assert($blob($rollback . '/' . $path) === $state['blob'], 'Rollback hash mismatch for ' . $path);
        $assert((fileperms($rollback . '/' . $path) & 0777) === $state['mode'], 'Rollback mode mismatch for ' . $path);
    }
    $assert(!file_exists($rollback . '/scripts/system_job.php'), 'Rollback failed to remove newly created system_job.php.');
    $assert(!file_exists($rollback . '/scripts/system_healthcheck.php'), 'Rollback failed to remove new healthcheck script.');
    $assert(!file_exists($rollback . '/lib/enterprise_runtime.php'), 'Rollback failed to remove new runtime hook.');
    $assert(!file_exists($rollback . '/lib/system_cli_bootstrap.php'), 'Rollback failed to remove new CLI bootstrap.');
    $assert($blob($rollback . '/config.php') === $rollbackConfigBefore, 'Rollback changed customized config.php.');

    echo "Enterprise bootstrap transition test passed with customized config.php preserved.\n";
} finally {
    foreach ($fixtureDatabases as $fixtureDatabase) {
        try {
            $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $fixtureDatabase) . '`');
        } catch (Throwable $e) {
        }
    }
    $remove($tempRoot);
}
