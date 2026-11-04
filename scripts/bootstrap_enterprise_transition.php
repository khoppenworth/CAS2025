#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-time fail-closed bridge from legacy CAS updater code to the
 * enterprise updater runtime.
 *
 * Invariant: config.php is site-owned and is never modified, copied,
 * patched, replaced, or hash-gated by this bridge.
 *
 * Default mode is check-only. Use --apply only after CHECK PASSED.
 */

const BRIDGE_FILES = [
    'admin/upgrade.php' => [
        'allowed_legacy' => [
            'afceaa03265bd76e6d16f14bcbfac8ea5ba5144c',
            'a97038b94921bcc75c6d4f31c9e6b286b0b9e771',
        ],
    ],
    'lib/system_backup.php' => [
        'allowed_legacy' => [
            '48c85a53283aa515ccce0fa9e84f4e02ef8112e8',
            '705af28f16565dfc97acf0142cefb0daf69419fa',
            '6d24268acba4f40ef3d872a9a3bbffd74e67985a',
        ],
    ],
    'lib/upgrade.php' => [
        'allowed_legacy' => [
            'a240b32ed60106ebf564ce01df7ad94ce1134265',
        ],
    ],
    'lib/enterprise_runtime.php' => [
        'allowed_legacy' => [],
        'may_be_absent' => true,
        'metadata_reference' => 'lib/path.php',
    ],
    'lib/path.php' => [
        'allowed_legacy' => [
            '1c350a4d4132ec695699db696ff6f6093efcebdb',
        ],
    ],
    'scripts/system_upgrade.php' => [
        'allowed_legacy' => [
            'c0a445961b73086b5e8534eb0da3fa830de6d4cc',
        ],
    ],
    'scripts/system_job.php' => [
        'allowed_legacy' => [],
        'may_be_absent' => true,
        'metadata_reference' => 'scripts/system_upgrade.php',
    ],
];

$options = getopt('', [
    'root::',
    'source-root:',
    'check',
    'apply',
    'skip-db-preflight',
    'test-fail-after::',
]);

$apply = isset($options['apply']);
if (isset($options['check']) && $apply) {
    fail('Choose either --check or --apply, not both.');
}

$root = realpath((string)($options['root'] ?? getcwd()));
if ($root === false || !is_dir($root)) {
    fail('CAS root directory does not exist.');
}
$root = rtrim($root, DIRECTORY_SEPARATOR);

$sourceRoot = realpath((string)($options['source-root'] ?? ''));
if ($sourceRoot === false || !is_dir($sourceRoot)) {
    fail('--source-root must point to the extracted official release package.');
}
$sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);

foreach (['config.php', 'admin/upgrade.php', 'lib/upgrade.php', 'lib/path.php', 'scripts/system_upgrade.php'] as $path) {
    if (!is_file($root . DIRECTORY_SEPARATOR . $path)) {
        fail('Not a CAS application root; missing ' . $path);
    }
}

$configPath = $root . '/config.php';
$configHashBefore = hash_file('sha256', $configPath);
if (!is_string($configHashBefore)) {
    fail('Unable to hash site-owned config.php.');
}

$testFailAfter = isset($options['test-fail-after']) ? (int)$options['test-fail-after'] : 0;
if ($testFailAfter > 0 && getenv('CAS_BOOTSTRAP_TEST_MODE') !== '1') {
    fail('--test-fail-after is available only in CAS_BOOTSTRAP_TEST_MODE.');
}

$storage = $root . '/storage/upgrades';
$bootstrapDir = $storage . '/bootstrap';
ensureDirectory($bootstrapDir);

$lockHandle = fopen($bootstrapDir . '/enterprise-transition.lock', 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fail('Another enterprise transition is already active.');
}

