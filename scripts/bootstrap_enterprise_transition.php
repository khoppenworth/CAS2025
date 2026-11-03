#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-time, fail-closed bridge from the legacy CAS updater (0.5.95-0.5.98)
 * to the enterprise-safe updater core shipped in 0.5.99.
 *
 * This script:
 * - never modifies the live application database;
 * - proves staging DB privileges using a throwaway database only;
 * - verifies every source and target updater file by Git blob SHA;
 * - preserves owner/group/mode for every replaced live file;
 * - installs files atomically and rolls all of them back on any failed check;
 * - leaves storage/upgrades/installed.json unchanged.
 *
 * Default mode is check-only. Use --apply only after the check succeeds.
 */

const BRIDGE_TARGET_REF = '0.5.99';
const BRIDGE_TARGET_COMMIT = 'e04e675857b4a175444c275a4846a481a9947792';

const BRIDGE_FILES = [
    'admin/upgrade.php' => [
        'target' => 'f0ac1b4e5912fd7a9503bfe028fa4949a0a65d7f',
        'allowed' => [
            'afceaa03265bd76e6d16f14bcbfac8ea5ba5144c', // 0.5.95/0.5.97
            'a97038b94921bcc75c6d4f31c9e6b286b0b9e771', // 0.5.98
            'f0ac1b4e5912fd7a9503bfe028fa4949a0a65d7f', // target/idempotent
        ],
    ],
    'config.php' => [
        'target' => '2a8cd5c4925774a53175ed8311df87abdc388494',
        'allowed' => [
            'f763808df8080d4a1a414c7ea7de899b801619eb', // 0.5.95-0.5.98
            '2a8cd5c4925774a53175ed8311df87abdc388494', // target/idempotent
        ],
    ],
    'lib/system_backup.php' => [
        'target' => 'c42f2659aa4e8e3d3116d0c5159597cc7616124f',
        'allowed' => [
            '48c85a53283aa515ccce0fa9e84f4e02ef8112e8', // 0.5.95
            '705af28f16565dfc97acf0142cefb0daf69419fa', // 0.5.97 parser hotfix
            '6d24268acba4f40ef3d872a9a3bbffd74e67985a', // 0.5.98
            'c42f2659aa4e8e3d3116d0c5159597cc7616124f', // target/idempotent
        ],
    ],
    'lib/upgrade.php' => [
        'target' => '96993ce9de7f1d9c4a896ae9453dbc909bc5ee8b',
        'allowed' => [
            'a240b32ed60106ebf564ce01df7ad94ce1134265', // 0.5.95-0.5.98
            '96993ce9de7f1d9c4a896ae9453dbc909bc5ee8b', // target/idempotent
        ],
    ],
    'scripts/system_upgrade.php' => [
        'target' => 'c646a5c5774f72663fd31e33157bb9ea641b1baf',
        'allowed' => [
            'c0a445961b73086b5e8534eb0da3fa830de6d4cc', // 0.5.95-0.5.98
            'c646a5c5774f72663fd31e33157bb9ea641b1baf', // target/idempotent
        ],
    ],
    'scripts/system_job.php' => [
        'target' => 'b54dc2d0c46c765490ac8af8b30ceaf05371d3a7',
        'allowed' => [
            'b54dc2d0c46c765490ac8af8b30ceaf05371d3a7', // target/idempotent
        ],
        'may_be_absent' => true,
        'metadata_reference' => 'scripts/system_upgrade.php',
    ],
];

$options = getopt('', [
    'root::',
    'source-root::',
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

$sourceRoot = null;
if (isset($options['source-root']) && trim((string)$options['source-root']) !== '') {
    $sourceRoot = realpath((string)$options['source-root']);
    if ($sourceRoot === false || !is_dir($sourceRoot)) {
        fail('--source-root does not exist.');
    }
    $sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);
}

$testFailAfter = isset($options['test-fail-after']) ? (int)$options['test-fail-after'] : 0;
if ($testFailAfter > 0 && getenv('CAS_BOOTSTRAP_TEST_MODE') !== '1') {
    fail('--test-fail-after is available only in CAS_BOOTSTRAP_TEST_MODE.');
}

$requiredRootFiles = ['config.php', 'admin/upgrade.php', 'lib/upgrade.php', 'scripts/system_upgrade.php'];
foreach ($requiredRootFiles as $path) {
    if (!is_file($root . DIRECTORY_SEPARATOR . $path)) {
        fail('Not a CAS application root; missing ' . $path);
    }
}

$storage = $root . '/storage/upgrades';
$bootstrapDir = $storage . '/bootstrap';
if (!is_dir($bootstrapDir) && !mkdir($bootstrapDir, 0750, true) && !is_dir($bootstrapDir)) {
    fail('Unable to create ' . $bootstrapDir);
}

$lockPath = $bootstrapDir . '/enterprise-transition.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fail('Another enterprise transition is already active.');
}

