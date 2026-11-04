#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-time bridge from legacy CAS 0.5.95-0.5.98 to the enterprise updater.
 *
 * Contract:
 * - source files come only from a complete release archive supplied with --source-root;
 * - config.php and .env are site-owned and are never modified or version-checked;
 * - the live application database is never modified;
 * - staging privileges are proven with a throwaway database;
 * - updater/runtime files are replaced atomically with owner/group/mode preserved;
 * - any failed post-install check rolls those files back exactly.
 */

const BRIDGE_FILES = [
    'lib/enterprise_runtime.php' => [
        'may_be_absent' => true,
        'metadata_reference' => 'lib/path.php',
    ],
    'admin/upgrade.php' => [],
    'lib/system_backup.php' => [],
    'lib/upgrade.php' => [],
    'scripts/system_upgrade.php' => [],
    'scripts/system_job.php' => [
        'may_be_absent' => true,
        'metadata_reference' => 'scripts/system_upgrade.php',
    ],
    // Activate the runtime hook last.
    'lib/path.php' => [],
]

$options = getopt('', [
    'root::',
    'source-root:',
    'check',
    'apply',
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

$sourceRootInput = trim((string)($options['source-root'] ?? ''));
if ($sourceRootInput === '') {
    fail('--source-root is required and must point to the extracted complete CAS release archive.');
}
$sourceRoot = realpath($sourceRootInput);
if ($sourceRoot === false || !is_dir($sourceRoot)) {
    fail('--source-root does not exist.');
}
$sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);

$testFailAfter = isset($options['test-fail-after']) ? (int)$options['test-fail-after'] : 0;
if ($testFailAfter > 0 && getenv('CAS_BOOTSTRAP_TEST_MODE') !== '1') {
    fail('--test-fail-after is available only in CAS_BOOTSTRAP_TEST_MODE.');
}

foreach (['config.php', 'admin/upgrade.php', 'lib/path.php', 'lib/upgrade.php', 'scripts/system_upgrade.php'] as $path) {
    if (!is_file($root . DIRECTORY_SEPARATOR . $path)) {
        fail('Not a CAS application root; missing ' . $path);
    }
}
foreach (array_keys(BRIDGE_FILES) as $path) {
    if (!is_file($sourceRoot . DIRECTORY_SEPARATOR . $path)) {
        fail('Release archive is incomplete; missing ' . $path);
    }
}

$protected = captureProtectedFiles($root);

$storage = $root . '/storage/upgrades';
$bootstrapDir = $storage . '/bootstrap';
ensureDirectory($bootstrapDir);

$lockPath = $bootstrapDir . '/enterprise-transition.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fail('Another enterprise transition is already active.');
}

try {
    assertProtectedFilesUnchanged($root, $protected);
    assertNoActiveSystemOperation($root);

    $sourceHashes = inspectSourceFiles($sourceRoot);
    $states = inspectLiveFiles($root);
    validateUpdaterState($states);

    info('Checking native MySQL/MariaDB client tools...');
    assertNativeTools();

    info('Proving staging privileges with an isolated throwaway database...');
    proveStagingPrivileges($root);
    info('Staging privilege check passed; the live application database was not modified.');

    lintPhpFiles($sourceRoot, array_keys(BRIDGE_FILES));
    verifySourceRuntime($sourceRoot);
    assertProtectedFilesUnchanged($root, $protected);

    if (!$apply) {
        info('CHECK PASSED. config.php, .env, application files, and the live database were not changed.');
        exit(0);
    }

    $snapshot = createSnapshot($root, $bootstrapDir, $states);
    info('Updater rollback snapshot created at ' . $snapshot);

    try {
        $index = 0;
        foreach (BRIDGE_FILES as $path => $spec) {
            $index++;
            installOne($root, $sourceRoot, $path, $states);

            if ($testFailAfter > 0 && $index === $testFailAfter) {
                throw new RuntimeException('Injected bridge failure after ' . $path);
            }
        }

        verifyInstalledFiles($root, $sourceHashes, $states);
        lintPhpFiles($root, array_keys(BRIDGE_FILES));
        verifyInstalledRuntime($root);
        assertProtectedFilesUnchanged($root, $protected);

        atomicJsonWrite($bootstrapDir . '/enterprise-transition-success.json', [
            'status' => 'completed',
            'completed_at' => date(DATE_ATOM),
            'rollback_snapshot' => $snapshot,
            'files' => array_keys(BRIDGE_FILES),
            'config_sha256' => $protected['config.php']['sha256'],
            'env_sha256' => $protected['.env']['sha256'] ?? null,
        ]);

        info('BRIDGE INSTALLATION PASSED.');
        info('config.php and .env are byte-identical to their pre-bridge state.');
        info('The live application database and installed-release metadata were not changed.');
    } catch (Throwable $installError) {
        fwrite(STDERR, '[ERROR] Bridge installation failed: ' . $installError->getMessage() . PHP_EOL);
        rollbackSnapshot($root, $snapshot);
        verifyRollback($root, $states);
        assertProtectedFilesUnchanged($root, $protected);
        fwrite(STDERR, '[INFO] Rollback verification passed; updater/runtime files were restored exactly.' . PHP_EOL);
        throw $installError;
    }
} catch (Throwable $e) {
    fail($e->getMessage());
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

function captureProtectedFiles(string $root): array
{
    $result = [];
    foreach (['config.php', '.env'] as $path) {
        $absolute = $root . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($absolute)) {
            if ($path === 'config.php') {
                throw new RuntimeException('config.php is missing.');
            }
            $result[$path] = ['exists' => false, 'sha256' => null];
            continue;
        }
        if (!is_file($absolute) || is_link($absolute)) {
            throw new RuntimeException('Protected site file is not a regular file: ' . $path);
        }
        $hash = hash_file('sha256', $absolute);
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash protected site file: ' . $path);
        }
        $result[$path] = ['exists' => true, 'sha256' => $hash];
    }
    return $result;
}

