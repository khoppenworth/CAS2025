<?php
declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

$remoteRepo = trim((string)(getenv('UPGRADE_TEST_REPO') ?: 'khoppenworth/CAS2025'));
$remoteRef = trim((string)(getenv('UPGRADE_TEST_REF') ?: ''));
if ($remoteRef === '') {
    fwrite(STDOUT, "Enterprise upgrade E2E skipped because UPGRADE_TEST_REF is not set.\n");
    exit(0);
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

$tempRoot = sys_get_temp_dir() . '/cas-enterprise-upgrade-e2e-' . bin2hex(random_bytes(5));
mkdir($tempRoot, 0750, true);

$run = static function (array $command, array $env = [], ?string $cwd = null) use ($repoRoot): array {
    $mergedEnv = array_merge($_ENV, [
        'PATH' => (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
    ], $env);
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd ?? $repoRoot, $mergedEnv);
    if (!is_resource($process)) throw new RuntimeException('Unable to launch E2E command.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$loadSql = static function (string $sqlFile, string $dbName) use ($host, $port, $user, $pass): void {
    $client = null;
    foreach (['/usr/bin/mysql', '/usr/bin/mariadb', '/usr/local/bin/mysql', '/usr/local/bin/mariadb'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $client = $candidate;
            break;
        }
    }
    if ($client === null) {
        throw new RuntimeException('Native mysql/mariadb client is unavailable for E2E fixture initialization.');
    }

    $process = proc_open([
        $client,
        '--host=' . $host,
        '--port=' . $port,
        '--user=' . $user,
        '--default-character-set=utf8mb4',
        $dbName,
    ], [
        0 => ['file', $sqlFile, 'rb'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, array_merge($_ENV, ['MYSQL_PWD' => $pass]));

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start native client for E2E schema initialization.');
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0) {
        throw new RuntimeException(
            'Unable to initialize real 0.5.95 schema from init.sql: '
            . trim($stderr !== '' ? $stderr : $stdout)
        );
    }
};

$sha256 = static function (string $path): string {
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) throw new RuntimeException('Unable to hash ' . $path);
    return $hash;
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

$createdDatabases = [];

$makeFixture = function (string $label) use (
    $repoRoot, $tempRoot, $host, $port, $user, $pass, $server, $run, $loadSql, &$createdDatabases
): array {
    $safeLabel = preg_replace('/[^A-Za-z0-9_]/', '_', $label);
    $dbName = 'cas_e2e_' . $safeLabel . '_' . bin2hex(random_bytes(3));
    $server->exec('CREATE DATABASE ' . quoteDb($dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdDatabases[] = $dbName;

    $fixture = $tempRoot . '/' . $safeLabel;
    mkdir($fixture, 0750, true);
    $cmd = 'git -C ' . escapeshellarg($repoRoot)
        . ' archive 0.5.95 | tar -x -C ' . escapeshellarg($fixture);
    $result = $run(['/bin/bash', '-lc', $cmd]);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('Unable to extract real 0.5.95 fixture: ' . $result['stderr']);
    }

    mkdir($fixture . '/storage/upgrades', 0750, true);
    $installed = json_encode([
        'tag' => '0.5.95',
        'name' => 'Release UAT 0.5.95',
        'repo' => 'khoppenworth/CAS2025',
        'installed_at' => '2026-11-04T12:00:00-05:00',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($fixture . '/storage/upgrades/installed.json', $installed);

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

    $config = file_get_contents($fixture . '/config.php');
    if (!is_string($config)) throw new RuntimeException('Unable to customize fixture config.');
    $config = preg_replace(
        '/^<\?php/',
        "<?php\n// LOCAL DEPLOYED CONFIG - E2E MUST NEVER MODIFY THIS FILE",
        $config,
        1
    );
    file_put_contents($fixture . '/config.php', $config);
    chmod($fixture . '/config.php', 0640);
    chmod($fixture . '/scripts/system_upgrade.php', 0750);

    file_put_contents($fixture . '/.htaccess', "RewriteEngine On\n# LOCAL APACHE SENTINEL\n");
    file_put_contents($fixture . '/.user.ini', "upload_max_filesize=64M\n; LOCAL PHP SENTINEL\n");

    // A stale tracked-area file proves successful deploy is exact and failed
    // deploy rollback restores the pre-upgrade application snapshot.
    file_put_contents($fixture . '/obsolete-enterprise-e2e.php', "<?php echo 'old-only';\n");

    $bridge = $run([
        PHP_BINARY,
        $repoRoot . '/scripts/bootstrap_enterprise_transition.php',
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--apply',
    ], [], $fixture);
    if ($bridge['exit'] !== 0) {
        throw new RuntimeException('Fixture bridge failed: ' . $bridge['stderr']);
    }

    // Initialize the real 0.5.95 base schema exactly from its shipped init.sql,
    // then let its own config perform the runtime/idempotent schema checks.
    $loadSql($fixture . '/init.sql', $dbName);

    $init = $run([
        PHP_BINARY,
        '-r',
        'require ' . var_export($fixture . '/config.php', true)
            . '; echo (string)$pdo->query("SELECT DATABASE()")->fetchColumn(), "\\n";',
    ], [], $fixture);
    if ($init['exit'] !== 0 || trim($init['stdout']) !== $dbName) {
        throw new RuntimeException('Fixture application initialization failed: ' . $init['stderr']);
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS enterprise_upgrade_sentinel ('
        . 'id INT PRIMARY KEY, value_text VARCHAR(100) NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec("DELETE FROM enterprise_upgrade_sentinel");
    $pdo->exec("INSERT INTO enterprise_upgrade_sentinel (id, value_text) VALUES (1, 'original-live-db')");

    return [
        'root' => $fixture,
        'db' => $dbName,
        'config_hash' => hash_file('sha256', $fixture . '/config.php'),
        'env_hash' => hash_file('sha256', $fixture . '/.env'),
        'htaccess_hash' => hash_file('sha256', $fixture . '/.htaccess'),
        'user_ini_hash' => hash_file('sha256', $fixture . '/.user.ini'),
        'installed' => (string)file_get_contents($fixture . '/storage/upgrades/installed.json'),
    ];
};

$upgrade = static function (array $fixture, ?string $failureStage = null) use (
    $run, $remoteRepo, $remoteRef
): array {
    $env = [
        'CAS_UPGRADE_TEST_MODE' => '1',
        'CAS_UPGRADE_TEST_FAIL_STAGE' => $failureStage ?? '',
    ];
    return $run([
        PHP_BINARY,
        $fixture['root'] . '/scripts/system_upgrade.php',
        '--action=upgrade',
        '--repo=' . $remoteRepo,
        '--ref=' . $remoteRef,
    ], $env, $fixture['root']);
};

$assertServerOwned = static function (array $fixture) use ($assert): void {
    $assert(hash_file('sha256', $fixture['root'] . '/.env') === $fixture['env_hash'], '.env changed.');
    $assert(hash_file('sha256', $fixture['root'] . '/.htaccess') === $fixture['htaccess_hash'], '.htaccess changed.');
    $assert(hash_file('sha256', $fixture['root'] . '/.user.ini') === $fixture['user_ini_hash'], '.user.ini changed.');
};

$assertOriginalDb = static function (array $fixture) use ($host, $port, $user, $pass, $assert): void {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$fixture['db']};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $value = (string)$pdo->query(
        'SELECT value_text FROM enterprise_upgrade_sentinel WHERE id=1'
    )->fetchColumn();
    $assert($value === 'original-live-db', 'Original live database was modified.');
};

try {
    // SUCCESS: actual bridge + actual upgrade command + actual candidate cutover.
    $success = $makeFixture('success');
    $successResult = $upgrade($success);
    $assert(
        $successResult['exit'] === 0,
        "Real upgrade command failed.\nSTDOUT:\n{$successResult['stdout']}\nSTDERR:\n{$successResult['stderr']}"
    );
    $assertServerOwned($success);
    $assert(
        hash_file('sha256', $success['root'] . '/config.php') === hash_file('sha256', $repoRoot . '/config.php'),
        'Successful upgrade did not install the release config.php.'
    );
    $assert(
        hash_file('sha256', $success['root'] . '/config.php') !== $success['config_hash'],
        'Successful upgrade incorrectly retained customized legacy config.php.'
    );
    $assertOriginalDb($success);
    $assert(!file_exists($success['root'] . '/obsolete-enterprise-e2e.php'), 'Exact deployment left stale application file.');
    $assert(!file_exists($success['root'] . '/storage/upgrades/maintenance.flag'), 'Maintenance flag remained after success.');

    $pointerPath = $success['root'] . '/storage/upgrades/active_database.json';
    $assert(is_file($pointerPath), 'Successful upgrade did not create active database pointer.');
    $pointer = json_decode((string)file_get_contents($pointerPath), true);
    $activeDb = is_array($pointer) ? (string)($pointer['db_name'] ?? '') : '';
    $assert($activeDb !== '' && $activeDb !== $success['db'], 'Upgrade did not cut over to a candidate database.');
    $createdDatabases[] = $activeDb;

    $activePdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$activeDb};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $activeValue = (string)$activePdo->query(
        'SELECT value_text FROM enterprise_upgrade_sentinel WHERE id=1'
    )->fetchColumn();
    $assert($activeValue === 'original-live-db', 'Candidate database lost live data.');

    $runtimeProbe = $run([
        PHP_BINARY,
        '-r',
        'require ' . var_export($success['root'] . '/config.php', true)
            . '; echo (string)$pdo->query("SELECT DATABASE()")->fetchColumn(), "\\n";',
    ], [], $success['root']);
    $assert($runtimeProbe['exit'] === 0, 'Post-upgrade runtime probe failed: ' . $runtimeProbe['stderr']);
    $assert(trim($runtimeProbe['stdout']) === $activeDb, 'Preserved custom config did not resolve active candidate DB.');

    $installed = json_decode((string)file_get_contents($success['root'] . '/storage/upgrades/installed.json'), true);
    $assert(is_array($installed) && (string)($installed['tag'] ?? '') === $remoteRef, 'Installed release metadata was not updated.');

    // Isolate rollback scenarios from the intentionally retained active database
    // created by the successful-upgrade scenario.
    $server->exec('DROP DATABASE IF EXISTS ' . quoteDb($activeDb));

    // FAILURES: each must restore exact pointer/metadata/app state and leave original DB untouched.
    foreach (['mid-deploy', 'after-deploy', 'after-pointer', 'after-installed'] as $stage) {
        $fixture = $makeFixture(str_replace('-', '_', $stage));
        $result = $upgrade($fixture, $stage);
        $assert($result['exit'] !== 0, 'Injected failure unexpectedly succeeded at ' . $stage);

        $assertServerOwned($fixture);
        $assert(
            hash_file('sha256', $fixture['root'] . '/config.php') === $fixture['config_hash'],
            'Failed upgrade did not restore customized legacy config.php exactly at ' . $stage
        );
        $assertOriginalDb($fixture);
        $assert(
            (string)file_get_contents($fixture['root'] . '/storage/upgrades/installed.json') === $fixture['installed'],
            'Installed metadata did not roll back exactly at ' . $stage
        );
        $assert(
            !file_exists($fixture['root'] . '/storage/upgrades/active_database.json'),
            'DB pointer existence did not roll back exactly at ' . $stage
        );
        $assert(
            !file_exists($fixture['root'] . '/storage/upgrades/maintenance.flag'),
            'Maintenance mode remained after successful rollback at ' . $stage
        );
        $assert(
            is_file($fixture['root'] . '/obsolete-enterprise-e2e.php'),
            'Application snapshot did not restore stale pre-upgrade file at ' . $stage
        );

        $leftovers = $server->query("SHOW DATABASES LIKE 'cas_upgrade_%'")->fetchAll(PDO::FETCH_COLUMN);
        $assert($leftovers === [], 'Failed upgrade left candidate database behind at ' . $stage);
    }

    fwrite(
        STDOUT,
        "Enterprise upgrade E2E passed: success cutover plus rollback during deploy and after deploy, pointer, and installed-metadata failure.\n"
    );
} finally {
    try {
        $rows = $server->query("SHOW DATABASES LIKE 'cas_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows ?: [] as $db) {
            $name = (string)$db;
            if (str_starts_with($name, 'cas_e2e_')
                || str_starts_with($name, 'cas_upgrade_')
                || str_starts_with($name, 'cas_verify_')
                || str_starts_with($name, 'cas_bridge_preflight_')
            ) {
                $server->exec('DROP DATABASE IF EXISTS ' . quoteDb($name));
            }
        }
    } catch (Throwable $e) {
    }
    $remove($tempRoot);
}

function quoteDb(string $name): string
{
    $tick = chr(96);
    return $tick . str_replace($tick, $tick . $tick, $name) . $tick;
}
