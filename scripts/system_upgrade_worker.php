#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/upgrade_jobs.php';

$options = getopt('', ['job-id:']);
$jobId = trim((string)($options['job-id'] ?? ''));
if ($jobId === '') {
    fwrite(STDERR, "Missing --job-id.\n");
    exit(1);
}

$jobs = upgrade_job_manager();
$job = $jobs->readJob($jobId);
if ($job === null) {
    fwrite(STDERR, "Job not found.\n");
    exit(1);
}

$arguments = is_array($job['arguments'] ?? null) ? $job['arguments'] : [];
$script = BASE_PATH . '/scripts/system_upgrade.php';
$php = PHP_BINARY ?: 'php';
$logPath = $jobs->logPath($jobId);

$jobs->update($jobId, [
    'status' => 'running',
    'phase' => 'worker_started',
    'worker_started_at' => date(DATE_ATOM),
]);

$command = array_merge([$php, $script], array_map('strval', $arguments));
$environment = $_ENV;
foreach ($_SERVER as $key => $value) {
    if (is_string($key) && is_string($value) && !isset($environment[$key])) {
        $environment[$key] = $value;
    }
}
$environment['CAS_UPGRADE_JOB_ID'] = $jobId;

$descriptors = [
    0 => ['file', '/dev/null', 'rb'],
    1 => ['file', $logPath, 'ab'],
    2 => ['file', $logPath, 'ab'],
];

$exitCode = 1;
$error = null;
try {
    $process = proc_open($command, $descriptors, $pipes, BASE_PATH, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start system operation process.');
    }

    $status = proc_get_status($process);
    $jobs->update($jobId, [
        'phase' => 'operation_running',
        'operation_pid' => (int)($status['pid'] ?? 0),
    ]);

    $exitCode = proc_close($process);
    if (!is_int($exitCode)) {
        $exitCode = 1;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    @file_put_contents($logPath, '[WORKER ERROR] ' . $error . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$jobs->update($jobId, [
    'status' => $exitCode === 0 ? 'completed' : 'failed',
    'phase' => $exitCode === 0 ? 'completed' : 'failed',
    'completed_at' => date(DATE_ATOM),
    'exit_code' => $exitCode,
    'error' => $error,
]);

$jobs->cleanup(20);
exit($exitCode);
