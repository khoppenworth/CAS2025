#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-time bridge from the legacy 0.5.95-0.5.101 updater to the simplified
 * enterprise updater. It never installs, patches, or hash-requires config.php.
 *
 * Default mode is --check. --source-root must be the extracted official release.
 */

const BRIDGE_TARGET_REF = '0.5.102';

const BRIDGE_FILES = [
    'admin/upgrade.php' => [
        'target' => 'e728cf5262452782cb18ddb9e6f28d3015f66207',
        'allowed' => [
            'afceaa03265bd76e6d16f14bcbfac8ea5ba5144c',
            'a97038b94921bcc75c6d4f31c9e6b286b0b9e771',
            'f0ac1b4e5912fd7a9503bfe028fa4949a0a65d7f',
            'e728cf5262452782cb18ddb9e6f28d3015f66207',
        ],
    ],
    'lib/system_backup.php' => [
        'target' => 'a17d99bc03ea9b66b17eda5801169cef5b3f31e0',
        'allowed' => [
            '48c85a53283aa515ccce0fa9e84f4e02ef8112e8',
            '705af28f16565dfc97acf0142cefb0daf69419fa',
            '6d24268acba4f40ef3d872a9a3bbffd74e67985a',
            'c42f2659aa4e8e3d3116d0c5159597cc7616124f',
            '34138ce19c94519bf39862b8efeb9a7a447eb3e4',
            '907556f6c0afb33741230c29399f36caa4d6fb72',
            'e901fb9aed43a8ed0981fe7008498cc29f9d2e5e',
            '9e591ec38ee78cff0a0149b602431552fd2199bc',
            'd50d952248b8d78739bb14b51844c99dc81c3c34',
            'e83069e3a56947a9aa3df5b291e6c0e9e7e62f5f',
            'fab8e6f267638977416209dee78b00a523f725f8',
            '2210f1fbf6184cf9c78eed70fd7939ecf5e642d4',
            'd994975996108445fa79bb57b8dda2312e97be9a',
            'a17d99bc03ea9b66b17eda5801169cef5b3f31e0',
        ],
    ],
    'lib/upgrade.php' => [
        'target' => 'a6bfec17e4692139c6e5d998dc109e7b1591bf8a',
        'allowed' => [
            'a240b32ed60106ebf564ce01df7ad94ce1134265',
            '96993ce9de7f1d9c4a896ae9453dbc909bc5ee8b',
            'f0d50481c0a80c474117c5a990401940b330607e',
            '2fe2499b10fec014cadafa7938a5dea89f878eb9',
            '0e16902b865a95fba2fa833896a966fb9592c344',
            'b02513ea8042e5543912bc6d1df58c3ce946c15a',
            'a6bfec17e4692139c6e5d998dc109e7b1591bf8a',
        ],
    ],
    'lib/path.php' => [
        'target' => '0d253ad666db3882bf7c1445c1f8e27183f141cf',
        'allowed' => [
            '1c350a4d4132ec695699db696ff6f6093efcebdb',
            '0d253ad666db3882bf7c1445c1f8e27183f141cf',
        ],
    ],
    'lib/enterprise_runtime.php' => [
        'target' => 'fffa004790a197127775d7f09ef42d9adbfb978b',
        'allowed' => ['66b524da32af90b5939d5120dedd84c3655c2453', 'fffa004790a197127775d7f09ef42d9adbfb978b'],
        'may_be_absent' => true,
        'metadata_reference' => 'lib/path.php',
    ],
    'lib/system_cli_bootstrap.php' => [
        'target' => 'b3f79991c7cd17a7b35b90c5eb91afb67d36d4bf',
        'allowed' => ['b3f79991c7cd17a7b35b90c5eb91afb67d36d4bf'],
        'may_be_absent' => true,
        'metadata_reference' => 'lib/path.php',
    ],
    'scripts/system_upgrade.php' => [
        'target' => '4c2e328ada59ac858d1a9269816c01b00f5cc359',
        'allowed' => [
            'c0a445961b73086b5e8534eb0da3fa830de6d4cc',
            'c646a5c5774f72663fd31e33157bb9ea641b1baf',
            '49e342b95719ce02c26cb6e2daf561667a4714c9',
            'f72c68d862daa86d44d2edb9fbc725189dcfd969',
            '138c7ec57eb1734d7db2252ec4ae697b15a5e5f9',
            '6acc8e85fc73c77e4875e6ecbbc60c2d057f4030',
            '38d4167ffa8b466ee8c2d501af9b88b69f5fc43e',
            'e5055de45acf6af9bb5449fdae88b3ab446d7803',
            '4c2e328ada59ac858d1a9269816c01b00f5cc359',
        ],
    ],
    'scripts/system_job.php' => [
        'target' => '0e69795a74dc23bb0745c1dffb46795b3b5ca13e',
        'allowed' => ['b54dc2d0c46c765490ac8af8b30ceaf05371d3a7', '0e69795a74dc23bb0745c1dffb46795b3b5ca13e'],
        'may_be_absent' => true,
        'metadata_reference' => 'scripts/system_upgrade.php',
    ],
    'scripts/system_healthcheck.php' => [
        'target' => 'e47e40d7113f070a9b80c0978923c2d28149fb4b',
        'allowed' => ['197c8ef2544f217a8ec2947938c48361740c9b24', 'e47e40d7113f070a9b80c0978923c2d28149fb4b'],
        'may_be_absent' => true,
        'metadata_reference' => 'scripts/system_upgrade.php',
    ],
];

