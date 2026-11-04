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

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$tempRoot = sys_get_temp_dir() . '/cas-upgrade-e2e-' . bin2hex(random_bytes(6));
mkdir($tempRoot, 0750, true);

$run = static function (array $command, ?string $cwd = null, array $env = [], ?string $stdinFile = null): array {
    $process = proc_open($command, [
        0 => $stdinFile !== null ? ['file', $stdinFile, 'rb'] : ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd, array_merge($_ENV, $env));
    if (!is_resource($process)) throw new RuntimeException('Unable to launch command.');
    if ($stdinFile === null && isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
};

$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path)) return;
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

$sha = static function (string $path): string {
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) throw new RuntimeException('Unable to hash ' . $path);
    return $hash;
};

$makeFixture = static function (string $name, string $dbName) use (
    $repoRoot, $tempRoot, $host, $port, $user, $pass, $run, $server
): string {
    $fixture = $tempRoot . '/' . $name;
    mkdir($fixture, 0750, true);

    $extract = $run(['/bin/bash', '-lc',
        'git -C ' . escapeshellarg($repoRoot) . ' archive 0.5.95 | tar -x -C ' . escapeshellarg($fixture)
    ]);
    if ($extract['exit'] !== 0) throw new RuntimeException('Unable to extract 0.5.95 fixture: ' . $extract['stderr']);

    $server->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $server->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    mkdir($fixture . '/storage/upgrades', 0750, true);
    file_put_contents($fixture . '/storage/upgrades/installed.json', json_encode([
        'tag' => '0.5.95',
        'name' => 'Release UAT 0.5.95',
        'installed_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    file_put_contents($fixture . '/.env',
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

    $configPath = $fixture . '/config.php';
    $config = file_get_contents($configPath);
    if (!is_string($config)) throw new RuntimeException('Unable to customize config fixture.');
    $custom = "\n// SITE-LOCAL CUSTOMIZATION: E2E sentinel; updater must never rewrite this file.\n";
    $pos = strrpos($config, '?>');
    $config = $pos === false ? $config . $custom : substr($config, 0, $pos) . $custom . substr($config, $pos);
    file_put_contents($configPath, $config);
    chmod($configPath, 0640);
    chmod($fixture . '/scripts/system_upgrade.php', 0750);

    // Build the fixture from the actual 0.5.95 init.sql, not a synthetic schema.
    $mysqlCli = trim((string)shell_exec('command -v mysql 2>/dev/null || command -v mariadb 2>/dev/null'));
    if ($mysqlCli === '') {
        throw new RuntimeException('Native MySQL/MariaDB client is required for the E2E fixture.');
    }
    $schema = $run([
        $mysqlCli,
        '--host=' . $host,
        '--port=' . $port,
        '--user=' . $user,
        '--default-character-set=utf8mb4',
        $dbName,
    ], $fixture, ['MYSQL_PWD' => $pass], $fixture . '/init.sql');
    if ($schema['exit'] !== 0) {
        throw new RuntimeException('Unable to initialize real 0.5.95 schema: ' . $schema['stderr']);
    }

    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbName . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('CREATE TABLE IF NOT EXISTS upgrade_safety_probe (id INT PRIMARY KEY, value_text VARCHAR(100) NOT NULL)');
    $pdo->exec("INSERT INTO upgrade_safety_probe (id, value_text) VALUES (1, 'original-live-db') ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)");

    return $fixture;
};

$createReleaseZip = static function (string $filename, string $migrationSql) use ($repoRoot): void {
    $zip = new ZipArchive();
    if ($zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create release test ZIP.');
    }
    $prefix = 'CAS2025-target/';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $absolute = $entry->getPathname();
        $relative = str_replace('\\', '/', substr($absolute, strlen($repoRoot) + 1));
        if ($relative === '' || $relative === '.git' || str_starts_with($relative, '.git/')
            || $relative === 'storage' || str_starts_with($relative, 'storage/')
            || $relative === 'migration.sql' || $relative === 'upgrade_to_v3.sql') {
            continue;
        }
        if ($entry->isDir()) $zip->addEmptyDir($prefix . $relative);
        elseif ($entry->isFile()) $zip->addFile($absolute, $prefix . $relative);
    }
    $zip->addFromString($prefix . 'migration.sql', $migrationSql);
    $zip->close();
};

$startServer = static function (string $directory): array {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) throw new RuntimeException('Unable to reserve HTTP test port: ' . $errstr);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr((string)$address, ':'), 1);

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $directory],
        [0 => ['pipe','r'], 1 => ['file', $directory . '/server.out', 'a'], 2 => ['file', $directory . '/server.err', 'a']],
        $pipes
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to start HTTP test server.');
    if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
    usleep(500000);
    return ['process' => $process, 'url' => 'http://127.0.0.1:' . $port];
};

$stopServer = static function ($process): void {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
};

$dbNames = [];
$http = null;