try {
    assertNoActiveSystemOperation($root);
    assertNativeTools();

    $targets = inspectTargetFiles($sourceRoot);
    $states = inspectLiveFiles($root);
    validateLiveState($states, $targets);

    if (!isset($options['skip-db-preflight'])) {
        info('Proving staging database privileges using an isolated throwaway database...');
        proveStagingPrivileges($root);
        info('Staging database privilege check passed; live database was not modified.');
    }

    lintPhpFiles($sourceRoot, array_keys(BRIDGE_FILES));

    assertConfigUnchanged($configPath, $configHashBefore);

    if (!$apply) {
        info('CHECK PASSED. config.php and live application files were not changed.');
        exit(0);
    }

    $snapshot = createSnapshot($root, $bootstrapDir, $states);
    info('Rollback snapshot created at ' . $snapshot);

    try {
        $index = 0;
        foreach (array_keys(BRIDGE_FILES) as $path) {
            $index++;
            installOne($root, $sourceRoot, $path, $states);
            if ($testFailAfter > 0 && $index === $testFailAfter) {
                throw new RuntimeException('Injected bootstrap failure after file ' . $path);
            }
        }

        verifyInstalledFiles($root, $sourceRoot, $states);
        lintPhpFiles($root, array_keys(BRIDGE_FILES));
        verifyRuntimeLoads($root);
        assertConfigUnchanged($configPath, $configHashBefore);

        atomicJsonWrite($bootstrapDir . '/enterprise-transition-success.json', [
            'status' => 'completed',
            'completed_at' => date(DATE_ATOM),
            'source_root' => $sourceRoot,
            'rollback_snapshot' => $snapshot,
            'config_sha256' => $configHashBefore,
            'files' => array_keys(BRIDGE_FILES),
        ]);

        info('BRIDGE INSTALLATION PASSED. config.php remained byte-for-byte unchanged.');
    } catch (Throwable $installError) {
        fwrite(STDERR, '[ERROR] Bridge installation failed: ' . $installError->getMessage() . PHP_EOL);
        rollbackSnapshot($root, $snapshot);
        verifyRollback($root, $states);
        assertConfigUnchanged($configPath, $configHashBefore);
        fwrite(STDERR, '[INFO] Exact updater rollback verified; config.php remained unchanged.' . PHP_EOL);
        throw $installError;
    }
} catch (Throwable $e) {
    fail($e->getMessage());
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

function inspectTargetFiles(string $sourceRoot): array
{
    $result = [];
    foreach (BRIDGE_FILES as $path => $spec) {
        $absolute = $sourceRoot . DIRECTORY_SEPARATOR . $path;
        if (!is_file($absolute) || is_link($absolute)) {
            throw new RuntimeException('Release package is missing required bridge file: ' . $path);
        }
        $contents = file_get_contents($absolute);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read release file ' . $path);
        }
        $result[$path] = gitBlobSha($contents);
    }
    return $result;
}

function inspectLiveFiles(string $root): array
{
    $result = [];
    foreach (BRIDGE_FILES as $path => $spec) {
        $absolute = $root . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($absolute)) {
            $result[$path] = ['exists' => false, 'blob' => null, 'mode' => null, 'uid' => null, 'gid' => null];
            continue;
        }
        if (!is_file($absolute) || is_link($absolute)) {
            throw new RuntimeException('Refusing non-regular updater file: ' . $path);
        }
        $contents = file_get_contents($absolute);
        $stat = stat($absolute);
        if (!is_string($contents) || $stat === false) {
            throw new RuntimeException('Unable to inspect updater file ' . $path);
        }
        $result[$path] = [
            'exists' => true,
            'blob' => gitBlobSha($contents),
            'mode' => $stat['mode'] & 0777,
            'uid' => (int)$stat['uid'],
            'gid' => (int)$stat['gid'],
        ];
    }
    return $result;
}

function validateLiveState(array $states, array $targets): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        $state = $states[$path];
        if (!$state['exists']) {
            if (!empty($spec['may_be_absent'])) {
                continue;
            }
            throw new RuntimeException('Required updater file is missing: ' . $path);
        }

        if ($state['blob'] === $targets[$path]) {
            continue;
        }

        if (!in_array($state['blob'], $spec['allowed_legacy'] ?? [], true)) {
            throw new RuntimeException(
                'Refusing unknown local modification to updater code ' . $path
                . ' (Git blob ' . $state['blob'] . '). config.php is intentionally not part of this check.'
            );
        }
    }
}