$options = getopt('', ['root::', 'source-root:', 'check', 'apply', 'test-fail-after::']);
$apply = isset($options['apply']);
if ($apply && isset($options['check'])) fail('Choose either --check or --apply.');

$root = realpath((string)($options['root'] ?? getcwd()));
$sourceRoot = realpath((string)($options['source-root'] ?? ''));
if ($root === false || !is_dir($root)) fail('CAS root does not exist.');
if ($sourceRoot === false || !is_dir($sourceRoot)) fail('--source-root must be the extracted official release.');
$root = rtrim($root, DIRECTORY_SEPARATOR);
$sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);

foreach (['config.php', 'admin/upgrade.php', 'lib/path.php', 'scripts/system_upgrade.php'] as $required) {
    if (!is_file($root . '/' . $required)) fail('Not a CAS root; missing ' . $required);
}

$configBefore = sha256File($root . '/config.php');
$installedBefore = is_file($root . '/storage/upgrades/installed.json')
    ? sha256File($root . '/storage/upgrades/installed.json')
    : null;

$storage = $root . '/storage/upgrades';
$bootstrap = $storage . '/bootstrap';
ensureDirectory($bootstrap);
$lock = fopen($bootstrap . '/enterprise-transition.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) fail('Another enterprise transition is active.');

$testFailAfter = isset($options['test-fail-after']) ? (int)$options['test-fail-after'] : 0;
if ($testFailAfter > 0 && getenv('CAS_BOOTSTRAP_TEST_MODE') !== '1') fail('--test-fail-after is test-only.');

try {
    $errors = [];
    $states = [];

    check($errors, 'active operation state', static fn() => assertNoActiveSystemOperation($root));
    check($errors, 'legacy updater files', function () use ($root, &$states): void {
        $states = inspectLiveFiles($root);
        validateKnownLegacyState($states);
    });
    $staging = createTempDirectory($bootstrap, 'target_');
    try {
        check($errors, 'release updater hashes', static fn() => stageTargetFiles($sourceRoot, $staging));
        check($errors, 'release PHP syntax', static fn() => lintPhpFiles($staging, array_keys(BRIDGE_FILES)));
        check(
            $errors,
            'full live backup round-trip',
            static fn() => verifyFullBackupRoundTrip($root, $staging)
        );

        if (sha256File($root . '/config.php') !== $configBefore) {
            $errors[] = 'config.php changed during preflight even though the bridge never writes it.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) fwrite(STDERR, '[ERROR] ' . $error . PHP_EOL);
            fail('PREFLIGHT FAILED with ' . count($errors) . ' issue(s). No updater files were changed.');
        }

        info('PREFLIGHT PASSED.');
        info('config.php SHA-256: ' . $configBefore . ' (protected; never installed by the bridge)');
        info('Updater hashes, PHP syntax, and a full native live-DB backup/temporary-restore round-trip all passed.');

        if (!$apply) {
            info('CHECK PASSED. No live application files were changed.');
            exit(0);
        }

        $snapshot = createSnapshot($root, $bootstrap, $states, $configBefore, $installedBefore);
        info('Rollback snapshot: ' . $snapshot);

        try {
            $index = 0;
            foreach (BRIDGE_FILES as $path => $spec) {
                $index++;
                installOne($root, $staging, $path, $states);
                if ($testFailAfter > 0 && $index === $testFailAfter) {
                    throw new RuntimeException('Injected bridge failure after ' . $path);
                }
            }

            verifyInstalledFiles($root);
            lintPhpFiles($root, array_keys(BRIDGE_FILES));
            verifyRuntimeLoads($root);

            if (sha256File($root . '/config.php') !== $configBefore) {
                throw new RuntimeException('Protected config.php changed during bridge installation.');
            }
            $installedAfter = is_file($root . '/storage/upgrades/installed.json')
                ? sha256File($root . '/storage/upgrades/installed.json')
                : null;
            if ($installedAfter !== $installedBefore) {
                throw new RuntimeException('Installed-release metadata changed during bridge installation.');
            }

            info('BRIDGE INSTALLATION PASSED.');
            info('config.php remained byte-identical: ' . $configBefore);
            info('The live database and installed release metadata were not changed.');
        } catch (Throwable $e) {
            fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
            rollbackSnapshot($root, $snapshot);
            verifyRollback($root, $states, $configBefore, $installedBefore);
            fwrite(STDERR, '[INFO] Exact bridge rollback verified.' . PHP_EOL);
            throw $e;
        }
    } finally {
        removeDirectory($staging);
    }
} catch (Throwable $e) {
    fail($e->getMessage());
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

function check(array &$errors, string $label, callable $fn): void
{
    try { $fn(); } catch (Throwable $e) { $errors[] = $label . ': ' . $e->getMessage(); }
}

function inspectLiveFiles(string $root): array
{
    $states = [];
    foreach (BRIDGE_FILES as $path => $spec) {
        $file = $root . '/' . $path;
        if (!file_exists($file)) {
            $states[$path] = ['exists' => false];
            continue;
        }
        if (!is_file($file) || is_link($file)) throw new RuntimeException('Refusing non-regular updater file ' . $path);
        $stat = stat($file);
        $content = file_get_contents($file);
        if ($stat === false || !is_string($content)) throw new RuntimeException('Unable to inspect ' . $path);
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

function validateKnownLegacyState(array $states): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        $state = $states[$path] ?? ['exists' => false];
        if (empty($state['exists'])) {
            if (!empty($spec['may_be_absent'])) continue;
            throw new RuntimeException('Required updater file is missing: ' . $path);
        }
        if (!in_array((string)$state['blob'], $spec['allowed'], true)) {
            throw new RuntimeException('Unknown/local modification in updater code: ' . $path
                . ' (' . $state['blob'] . '). config.php is intentionally exempt; updater code is not.');
        }
    }
}

function stageTargetFiles(string $sourceRoot, string $staging): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        $source = $sourceRoot . '/' . $path;
        if (!is_file($source)) throw new RuntimeException('Release is missing ' . $path);
        $content = file_get_contents($source);
        if (!is_string($content)) throw new RuntimeException('Unable to read release file ' . $path);
        $actual = gitBlobSha($content);
        if (!hash_equals((string)$spec['target'], $actual)) {
            throw new RuntimeException('Release hash mismatch for ' . $path
                . '; expected ' . $spec['target'] . ', got ' . $actual);
        }
        $target = $staging . '/' . $path;
        ensureDirectory(dirname($target));
        if (file_put_contents($target, $content, LOCK_EX) === false) throw new RuntimeException('Unable to stage ' . $path);
    }
}

