<?php
declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$temp = sys_get_temp_dir() . '/cas-enterprise-runtime-' . bin2hex(random_bytes(5));
mkdir($temp . '/lib', 0755, true);
mkdir($temp . '/storage/upgrades', 0755, true);
mkdir($temp . '/public/admin', 0755, true);

foreach (['lib/path.php', 'lib/enterprise_runtime.php'] as $path) {
    $target = $temp . '/' . $path;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
    if (!copy($repoRoot . '/' . $path, $target)) {
        throw new RuntimeException('Unable to copy runtime fixture file ' . $path);
    }
}

$run = static function (array $command, array $env = [], ?string $cwd = null): array {
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd, array_merge($_ENV, $env));
    if (!is_resource($process)) throw new RuntimeException('Unable to launch runtime probe.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$probeCode = static function (string $root): string {
    return 'define("BASE_PATH",' . var_export($root, true) . ');'
        . 'define("BASE_URL","/");'
        . 'require ' . var_export($root . '/lib/path.php', true) . ';'
        . 'echo (string)(getenv("DB_NAME") ?: ""), "\\n";';
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

$serverProcess = null;
try {
    // No pointer: preserve the configured DB_NAME.
    $baseline = $run(
        [PHP_BINARY, '-r', $probeCode($temp)],
        ['DB_NAME' => 'baseline_db'],
        $temp
    );
    $assert($baseline['exit'] === 0, 'Baseline runtime probe failed: ' . $baseline['stderr']);
    $assert(trim($baseline['stdout']) === 'baseline_db', 'Runtime changed DB_NAME without a pointer.');

    // Valid pointer: override DB_NAME before config/.env processing would occur.
    file_put_contents(
        $temp . '/storage/upgrades/active_database.json',
        json_encode(['db_name' => 'cas_upgrade_verified_123']) . "\n"
    );
    $pointer = $run(
        [PHP_BINARY, '-r', $probeCode($temp)],
        ['DB_NAME' => 'baseline_db'],
        $temp
    );
    $assert($pointer['exit'] === 0, 'Valid pointer runtime probe failed: ' . $pointer['stderr']);
    $assert(trim($pointer['stdout']) === 'cas_upgrade_verified_123', 'Valid active DB pointer was not honored.');

    // Corrupted pointer: fail closed instead of silently returning to the old DB.
    file_put_contents($temp . '/storage/upgrades/active_database.json', "{not-json\n");
    $invalid = $run(
        [PHP_BINARY, '-r', $probeCode($temp)],
        ['DB_NAME' => 'baseline_db'],
        $temp
    );
    $assert($invalid['exit'] !== 0, 'Invalid active DB pointer did not fail closed.');
    $assert(
        str_contains($invalid['stderr'], 'CAS active database pointer is invalid'),
        'Invalid pointer failure did not explain the routing problem.'
    );
    @unlink($temp . '/storage/upgrades/active_database.json');

    // Web maintenance behavior through a real PHP server.
    file_put_contents(
        $temp . '/public/index.php',
        '<?php define("BASE_PATH",' . var_export($temp, true) . ');'
        . 'define("BASE_URL","/"); require ' . var_export($temp . '/lib/path.php', true) . '; echo "PUBLIC-OK";'
    );
    file_put_contents(
        $temp . '/public/admin/upgrade.php',
        '<?php define("BASE_PATH",' . var_export($temp, true) . ');'
        . 'define("BASE_URL","/"); require ' . var_export($temp . '/lib/path.php', true) . '; echo "ADMIN-OK";'
    );

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($socket)) throw new RuntimeException('Unable to reserve local test port: ' . $errstr);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address) || !preg_match('/:(\d+)$/', $address, $m)) {
        throw new RuntimeException('Unable to determine local test port.');
    }
    $port = (int)$m[1];

    $serverProcess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $temp . '/public'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $serverPipes,
        $temp
    );
    if (!is_resource($serverProcess)) throw new RuntimeException('Unable to start PHP test server.');
    fclose($serverPipes[0]);

    $httpGet = static function (string $url): array {
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'timeout' => 2,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string)$headers[0], $m)) {
            $status = (int)$m[1];
        }
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };

    $baseUrl = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        usleep(100000);
        $response = $httpGet($baseUrl . '/index.php');
        if ($response['status'] === 200) {
            $ready = true;
            break;
        }
    }
    $assert($ready, 'PHP test server did not become ready.');

    file_put_contents($temp . '/storage/upgrades/maintenance.flag', "{}\n");

    $public = $httpGet($baseUrl . '/index.php');
    $assert($public['status'] === 503, 'Public request was not blocked during maintenance.');
    $assert(str_contains($public['body'], 'System maintenance'), 'Maintenance response body is missing.');

    $admin = $httpGet($baseUrl . '/admin/upgrade.php');
    $assert($admin['status'] === 200, 'Upgrade page was not exempt during maintenance.');
    $assert(str_contains($admin['body'], 'ADMIN-OK'), 'Upgrade page did not execute during maintenance.');

    fwrite(STDOUT, "Enterprise runtime pointer and maintenance tests passed.\n");
} finally {
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        if (isset($serverPipes[1]) && is_resource($serverPipes[1])) fclose($serverPipes[1]);
        if (isset($serverPipes[2]) && is_resource($serverPipes[2])) fclose($serverPipes[2]);
        proc_close($serverProcess);
    }
    $remove($temp);
}