function assertProtectedFilesUnchanged(string $root, array $protected): void
{
    foreach ($protected as $path => $state) {
        $absolute = $root . DIRECTORY_SEPARATOR . $path;
        if (!(bool)$state['exists']) {
            if (file_exists($absolute)) {
                throw new RuntimeException('Protected site file was unexpectedly created: ' . $path);
            }
            continue;
        }
        if (!is_file($absolute)) {
            throw new RuntimeException('Protected site file disappeared: ' . $path);
        }
        $hash = hash_file('sha256', $absolute);
        if (!is_string($hash) || !hash_equals((string)$state['sha256'], $hash)) {
            throw new RuntimeException('Protected site file changed unexpectedly: ' . $path);
        }
    }
}

function inspectSourceFiles(string $sourceRoot): array
{
    $hashes = [];
    foreach (array_keys(BRIDGE_FILES) as $path) {
        $absolute = $sourceRoot . DIRECTORY_SEPARATOR . $path;
        $content = file_get_contents($absolute);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read release source file ' . $path);
        }
        $hashes[$path] = gitBlobSha($content);
    }
    return $hashes;
}

function inspectLiveFiles(string $root): array
{
    $states = [];
    foreach (BRIDGE_FILES as $path => $spec) {
        $absolute = $root . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($absolute)) {
            $states[$path] = ['exists' => false, 'blob' => null, 'mode' => null, 'uid' => null, 'gid' => null];
            continue;
        }
        if (!is_file($absolute) || is_link($absolute)) {
            throw new RuntimeException('Refusing to bridge non-regular updater file: ' . $path);
        }
        $stat = stat($absolute);
        $content = file_get_contents($absolute);
        if ($stat === false || !is_string($content)) {
            throw new RuntimeException('Unable to inspect updater file ' . $path);
        }
        $states[$path] = [
            'exists' => true,
            'blob' => gitBlobSha($content),
            'mode' => $stat['mode'] & 0777,
            'uid' => $stat['uid'],
            'gid' => $stat['gid'],
        ];
    }
    return $states;
}

function validateUpdaterState(array $states): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        if (!$states[$path]['exists'] && empty($spec['may_be_absent'])) {
            throw new RuntimeException('Required updater file is missing: ' . $path);
        }
    }
}

function assertNoActiveSystemOperation(string $root): void
{
    foreach ([
        $root . '/storage/upgrades/maintenance.flag',
        $root . '/storage/upgrades/active_database.json',
    ] as $path) {
        if (is_file($path)) {
            throw new RuntimeException('Enterprise runtime state already exists: ' . substr($path, strlen($root) + 1));
        }
    }

    foreach (glob($root . '/storage/upgrades/jobs/*.json') ?: [] as $jobFile) {
        $job = json_decode((string)file_get_contents($jobFile), true);
        if (is_array($job) && in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
            throw new RuntimeException('A system job is already active: ' . basename($jobFile));
        }
    }
}

function createSnapshot(string $root, string $bootstrapDir, array $states): string
{
    $snapshot = $bootstrapDir . '/snapshot-' . gmdate('Ymd_His') . '-' . bin2hex(random_bytes(3));
    ensureDirectory($snapshot . '/original');

    foreach ($states as $path => $state) {
        if (!$state['exists']) {
            continue;
        }
        $source = $root . DIRECTORY_SEPARATOR . $path;
        $target = $snapshot . '/original/' . $path;
        ensureDirectory(dirname($target));
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to snapshot updater file ' . $path);
        }
    }

    atomicJsonWrite($snapshot . '/manifest.json', ['created_at' => date(DATE_ATOM), 'files' => $states]);
    return $snapshot;
}