function installOne(string $root, string $sourceRoot, string $path, array $states): void
{
    $source = $sourceRoot . DIRECTORY_SEPARATOR . $path;
    $destination = $root . DIRECTORY_SEPARATOR . $path;
    ensureDirectory(dirname($destination));

    $state = $states[$path];
    if ($state['exists']) {
        $mode = (int)$state['mode'];
        $uid = (int)$state['uid'];
        $gid = (int)$state['gid'];
    } else {
        $reference = BRIDGE_FILES[$path]['metadata_reference'] ?? null;
        if (!is_string($reference) || empty($states[$reference]['exists'])) {
            throw new RuntimeException('No safe metadata reference exists for ' . $path);
        }
        $mode = (int)$states[$reference]['mode'];
        $uid = (int)$states[$reference]['uid'];
        $gid = (int)$states[$reference]['gid'];
    }

    $temp = dirname($destination) . '/.' . basename($destination) . '.bridge-' . bin2hex(random_bytes(4));
    if (!copy($source, $temp)) {
        throw new RuntimeException('Unable to prepare replacement for ' . $path);
    }

    try {
        if (!chmod($temp, $mode)) {
            throw new RuntimeException('Unable to preserve mode for ' . $path);
        }
        $stat = stat($temp);
        if ($stat === false) {
            throw new RuntimeException('Unable to inspect replacement metadata for ' . $path);
        }
        if ((int)$stat['uid'] !== $uid && (!function_exists('chown') || !@chown($temp, $uid))) {
            throw new RuntimeException('Unable to preserve owner for ' . $path);
        }
        if ((int)$stat['gid'] !== $gid && (!function_exists('chgrp') || !@chgrp($temp, $gid))) {
            throw new RuntimeException('Unable to preserve group for ' . $path);
        }
        if (!rename($temp, $destination)) {
            throw new RuntimeException('Atomic replacement failed for ' . $path);
        }
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
}

function verifyInstalledFiles(string $root, string $sourceRoot, array $states): void
{
    foreach (array_keys(BRIDGE_FILES) as $path) {
        $live = file_get_contents($root . '/' . $path);
        $source = file_get_contents($sourceRoot . '/' . $path);
        if (!is_string($live) || !is_string($source) || !hash_equals(gitBlobSha($source), gitBlobSha($live))) {
            throw new RuntimeException('Installed bridge file verification failed for ' . $path);
        }

        $stat = stat($root . '/' . $path);
        $reference = $states[$path]['exists']
            ? $states[$path]
            : $states[(string)BRIDGE_FILES[$path]['metadata_reference']];
        if ($stat === false
            || (($stat['mode'] & 0777) !== (int)$reference['mode'])
            || (int)$stat['uid'] !== (int)$reference['uid']
            || (int)$stat['gid'] !== (int)$reference['gid']
        ) {
            throw new RuntimeException('Installed metadata verification failed for ' . $path);
        }
    }
}

function createSnapshot(string $root, string $bootstrapDir, array $states): string
{
    $snapshot = $bootstrapDir . '/snapshot-' . gmdate('Ymd_His') . '-' . bin2hex(random_bytes(3));
    ensureDirectory($snapshot . '/original');

    foreach ($states as $path => $state) {
        if (!$state['exists']) continue;
        $target = $snapshot . '/original/' . $path;
        ensureDirectory(dirname($target));
        if (!copy($root . '/' . $path, $target)) {
            throw new RuntimeException('Unable to snapshot ' . $path);
        }
    }

    atomicJsonWrite($snapshot . '/manifest.json', ['files' => $states]);
    return $snapshot;
}

function rollbackSnapshot(string $root, string $snapshot): void
{
    $manifest = json_decode((string)file_get_contents($snapshot . '/manifest.json'), true);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
        throw new RuntimeException('Rollback snapshot manifest is unreadable.');
    }

    foreach (array_reverse(array_keys(BRIDGE_FILES)) as $path) {
        $state = $manifest['files'][$path] ?? null;
        if (!is_array($state)) throw new RuntimeException('Rollback metadata missing for ' . $path);
        $destination = $root . '/' . $path;

        if (empty($state['exists'])) {
            if (file_exists($destination) && !@unlink($destination)) {
                throw new RuntimeException('Unable to remove bridge-created file ' . $path);
            }
            continue;
        }

        $source = $snapshot . '/original/' . $path;
        $temp = dirname($destination) . '/.' . basename($destination) . '.rollback-' . bin2hex(random_bytes(4));
        if (!copy($source, $temp)) throw new RuntimeException('Unable to prepare rollback for ' . $path);
        @chmod($temp, (int)$state['mode']);
        if (function_exists('chown')) @chown($temp, (int)$state['uid']);
        if (function_exists('chgrp')) @chgrp($temp, (int)$state['gid']);
        if (!rename($temp, $destination)) {
            @unlink($temp);
            throw new RuntimeException('Unable to restore ' . $path);
        }
    }
}

