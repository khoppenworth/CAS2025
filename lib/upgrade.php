<?php

declare(strict_types=1);

const ADMIN_UPGRADE_DEFAULT_REPO = 'khoppenworth/HRassessv300';
const ADMIN_UPGRADE_STORAGE_DIR = 'storage/upgrades';
const ADMIN_UPGRADE_RUN_DIR = 'storage/upgrades/runs';
const ADMIN_UPGRADE_MANUAL_DIR = 'storage/upgrades/manual';
const ADMIN_UPGRADE_INSTALLED_FILE = 'storage/upgrades/installed.json';

final class UpgradeEngine
{
    private string $root;
    private string $storageDirectory;
    private string $runsDirectory;
    private string $manualDirectory;
    private string $installedFile;

    public function __construct(?string $basePath = null)
    {
        $resolvedRoot = $basePath ?? base_path('');
        $this->root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
        $this->storageDirectory = $this->path(ADMIN_UPGRADE_STORAGE_DIR);
        $this->runsDirectory = $this->path(ADMIN_UPGRADE_RUN_DIR);
        $this->manualDirectory = $this->path(ADMIN_UPGRADE_MANUAL_DIR);
        $this->installedFile = $this->path(ADMIN_UPGRADE_INSTALLED_FILE);

        $this->ensureDirectory($this->storageDirectory);
        $this->ensureDirectory($this->runsDirectory);
        $this->ensureDirectory($this->manualDirectory);
    }

    public function normalizeSource(string $value): string
    {
        $trimmed = trim(str_replace(["\r", "\n"], '', $value));
        return $trimmed;
    }

    public function isValidSource(string $value): bool
    {
        $normalized = $this->normalizeSource($value);
        if ($normalized === '') {
            return false;
        }

        if ($this->looksLikeSlug($normalized)) {
            return true;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return true;
        }

        $absolute = $this->path($normalized);
        return is_file($absolute) || is_dir($absolute);
    }