try {
    $serverDir = $tempRoot . '/http';
    mkdir($serverDir, 0750, true);
    $goodZip = $serverDir . '/good.zip';
    $badZip = $serverDir . '/bad.zip';
    $createReleaseZip($goodZip, "CREATE TABLE upgrade_candidate_probe (id INT PRIMARY KEY, marker VARCHAR(50) NOT NULL);\nINSERT INTO upgrade_candidate_probe VALUES (1, 'candidate-only');\n");
    $createReleaseZip($badZip, "THIS IS DELIBERATELY INVALID SQL;\n");

    $http = $startServer($serverDir);

    $runScenario = static function (
        string $name,
        string $zipName,
        ?string $failpoint,
        bool $expectSuccess
    ) use (
        $repoRoot, $tempRoot, $host, $port, $user, $pass, $server, $makeFixture, $run, $sha, $assert, $http, &$dbNames
    ): void {
        $dbName = 'cas_e2e_' . preg_replace('/[^a-z0-9]+/i', '_', $name) . '_' . bin2hex(random_bytes(3));
        $dbNames[] = $dbName;
        $fixture = $makeFixture($name, $dbName);

        $configHash = $sha($fixture . '/config.php');
        $indexHash = $sha($fixture . '/index.php');
        $installedBefore = file_get_contents($fixture . '/storage/upgrades/installed.json');

        $bridge = $run([
            PHP_BINARY,
            $repoRoot . '/scripts/bootstrap_enterprise_transition.php',
            '--root=' . $fixture,
            '--source-root=' . $repoRoot,
            '--apply',
        ], $repoRoot);
        $assert($bridge['exit'] === 0, $name . ': bridge failed: ' . $bridge['stderr']);
        $assert($sha($fixture . '/config.php') === $configHash, $name . ': bridge changed config.php.');

        $env = [];
        if ($failpoint !== null) {
            $env = ['CAS_UPGRADE_TEST_MODE' => '1', 'CAS_UPGRADE_FAIL_AT' => $failpoint];
        }

        $upgrade = $run([
            PHP_BINARY,
            $fixture . '/scripts/system_upgrade.php',
            '--action=upgrade',
            '--repo=' . $http['url'] . '/' . $zipName,
            '--ref=0.5.e2e',
        ], $fixture, $env);

        if ($expectSuccess) {
            $assert($upgrade['exit'] === 0, $name . ': expected upgrade success: ' . $upgrade['stderr']);
        } else {
            $assert($upgrade['exit'] !== 0, $name . ': expected upgrade failure.');
        }

        $assert($sha($fixture . '/config.php') === $configHash, $name . ': upgrade changed config.php.');

        $live = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbName . ';charset=utf8mb4',
            $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $assert(
            (string)$live->query('SELECT value_text FROM upgrade_safety_probe WHERE id=1')->fetchColumn() === 'original-live-db',
            $name . ': original live database data changed.'
        );
        $assert(
            (int)$live->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='upgrade_candidate_probe'")->fetchColumn() === 0,
            $name . ': candidate migration leaked into original live database.'
        );

        $pointerPath = $fixture . '/storage/upgrades/active_database.json';
        $installedAfter = file_get_contents($fixture . '/storage/upgrades/installed.json');

        if ($expectSuccess) {
            $assert(is_file($pointerPath), $name . ': successful upgrade did not create active DB pointer.');
            $pointer = json_decode((string)file_get_contents($pointerPath), true);
            $candidateDb = is_array($pointer) ? (string)($pointer['db_name'] ?? '') : '';
            $assert($candidateDb !== '' && $candidateDb !== $dbName, $name . ': invalid candidate DB pointer.');

            $candidate = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $candidateDb . ';charset=utf8mb4',
                $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $assert(
                (string)$candidate->query('SELECT marker FROM upgrade_candidate_probe WHERE id=1')->fetchColumn() === 'candidate-only',
                $name . ': candidate migration did not execute on candidate DB.'
            );
            $installed = json_decode((string)$installedAfter, true);
            $assert(($installed['tag'] ?? '') === '0.5.e2e', $name . ': installed release metadata not updated.');

            $probe = $run([
                PHP_BINARY, '-r',
                'chdir(' . var_export($fixture, true) . '); require "config.php"; echo $pdo->query("SELECT DATABASE()")->fetchColumn();'
            ], $fixture);
            $assert($probe['exit'] === 0 && trim($probe['stdout']) === $candidateDb, $name . ': runtime did not select active candidate DB.');
        } else {
            $assert(!is_file($pointerPath), $name . ': failed upgrade left an active DB pointer behind.');
            $assert($installedAfter === $installedBefore, $name . ': failed upgrade changed installed-release metadata.');
            $assert($sha($fixture . '/index.php') === $indexHash, $name . ': failed upgrade did not restore original application.');
        }
    };

    $runScenario('success', 'good.zip', null, true);
    $runScenario('candidate_migration_failure', 'bad.zip', null, false);
    $runScenario('after_app_deploy_failure', 'good.zip', 'after_app_deploy', false);
    $runScenario('after_pointer_switch_failure', 'good.zip', 'after_pointer_switch', false);

    echo "Enterprise upgrade end-to-end test passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Enterprise upgrade end-to-end test failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_array($http ?? null)) $stopServer($http['process'] ?? null);
    try {
        foreach ($dbNames as $dbName) {
            $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`','``',$dbName) . '`');
        }
        $leftovers = $server->query("SHOW DATABASES LIKE 'cas_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($leftovers ?: [] as $leftover) {
            if (preg_match('/^cas_(upgrade|verify|restore|bridge_preflight)_/', (string)$leftover)) {
                $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`','``',(string)$leftover) . '`');
            }
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . "\n");
    }
    $remove($tempRoot);
}