try {
    assertNoActiveSystemOperation($root);

    $states = inspectLiveFiles($root);
    validateKnownLegacyState($states);

    $version = readInstalledVersion($root);
    if ($version !== '') {
        info('Installed release metadata reports ' . $version . '.');
    }
    if ($version !== '' && version_compare(ltrim($version, 'vV'), '0.5.99', '>=')) {
        info('Enterprise updater is already installed; bridge is idempotent.');
    }

    info('Checking native database client prerequisites...');
    assertNativeTools();

    if (!isset($options['skip-db-preflight'])) {
        info('Proving staging database privileges using an isolated throwaway database...');
        proveStagingPrivileges($root);
        info('Staging database privilege check passed; live database was not modified.');
    } else {
        info('Database privilege preflight skipped by explicit option.');
    }

    $staging = createTempDirectory($bootstrapDir, 'target_');
    try {
        info('Acquiring exact enterprise updater files from immutable target ' . BRIDGE_TARGET_COMMIT . '...');
        stageTargetFiles($staging, $sourceRoot);
        lintPhpFiles($staging);
        info('Target updater file hashes and PHP syntax are valid.');

        if (!$apply) {
            info('CHECK PASSED. No live application files were changed.');
            info('Run this same command with --apply to perform the atomic bridge installation.');
            exit(0);
        }

        $snapshot = createSnapshot($root, $bootstrapDir, $states);
        info('Rollback snapshot created at ' . $snapshot);

        $changed = [];
        try {
            $index = 0;
            foreach (BRIDGE_FILES as $path => $spec) {
                $index++;
                installOne($root, $staging, $path, $states);
                $changed[] = $path;

                if ($testFailAfter > 0 && $index === $testFailAfter) {
                    throw new RuntimeException('Injected bootstrap failure after file ' . $path);
                }
            }

            verifyInstalledFiles($root, $states);
            lintPhpFiles($root, array_keys(BRIDGE_FILES));
            verifyRuntimeLoads($root);

            $success = [
                'status' => 'completed',
                'completed_at' => date(DATE_ATOM),
                'target_ref' => BRIDGE_TARGET_REF,
                'target_commit' => BRIDGE_TARGET_COMMIT,
                'previous_release_metadata' => $version,
                'rollback_snapshot' => $snapshot,
                'files' => array_keys(BRIDGE_FILES),
            ];
            atomicJsonWrite($bootstrapDir . '/enterprise-transition-success.json', $success);

            info('BRIDGE INSTALLATION PASSED.');
            info('The CAS application database and installed-release metadata were not changed.');
            info('The updater core is now the enterprise 0.5.99 implementation and can perform the normal verified upgrade.');
        } catch (Throwable $installError) {
            fwrite(STDERR, '[ERROR] Bridge installation failed: ' . $installError->getMessage() . PHP_EOL);
            fwrite(STDERR, '[INFO] Rolling all updater files back from ' . $snapshot . PHP_EOL);
            rollbackSnapshot($root, $snapshot);
            verifyRollback($root, $states);
            fwrite(STDERR, '[INFO] Rollback verification passed; the pre-bridge updater files were restored exactly.' . PHP_EOL);
            throw $installError;
        }
    } finally {
        removeDirectory($staging);
    }
} catch (Throwable $e) {
    fail($e->getMessage());
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
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
            throw new RuntimeException('Refusing to bridge non-regular file: ' . $path);
        }
        $stat = stat($absolute);
        if ($stat === false) {
            throw new RuntimeException('Unable to stat ' . $path);
        }
        $content = file_get_contents($absolute);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read ' . $path);
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