function createSnapshot(string $root, string $bootstrap, array $states, string $configHash, ?string $installedHash): string
{
    $snapshot = $bootstrap . '/snapshot-' . gmdate('Ymd_His') . '-' . bin2hex(random_bytes(3));
    ensureDirectory($snapshot . '/original');

    foreach ($states as $path => $state) {
        if (empty($state['exists'])) continue;
        $target = $snapshot . '/original/' . $path;
        ensureDirectory(dirname($target));
        if (!copy($root . '/' . $path, $target)) throw new RuntimeException('Unable to snapshot ' . $path);
    }

    ensureDirectory($snapshot . '/protected');
    if (!copy($root . '/config.php', $snapshot . '/protected/config.php')) {
        throw new RuntimeException('Unable to snapshot protected config.php');
    }
    if (is_file($root . '/storage/upgrades/installed.json')) {
        if (!copy($root . '/storage/upgrades/installed.json', $snapshot . '/protected/installed.json')) {
            throw new RuntimeException('Unable to snapshot installed release metadata.');
        }
    }

    atomicJsonWrite($snapshot . '/manifest.json', [
        'created_at' => date(DATE_ATOM),
        'target' => BRIDGE_TARGET_REF,
        'files' => $states,
        'config_sha256' => $configHash,
        'installed_sha256' => $installedHash,
    ]);
    return $snapshot;
}

