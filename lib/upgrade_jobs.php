<?php

declare(strict_types=1);

final class UpgradeJobManager
{
    private string $root;
    private string $jobsDirectory;

    public function __construct(?string $root = null)
    {
        $resolved = $root ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__));
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
        $this->jobsDirectory = $this->root . '/storage/upgrades/jobs';
        if (!is_dir($this->jobsDirectory) && !mkdir($this->jobsDirectory, 0750, true) && !is_dir($this->jobsDirectory)) {
            throw new RuntimeException('Unable to create upgrade jobs directory.');
        }
    }

    public function start(string $type, array $arguments, array $context = []): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new RuntimeException('Background upgrade jobs require the Linux deployment environment.');
        }

        $startLock = fopen($this->jobsDirectory . '/start.lock', 'c+');
        if ($startLock === false || !flock($startLock, LOCK_EX)) {
            throw new RuntimeException('Unable to acquire background job start lock.');
        }

        try {
            $this->assertNoActiveJob();

            $id = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $jobDir = $this->jobsDirectory . '/' . $id;
        if (!mkdir($jobDir, 0750, true) && !is_dir($jobDir)) {
            throw new RuntimeException('Unable to create upgrade job directory.');
        }

        $job = [
            'id' => $id,
            'type' => $type,
            'status' => 'queued',
            'phase' => 'queued',
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
            'arguments' => array_values($arguments),
            'context' => $context,
            'pid' => null,
            'exit_code' => null,
            'error' => null,
        ];
        $this->writeJob($id, $job);

        $php = PHP_BINARY ?: 'php';
        $worker = $this->root . '/scripts/system_upgrade_worker.php';
        if (!is_file($worker)) {
            throw new RuntimeException('Upgrade background worker is missing.');
        }

        $command = 'nohup '
            . escapeshellarg($php) . ' '
            . escapeshellarg($worker) . ' '
            . escapeshellarg('--job-id=' . $id)
            . ' > /dev/null 2>&1 < /dev/null & echo $!';

        $output = [];
        $exit = 1;
        exec($command, $output, $exit);
        $pid = isset($output[0]) ? (int)$output[0] : 0;
        if ($exit !== 0 || $pid <= 0) {
            $job['status'] = 'failed';
            $job['phase'] = 'failed_to_start';
            $job['error'] = 'Unable to launch background worker.';
            $job['updated_at'] = date(DATE_ATOM);
            $this->writeJob($id, $job);
            throw new RuntimeException('Unable to launch background upgrade worker.');
        }

        $job['pid'] = $pid;
        $job['status'] = 'running';
        $job['phase'] = 'starting';
        $job['started_at'] = date(DATE_ATOM);
        $job['updated_at'] = date(DATE_ATOM);
        $this->writeJob($id, $job);

            return $job;
        } finally {
            flock($startLock, LOCK_UN);
            fclose($startLock);
        }
    }

    public function latest(): ?array
    {
        $files = glob($this->jobsDirectory . '/*/job.json') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        return $this->readJob(basename(dirname($files[0])));
    }

    public function readJob(string $id): ?array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $id) !== 1) {
            return null;
        }
        $path = $this->jobsDirectory . '/' . $id . '/job.json';
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function update(string $id, array $changes): void
    {
        $job = $this->readJob($id);
        if ($job === null) {
            throw new RuntimeException('Upgrade job not found: ' . $id);
        }
        $job = array_replace($job, $changes, ['updated_at' => date(DATE_ATOM)]);
        $this->writeJob($id, $job);
    }

    public function logPath(string $id): string
    {
        return $this->jobsDirectory . '/' . $id . '/job.log';
    }

    public function tail(string $id, int $lines = 40): string
    {
        $path = $this->logPath($id);
        if (!is_file($path)) {
            return '';
        }
        $content = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($content)) {
            return '';
        }
        return implode("\n", array_slice($content, -max(1, $lines)));
    }

    public function cleanup(int $keep = 20): void
    {
        $dirs = array_values(array_filter(glob($this->jobsDirectory . '/*') ?: [], 'is_dir'));
        usort($dirs, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($dirs, max(0, $keep)) as $dir) {
            $job = $this->readJob(basename($dir));
            if (($job['status'] ?? '') === 'running' || ($job['status'] ?? '') === 'queued') {
                continue;
            }
            $this->removeDirectory($dir);
        }
    }

    private function assertNoActiveJob(): void
    {
        foreach (glob($this->jobsDirectory . '/*/job.json') ?: [] as $file) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            if (in_array((string)($decoded['status'] ?? ''), ['queued', 'running'], true)) {
                throw new RuntimeException(
                    'Another system operation is already running: ' . (string)($decoded['id'] ?? basename(dirname($file)))
                );
            }
        }
    }

    private function writeJob(string $id, array $job): void
    {
        $dir = $this->jobsDirectory . '/' . $id;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create job directory.');
        }
        $path = $dir . '/job.json';
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write upgrade job state.');
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to atomically update upgrade job state.');
        }
    }

    private function removeDirectory(string $path): void
    {
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
    }
}

function upgrade_job_manager(): UpgradeJobManager
{
    static $manager = null;
    if (!$manager instanceof UpgradeJobManager) {
        $manager = new UpgradeJobManager(defined('BASE_PATH') ? BASE_PATH : null);
    }
    return $manager;
}