function validateKnownLegacyState(array $states): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        $state = $states[$path];
        if (!$state['exists']) {
            if (!empty($spec['may_be_absent'])) {
                continue;
            }
            throw new RuntimeException('Required updater file is missing: ' . $path);
        }

        if (!in_array($state['blob'], $spec['allowed'], true)) {
            throw new RuntimeException(
                'Refusing to overwrite locally modified or unknown updater file ' . $path
                . ' (Git blob ' . $state['blob'] . '). No files were changed.'
            );
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
            throw new RuntimeException(
                'Refusing legacy bridge because enterprise runtime state already exists: '
                . substr($path, strlen($root) + 1)
            );
        }
    }

    foreach (glob($root . '/storage/upgrades/jobs/*.json') ?: [] as $jobFile) {
        $job = json_decode((string)file_get_contents($jobFile), true);
        if (is_array($job) && in_array((string)($job['status'] ?? ''), ['queued', 'running'], true)) {
            throw new RuntimeException('Refusing bridge while a system job is active: ' . basename($jobFile));
        }
    }

    foreach (glob($root . '/storage/upgrades/runs/*/manifest.json') ?: [] as $manifestFile) {
        $run = json_decode((string)file_get_contents($manifestFile), true);
        if (is_array($run) && in_array((string)($run['status'] ?? ''), ['pending', 'running'], true)) {
            throw new RuntimeException('Refusing bridge while an upgrade run is active: ' . basename(dirname($manifestFile)));
        }
    }
}

function stageTargetFiles(string $staging, ?string $sourceRoot): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        $target = $staging . DIRECTORY_SEPARATOR . $path;
        ensureDirectory(dirname($target));

        if ($sourceRoot !== null) {
            $source = $sourceRoot . DIRECTORY_SEPARATOR . $path;
            if (!is_file($source)) {
                throw new RuntimeException('Source root is missing target file ' . $path);
            }
            $content = file_get_contents($source);
        } else {
            $url = 'https://raw.githubusercontent.com/khoppenworth/CAS2025/'
                . BRIDGE_TARGET_COMMIT . '/' . str_replace('%2F', '/', rawurlencode($path));
            $content = downloadText($url);
        }

        if (!is_string($content)) {
            throw new RuntimeException('Unable to obtain target file ' . $path);
        }

        $blob = gitBlobSha($content);
        if (!hash_equals((string)$spec['target'], $blob)) {
            throw new RuntimeException(
                'Target file integrity mismatch for ' . $path
                . '; expected Git blob ' . $spec['target'] . ', got ' . $blob
            );
        }

        if (file_put_contents($target, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to stage target file ' . $path);
        }
    }
}

function createSnapshot(string $root, string $bootstrapDir, array $states): string
{
    $snapshot = $bootstrapDir . '/snapshot-' . gmdate('Ymd_His') . '-' . bin2hex(random_bytes(3));
    ensureDirectory($snapshot);

    $manifest = [
        'created_at' => date(DATE_ATOM),
        'target_ref' => BRIDGE_TARGET_REF,
        'target_commit' => BRIDGE_TARGET_COMMIT,
        'files' => $states,
    ];

    foreach ($states as $path => $state) {
        if (!$state['exists']) {
            continue;
        }
        $source = $root . DIRECTORY_SEPARATOR . $path;
        $target = $snapshot . DIRECTORY_SEPARATOR . 'original' . DIRECTORY_SEPARATOR . $path;
        ensureDirectory(dirname($target));
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to snapshot ' . $path);
        }
        @chmod($target, (int)$state['mode']);
    }

    atomicJsonWrite($snapshot . '/manifest.json', $manifest);
    return $snapshot;
}

