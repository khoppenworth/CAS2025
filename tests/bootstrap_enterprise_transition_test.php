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
$server->exec('CREATE DATABASE ' . quoteDb($baseDb) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$tempRoot = sys_get_temp_dir() . '/cas-enterprise-bridge-' . bin2hex(random_bytes(6));
mkdir($tempRoot, 0750, true);

$run = static function (array $command, array $env = [], ?string $cwd = null) use ($repoRoot): array {
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd ?? $repoRoot, array_merge($_ENV, $env));
    if (!is_resource($process)) throw new RuntimeException('Unable to launch test command.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$sha256 = static function (string $path): string {
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) throw new RuntimeException('Unable to hash ' . $path);
    return $hash;
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

$makeFixture = static function (string $name) use (
    $repoRoot, $tempRoot, $host, $port, $user, $pass, $baseDb, $run
): string {
    $fixture = $tempRoot . '/' . $name;
    mkdir($fixture, 0750, true);

    $cmd = 'git -C ' . escapeshellarg($repoRoot)
        . ' archive 0.5.95 | tar -x -C ' . escapeshellarg($fixture);
    $result = $run(['/bin/bash', '-lc', $cmd]);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('Unable to extract real 0.5.95 fixture: ' . $result['stderr']);
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

    // Deliberately make config.php differ from the released 0.5.95 blob while
    // remaining valid PHP. A real deployed config is allowed to be site-owned.
    $config = file_get_contents($fixture . '/config.php');
    if (!is_string($config) || !str_starts_with($config, '<?php')) {
        throw new RuntimeException('Fixture config.php is not valid input.');
    }
    $config = preg_replace('/^<\?php/', "<?php\n// LOCAL SITE CONFIG SENTINEL - MUST SURVIVE BYTE-FOR-BYTE", $config, 1);
    file_put_contents($fixture . '/config.php', $config);

    chmod($fixture . '/config.php', 0640);
    chmod($fixture . '/scripts/system_upgrade.php', 0750);

    return $fixture;
};

try {
    $script = $repoRoot . '/scripts/bootstrap_enterprise_transition.php';

    // Success fixture begins with a customized config.php.
    $fixture = $makeFixture('success');
    $configBefore = $sha256($fixture . '/config.php');
    $envBefore = $sha256($fixture . '/.env');
    $installedBefore = (string)file_get_contents($fixture . '/storage/upgrades/installed.json');
    $configMode = fileperms($fixture . '/config.php') & 0777;
    $scriptMode = fileperms($fixture . '/scripts/system_upgrade.php') & 0777;

    $check = $run([
        PHP_BINARY, $script,
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--check',
    ]);
    $assert($check['exit'] === 0, 'Bridge check failed on customized config.php: ' . $check['stderr']);
    $assert(str_contains($check['stdout'], 'CHECK PASSED'), 'Bridge check did not report success.');
    $assert($sha256($fixture . '/config.php') === $configBefore, 'Check-only changed customized config.php.');
    $assert($sha256($fixture . '/.env') === $envBefore, 'Check-only changed .env.');

    $apply = $run([
        PHP_BINARY, $script,
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--apply',
    ]);
    $assert($apply['exit'] === 0, 'Bridge apply failed: ' . $apply['stderr']);
    $assert(str_contains($apply['stdout'], 'BRIDGE INSTALLATION PASSED'), 'Bridge apply did not report success.');
    $assert($sha256($fixture . '/config.php') === $configBefore, 'Bridge apply changed customized config.php.');
    $assert($sha256($fixture . '/.env') === $envBefore, 'Bridge apply changed .env.');
    $assert((fileperms($fixture . '/config.php') & 0777) === $configMode, 'config.php mode changed.');

    foreach ([
        'lib/enterprise_runtime.php',
        'admin/upgrade.php',
        'lib/system_backup.php',
        'lib/upgrade.php',
        'scripts/system_upgrade.php',
        'scripts/system_job.php',
        'lib/path.php',
    ] as $path) {
        $assert(
            $blob($fixture . '/' . $path) === $blob($repoRoot . '/' . $path),
            'Bridge target differs from release source: ' . $path
        );
    }
    $assert((fileperms($fixture . '/scripts/system_job.php') & 0777) === $scriptMode, 'New job runner mode is wrong.');
    $assert(
        (string)file_get_contents($fixture . '/storage/upgrades/installed.json') === $installedBefore,
        'Bridge changed installed-release metadata.'
    );

    // Idempotent rerun remains safe.
    $second = $run([
        PHP_BINARY, $script,
        '--root=' . $fixture,
        '--source-root=' . $repoRoot,
        '--apply',
    ]);
    $assert($second['exit'] === 0, 'Idempotent bridge rerun failed: ' . $second['stderr']);
    $assert($sha256($fixture . '/config.php') === $configBefore, 'Idempotent bridge changed config.php.');
    $assert($sha256($fixture . '/.env') === $envBefore, 'Idempotent bridge changed .env.');

    // Prove the preserved customized config now honors the enterprise DB pointer
    // solely through the new tracked runtime hook.
    $pointerDb = 'cas_pointer_' . bin2hex(random_bytes(4));
    $server->exec('CREATE DATABASE ' . quoteDb($pointerDb) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    file_put_contents(
        $fixture . '/storage/upgrades/active_database.json',
        json_encode(['db_name' => $pointerDb, 'activated_at' => date(DATE_ATOM)]) . "\n"
    );

    $probeCode = 'require ' . var_export($fixture . '/config.php', true)
        . '; echo (string)$pdo->query("SELECT DATABASE()")->fetchColumn(), "\\n";';
    $probe = $run([PHP_BINARY, '-r', $probeCode], [], $fixture);
    $assert($probe['exit'] === 0, 'Customized config runtime pointer probe failed: ' . $probe['stderr']);
    $assert(trim($probe['stdout']) === $pointerDb, 'Customized config did not honor active DB pointer.');
    @unlink($fixture . '/storage/upgrades/active_database.json');
    $server->exec('DROP DATABASE IF EXISTS ' . quoteDb($pointerDb));

    // Unknown modifications to actual updater code remain fail-closed.
    $modified = $makeFixture('modified-updater');
    file_put_contents($modified . '/admin/upgrade.php', "\n// unknown updater modification\n", FILE_APPEND);
    $modifiedConfig = $sha256($modified . '/config.php');
    $refusal = $run([
        PHP_BINARY, $script,
        '--root=' . $modified,
        '--source-root=' . $repoRoot,
        '--apply',
    ]);
    $assert($refusal['exit'] !== 0, 'Bridge unexpectedly accepted modified updater code.');
    $assert($sha256($modified . '/config.php') === $modifiedConfig, 'Refused bridge changed config.php.');

    // Injected failure after several replacements must restore exact 0.5.95 updater files.
    $rollback = $makeFixture('rollback');
    $rollbackConfig = $sha256($rollback . '/config.php');
    $rollbackEnv = $sha256($rollback . '/.env');
    $original = [];
    foreach ([
        'admin/upgrade.php',
        'lib/path.php',
        'lib/system_backup.php',
        'lib/upgrade.php',
        'scripts/system_upgrade.php',
    ] as $path) {
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
        '--test-fail-after=4',
    ], ['CAS_BOOTSTRAP_TEST_MODE' => '1']);
    $assert($injected['exit'] !== 0, 'Injected bridge failure unexpectedly succeeded.');
    foreach ($original as $path => $state) {
        $assert($blob($rollback . '/' . $path) === $state['blob'], 'Rollback hash mismatch for ' . $path);
        $assert((fileperms($rollback . '/' . $path) & 0777) === $state['mode'], 'Rollback mode mismatch for ' . $path);
    }
    $assert(!file_exists($rollback . '/lib/enterprise_runtime.php'), 'Rollback left runtime hook behind.');
    $assert(!file_exists($rollback . '/scripts/system_job.php'), 'Rollback left job runner behind.');
    $assert($sha256($rollback . '/config.php') === $rollbackConfig, 'Rollback changed customized config.php.');
    $assert($sha256($rollback . '/.env') === $rollbackEnv, 'Rollback changed .env.');

    fwrite(STDOUT, "Enterprise bootstrap transition test passed with customized config.php preserved byte-for-byte.\n");
} finally {
    try {
        $server->exec('DROP DATABASE IF EXISTS ' . quoteDb($baseDb));
        $rows = $server->query("SHOW DATABASES LIKE 'cas_bridge_preflight_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows ?: [] as $db) $server->exec('DROP DATABASE IF EXISTS ' . quoteDb((string)$db));
        $rows = $server->query("SHOW DATABASES LIKE 'cas_pointer_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows ?: [] as $db) $server->exec('DROP DATABASE IF EXISTS ' . quoteDb((string)$db));
    } catch (Throwable $e) {
    }
    $remove($tempRoot);
}

function quoteDb(string $name): string
{
    $tick = chr(96);
    return $tick . str_replace($tick, $tick . $tick, $name) . $tick;
}