function installOne(string $root, string $sourceRoot, string $path, array $states): void
{
    $source = $sourceRoot . DIRECTORY_SEPARATOR . $path;
    $destination = $root . DIRECTORY_SEPARATOR . $path;
    ensureDirectory(dirname($destination));

    $state = $states[$path];
    if ($state['exists']) {
        $metadata = $state;
    } else {
        $reference = BRIDGE_FILES[$path]['metadata_reference'] ?? null;
        if (!is_string($reference) || !isset($states[$reference]) || !$states[$reference]['exists']) {
            throw new RuntimeException('No metadata reference exists for new updater file ' . $path);
        }
        $metadata = $states[$reference];
    }

    $temp = dirname($destination) . '/.' . basename($destination) . '.bridge-' . bin2hex(random_bytes(4));
    if (!copy($source, $temp)) {
        throw new RuntimeException('Unable to prepare updater file ' . $path);
    }

    try {
        if (!chmod($temp, (int)$metadata['mode'])) {
            throw new RuntimeException('Unable to preserve mode for ' . $path);
        }

        $stat = stat($temp);
        if ($stat === false) {
            throw new RuntimeException('Unable to inspect staged updater file ' . $path);
        }

        if ((int)$stat['uid'] !== (int)$metadata['uid'] && (!function_exists('chown') || !@chown($temp, (int)$metadata['uid']))) {
            throw new RuntimeException('Unable to preserve owner for ' . $path);
        }
        if ((int)$stat['gid'] !== (int)$metadata['gid'] && (!function_exists('chgrp') || !@chgrp($temp, (int)$metadata['gid']))) {
            throw new RuntimeException('Unable to preserve group for ' . $path);
        }

        if (!rename($temp, $destination)) {
            throw new RuntimeException('Atomic updater replacement failed for ' . $path);
        }
    } finally {
        if (is_file($temp)) {
            @unlink($temp);
        }
    }
}

function verifyInstalledFiles(string $root, array $sourceHashes, array $states): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        $absolute = $root . DIRECTORY_SEPARATOR . $path;
        $content = file_get_contents($absolute);
        if (!is_string($content) || !hash_equals((string)$sourceHashes[$path], gitBlobSha($content))) {
            throw new RuntimeException('Installed updater hash verification failed for ' . $path);
        }

        $stat = stat($absolute);
        if ($stat === false) {
            throw new RuntimeException('Unable to inspect installed updater file ' . $path);
        }

        $metadata = $states[$path]['exists']
            ? $states[$path]
            : $states[(string)(BRIDGE_FILES[$path]['metadata_reference'] ?? '')];

        if (($stat['mode'] & 0777) !== (int)$metadata['mode']
            || (int)$stat['uid'] !== (int)$metadata['uid']
            || (int)$stat['gid'] !== (int)$metadata['gid']
        ) {
            throw new RuntimeException('Installed updater metadata verification failed for ' . $path);
        }
    }
}

function rollbackSnapshot(string $root, string $snapshot): void
{
    $manifest = json_decode((string)file_get_contents($snapshot . '/manifest.json'), true);
    $states = is_array($manifest) && is_array($manifest['files'] ?? null) ? $manifest['files'] : null;
    if (!is_array($states)) {
        throw new RuntimeException('Updater rollback snapshot is unreadable.');
    }

    foreach (array_reverse(array_keys(BRIDGE_FILES)) as $path) {
        $state = $states[$path] ?? null;
        if (!is_array($state)) {
            throw new RuntimeException('Updater rollback metadata is missing for ' . $path);
        }

        $destination = $root . DIRECTORY_SEPARATOR . $path;
        if (empty($state['exists'])) {
            if (file_exists($destination) && !@unlink($destination)) {
                throw new RuntimeException('Unable to remove bridge-created file ' . $path);
            }
            continue;
        }

        $source = $snapshot . '/original/' . $path;
        $temp = dirname($destination) . '/.' . basename($destination) . '.rollback-' . bin2hex(random_bytes(4));
        if (!copy($source, $temp)) {
            throw new RuntimeException('Unable to prepare rollback for ' . $path);
        }
        @chmod($temp, (int)$state['mode']);
        if (function_exists('chown')) @chown($temp, (int)$state['uid']);
        if (function_exists('chgrp')) @chgrp($temp, (int)$state['gid']);
        if (!rename($temp, $destination)) {
            @unlink($temp);
            throw new RuntimeException('Unable to restore updater file ' . $path);
        }
    }
}

