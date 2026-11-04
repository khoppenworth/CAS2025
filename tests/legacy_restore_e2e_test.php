<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/system_backup.php';

$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

$dsn = getenv('BACKUP_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = getenv('BACKUP_DB_USER') ?: 'root';
$pass = getenv('BACKUP_DB_PASS') ?: 'root';
preg_match('/host=([^;]+)/', $dsn, $hm);
preg_match('/port=([0-9]+)/', $dsn, $pm);
$host = $hm[1] ?? '127.0.0.1';
$port = $pm[1] ?? '3306';

putenv('DB_HOST=' . $host);
putenv('DB_PORT=' . $port);
putenv('DB_USER=' . $user);
putenv('DB_PASS=' . $pass);
putenv('DB_ADMIN_USER=' . $user);
putenv('DB_ADMIN_PASS=' . $pass);

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$tempRoot = sys_get_temp_dir() . '/cas-legacy-restore-e2e-' . bin2hex(random_bytes(6));
$fixture = $tempRoot . '/app';
$dbName = 'cas_legacy_restore_' . bin2hex(random_bytes(4));
$backupPath = $tempRoot . '/legacy-0.5.95.zip';

$run = static function (array $command, ?string $cwd = null, array $env = [], ?string $stdin = null): array {
    $process = proc_open($command, [
        0 => $stdin !== null ? ['file', $stdin, 'rb'] : ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd, array_merge($_ENV, $env));
    if (!is_resource($process)) throw new RuntimeException('Unable to launch child process.');
    if ($stdin === null && isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
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

$copyCurrent = static function (string $source, string $target) use (&$copyCurrent): void {
    $preserve = ['.git', '.env', 'config.php', 'assets/uploads', 'storage'];
    $skip = static function (string $relative) use ($preserve): bool {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        foreach ($preserve as $path) {
            if ($relative === $path || str_starts_with($relative, $path . '/')) return true;
        }
        return false;
    };

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
        if ($relative === '' || $skip($relative)) continue;
        $destination = $target . '/' . $relative;
        if ($entry->isDir()) {
            if (!is_dir($destination)) mkdir($destination, 0755, true);
        } elseif ($entry->isFile()) {
            if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0755, true);
            copy($entry->getPathname(), $destination);
        }
    }
};

try {
    mkdir($fixture, 0750, true);

    $extract = $run(['/bin/bash', '-lc',
        'git -C ' . escapeshellarg($repoRoot)
        . ' archive 0.5.95 | tar -x -C ' . escapeshellarg($fixture)
    ]);
    $assert($extract['exit'] === 0, 'Unable to extract real 0.5.95 tree: ' . $extract['stderr']);

    mkdir($fixture . '/storage/upgrades', 0750, true);
    file_put_contents($fixture . '/storage/upgrades/installed.json', json_encode([
        'tag' => '0.5.95',
        'name' => 'Release UAT 0.5.95',
        'installed_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT) . "\n");

    file_put_contents($fixture . '/.env',
        "DB_HOST={$host}\nDB_PORT={$port}\nDB_NAME={$dbName}\n"
        . "DB_USER={$user}\nDB_PASS={$pass}\nDB_ADMIN_USER={$user}\nDB_ADMIN_PASS={$pass}\n"
        . "BASE_URL=/\nAPP_DEBUG=0\n"
    );

    $configPath = $fixture . '/config.php';
    $config = file_get_contents($configPath);
    $assert(is_string($config), 'Unable to read 0.5.95 config.');
    $custom = "\n// SITE LOCAL CONFIG SENTINEL - MUST SURVIVE RESTORE EXACTLY\n";
    $pos = strrpos($config, '?>');
    $config = $pos === false ? $config . $custom : substr($config, 0, $pos) . $custom . substr($config, $pos);
    file_put_contents($configPath, $config);
    $configHash = hash_file('sha256', $configPath);
    $legacyIndexHash = hash_file('sha256', $fixture . '/index.php');

    $server->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $server->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $cli = trim((string)shell_exec('command -v mysql 2>/dev/null || command -v mariadb 2>/dev/null'));
    $assert($cli !== '', 'Native DB client not available.');
    $schema = $run([
        $cli, '--host=' . $host, '--port=' . $port, '--user=' . $user,
        '--default-character-set=utf8mb4', $dbName,
    ], $fixture, ['MYSQL_PWD' => $pass], $fixture . '/init.sql');
    $assert($schema['exit'] === 0, 'Unable to initialize 0.5.95 DB: ' . $schema['stderr']);

    putenv('DB_NAME=' . $dbName);
    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbName . ';charset=utf8mb4',
        $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $pdo->exec(
        "INSERT INTO users (username,password,role,full_name,email,account_status) "
        . "VALUES ('legacy_restore_admin','x','admin','Legacy Restore Admin','legacy-restore@example.invalid','active')"
    );
    $legacyUserCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $manager = new SystemBackupManager($fixture);
    $backup = $manager->createBackupSet($pdo, 'manual', $backupPath, [
        'id' => 'real_0_5_95_restore',
        'label' => 'Real 0.5.95 application restore E2E',
    ]);
    $assert($manager->validateBackupSet($backup['absolute_path'])['valid'] === true, 'Legacy backup did not validate.');

    // Simulate the live system having moved to the new recovery core and newer DB state.
    $copyCurrent($repoRoot, $fixture);
    $trusted = [
        'admin/upgrade.php',
        'lib/system_backup.php',
        'lib/upgrade.php',
        'lib/path.php',
        'lib/enterprise_runtime.php',
        'scripts/system_upgrade.php',
        'scripts/system_job.php',
    ];
    $trustedHashes = [];
    foreach ($trusted as $path) {
        $hash = hash_file('sha256', $fixture . '/' . $path);
        $assert(is_string($hash), 'Trusted recovery file missing: ' . $path);
        $trustedHashes[$path] = $hash;
    }

    file_put_contents($fixture . '/index.php', "<?php echo 'newer-app-state';\n");
    $pdo->exec(
        "INSERT INTO users (username,password,role,full_name,email,account_status) "
        . "VALUES ('post_backup_user','x','staff','Post Backup User','post-backup@example.invalid','active')"
    );
    $liveUserCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $assert($liveUserCount === $legacyUserCount + 1, 'Unable to establish post-backup live DB mutation.');

    $result = $manager->restoreBackupSet($pdo, $backup['absolute_path']);
    $candidateDb = (string)($result['active_database'] ?? '');
    $assert($candidateDb !== '' && $candidateDb !== $dbName, 'Restore did not cut over to candidate DB.');

    $assert(hash_file('sha256', $configPath) === $configHash, 'Restore changed site-owned config.php.');
    $assert(hash_file('sha256', $fixture . '/index.php') === $legacyIndexHash, 'Legacy application index was not restored.');

    foreach ($trustedHashes as $path => $expectedHash) {
        $assert(
            hash_file('sha256', $fixture . '/' . $path) === $expectedHash,
            'Restore downgraded trusted recovery core: ' . $path
        );
    }

    // Original DB remains exactly at its newer pre-restore state.
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $liveUserCount,
        'Restore modified the previously active live DB.'
    );

    $candidate = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $candidateDb . ';charset=utf8mb4',
        $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $assert(
        (int)$candidate->query('SELECT COUNT(*) FROM users')->fetchColumn() === $legacyUserCount,
        'Restored candidate DB does not match the 0.5.95 backup.'
    );
    $assert(
        (int)$candidate->query("SELECT COUNT(*) FROM users WHERE username='post_backup_user'")->fetchColumn() === 0,
        'Post-backup live user leaked into restored candidate.'
    );

    // Fresh process proves preserved 0.5.95 config + trusted runtime selects candidate.
    $probe = $run([
        PHP_BINARY, '-r',
        'chdir($argv[1]); require "config.php"; '
        . '$db=(string)$pdo->query("SELECT DATABASE()")->fetchColumn(); '
        . 'echo $db . "|" . (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();',
        $fixture,
    ], $fixture);
    $assert($probe['exit'] === 0, 'Restored 0.5.95 application failed fresh boot: ' . $probe['stderr']);
    $assert(
        trim($probe['stdout']) === $candidateDb . '|' . $legacyUserCount,
        'Restored application did not boot against restored candidate DB.'
    );

    echo "Real 0.5.95 legacy backup restore E2E passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Real 0.5.95 legacy backup restore E2E failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`','``',$dbName) . '`');
        $leftovers = $server->query("SHOW DATABASES LIKE 'cas_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($leftovers ?: [] as $leftover) {
            if (preg_match('/^cas_(restore|verify|upgrade)_/', (string)$leftover)) {
                $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`','``',(string)$leftover) . '`');
            }
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . "\n");
    }
    putenv('DB_NAME');
    $remove($tempRoot);
}