function verifyRollback(string $root, array $states): void
{
    foreach ($states as $path => $state) {
        $absolute = $root . '/' . $path;
        if (!$state['exists']) {
            if (file_exists($absolute)) throw new RuntimeException('Rollback left new file behind: ' . $path);
            continue;
        }
        $contents = file_get_contents($absolute);
        $stat = stat($absolute);
        if (!is_string($contents) || $stat === false
            || gitBlobSha($contents) !== $state['blob']
            || (($stat['mode'] & 0777) !== (int)$state['mode'])
            || (int)$stat['uid'] !== (int)$state['uid']
            || (int)$stat['gid'] !== (int)$state['gid']
        ) {
            throw new RuntimeException('Rollback verification failed for ' . $path);
        }
    }
}

function verifyRuntimeLoads(string $root): void
{
    $code = 'require ' . var_export($root . '/lib/path.php', true) . ';'
        . 'if (!function_exists("base_path")) exit(11);'
        . 'echo "runtime-ok\\n";';
    $result = runCommand([PHP_BINARY ?: 'php', '-r', $code]);
    if (!str_contains($result, 'runtime-ok')) {
        throw new RuntimeException('Enterprise runtime verification failed.');
    }
}

function assertConfigUnchanged(string $path, string $expectedHash): void
{
    $actual = hash_file('sha256', $path);
    if (!is_string($actual) || !hash_equals($expectedHash, $actual)) {
        throw new RuntimeException('SAFETY INVARIANT FAILED: config.php changed during the bridge.');
    }
}

function assertNoActiveSystemOperation(string $root): void
{
    if (is_file($root . '/storage/upgrades/maintenance.flag')) {
        throw new RuntimeException('Refusing bridge while maintenance mode is active.');
    }
    foreach (glob($root . '/storage/upgrades/jobs/*.json') ?: [] as $jobFile) {
        $job = json_decode((string)file_get_contents($jobFile), true);
        if (is_array($job) && in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
            throw new RuntimeException('Refusing bridge while a system job is active: ' . basename($jobFile));
        }
    }
}

function assertNativeTools(): void
{
    if (findExecutable(['mysqldump', 'mariadb-dump']) === null) {
        throw new RuntimeException('Native dump utility is missing (mysqldump or mariadb-dump).');
    }
    if (findExecutable(['mysql', 'mariadb']) === null) {
        throw new RuntimeException('Native restore utility is missing (mysql or mariadb).');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open is required.');
    }
}