function installOne(string $root, string $staging, string $path, array $states): void
{
    $source = $staging . '/' . $path;
    $destination = $root . '/' . $path;
    ensureDirectory(dirname($destination));

    $state = $states[$path] ?? ['exists' => false];
    if (!empty($state['exists'])) {
        $reference = $state;
    } else {
        $referencePath = (string)(BRIDGE_FILES[$path]['metadata_reference'] ?? '');
        $reference = $states[$referencePath] ?? null;
        if (!is_array($reference) || empty($reference['exists'])) {
            throw new RuntimeException('No metadata reference for new file ' . $path);
        }
    }

    $temp = dirname($destination) . '/.' . basename($destination) . '.bridge-' . bin2hex(random_bytes(4));
    if (!copy($source, $temp)) throw new RuntimeException('Unable to prepare ' . $path);

    try {
        if (!chmod($temp, (int)$reference['mode'])) throw new RuntimeException('Unable to preserve mode for ' . $path);
        $stat = stat($temp);
        if ($stat === false) throw new RuntimeException('Unable to stat staged ' . $path);
        if ((int)$stat['uid'] !== (int)$reference['uid'] && !@chown($temp, (int)$reference['uid'])) {
            throw new RuntimeException('Unable to preserve owner for ' . $path);
        }
        if ((int)$stat['gid'] !== (int)$reference['gid'] && !@chgrp($temp, (int)$reference['gid'])) {
            throw new RuntimeException('Unable to preserve group for ' . $path);
        }
        if (!rename($temp, $destination)) throw new RuntimeException('Atomic replacement failed for ' . $path);
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
}

function verifyInstalledFiles(string $root): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        $content = file_get_contents($root . '/' . $path);
        if (!is_string($content) || !hash_equals((string)$spec['target'], gitBlobSha($content))) {
            throw new RuntimeException('Installed hash mismatch for ' . $path);
        }
    }
}

function rollbackSnapshot(string $root, string $snapshot): void
{
    $manifest = json_decode((string)file_get_contents($snapshot . '/manifest.json'), true);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) throw new RuntimeException('Rollback manifest is invalid.');

    foreach (array_reverse(array_keys(BRIDGE_FILES)) as $path) {
        $state = $manifest['files'][$path] ?? ['exists' => false];
        $destination = $root . '/' . $path;
        if (empty($state['exists'])) {
            if (file_exists($destination) && !@unlink($destination)) throw new RuntimeException('Unable to remove ' . $path);
            continue;
        }
        $source = $snapshot . '/original/' . $path;
        $temp = dirname($destination) . '/.' . basename($destination) . '.rollback-' . bin2hex(random_bytes(4));
        if (!copy($source, $temp)) throw new RuntimeException('Unable to prepare rollback for ' . $path);
        @chmod($temp, (int)$state['mode']);
        @chown($temp, (int)$state['uid']);
        @chgrp($temp, (int)$state['gid']);
        if (!rename($temp, $destination)) {
            @unlink($temp);
            throw new RuntimeException('Unable to restore ' . $path);
        }
    }

    if (sha256File($root . '/config.php') !== (string)($manifest['config_sha256'] ?? '')) {
        if (!copy($snapshot . '/protected/config.php', $root . '/config.php')) {
            throw new RuntimeException('Unable to restore protected config.php after invariant violation.');
        }
    }

    $installedOriginal = $snapshot . '/protected/installed.json';
    if (is_file($installedOriginal)) {
        if (!copy($installedOriginal, $root . '/storage/upgrades/installed.json')) {
            throw new RuntimeException('Unable to restore installed release metadata.');
        }
    } elseif (is_file($root . '/storage/upgrades/installed.json')) {
        @unlink($root . '/storage/upgrades/installed.json');
    }
}

function verifyRollback(string $root, array $states, string $configHash, ?string $installedHash): void
{
    foreach ($states as $path => $state) {
        $file = $root . '/' . $path;
        if (empty($state['exists'])) {
            if (file_exists($file)) throw new RuntimeException('Rollback left new file ' . $path);
            continue;
        }
        $content = file_get_contents($file);
        if (!is_string($content) || gitBlobSha($content) !== (string)$state['blob']) {
            throw new RuntimeException('Rollback hash mismatch for ' . $path);
        }
    }
    if (sha256File($root . '/config.php') !== $configHash) throw new RuntimeException('config.php was not restored byte-identically.');
    $actualInstalled = is_file($root . '/storage/upgrades/installed.json')
        ? sha256File($root . '/storage/upgrades/installed.json')
        : null;
    if ($actualInstalled !== $installedHash) throw new RuntimeException('Installed release metadata was not restored exactly.');
}