function installOne(string $root, string $staging, string $path, array $states): void
{
    $source = $staging . DIRECTORY_SEPARATOR . $path;
    $destination = $root . DIRECTORY_SEPARATOR . $path;
    ensureDirectory(dirname($destination));

    $state = $states[$path];
    if ($state['exists']) {
        $mode = (int)$state['mode'];
        $uid = (int)$state['uid'];
        $gid = (int)$state['gid'];
    } else {
        $reference = BRIDGE_FILES[$path]['metadata_reference'] ?? null;
        if (!is_string($reference) || !isset($states[$reference]) || !$states[$reference]['exists']) {
            throw new RuntimeException('No safe metadata reference exists for new file ' . $path);
        }
        $mode = (int)$states[$reference]['mode'];
        $uid = (int)$states[$reference]['uid'];
        $gid = (int)$states[$reference]['gid'];
    }

    $temp = dirname($destination) . '/.' . basename($destination) . '.bridge-' . bin2hex(random_bytes(4));
    $content = file_get_contents($source);
    if (!is_string($content) || file_put_contents($temp, $content, LOCK_EX) === false) {
        @unlink($temp);
        throw new RuntimeException('Unable to create atomic replacement for ' . $path);
    }

    try {
        if (!chmod($temp, $mode)) {
            throw new RuntimeException('Unable to preserve permissions for ' . $path);
        }

        $tempStat = stat($temp);
        if ($tempStat === false) {
            throw new RuntimeException('Unable to inspect replacement metadata for ' . $path);
        }

        if ((int)$tempStat['uid'] !== $uid && (!function_exists('chown') || !@chown($temp, $uid))) {
            throw new RuntimeException('Unable to preserve owner for ' . $path);
        }
        if ((int)$tempStat['gid'] !== $gid && (!function_exists('chgrp') || !@chgrp($temp, $gid))) {
            throw new RuntimeException('Unable to preserve group for ' . $path);
        }

        clearstatcache(true, $temp);
        $verified = stat($temp);
        if ($verified === false
            || (($verified['mode'] & 0777) !== $mode)
            || ((int)$verified['uid'] !== $uid)
            || ((int)$verified['gid'] !== $gid)
        ) {
            throw new RuntimeException('Replacement metadata verification failed for ' . $path);
        }

        if (!rename($temp, $destination)) {
            throw new RuntimeException('Atomic replacement failed for ' . $path);
        }
    } finally {
        if (is_file($temp)) {
            @unlink($temp);
        }
    }
}

function verifyInstalledFiles(string $root, array $states): void
{
    foreach (BRIDGE_FILES as $path => $spec) {
        $absolute = $root . DIRECTORY_SEPARATOR . $path;
        $content = file_get_contents($absolute);
        if (!is_string($content) || !hash_equals((string)$spec['target'], gitBlobSha($content))) {
            throw new RuntimeException('Installed target hash verification failed for ' . $path);
        }

        $stat = stat($absolute);
        if ($stat === false) {
            throw new RuntimeException('Unable to stat installed file ' . $path);
        }

        $reference = $states[$path]['exists']
            ? $states[$path]
            : $states[(string)(BRIDGE_FILES[$path]['metadata_reference'] ?? '')];

        if (($stat['mode'] & 0777) !== (int)$reference['mode']
            || (int)$stat['uid'] !== (int)$reference['uid']
            || (int)$stat['gid'] !== (int)$reference['gid']
        ) {
            throw new RuntimeException('Installed ownership/permission verification failed for ' . $path);
        }
    }
}

function rollbackSnapshot(string $root, string $snapshot): void
{
    $manifest = json_decode((string)file_get_contents($snapshot . '/manifest.json'), true);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
        throw new RuntimeException('Rollback snapshot manifest is unreadable.');
    }

    foreach (array_reverse(array_keys(BRIDGE_FILES)) as $path) {
        $state = $manifest['files'][$path] ?? null;
        if (!is_array($state)) {
            throw new RuntimeException('Rollback metadata missing for ' . $path);
        }
        $destination = $root . DIRECTORY_SEPARATOR . $path;

        if (empty($state['exists'])) {
            if (file_exists($destination) && !@unlink($destination)) {
                throw new RuntimeException('Unable to remove bridge-created file during rollback: ' . $path);
            }
            continue;
        }

        $source = $snapshot . '/original/' . $path;
        if (!is_file($source)) {
            throw new RuntimeException('Rollback copy missing for ' . $path);
        }

        $temp = dirname($destination) . '/.' . basename($destination) . '.rollback-' . bin2hex(random_bytes(4));
        if (!copy($source, $temp)) {
            throw new RuntimeException('Unable to prepare rollback for ' . $path);
        }
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

function lintPhpFiles(string $root, ?array $paths = null): void
{
    $paths = $paths ?? array_keys(BRIDGE_FILES);
    foreach ($paths as $path) {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            continue;
        }
        $file = $root . DIRECTORY_SEPARATOR . $path;
        if (!is_file($file)) {
            throw new RuntimeException('PHP lint target is missing: ' . $path);
        }
        runCommand([PHP_BINARY ?: 'php', '-l', $file]);
    }
}

