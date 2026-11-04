<?php

declare(strict_types=1);

$dsn = getenv('JOB_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = getenv('JOB_DB_USER') ?: 'root';
$pass = getenv('JOB_DB_PASS') ?: 'root';
$dbName = getenv('JOB_DB_NAME') ?: 'cas_system_job_test';

$server = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$safeDb = preg_replace('/[^A-Za-z0-9_]/', '_', $dbName);
$server->exec('DROP DATABASE IF EXISTS `' . $safeDb . '`');
$server->exec('CREATE DATABASE `' . $safeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

if (preg_match('/host=([^;]+)/', $dsn, $m)) putenv('DB_HOST=' . $m[1]);
if (preg_match('/port=([0-9]+)/', $dsn, $m)) putenv('DB_PORT=' . $m[1]);
putenv('DB_NAME=' . $safeDb);
putenv('DB_USER=' . $user);
putenv('DB_PASS=' . $pass);
putenv('DB_ADMIN_USER=' . $user);
putenv('DB_ADMIN_PASS=' . $pass);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/upgrade.php';

// Regression: an older running job must not be hidden by a newer completed job.
$hiddenRunningId = 'hidden_running_' . bin2hex(random_bytes(3));
$hiddenCompletedId = 'hidden_completed_' . bin2hex(random_bytes(3));
upgrade_write_job([
    'id' => $hiddenRunningId,
    'type' => 'upgrade',
    'status' => 'running',
    'created_at' => date(DATE_ATOM, time() - 60),
    'started_at' => date(DATE_ATOM, time() - 60),
    'completed_at' => null,
    'exit_code' => null,
    'message' => 'running test fixture',
    'stdout' => '',
    'stderr' => '',
    'arguments' => [],
]);
upgrade_write_job([
    'id' => $hiddenCompletedId,
    'type' => 'backup',
    'status' => 'completed',
    'created_at' => date(DATE_ATOM),
    'started_at' => date(DATE_ATOM),
    'completed_at' => date(DATE_ATOM),
    'exit_code' => 0,
    'message' => 'completed test fixture',
    'stdout' => '',
    'stderr' => '',
    'arguments' => [],
]);

$blocked = false;
try {
    upgrade_start_cli_job(['--action=list-backups'], 'must-be-blocked');
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'already running');
}
if (!$blocked) {
    throw new RuntimeException('A hidden older running system job did not block a new launch.');
}
@unlink(upgrade_job_path($hiddenRunningId));
@unlink(upgrade_job_path($hiddenCompletedId));

$startedAt = microtime(true);
$job = upgrade_start_cli_job(['--action=list-backups'], 'launcher-test');
$elapsed = microtime(true) - $startedAt;

if ($elapsed > 3.0) {
    throw new RuntimeException('Detached job launch blocked the caller for too long: ' . $elapsed . ' seconds.');
}

$jobId = (string)$job['id'];
$deadline = microtime(true) + 20.0;
$final = null;
while (microtime(true) < $deadline) {
    usleep(200000);
    $current = upgrade_read_job($jobId);
    if (is_array($current) && in_array((string)($current['status'] ?? ''), ['completed', 'failed'], true)) {
        $final = $current;
        break;
    }
}

try {
    if (!is_array($final)) {
        throw new RuntimeException('Detached system job did not complete within the test window.');
    }
    if (($final['status'] ?? '') !== 'completed' || (int)($final['exit_code'] ?? 1) !== 0) {
        throw new RuntimeException(
            'Detached system job failed: ' . (string)($final['stderr'] ?? $final['message'] ?? 'unknown error')
        );
    }

    fwrite(STDOUT, "Detached system job launcher returned immediately and completed successfully.\n");
} finally {
    @unlink(upgrade_job_path($jobId));
    @unlink(upgrade_job_directory() . DIRECTORY_SEPARATOR . '.launch.lock');
    $server->exec('DROP DATABASE IF EXISTS `' . $safeDb . '`');
}