function verifyRollback(string $root, array $states): void
{
    foreach ($states as $path => $state) {
        $absolute = $root . DIRECTORY_SEPARATOR . $path;
        if (!$state['exists']) {
            if (file_exists($absolute)) {
                throw new RuntimeException('Rollback left bridge-created file behind: ' . $path);
            }
            continue;
        }

        $content = file_get_contents($absolute);
        $stat = stat($absolute);
        if (!is_string($content) || $stat === false
            || gitBlobSha($content) !== $state['blob']
            || (($stat['mode'] & 0777) !== (int)$state['mode'])
            || (int)$stat['uid'] !== (int)$state['uid']
            || (int)$stat['gid'] !== (int)$state['gid']
        ) {
            throw new RuntimeException('Rollback verification failed for ' . $path);
        }
    }
}

function lintPhpFiles(string $root, array $paths): void
{
    foreach ($paths as $path) {
        $result = runCommand([PHP_BINARY ?: 'php', '-l', $root . DIRECTORY_SEPARATOR . $path]);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('PHP syntax check failed for ' . $path . ': ' . trim($result['stderr']));
        }
    }
}

function verifySourceRuntime(string $sourceRoot): void
{
    verifyRuntimePath($sourceRoot);
}

function verifyInstalledRuntime(string $root): void
{
    verifyRuntimePath($root);
}

function verifyRuntimePath(string $root): void
{
    $code = 'define("BASE_PATH",' . var_export($root, true) . ');'
        . 'define("BASE_URL","/");'
        . 'require ' . var_export($root . '/lib/path.php', true) . ';'
        . 'if (!function_exists("cas_enterprise_runtime_apply")) exit(11);'
        . 'echo "runtime-ok\\\\n";';
    $result = runCommand([PHP_BINARY ?: 'php', '-r', $code]);
    if ($result['exit'] !== 0 || strpos($result['stdout'], 'runtime-ok') === false) {
        throw new RuntimeException('Enterprise runtime hook failed isolated load verification: ' . trim($result['stderr']));
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
        throw new RuntimeException('PHP proc_open is required for background system jobs.');
    }
}

function proveStagingPrivileges(string $root): void
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('PDO MySQL extension is required.');
    }

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

    if ($appUser === '' || $adminUser === '') {
        throw new RuntimeException('DB_USER and a staging-capable DB_ADMIN_USER (or DB_USER fallback) are required.');
    }

    $dsnBase = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $appLive = new PDO($dsnBase . ';dbname=' . $dbName, $appUser, $appPass, $pdoOptions);
    $currentUser = (string)$appLive->query('SELECT CURRENT_USER()')->fetchColumn();
    if ($currentUser === '' || strpos($currentUser, '@') === false) {
        throw new RuntimeException('Unable to determine the application database account.');
    }

    $admin = new PDO($dsnBase, $adminUser, $adminPass, $pdoOptions);
    $candidate = 'cas_bridge_preflight_' . bin2hex(random_bytes(5));
    $tick = chr(96);
    $quotedDb = $tick . str_replace($tick, $tick . $tick, $candidate) . $tick;

    try {
        $admin->exec('CREATE DATABASE ' . $quotedDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            $appCandidate = new PDO($dsnBase . ';dbname=' . $candidate, $appUser, $appPass, $pdoOptions);
            $appCandidate->query('SELECT 1')->fetchColumn();
        } catch (Throwable $initialAccessError) {
            [$accountUser, $accountHost] = explode('@', $currentUser, 2);
            $admin->exec(
                'GRANT ALL PRIVILEGES ON ' . $quotedDb . '.* TO '
                . $admin->quote($accountUser) . '@' . $admin->quote($accountHost)
            );
            $appCandidate = new PDO($dsnBase . ';dbname=' . $candidate, $appUser, $appPass, $pdoOptions);
        }

        $appCandidate->exec('CREATE TABLE bridge_probe (id INT PRIMARY KEY)');
        $appCandidate->exec('INSERT INTO bridge_probe (id) VALUES (1)');
        if ((int)$appCandidate->query('SELECT COUNT(*) FROM bridge_probe')->fetchColumn() !== 1) {
            throw new RuntimeException('Application staging database write test failed.');
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
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#' || strpos($trimmed, '=') === false) continue;
        [$key, $value] = explode('=', $trimmed, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2
            && (($value[0] === '"' && $value[strlen($value)-1] === '"')
                || ($value[0] === "'" && $value[strlen($value)-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') $result[$key] = $value;
    }
    return $result;
}

function runCommand(array $command): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

function findExecutable(array $names): ?string
{
    foreach ($names as $name) {
        foreach (['/usr/bin', '/usr/local/bin', '/bin'] as $directory) {
            $candidate = $directory . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) return $candidate;
        }
        $output = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0])) return (string)$output[0];
    }
    return null;
}

function gitBlobSha(string $content): string
{
    return sha1('blob ' . strlen($content) . "\\0" . $content);
}

function atomicJsonWrite(string $path, array $payload): void
{
    ensureDirectory(dirname($path));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Unable to encode bridge JSON.');
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write bridge state.');
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