function verifyRuntimeLoads(string $root): void
{
    $code = 'define("APP_BOOTSTRAPPED", true);'
        . 'require ' . var_export($root . '/config.php', true) . ';'
        . 'require ' . var_export($root . '/lib/upgrade.php', true) . ';'
        . 'if (!function_exists("upgrade_start_cli_job")) exit(11);'
        . 'if (!class_exists("SystemBackupManager")) exit(12);'
        . 'echo "enterprise-updater-ok\\n";';

    $output = runCommand([PHP_BINARY ?: 'php', '-r', $code]);
    if (strpos($output, 'enterprise-updater-ok') === false) {
        throw new RuntimeException('Enterprise updater runtime verification failed.');
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
        throw new RuntimeException('PHP proc_open is required for detached enterprise system jobs.');
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
        throw new RuntimeException('DB_USER and DB_ADMIN_USER (or a privileged DB_USER fallback) are required.');
    }

    $dsnBase = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $appLive = new PDO($dsnBase . ';dbname=' . $dbName, $appUser, $appPass, $options);
    $currentUser = (string)$appLive->query('SELECT CURRENT_USER()')->fetchColumn();
    if ($currentUser === '' || strpos($currentUser, '@') === false) {
        throw new RuntimeException('Unable to determine application MySQL account.');
    }

    $admin = new PDO($dsnBase, $adminUser, $adminPass, $options);
    $candidate = 'cas_bridge_preflight_' . bin2hex(random_bytes(5));
    $quotedDb = '`' . str_replace('`', '``', $candidate) . '`';

    try {
        $admin->exec('CREATE DATABASE ' . $quotedDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $appCandidate = null;
        try {
            $appCandidate = new PDO($dsnBase . ';dbname=' . $candidate, $appUser, $appPass, $options);
            $appCandidate->query('SELECT 1')->fetchColumn();
        } catch (Throwable $initialAccessError) {
            [$accountUser, $accountHost] = explode('@', $currentUser, 2);
            $account = $admin->quote($accountUser) . '@' . $admin->quote($accountHost);
            $admin->exec('GRANT ALL PRIVILEGES ON ' . $quotedDb . '.* TO ' . $account);
            $appCandidate = new PDO($dsnBase . ';dbname=' . $candidate, $appUser, $appPass, $options);
        }

        $appCandidate->exec('CREATE TABLE bridge_probe (id INT PRIMARY KEY)');
        $appCandidate->exec('INSERT INTO bridge_probe (id) VALUES (1)');
        $value = (int)$appCandidate->query('SELECT COUNT(*) FROM bridge_probe')->fetchColumn();
        if ($value !== 1) {
            throw new RuntimeException('Application account staging write verification failed.');
        }
        $appCandidate->exec('DROP TABLE bridge_probe');
    } finally {
        try {
            $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDb);
        } catch (Throwable $dropError) {
            throw new RuntimeException(
                'Staging privilege preflight could not remove throwaway database ' . $candidate
                . ': ' . $dropError->getMessage(),
                0,
                $dropError
            );
        }
    }
}

function readInstalledVersion(string $root): string
{
    $path = $root . '/storage/upgrades/installed.json';
    if (!is_file($path)) return '';
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? trim((string)($data['tag'] ?? $data['name'] ?? '')) : '';
}

function readEnvironment(string $path): array
{
    if (!is_file($path)) return [];
    $result = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || strpos($trimmed, '=') === false) continue;
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

function downloadText(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'CAS-enterprise-bootstrap/1.0',
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if (is_string($body)) return $body;
        throw new RuntimeException('Unable to download updater file: ' . $error);
    }

    if ((bool)ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => [
            'timeout' => 120,
            'header' => "User-Agent: CAS-enterprise-bootstrap/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        if (is_string($body)) return $body;
    }

    $curl = findExecutable(['curl']);
    if ($curl !== null) {
        return runCommand([$curl, '-fsSL', '--connect-timeout', '15', '--max-time', '120', $url]);
    }

    throw new RuntimeException('No HTTPS download method is available (PHP cURL, allow_url_fopen, or curl binary).');
}

function gitBlobSha(string $content): string
{
    return sha1('blob ' . strlen($content) . "\0" . $content);
}

function runCommand(array $command): string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Command failed: ' . implode(' ', array_map('strval', $command))
            . ($stderr !== '' ? ' :: ' . trim($stderr) : ''));
    }
    return $stdout;
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

function atomicJsonWrite(string $path, array $payload): void
{
    ensureDirectory(dirname($path));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Unable to encode bootstrap JSON.');
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write ' . $path);
    }
}

function createTempDirectory(string $parent, string $prefix): string
{
    $path = $parent . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
    ensureDirectory($path);
    return $path;
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory ' . $path);
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) @rmdir($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($path);
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
