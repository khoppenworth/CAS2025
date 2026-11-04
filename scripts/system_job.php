<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/system_cli_bootstrap.php';
require_once __DIR__ . '/../lib/upgrade.php';

$jobId = '';
$type = 'system';
$forward = [];
$separatorSeen = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($separatorSeen) {
        $forward[] = $arg;
        continue;
    }
    if ($arg === '--') {
        $separatorSeen = true;
        continue;
    }
    if (str_starts_with($arg, '--job-id=')) {
        $jobId = substr($arg, strlen('--job-id='));
        continue;
    }
    if (str_starts_with($arg, '--type=')) {
        $type = substr($arg, strlen('--type='));
        continue;
    }
}

if ($jobId === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $jobId)) {
    fwrite(STDERR, "Invalid job id.\n");
    exit(1);
}

$job = upgrade_read_job($jobId) ?? [
    'id' => $jobId,
    'type' => $type,
    'status' => 'queued',
    'created_at' => date(DATE_ATOM),
];

$job['status'] = 'running';
$job['started_at'] = date(DATE_ATOM);
$job['message'] = 'Running';
upgrade_write_job($job);

$result = [
    'exit_code' => 1,
    'stdout' => '',
    'stderr' => '',
    'command' => [],
];

try {
    $result = upgrade_run_cli($forward);
    $job['exit_code'] = (int)$result['exit_code'];
    $job['stdout'] = (string)$result['stdout'];
    $job['stderr'] = (string)$result['stderr'];
    $job['status'] = ((int)$result['exit_code'] === 0) ? 'completed' : 'failed';
    $job['message'] = ((int)$result['exit_code'] === 0) ? 'Completed successfully' : 'Failed';
} catch (Throwable $e) {
    $job['status'] = 'failed';
    $job['exit_code'] = 1;
    $job['stderr'] = $e->getMessage();
    $job['message'] = 'Failed';
} finally {
    $job['completed_at'] = date(DATE_ATOM);
    upgrade_write_job($job);
}

exit((int)($job['exit_code'] ?? 1));