function proveStagingPrivileges(string $root): void
{
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PDO MySQL extension is required.');

    $env = readEnvironment($root . '/.env');
    $get = static function (string $name, string $default = '') use ($env): string {
        $runtime = getenv($name);
        if ($runtime !== false && $runtime !== '') return (string)$runtime;
        return isset($env[$name]) && $env[$name] !== '' ? (string)$env[$name] : $default;
    };

    $host = $get('DB_HOST', '127.0.0.1');
    $port = (int)$get('DB_PORT', '3306');
    $dbName = $get('DB_NAME', 'epss_v300');
    $appUser = $get('DB_USER');
    $appPass = $get('DB_PASS');
    $adminUser = $get('DB_ADMIN_USER', $appUser);
    $adminPass = $get('DB_ADMIN_PASS', $appPass);
    if ($appUser === '' || $adminUser === '') throw new RuntimeException('DB_USER and a staging-capable DB_ADMIN_USER/DB_USER are required.');

    $dsnBase = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    $pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $appLive = new PDO($dsnBase . ';dbname=' . $dbName, $appUser, $appPass, $pdoOptions);
    $currentUser = (string)$appLive->query('SELECT CURRENT_USER()')->fetchColumn();
    $admin = new PDO($dsnBase, $adminUser, $adminPass, $pdoOptions);

    $candidate = 'cas_bridge_preflight_' . bin2hex(random_bytes(5));
    $quotedDb = '`' . str_replace('`', '``', $candidate) . '`';

    try {
        $admin->exec('CREATE DATABASE ' . $quotedDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $appCandidate = new PDO($dsnBase . ';dbname=' . $candidate, $appUser, $appPass, $pdoOptions);
            $appCandidate->query('SELECT 1');
        } catch (Throwable $e) {
            if (!preg_match('/^([^@]+)@(.+)$/', $currentUser, $m)) {
                throw new RuntimeException('Unable to determine application database account.');
            }
            $admin->exec('GRANT ALL PRIVILEGES ON ' . $quotedDb . '.* TO ' . $admin->quote($m[1]) . '@' . $admin->quote($m[2]));
            $appCandidate = new PDO($dsnBase . ';dbname=' . $candidate, $appUser, $appPass, $pdoOptions);
        }
        $appCandidate->exec('CREATE TABLE bridge_probe (id INT PRIMARY KEY, v INT NULL)');
        $appCandidate->exec('INSERT INTO bridge_probe (id, v) VALUES (1, 1)');
        $appCandidate->exec('ALTER TABLE bridge_probe ADD COLUMN note VARCHAR(10) NULL');
        $appCandidate->exec('CREATE INDEX bridge_probe_idx ON bridge_probe (v)');
        $appCandidate->exec('CREATE VIEW bridge_probe_view AS SELECT id FROM bridge_probe');
        $appCandidate->exec(
            'CREATE TRIGGER bridge_probe_trigger BEFORE INSERT ON bridge_probe '
            . 'FOR EACH ROW SET NEW.v = COALESCE(NEW.v, 0)'
        );
        $appCandidate->exec('CREATE PROCEDURE bridge_probe_proc() SELECT 1');
        $appCandidate->exec(
            'CREATE EVENT bridge_probe_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY '
            . 'DO INSERT INTO bridge_probe (id, v) VALUES (999999, 1)'
        );
        if ((int)$appCandidate->query('SELECT COUNT(*) FROM bridge_probe')->fetchColumn() !== 1) {
            throw new RuntimeException('Staging application account restore-capability verification failed.');
        }
    } finally {
        $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDb);
    }
}

function readEnvironment(string $path): array
{
    if (!is_file($path)) return [];
    $result = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') $result[$key] = $value;
    }
    return $result;
}

function lintPhpFiles(string $root, array $paths): void
{
    foreach ($paths as $path) {
        $file = $root . '/' . $path;
        $result = runCommand([PHP_BINARY ?: 'php', '-l', $file]);
        if (!str_contains($result, 'No syntax errors detected')) {
            throw new RuntimeException('PHP syntax validation failed for ' . $path);
        }
    }
}

function gitBlobSha(string $content): string
{
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

function findExecutable(array $names): ?string
{
    foreach ($names as $name) {
        foreach (['/usr/bin', '/usr/local/bin', '/bin'] as $dir) {
            $path = $dir . '/' . $name;
            if (is_file($path) && is_executable($path)) return $path;
        }
    }
    return null;
}

function runCommand(array $command): string
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start command.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException('Command failed: ' . trim($stderr ?: $stdout));
    return $stdout;
}

function atomicJsonWrite(string $path, array $payload): void
{
    ensureDirectory(dirname($path));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Unable to encode JSON.');
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write ' . $path);
    }
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory ' . $path);
    }
}

function info(string $message): void
{
    fwrite(STDOUT, '[INFO] ' . $message . PHP_EOL);
}

function fail(string $message): void
{
    fwrite(STDERR, '[ERROR] ' . $message . PHP_EOL);
    exit(1);
}