    public function extractSlug(string $value): ?string
    {
        $normalized = $this->normalizeSource($value);
        if ($normalized === '') {
            return null;
        }

        if ($this->looksLikeSlug($normalized)) {
            return $normalized;
        }

        $parsed = @parse_url($normalized);
        if ($parsed === false) {
            return null;
        }

        $host = strtolower((string)($parsed['host'] ?? ''));
        if ($host !== '' && strpos($host, 'github.com') === false) {
            return null;
        }

        $path = trim((string)($parsed['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        $parts = explode('/', $path);
        if (count($parts) < 2) {
            return null;
        }

        $owner = $parts[0];
        $repository = preg_replace('/\.git$/', '', $parts[1]);
        if (!is_string($repository) || $repository === '') {
            return null;
        }

        $slug = $owner . '/' . $repository;
        return $this->looksLikeSlug($slug) ? $slug : null;
    }

    public function fetchLatestRelease(string $source, ?string $token = null): ?array
    {
        $normalized = $this->normalizeSource($source);
        if ($normalized === '') {
            return null;
        }

        if ($this->looksLikeSlug($normalized)) {
            return $this->fetchLatestGitHubRelease($normalized, $token);
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            $name = basename((string)parse_url($normalized, PHP_URL_PATH));
            if ($name === '') {
                $name = 'download';
            }

            return [
                'tag' => $name,
                'name' => $name,
                'url' => $normalized,
                'download_url' => $normalized,
            ];
        }

        return null;
    }

    public function listUpgradeRuns(): array
    {
        $pattern = $this->runsDirectory . DIRECTORY_SEPARATOR . '*/manifest.json';
        $files = glob($pattern);
        if ($files === false) {
            $files = [];
        }

        $runs = [];
        foreach ($files as $manifestPath) {
            $manifest = $this->readJson($manifestPath);
            if (!is_array($manifest)) {
                continue;
            }
            $manifest['manifest_path'] = $manifestPath;
            $runs[] = $manifest;
        }

        usort($runs, static function (array $a, array $b): int {
            $aTime = (string)($a['timestamp'] ?? '');
            $bTime = (string)($b['timestamp'] ?? '');
            return strcmp($bTime, $aTime);
        });

        return $runs;
    }

    public function beginRun(string $type, array $context = []): array
    {
        $id = date('Ymd_His');
        $runDir = $this->runsDirectory . DIRECTORY_SEPARATOR . $id;
        $this->ensureDirectory($runDir);

        $manifest = array_merge([
            'id' => $id,
            'timestamp' => $id,
            'kind' => $type,
            'status' => 'pending',
            'created_at' => date(DATE_ATOM),
        ], $context);

        $manifestPath = $runDir . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->writeJson($manifestPath, $manifest);

        return [
            'id' => $id,
            'dir' => $runDir,
            'manifest' => $manifest,
            'manifest_path' => $manifestPath,
        ];
    }

    public function updateRun(array &$run, array $changes): void
    {
        $run['manifest'] = array_replace($run['manifest'], $changes);
        $this->writeJson($run['manifest_path'], $run['manifest']);
    }

    public function completeRun(array &$run, string $status, ?string $error = null): void
    {
        $this->updateRun($run, [
            'status' => $status,
            'completed_at' => date(DATE_ATOM),
            'error' => $error,
        ]);
    }

    public function storeInstalledRelease(array $release): void
    {
        $tag = trim((string)($release['tag'] ?? ''));
        $name = trim((string)($release['name'] ?? ''));
        if ($tag === '' && $name === '') {
            return;
        }

        $payload = [
            'tag' => $tag !== '' ? $tag : null,
            'name' => $name !== '' ? $name : ($tag !== '' ? $tag : null),
            'repo' => isset($release['repo']) ? (string)$release['repo'] : null,
            'url' => isset($release['url']) ? (string)$release['url'] : null,
            'installed_at' => isset($release['installed_at']) && $release['installed_at'] !== ''
                ? (string)$release['installed_at']
                : date(DATE_ATOM),
        ];

        $this->writeJson($this->installedFile, $payload);
    }

    public function currentRelease(): ?array
    {
        $data = $this->readJson($this->installedFile);
        if (!is_array($data)) {
            return null;
        }

        $tag = trim((string)($data['tag'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        if ($tag === '' && $name === '') {
            return null;
        }

        return [
            'tag' => $tag !== '' ? $tag : null,
            'label' => $name !== '' ? $name : ($tag !== '' ? $tag : null),
            'installed_at' => isset($data['installed_at']) ? (string)$data['installed_at'] : null,
            'repo' => isset($data['repo']) ? (string)$data['repo'] : null,
            'url' => isset($data['url']) ? (string)$data['url'] : null,
        ];
    }

    public function manualBackupDirectory(): string
    {
        return $this->manualDirectory;
    }

    public function createManualBackup(PDO $pdo): array
    {
        $this->ensureZipSupport();

        $timestamp = date('Ymd_His');
        $filename = 'manual-backup-' . $timestamp . '.zip';
        $target = $this->manualDirectory . DIRECTORY_SEPARATOR . $filename;

        $archive = new ZipArchive();
        if ($archive->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create backup archive at ' . $target);
        }

        $applicationArchivePath = null;
        $applicationArchiveTempDir = null;

        try {
            $summary = [
                'generated_at' => date(DATE_ATOM),
                'users' => $this->fetchCount($pdo, 'SELECT COUNT(*) FROM users'),
                'assessments' => $this->fetchCount($pdo, 'SELECT COUNT(*) FROM questionnaire_response'),
                'drafts' => $this->fetchCount($pdo, "SELECT COUNT(*) FROM questionnaire_response WHERE status='draft'"),
            ];
            $archive->addFromString('meta/summary.json', $this->encodeJson($summary));

            $this->addJsonFromQuery($archive, $pdo, 'data/site_config.json', 'SELECT * FROM site_config ORDER BY id');
            $this->addUsersExport($archive, $pdo);
            $this->addJsonFromQuery(
                $archive,
                $pdo,
                'data/questionnaires.json',
                'SELECT id, title, description, created_at FROM questionnaire ORDER BY id'
            );
            $this->addJsonFromQuery(
                $archive,
                $pdo,
                'data/questionnaire_items.json',
                'SELECT id, questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple '
                . 'FROM questionnaire_item ORDER BY questionnaire_id, order_index'
            );
            $this->addJsonFromQuery(
                $archive,
                $pdo,
                'data/questionnaire_responses.json',
                'SELECT id, user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, '
                . 'review_comment, created_at FROM questionnaire_response ORDER BY id'
            );
            $this->addJsonFromQuery(
                $archive,
                $pdo,
                'data/questionnaire_response_items.json',
                'SELECT response_id, linkId, answer FROM questionnaire_response_item ORDER BY response_id, id'
            );

            $archive->addFromString('database/backup.sql', $this->exportDatabase($pdo));
            $this->addUploadsToArchive($archive);
            [$applicationArchivePath, $applicationArchiveTempDir] = $this->addApplicationArchiveToBackup($archive);
        } catch (Throwable $e) {
            $archive->close();
            if (is_file($target)) {
                @unlink($target);
            }
            $this->cleanupApplicationArchive($applicationArchivePath, $applicationArchiveTempDir);
            throw $e;
        }

        if ($archive->close() !== true) {
            if (is_file($target)) {
                @unlink($target);
            }
            $this->cleanupApplicationArchive($applicationArchivePath, $applicationArchiveTempDir);
            throw new RuntimeException('Failed to finalise backup archive at ' . $target);
        }

        $this->cleanupApplicationArchive($applicationArchivePath, $applicationArchiveTempDir);

        clearstatcache(true, $target);
        $size = filesize($target);
        if ($size === false || $size <= 0) {
            if (is_file($target)) {
                @unlink($target);
            }
            throw new RuntimeException('The generated backup archive appears to be empty.');
        }

        return [
            'id' => $timestamp,
            'filename' => $filename,
            'absolute_path' => $target,
            'relative_path' => $filename,
            'size' => (int)$size,
        ];
    }

    public function exportDatabase(PDO $pdo): string
    {
        $lines = ['-- Database backup generated ' . date(DATE_ATOM)];

        try {
            $tablesStatement = $pdo->query('SHOW TABLES');
            $tables = $tablesStatement ? $tablesStatement->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable $e) {
            error_log('upgrade exportDatabase failed to list tables: ' . $e->getMessage());
            $tables = [];
        }

        foreach ($tables as $tableName) {
            $table = (string)$tableName;
            if ($table === '') {
                continue;
            }

            $safe = str_replace('`', '``', $table);
            $lines[] = '';
            $lines[] = 'DROP TABLE IF EXISTS `' . $safe . '`;';

            try {
                $definitionStatement = $pdo->query('SHOW CREATE TABLE `' . $safe . '`');
                $definition = $definitionStatement ? $definitionStatement->fetch(PDO::FETCH_ASSOC) : null;
                if (is_array($definition)) {
                    $create = $definition['Create Table'] ?? ($definition['Create View'] ?? null);
                    if (is_string($create) && $create !== '') {
                        $lines[] = $create . ';';
                    }
                }
            } catch (Throwable $e) {
                error_log('upgrade exportDatabase failed to fetch definition: ' . $e->getMessage());
            }

            try {
                $dataStatement = $pdo->query('SELECT * FROM `' . $safe . '`');
                if ($dataStatement) {
                    while ($row = $dataStatement->fetch(PDO::FETCH_ASSOC)) {
                        $columns = [];
                        $values = [];
                        foreach ($row as $column => $value) {
                            $columns[] = '`' . str_replace('`', '``', (string)$column) . '`';
                            $values[] = $value === null ? 'NULL' : $pdo->quote((string)$value);
                        }

                        $lines[] = sprintf(
                            'INSERT INTO `%s` (%s) VALUES (%s);',
                            $safe,
                            implode(', ', $columns),
                            implode(', ', $values)
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('upgrade exportDatabase failed to fetch data: ' . $e->getMessage());
            }
        }

        if ($tables === []) {
            $lines[] = '-- No tables were found during export.';
        }

        return implode("\n", $lines) . "\n";
    }

    public function fetchCount(PDO $pdo, string $sql): int
    {
        try {
            $statement = $pdo->query($sql);
            $row = $statement ? $statement->fetch(PDO::FETCH_NUM) : null;
            if (!is_array($row) || !array_key_exists(0, $row)) {
                return 0;
            }
            return (int)$row[0];
        } catch (Throwable $e) {
            error_log('upgrade fetchCount error: ' . $e->getMessage());
            return 0;
        }
    }

    public function streamDownload(string $path, string $filename, ?int $size = null, string $contentType = 'application/zip'): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('Download source not found at ' . $path);
        }

        if ($size === null) {
            $stat = filesize($path);
            if ($stat !== false) {
                $size = (int)$stat;
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (!headers_sent()) {
            $type = trim($contentType) !== '' ? $contentType : 'application/zip';
            header('Content-Type: ' . $type);
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            if ($size !== null && $size > 0) {
                header('Content-Length: ' . (string)$size);
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open download source at ' . $path);
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                if (function_exists('flush')) {
                    flush();
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function createApplicationArchive(string $destination, array $preserve = []): void
    {
        $this->ensureZipSupport();

        $archive = new ZipArchive();
        if ($archive->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open archive at ' . $destination);
        }

        $exclusions = ['storage/upgrades'];
        foreach ($preserve as $item) {
            $normalized = trim((string)$item, '/');
            if ($normalized === '' || in_array($normalized, $exclusions, true)) {
                continue;
            }
            $exclusions[] = $normalized;
        }

        try {
            $directoryIterator = new RecursiveDirectoryIterator(
                $this->root,
                FilesystemIterator::SKIP_DOTS
            );
            $iterator = new RecursiveIteratorIterator(
                $directoryIterator,
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                $relative = substr($fileInfo->getPathname(), strlen($this->root) + 1);
                if ($relative === false || $relative === '') {
                    continue;
                }

                $relative = str_replace('\\', '/', $relative);
                if ($this->shouldSkip($relative, $exclusions)) {
                    continue;
                }

                if ($fileInfo->isDir()) {
                    $archive->addEmptyDir($relative);
                    continue;
                }

                $archive->addFile($fileInfo->getPathname(), $relative);
            }
        } finally {
            $archive->close();
        }
    }

    public function createDatabaseDumpFile(PDO $pdo, string $destination): void
    {
        $dump = $this->exportDatabase($pdo);
        if (file_put_contents($destination, $dump) === false) {
            throw new RuntimeException('Unable to write database dump to ' . $destination);
        }
    }

    public function restoreApplicationSnapshot(string $archivePath, array $preserve = []): void
    {
        $this->ensureZipSupport();

        if (!is_file($archivePath)) {
            throw new RuntimeException('Application snapshot not found at ' . $archivePath);
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open application snapshot archive at ' . $archivePath);
        }

        $tempDir = $this->temporaryDirectory('restore_');

        try {
            if (!$zip->extractTo($tempDir)) {
                throw new RuntimeException('Failed to extract application snapshot from ' . $archivePath);
            }
        } finally {
            $zip->close();
        }

        try {
            $this->deployRelease($tempDir, $preserve);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    public function restoreDatabaseFromDump(PDO $pdo, string $dumpPath): void
    {
        if (!is_file($dumpPath)) {
            throw new RuntimeException('Database dump not found at ' . $dumpPath);
        }

        $handle = fopen($dumpPath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open database dump at ' . $dumpPath);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $buffer = '';
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                    continue;
                }

                $buffer .= $line;
                if (substr(rtrim($line), -1) === ';') {
                    $statement = trim($buffer);
                    if ($statement !== '') {
                        $pdo->exec($statement);
                    }
                    $buffer = '';
                }
            }

            $statement = trim($buffer);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        } finally {
            fclose($handle);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function downloadReleaseArchive(string $source, string $reference, ?string $token = null): array
    {
        $normalized = $this->normalizeSource($source);
        if ($normalized === '') {
            throw new RuntimeException('Unable to determine repository slug from ' . $source);
        }

        $downloadUrl = null;
        $slug = null;

        if ($this->looksLikeSlug($normalized)) {
            $slug = $normalized;
        } elseif (preg_match('#^https?://#i', $normalized) === 1) {
            $downloadUrl = $normalized;

            $parsed = @parse_url($normalized);
            if ($parsed !== false) {
                $path = trim((string)($parsed['path'] ?? ''), '/');
                $segments = $path !== '' ? explode('/', $path) : [];
                if (count($segments) === 2) {
                    $extracted = $this->extractSlug($normalized);
                    if ($extracted !== null) {
                        $slug = $extracted;
                    }
                }
            }
        }

        if ($downloadUrl === null) {
            if ($slug === null) {
                throw new RuntimeException('Unable to determine repository slug from ' . $source);
            }

            $downloadUrl = 'https://codeload.github.com/' . $slug . '/zip/' . rawurlencode($reference);
        }

        $tmpDir = $this->temporaryDirectory('release_');
        $archivePath = $tmpDir . DIRECTORY_SEPARATOR . 'release.zip';

        $context = $this->githubStreamContext($token);
        $stream = @fopen($downloadUrl, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('Unable to download release archive from ' . $downloadUrl);
        }

        $archiveHandle = fopen($archivePath, 'wb');
        if ($archiveHandle === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create temporary archive file at ' . $archivePath);
        }

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false) {
                    break;
                }
                fwrite($archiveHandle, $chunk);
            }
        } finally {
            fclose($stream);
            fclose($archiveHandle);
        }

        $extractDir = $tmpDir . DIRECTORY_SEPARATOR . 'package';
        $this->ensureDirectory($extractDir);

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open downloaded archive at ' . $archivePath);
        }

        try {
            if (!$zip->extractTo($extractDir)) {
                throw new RuntimeException('Failed to extract downloaded archive at ' . $archivePath);
            }
        } finally {
            $zip->close();
        }

        $root = $this->discoverPackageRoot($extractDir);

        return [
            'tmp_dir' => $tmpDir,
            'archive' => $archivePath,
            'extract_root' => $root,
        ];
    }

    public function deployRelease(string $sourcePath, array $preserve = []): void
    {
        $exclusions = ['storage/upgrades'];
        foreach ($preserve as $item) {
            $normalized = trim((string)$item, '/');
            if ($normalized === '' || in_array($normalized, $exclusions, true)) {
                continue;
            }
            $exclusions[] = $normalized;
        }

        $directoryIterator = new RecursiveDirectoryIterator(
            $sourcePath,
            FilesystemIterator::SKIP_DOTS
        );
        $iterator = new RecursiveIteratorIterator(
            $directoryIterator,
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $relative = substr($fileInfo->getPathname(), strlen($sourcePath) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }

            $relative = str_replace('\\', '/', $relative);
            if ($this->shouldSkip($relative, $exclusions)) {
                continue;
            }

            $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if ($fileInfo->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('Unable to create directory ' . $target);
                }
                continue;
            }

            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create directory ' . $directory);
            }

            if (!copy($fileInfo->getPathname(), $target)) {
                throw new RuntimeException('Failed to copy ' . $fileInfo->getPathname() . ' to ' . $target);
            }
        }
    }

    public function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
                continue;
            }

            @unlink($fileInfo->getPathname());
        }

        @rmdir($path);
    }

    public function absoluteFromRoot(string $relative): string
    {
        return $this->path($relative);
    }

    public function relativeToRoot(string $path): string
    {
        $normalizedRoot = $this->root . DIRECTORY_SEPARATOR;
        if (strpos($path, $normalizedRoot) === 0) {
            return ltrim(str_replace('\\', '/', substr($path, strlen($normalizedRoot))), '/');
        }

        return $path;
    }

    private function looksLikeSlug(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $value) === 1;
    }

    private function fetchLatestGitHubRelease(string $slug, ?string $token): ?array
    {
        $headers = [
            'User-Agent: HRassessv300-upgrade-engine',
            'Accept: application/vnd.github+json',
        ];

        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $latest = $this->requestJson('https://api.github.com/repos/' . $slug . '/releases/latest', $headers);
        if (is_array($latest) && isset($latest['tag_name'])) {
            $tag = (string)$latest['tag_name'];
            $name = (string)($latest['name'] ?? $tag);
            $download = isset($latest['zipball_url'])
                ? (string)$latest['zipball_url']
                : 'https://codeload.github.com/' . $slug . '/zip/' . rawurlencode($tag);

            return [
                'tag' => $tag,
                'name' => $name,
                'url' => isset($latest['html_url']) ? (string)$latest['html_url'] : null,
                'download_url' => $download,
            ];
        }

        $tags = $this->requestJson('https://api.github.com/repos/' . $slug . '/tags?per_page=1', $headers);
        if (is_array($tags) && isset($tags[0]['name'])) {
            $tag = (string)$tags[0]['name'];
            return [
                'tag' => $tag,
                'name' => $tag,
                'url' => 'https://github.com/' . $slug . '/tree/' . rawurlencode($tag),
                'download_url' => 'https://codeload.github.com/' . $slug . '/zip/' . rawurlencode($tag),
            ];
        }

        return null;
    }

    private function requestJson(string $url, array $headers): mixed
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $contents = @file_get_contents($url, false, $context);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function encodeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode JSON payload.');
        }

        return $json . "\n";
    }

    private function addJsonFromQuery(ZipArchive $archive, PDO $pdo, string $path, string $sql): void
    {
        try {
            $statement = $pdo->query($sql);
            $rows = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            error_log('upgrade backup query failed: ' . $e->getMessage());
            $rows = [];
        }

        $archive->addFromString($path, $this->encodeJson($rows));
    }

    private function addUsersExport(ZipArchive $archive, PDO $pdo): void
    {
        try {
            $statement = $pdo->query(
                'SELECT id, username, role, full_name, email, work_function, account_status, next_assessment_date, '
                . 'first_login_at, created_at, password FROM users ORDER BY id'
            );
            $rows = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            error_log('upgrade backup users query failed: ' . $e->getMessage());
            $rows = [];
        }

        foreach ($rows as &$row) {
            unset($row['password']);
        }
        unset($row);

        $archive->addFromString('data/users.json', $this->encodeJson($rows));
    }

    private function addUploadsToArchive(ZipArchive $archive): void
    {
        $uploads = $this->path('assets/uploads');
        if (!is_dir($uploads)) {
            return;
        }

        $directoryIterator = new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator(
            $directoryIterator,
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                continue;
            }

            $relative = substr($fileInfo->getPathname(), strlen($uploads) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }

            $relative = str_replace('\\', '/', $relative);
            $archive->addFile($fileInfo->getPathname(), 'uploads/' . $relative);
        }
    }

    private function addApplicationArchiveToBackup(ZipArchive $archive): array
    {
        $tempDir = $this->temporaryDirectory('application_');
        $applicationArchive = $tempDir . DIRECTORY_SEPARATOR . 'application.zip';

        try {
            $this->createApplicationArchive($applicationArchive);
            if ($archive->addFile($applicationArchive, 'application.zip') !== true) {
                throw new RuntimeException('Unable to add application archive to backup.');
            }
        } catch (Throwable $e) {
            if (is_file($applicationArchive)) {
                @unlink($applicationArchive);
            }
            $this->removeDirectory($tempDir);
            throw $e;
        }

        return [$applicationArchive, $tempDir];
    }

    private function cleanupApplicationArchive(?string $archivePath, ?string $tempDir): void
    {
        if (is_string($archivePath) && $archivePath !== '' && is_file($archivePath)) {
            @unlink($archivePath);
        }

        if (is_string($tempDir) && $tempDir !== '' && is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }
    }

    private function temporaryDirectory(string $prefix): string
    {
        $base = $this->path(ADMIN_UPGRADE_STORAGE_DIR . '/tmp');
        $this->ensureDirectory($base);

        $temp = tempnam($base, $prefix);
        if ($temp === false) {
            throw new RuntimeException('Unable to create temporary directory inside ' . $base);
        }

        if (is_file($temp)) {
            @unlink($temp);
        }

        if (!mkdir($temp, 0755, true) && !is_dir($temp)) {
            throw new RuntimeException('Unable to initialise temporary directory at ' . $temp);
        }

        return $temp;
    }

    private function githubStreamContext(?string $token)
    {
        $headers = ['User-Agent: HRassessv300-upgrade-engine'];
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 60,
            ],
        ]);
    }

    private function discoverPackageRoot(string $directory): string
    {
        $entries = scandir($directory);
        if (!is_array($entries)) {
            return $directory;
        }

        $filtered = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $filtered[] = $entry;
        }

        if (count($filtered) === 1) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $filtered[0];
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $directory;
    }

    private function shouldSkip(string $relative, array $exclusions): bool
    {
        foreach ($exclusions as $entry) {
            if ($entry === '') {
                continue;
            }

            if (strpos($relative, $entry) === 0) {
                return true;
            }
        }

        return false;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory ' . $path);
        }
    }

    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode JSON for ' . $path);
        }

        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Unable to write JSON to ' . $path);
        }
    }

    private function readJson(string $path): mixed
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function path(string $relative): string
    {
        $normalized = ltrim(str_replace(['\\', '\0'], '/', $relative), '/');
        return $this->root . ($normalized !== '' ? DIRECTORY_SEPARATOR . $normalized : '');
    }

    private function ensureZipSupport(): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ext-zip is required for upgrade operations.');
        }
    }
}

