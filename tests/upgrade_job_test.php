<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/upgrade_jobs.php';

if (PHP_OS_FAMILY === 'Windows') {
    fwrite(STDOUT, "Upgrade job test skipped on Windows.\n");
    exit(0);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . '/cas-upgrade-job-test-' . bin2hex(random_bytes(5));

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
};

try {
    mkdir($root . '/scripts', 0755, true);
    mkdir($root . '/storage/upgrades/jobs', 0755, true);

    $worker = <<<'PHP'
#!/usr/bin/env php
<?php
$options = getopt('', ['job-id:']);
$id = (string)($options['job-id'] ?? '');
$root = dirname(__DIR__);
$path = $root . '/storage/upgrades/jobs/' . $id . '/job.json';
usleep(700000);
$job = json_decode((string)file_get_contents($path), true);
$job['status'] = 'completed';
$job['phase'] = 'completed';
$job['completed_at'] = date(DATE_ATOM);
$tmp = $path . '.dummy-tmp';
file_put_contents($tmp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
rename($tmp, $path);
file_put_contents($root . '/storage/upgrades/jobs/' . $id . '/job.log', "dummy worker completed\n");
PHP;
    file_put_contents($root . '/scripts/system_upgrade_worker.php', $worker . "\n");
    chmod($root . '/scripts/system_upgrade_worker.php', 0755);

    $manager = new UpgradeJobManager($root);

    $startedAt = microtime(true);
    $job = $manager->start('test', ['--action=test']);
    $elapsed = microtime(true) - $startedAt;

    $assert($elapsed < 0.6, 'Background job start blocked on worker completion.');
    $assert(($job['status'] ?? '') === 'running', 'New background job was not marked running.');
    $assert((int)($job['pid'] ?? 0) > 0, 'Background job did not record a launcher PID.');

    $concurrencyBlocked = false;
    try {
        $manager->start('second', ['--action=second']);
    } catch (RuntimeException $e) {
        $concurrencyBlocked = str_contains($e->getMessage(), 'already running');
    }
    $assert($concurrencyBlocked, 'A second concurrent system operation was not rejected.');

    $completed = null;
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        usleep(100000);
        $completed = $manager->readJob((string)$job['id']);
        if (($completed['status'] ?? '') === 'completed') {
            break;
        }
    }

    $assert(is_array($completed) && ($completed['status'] ?? '') === 'completed', 'Detached worker did not complete independently.');
    $assert(str_contains($manager->tail((string)$job['id']), 'dummy worker completed'), 'Background job log tail is unavailable.');

    $second = $manager->start('second', ['--action=second']);
    $assert(($second['status'] ?? '') === 'running', 'A new operation could not start after the previous one completed.');

    fwrite(STDOUT, "Detached upgrade job/concurrency test passed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Upgrade job test failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    // Give the second detached dummy worker enough time to finish before cleanup.
    usleep(900000);
    $removeDirectory($root);
}