function verifyRuntimeLoads(string $root): void
{
    // Load the actual deployment-specific config.php without running its normal
    // request/bootstrap path. This proves the local config still reaches lib/path.php
    // and therefore the enterprise runtime, without opening/mutating the database.
    $code = 'define("APP_BOOTSTRAPPED", true);'
        . 'require ' . var_export($root . '/config.php', true) . ';'
        . 'if(!function_exists("base_path")) exit(10);'
        . 'require ' . var_export($root . '/lib/upgrade.php', true) . ';'
        . '$e=new UpgradeEngine(' . var_export($root, true) . ');'
        . 'if(!function_exists("upgrade_start_cli_job")) exit(11);'
        . 'if(!class_exists("SystemBackupManager")) exit(12);'
        . 'echo "ENTERPRISE_RUNTIME_OK\\n";';
    $result = runCommand([PHP_BINARY ?: 'php', '-r', $code]);
    if (strpos($result, 'ENTERPRISE_RUNTIME_OK') === false) {
        throw new RuntimeException('Deployment config could not load the enterprise runtime safely.');
    }
}

function assertNoActiveSystemOperation(string $root): void
{
    foreach (['maintenance.flag', 'active_database.json'] as $name) {
        if (is_file($root . '/storage/upgrades/' . $name)) {
            throw new RuntimeException('Existing enterprise runtime state requires review: storage/upgrades/' . $name);
        }
    }
    foreach (glob($root . '/storage/upgrades/jobs/*.json') ?: [] as $file) {
        $job = json_decode((string)file_get_contents($file), true);
        if (is_array($job) && in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
            throw new RuntimeException('System job is active: ' . basename($file));
        }
    }
}

function verifyFullBackupRoundTrip(string $root, string $staging): void
{
    $env = readEnvironment($root . '/.env');
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_ADMIN_USER', 'DB_ADMIN_PASS'] as $key) {
        $runtime = getenv($key);
        if (($runtime === false || $runtime === '') && array_key_exists($key, $env)) {
            putenv($key . '=' . (string)$env[$key]);
            $_ENV[$key] = (string)$env[$key];
        }
    }

    $host = (string)(getenv('DB_HOST') ?: '127.0.0.1');
    $port = (int)(getenv('DB_PORT') ?: 3306);
    $dbName = (string)(getenv('DB_NAME') ?: '');
    $dbUser = (string)(getenv('DB_USER') ?: '');
    $dbPass = (string)(getenv('DB_PASS') ?: '');

    if ($dbName === '' || preg_match('/^[A-Za-z0-9_]+$/', $dbName) !== 1 || $dbUser === '') {
        throw new RuntimeException('DB_NAME/DB_USER is missing or unsafe in the server environment.');
    }

    require_once $staging . '/lib/system_backup.php';

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port > 0 ? $port : 3306,
            $dbName
        ),
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $manager = new SystemBackupManager($root);
    $target = $root . '/storage/upgrades/bootstrap/preflight-roundtrip-'
        . gmdate('Ymd_His') . '-' . bin2hex(random_bytes(3)) . '.zip';

    try {
        $backup = $manager->createBackupSet(
            $pdo,
            'preflight',
            $target,
            [
                'id' => 'bridge_preflight_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3)),
                'label' => 'One-time enterprise transition preflight',
            ]
        );

        if (!is_file((string)$backup['absolute_path']) || empty($backup['manifest']['database']['verified_restore'])) {
            throw new RuntimeException('Full preflight backup did not prove a native staging restore.');
        }
    } finally {
        if (is_file($target)) {
            @unlink($target);
        }
    }
}

function lintPhpFiles(string $root, array $paths): void
{
    foreach ($paths as $path) {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') continue;
        runCommand([PHP_BINARY ?: 'php', '-l', $root . '/' . $path]);
    }
}

function readEnvironment(string $path): array
{
    $result = [];
    if (!is_file($path)) return $result;
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2
            && (($value[0] === '"' && $value[strlen($value)-1] === '"')
                || ($value[0] === "'" && $value[strlen($value)-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $result[trim($key)] = $value;
    }
    return $result;
}

function runCommand(array $command): string
{
    $process = proc_open($command, [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start command.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException(trim($stderr !== '' ? $stderr : $stdout));
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

function createTempDirectory(string $parent, string $prefix): string
{
    $path = $parent . '/' . $prefix . bin2hex(random_bytes(5));
    ensureDirectory($path);
    return $path;
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) throw new RuntimeException('Unable to create ' . $path);
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    @rmdir($path);
}

function gitBlobSha(string $content): string
{
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

function sha256File(string $path): string
{
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) throw new RuntimeException('Unable to hash ' . $path);
    return $hash;
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