function upgrade_engine(): UpgradeEngine
{
    static $engine = null;
    if ($engine instanceof UpgradeEngine) {
        return $engine;
    }

    $engine = new UpgradeEngine(base_path(''));
    return $engine;
}

function upgrade_current_version(): string
{
    $info = upgrade_current_release_info();
    if ($info !== null) {
        $tag = trim((string)($info['tag'] ?? ''));
        if ($tag !== '') {
            return $tag;
        }

        $label = trim((string)($info['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
    }

    return '';
}

function upgrade_current_release_info(): ?array
{
    $engine = upgrade_engine();
    $release = $engine->currentRelease();
    if ($release !== null) {
        return $release;
    }

    foreach (upgrade_list_backups() as $backup) {
        if (($backup['status'] ?? '') !== 'success') {
            continue;
        }

        $label = (string)($backup['version_label'] ?? '');
        $ref = (string)($backup['ref'] ?? '');

        return [
            'label' => $label !== '' ? $label : ($ref !== '' ? $ref : null),
            'tag' => $ref !== '' ? $ref : null,
            'installed_at' => $backup['completed_at'] ?? null,
            'repo' => $backup['repo'] ?? null,
            'url' => $backup['release_url'] ?? null,
        ];
    }

    return null;
}

function upgrade_store_installed_release(array $release): void
{
    upgrade_engine()->storeInstalledRelease($release);
}

function upgrade_normalize_version_string(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    $normalized = preg_replace('/^v(?=\d)/i', '', $trimmed);
    return is_string($normalized) && $normalized !== '' ? $normalized : $trimmed;
}

function upgrade_release_is_newer(string $candidate, ?string $currentTag, ?string $currentLabel = null): bool
{
    $candidateNormalized = upgrade_normalize_version_string($candidate);
    if ($candidateNormalized === '') {
        return false;
    }

    $baseline = null;
    if ($currentTag !== null && $currentTag !== '') {
        $baseline = upgrade_normalize_version_string($currentTag);
    } elseif ($currentLabel !== null && $currentLabel !== '') {
        $baseline = upgrade_normalize_version_string($currentLabel);
    }

    if ($baseline === null || $baseline === '') {
        return true;
    }

    $pattern = '/^[0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.-]+)?$/';
    if (preg_match($pattern, $candidateNormalized) === 1 && preg_match($pattern, $baseline) === 1) {
        return version_compare($candidateNormalized, $baseline, '>');
    }

    return strcasecmp($candidateNormalized, $baseline) !== 0;
}

function upgrade_normalize_source(string $value): string
{
    return upgrade_engine()->normalizeSource($value);
}

function upgrade_source_from_composer(): ?string
{
    $composerPath = base_path('composer.json');
    if (!is_file($composerPath)) {
        return null;
    }

    $contents = @file_get_contents($composerPath);
    if ($contents === false) {
        return null;
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded) || empty($decoded['name'])) {
        return null;
    }

    $name = str_replace('\\', '/', (string)$decoded['name']);
    $normalized = upgrade_normalize_source($name);

    return preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $normalized) === 1 ? $normalized : null;
}

function upgrade_effective_source(array $cfg): string
{
    $stored = upgrade_normalize_source((string)($cfg['upgrade_repo'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }

    $composer = upgrade_source_from_composer();
    if ($composer !== null) {
        return $composer;
    }

    return ADMIN_UPGRADE_DEFAULT_REPO;
}

function upgrade_extract_slug(string $value): ?string
{
    return upgrade_engine()->extractSlug($value);
}

function upgrade_is_valid_source(string $value): bool
{
    return upgrade_engine()->isValidSource($value);
}

function upgrade_repository_argument(string $value): string
{
    return upgrade_normalize_source($value);
}

function upgrade_fetch_latest_release(string $source, ?string $token = null): ?array
{
    return upgrade_engine()->fetchLatestRelease($source, $token);
}

function upgrade_list_backups(): array
{
    $runs = upgrade_engine()->listUpgradeRuns();
    $result = [];
    foreach ($runs as $run) {
        $result[] = [
            'id' => (string)($run['id'] ?? ($run['timestamp'] ?? '')),
            'timestamp' => (string)($run['timestamp'] ?? ''),
            'status' => (string)($run['status'] ?? 'unknown'),
            'ref' => (string)($run['ref'] ?? ''),
            'repo' => (string)($run['repo'] ?? ''),
            'version_label' => (string)($run['version_label'] ?? ''),
            'release_url' => isset($run['release_url']) ? (string)$run['release_url'] : null,
            'started_at' => isset($run['created_at']) ? (string)$run['created_at'] : null,
            'completed_at' => isset($run['completed_at']) ? (string)$run['completed_at'] : null,
            'manifest_path' => isset($run['manifest_path']) ? (string)$run['manifest_path'] : null,
        ];
    }

    return $result;
}

function upgrade_run_cli(array $arguments): array
{
    $php = PHP_BINARY ?: 'php';
    $script = base_path('scripts/system_upgrade.php');
    if (!is_file($script)) {
        throw new RuntimeException('Upgrade script not found at ' . $script);
    }

    $command = array_merge([$php, $script], $arguments);

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptor, $pipes, base_path(''));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start upgrade process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exit_code' => is_int($exitCode) ? $exitCode : 1,
        'stdout' => $stdout !== false ? $stdout : '',
        'stderr' => $stderr !== false ? $stderr : '',
        'command' => $command,
    ];
}

function upgrade_format_command(array $command): string
{
    if ($command === []) {
        return '';
    }

    $escaped = array_map(static function ($segment): string {
        return escapeshellarg((string)$segment);
    }, $command);

    return implode(' ', $escaped);
}

function upgrade_save_source(PDO $pdo, ?string $source): void
{
    if ($source === null || upgrade_normalize_source($source) === '') {
        $stmt = $pdo->prepare(
            'INSERT INTO site_config (id, upgrade_repo) VALUES (1, NULL) '
            . 'ON DUPLICATE KEY UPDATE upgrade_repo=VALUES(upgrade_repo)'
        );
        $stmt->execute();
        return;
    }

    $normalized = upgrade_normalize_source($source);
    $stmt = $pdo->prepare(
        'INSERT INTO site_config (id, upgrade_repo) VALUES (1, ?) '
        . 'ON DUPLICATE KEY UPDATE upgrade_repo=VALUES(upgrade_repo)'
    );
    $stmt->execute([$normalized]);
}

function upgrade_fetch_count(PDO $pdo, string $sql): int
{
    return upgrade_engine()->fetchCount($pdo, $sql);
}

function upgrade_export_database(PDO $pdo): string
{
    return upgrade_engine()->exportDatabase($pdo);
}

function upgrade_manual_backup_directory(): string
{
    return upgrade_engine()->manualBackupDirectory();
}

function upgrade_resolve_manual_backup_path(string $filename): ?string
{
    $normalized = trim(str_replace(['\\', '\0'], '/', $filename), '/');
    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return null;
    }

    return upgrade_manual_backup_directory() . DIRECTORY_SEPARATOR . $normalized;
}

function upgrade_create_manual_backup(PDO $pdo): array
{
    return upgrade_engine()->createManualBackup($pdo);
}

function upgrade_stream_download(string $filePath, string $downloadName, ?int $size = null, string $contentType = 'application/zip'): void
{
    upgrade_engine()->streamDownload($filePath, $downloadName, $size, $contentType);
}

function upgrade_should_ignore_sql_error(PDOException $exception, string $statement): bool
{
    $info = $exception->errorInfo ?? [];
    $sqlState = isset($info[0]) && is_string($info[0]) ? strtoupper($info[0]) : strtoupper((string)$exception->getCode());
    $driverCode = isset($info[1]) ? (int)$info[1] : null;
    $normalizedStatement = strtolower($statement);

    $isAddColumn = strpos($normalizedStatement, 'add column') !== false;
    if ($isAddColumn && ($driverCode === 1060 || $sqlState === '42S21')) {
        return true;
    }

    $isAddIndex = strpos($normalizedStatement, 'add index') !== false
        || strpos($normalizedStatement, 'add key') !== false
        || strpos($normalizedStatement, 'add unique') !== false
        || strpos($normalizedStatement, 'add constraint') !== false;
    if ($isAddIndex && ($driverCode === 1061 || $sqlState === '42000')) {
        return true;
    }

    $isDropClause = strpos($normalizedStatement, 'drop column') !== false
        || strpos($normalizedStatement, 'drop index') !== false
        || strpos($normalizedStatement, 'drop key') !== false
        || strpos($normalizedStatement, 'drop foreign key') !== false;
    if ($isDropClause && ($driverCode === 1091 || $sqlState === '42000')) {
        return true;
    }

    return false;
}
