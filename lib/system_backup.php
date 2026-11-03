<?php

declare(strict_types=1);

require_once __DIR__ . '/enterprise_recovery.php';

const SYSTEM_BACKUP_FORMAT_VERSION = 3;
const SYSTEM_BACKUP_SUPPORTED_FORMAT_VERSIONS = [2, 3];

final class SystemBackupManager
{
    private string $root;
    private string $storageDirectory;
    private string $manualDirectory;
    private string $emergencyDirectory;
    private string $importsDirectory;
    private string $runsDirectory;
    private string $tmpDirectory;
    private string $installedFile;

    public function __construct(?string $basePath = null)
    {
        $resolvedRoot = $basePath ?? base_path('');
        $this->root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
        $this->storageDirectory = $this->path('storage/upgrades');
        $this->manualDirectory = $this->path('storage/upgrades/manual');
        $this->emergencyDirectory = $this->path('storage/upgrades/emergency');
        $this->importsDirectory = $this->path('storage/upgrades/imports');
        $this->runsDirectory = $this->path('storage/upgrades/runs');
        $this->tmpDirectory = $this->path('storage/upgrades/tmp');
        $this->installedFile = $this->path('storage/upgrades/installed.json');

        foreach ([
            $this->storageDirectory,
            $this->manualDirectory,
            $this->emergencyDirectory,
            $this->importsDirectory,
            $this->runsDirectory,
            $this->tmpDirectory,
        ] as $directory) {
            $this->ensureDirectory($directory);
        }
    }

    public function defaultPreservePaths(): array
    {
        return [
            '.git',
            '.env',
            'config.php',
            'assets/uploads',
            'storage',
        ];
    }

    public function createManualBackup(PDO $pdo): array
    {
        $id = 'manual_' . date('Ymd_His');
        $target = $this->manualDirectory . DIRECTORY_SEPARATOR . 'full-backup-set-' . $id . '.zip';

        return $this->createBackupSet($pdo, 'manual', $target, [
            'id' => $id,
            'label' => 'Manual full backup',
        ]);
    }

    public function createUpgradeBackup(PDO $pdo, string $runId, string $runDirectory, array $metadata = [], array $preserve = []): array
    {
        $target = rtrim($runDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backup-set.zip';

        return $this->createBackupSet(
            $pdo,
            'upgrade',
            $target,
            array_merge($metadata, ['id' => $runId]),
            $preserve
        );
    }

    public function createBackupSet(
        PDO $pdo,
        string $kind,
        string $target,
        array $metadata = [],
        array $preserve = []
    ): array {
        $this->ensureZipSupport();

        $id = trim((string)($metadata['id'] ?? ''));
        if ($id === '') {
            $id = $kind . '_' . date('Ymd_His');
        }

        $preservePaths = $this->normalizePreservePaths($preserve);
        $working = $this->temporaryDirectory('backup_');
        $applicationArchive = $working . DIRECTORY_SEPARATOR . 'application.zip';
        $databaseDump = $working . DIRECTORY_SEPARATOR . 'database.sql';

        try {
            $application = $this->createApplicationSnapshot($applicationArchive, $preservePaths);
            $database = $this->createDatabaseSnapshot($pdo, $databaseDump);

            $manifest = [
                'format' => 'cas-full-backup-set',
                'format_version' => SYSTEM_BACKUP_FORMAT_VERSION,
                'id' => $id,
                'kind' => $kind,
                'status' => 'ready',
                'created_at' => date(DATE_ATOM),
                'preserve_paths' => $preservePaths,
                'installed_release' => $this->readInstalledRelease(),
                'application' => $application,
                'database' => $database,
                'checksums' => [
                    'application.zip' => hash_file('sha256', $applicationArchive),
                    'database/backup.sql' => hash_file('sha256', $databaseDump),
                ],
                'metadata' => $metadata,
            ];

            $directory = dirname($target);
            $this->ensureDirectory($directory);

            $archive = new ZipArchive();
            if ($archive->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create backup set at ' . $target);
            }

            try {
                if ($archive->addFile($applicationArchive, 'application.zip') !== true) {
                    throw new RuntimeException('Unable to add application snapshot to backup set.');
                }
                if ($archive->addFile($databaseDump, 'database/backup.sql') !== true) {
                    throw new RuntimeException('Unable to add database snapshot to backup set.');
                }
                if ($archive->addFromString('manifest.json', $this->encodeJson($manifest)) !== true) {
                    throw new RuntimeException('Unable to add backup manifest.');
                }
                $summary = [
                    'generated_at' => $manifest['created_at'],
                    'kind' => $kind,
                    'application_files' => $application['file_count'],
                    'database_objects' => count($database['objects']),
                    'database_rows' => array_sum(array_map(
                        static fn(array $object): int => (int)($object['row_count'] ?? 0),
                        $database['objects']
                    )),
                ];
                $archive->addFromString('meta/summary.json', $this->encodeJson($summary));
            } catch (Throwable $e) {
                $archive->close();
                if (is_file($target)) {
                    @unlink($target);
                }
                throw $e;
            }

            if ($archive->close() !== true) {
                if (is_file($target)) {
                    @unlink($target);
                }
                throw new RuntimeException('Unable to finalise backup set at ' . $target);
            }

            clearstatcache(true, $target);
            $size = filesize($target);
            if ($size === false || $size <= 0) {
                @unlink($target);
                throw new RuntimeException('The generated backup set is empty.');
            }

            $validation = $this->validateBackupSet($target);
            if (!$validation['valid']) {
                @unlink($target);
                throw new RuntimeException('Backup set validation failed: ' . implode('; ', $validation['errors']));
            }

            return [
                'id' => $id,
                'kind' => $kind,
                'filename' => basename($target),
                'absolute_path' => $target,
                'relative_path' => $this->relativeToRoot($target),
                'size' => (int)$size,
                'manifest' => $manifest,
            ];
        } finally {
            $this->removeDirectory($working);
        }
    }

    public function listBackupSets(bool $includeEmergency = true): array
    {
        $paths = [];

        foreach ([
            $this->manualDirectory . DIRECTORY_SEPARATOR . '*.zip',
            $this->importsDirectory . DIRECTORY_SEPARATOR . '*.zip',
            $this->runsDirectory . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'backup-set.zip',
        ] as $pattern) {
            $matches = glob($pattern);
            if (is_array($matches)) {
                $paths = array_merge($paths, $matches);
            }
        }

        if ($includeEmergency) {
            $matches = glob($this->emergencyDirectory . DIRECTORY_SEPARATOR . '*.zip');
            if (is_array($matches)) {
                $paths = array_merge($paths, $matches);
            }
        }

        $result = [];
        foreach (array_values(array_unique($paths)) as $path) {
            $manifest = $this->readManifestFromArchive($path);
            if (!is_array($manifest)) {
                continue;
            }

            $installed = is_array($manifest['installed_release'] ?? null)
                ? $manifest['installed_release']
                : [];
            $metadata = is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : [];

            $result[] = [
                'id' => (string)($manifest['id'] ?? basename($path)),
                'timestamp' => (string)($manifest['created_at'] ?? ''),
                'status' => (string)($manifest['status'] ?? 'ready'),
                'kind' => (string)($manifest['kind'] ?? 'backup'),
                'ref' => (string)($installed['tag'] ?? ''),
                'repo' => (string)($installed['repo'] ?? ''),
                'version_label' => (string)($installed['name'] ?? ($installed['label'] ?? '')),
                'release_url' => isset($installed['url']) ? (string)$installed['url'] : null,
                'started_at' => (string)($manifest['created_at'] ?? ''),
                'completed_at' => (string)($manifest['created_at'] ?? ''),
                'manifest_path' => $this->relativeToRoot($path) . '#manifest.json',
                'backup_set_path' => $path,
                'size' => (int)(filesize($path) ?: 0),
                'format_version' => (int)($manifest['format_version'] ?? 0),
                'label' => (string)($metadata['label'] ?? ''),
            ];
        }

        usort($result, static function (array $a, array $b): int {
            return strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? ''));
        });

        return $result;
    }

    public function findBackupSet(string $id): ?array
    {
        foreach ($this->listBackupSets(true) as $backup) {
            if ((string)($backup['id'] ?? '') === $id) {
                return $backup;
            }
        }

        return null;
    }

    public function importBackupSet(PDO $pdo, string $uploadedPath, string $originalName = ''): array
    {
        if (!is_file($uploadedPath)) {
            throw new RuntimeException('Uploaded backup set was not found.');
        }

        $validation = $this->validateBackupSet($uploadedPath);
        if (!$validation['valid']) {
            throw new RuntimeException('Backup set validation failed: ' . implode('; ', $validation['errors']));
        }

        $manifest = $validation['manifest'];
        $id = (string)($manifest['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Backup set does not contain an identifier.');
        }

        if ($this->findBackupSet($id) !== null) {
            throw new RuntimeException('Backup set ' . $id . ' already exists on this server.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($originalName));
        if (!is_string($safeName) || $safeName === '' || strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) !== 'zip') {
            $safeName = 'backup-set.zip';
        }

        $target = $this->importsDirectory
            . DIRECTORY_SEPARATOR
            . 'imported-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $id)
            . '-' . date('Ymd_His')
            . '-' . $safeName;

        if (!copy($uploadedPath, $target)) {
            throw new RuntimeException('Unable to store imported backup set.');
        }

        $storedValidation = $this->validateBackupSet($target);
        if (!$storedValidation['valid']) {
            @unlink($target);
            throw new RuntimeException('Stored backup set failed validation.');
        }

        try {
            $this->verifyDatabaseFromBackupSet($pdo, $target);
        } catch (Throwable $e) {
            @unlink($target);
            throw new RuntimeException(
                'Imported backup failed isolated database restore verification: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return [
            'id' => $id,
            'filename' => basename($target),
            'absolute_path' => $target,
            'relative_path' => $this->relativeToRoot($target),
            'size' => (int)(filesize($target) ?: 0),
            'manifest' => $storedValidation['manifest'],
        ];
    }

    public function validateBackupSet(string $archivePath): array
    {
        $errors = [];
        $manifest = null;
        $working = null;

        if (!is_file($archivePath)) {
            return ['valid' => false, 'errors' => ['Backup set file does not exist.'], 'manifest' => null];
        }

        $zip = new ZipArchive();
        $open = $zip->open($archivePath, ZipArchive::CHECKCONS);
        if ($open !== true) {
            return ['valid' => false, 'errors' => ['Backup set is not a valid ZIP archive.'], 'manifest' => null];
        }

        try {
            $this->assertSafeArchiveEntries($zip);

            foreach (['manifest.json', 'application.zip', 'database/backup.sql'] as $required) {
                if ($zip->locateName($required, ZipArchive::FL_NOCASE) === false) {
                    $errors[] = 'Required component is missing: ' . $required;
                }
            }

            $manifestRaw = $zip->getFromName('manifest.json');
            if ($manifestRaw === false) {
                $errors[] = 'Unable to read manifest.json.';
            } else {
                $decoded = json_decode($manifestRaw, true);
                if (!is_array($decoded)) {
                    $errors[] = 'manifest.json is not valid JSON.';
                } else {
                    $manifest = $decoded;
                }
            }

            if ($manifest !== null) {
                if (($manifest['format'] ?? '') !== 'cas-full-backup-set') {
                    $errors[] = 'Unsupported backup set format.';
                }
                if (!in_array((int)($manifest['format_version'] ?? 0), SYSTEM_BACKUP_SUPPORTED_FORMAT_VERSIONS, true)) {
                    $errors[] = 'Unsupported backup set format version.';
                }
                if (trim((string)($manifest['id'] ?? '')) === '') {
                    $errors[] = 'Backup set identifier is missing.';
                }
            }

            if ($errors === []) {
                $working = $this->temporaryDirectory('validate_');
                if (!$zip->extractTo($working, ['application.zip', 'database/backup.sql'])) {
                    $errors[] = 'Unable to extract backup components for validation.';
                } else {
                    $appPath = $working . DIRECTORY_SEPARATOR . 'application.zip';
                    $dbPath = $working . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backup.sql';

                    $checksums = is_array($manifest['checksums'] ?? null) ? $manifest['checksums'] : [];
                    $expectedApp = (string)($checksums['application.zip'] ?? '');
                    $expectedDb = (string)($checksums['database/backup.sql'] ?? '');

                    if ($expectedApp === '' || !hash_equals($expectedApp, (string)hash_file('sha256', $appPath))) {
                        $errors[] = 'Application snapshot checksum does not match.';
                    }
                    if ($expectedDb === '' || !hash_equals($expectedDb, (string)hash_file('sha256', $dbPath))) {
                        $errors[] = 'Database snapshot checksum does not match.';
                    }

                    $inner = new ZipArchive();
                    if ($inner->open($appPath, ZipArchive::CHECKCONS) !== true) {
                        $errors[] = 'Application snapshot is not a valid ZIP archive.';
                    } else {
                        try {
                            $this->assertSafeArchiveEntries($inner);
                        } catch (Throwable $e) {
                            $errors[] = $e->getMessage();
                        } finally {
                            $inner->close();
                        }
                    }

                    if (!is_file($dbPath) || (filesize($dbPath) ?: 0) <= 0) {
                        $errors[] = 'Database snapshot is empty.';
                    }
                    // SQL syntax is intentionally not interpreted in PHP. New v3 backups
                    // are proven by a real native-client restore into an isolated database
                    // during creation, and every restore stages the dump again before cutover.
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        } finally {
            $zip->close();
            if ($working !== null) {
                $this->removeDirectory($working);
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'manifest' => $manifest,
        ];
    }

    public function restoreBackupSet(PDO $pdo, string $archivePath, array $preserve = []): array
    {
        $preservePaths = $this->normalizePreservePaths($preserve);
        $prepared = $this->prepareBackupSet($archivePath);
        $targetManifest = $prepared['manifest'];
        $runtime = new EnterpriseRecovery($this->root);
        $sourceDatabase = $runtime->activeDatabaseName($pdo);

        // Manual restore always creates a fresh, independently verified recovery point
        // before any live application file is changed.
        $emergencyId = 'pre_restore_' . date('Ymd_His');
        $emergencyPath = $this->emergencyDirectory . DIRECTORY_SEPARATOR . $emergencyId . '.zip';
        $emergency = $this->createBackupSet($pdo, 'emergency', $emergencyPath, [
            'id' => $emergencyId,
            'label' => 'Automatic pre-restore safety backup',
            'restore_target' => (string)($targetManifest['id'] ?? ''),
        ], $preservePaths);

        $expectedObjects = is_array($targetManifest['database']['objects'] ?? null)
            ? $targetManifest['database']['objects']
            : [];

        // The target database is fully restored and verified in isolation while the
        // current production database remains online and untouched.
        $candidate = $runtime->stageDatabase(
            $pdo,
            (string)$prepared['database_path'],
            $expectedObjects,
            true
        );

        $maintenanceActive = false;
        $applicationChanged = false;
        try {
            $runtime->enterMaintenance(
                'Verified system restore cutover',
                ['restore_target' => (string)($targetManifest['id'] ?? '')]
            );
            $maintenanceActive = true;

            $applicationChanged = true;
            $this->restoreApplicationExact((string)$prepared['application_path'], $preservePaths);

            $expectedTreeHash = (string)($targetManifest['application']['tree_hash'] ?? '');
            if ($expectedTreeHash !== '') {
                $current = $this->applicationTreeMetadata($preservePaths);
                if (!hash_equals($expectedTreeHash, (string)$current['tree_hash'])) {
                    throw new RuntimeException('Application verification failed after restore.');
                }
            }

            $runtime->switchActiveDatabase((string)$candidate['database'], [
                'operation' => 'restore',
                'previous_database' => $sourceDatabase,
                'backup_id' => (string)($targetManifest['id'] ?? ''),
            ]);

            /** @var PDO $candidatePdo */
            $candidatePdo = $candidate['pdo'];
            $this->verifyDatabaseState($candidatePdo, $expectedObjects);

            $installed = $targetManifest['installed_release'] ?? null;
            if (is_array($installed) && $installed !== []) {
                $this->writeInstalledRelease($installed);
            }

            $runtime->exitMaintenance();
            $maintenanceActive = false;
            $runtime->cleanupRetention();

            return [
                'restored_id' => (string)($targetManifest['id'] ?? ''),
                'emergency_backup_id' => $emergency['id'],
                'emergency_backup_path' => $emergency['relative_path'],
                'previous_database' => $sourceDatabase,
                'active_database' => (string)$candidate['database'],
            ];
        } catch (Throwable $restoreError) {
            $rollbackError = null;
            try {
                // The original database was never dropped or migrated. Rollback is an
                // atomic pointer switch plus an application-only restore from the
                // emergency backup; no destructive database replay is required.
                $runtime->switchActiveDatabase($sourceDatabase, [
                    'operation' => 'restore_rollback',
                    'failed_restore_target' => (string)($targetManifest['id'] ?? ''),
                ]);
                if ($applicationChanged) {
                    $this->restoreApplicationFromBackupSet(
                        (string)$emergency['absolute_path'],
                        $preservePaths
                    );
                }
                if ($maintenanceActive) {
                    $runtime->exitMaintenance();
                    $maintenanceActive = false;
                }
                if (!empty($candidate['database'])) {
                    $runtime->discardStagedDatabase($pdo, (string)$candidate['database']);
                }
            } catch (Throwable $rollbackFailure) {
                $rollbackError = $rollbackFailure;
                // Deliberately leave maintenance mode active if rollback cannot be
                // proven. Serving an uncertain mixed state is worse than downtime.
            }

            $message = 'Restore failed before a verified cutover could complete: ' . $restoreError->getMessage();
            if ($rollbackError === null) {
                $message .= ' The original database remained intact and the previous application state was restored.';
            } else {
                $message .= ' Automatic application rollback also failed; maintenance mode remains active: '
                    . $rollbackError->getMessage();
            }
            throw new RuntimeException($message, 0, $restoreError);
        } finally {
            $this->cleanupPreparedBackupSet($prepared);
        }
    }

    public function restoreApplicationFromBackupSet(string $archivePath, array $preserve = []): void
    {
        $preservePaths = $this->normalizePreservePaths($preserve);
        $prepared = $this->prepareBackupSet($archivePath);
        try {
            $this->restoreApplicationExact((string)$prepared['application_path'], $preservePaths);
            $expectedTreeHash = (string)($prepared['manifest']['application']['tree_hash'] ?? '');
            if ($expectedTreeHash !== '') {
                $current = $this->applicationTreeMetadata($preservePaths);
                if (!hash_equals($expectedTreeHash, (string)$current['tree_hash'])) {
                    throw new RuntimeException('Application verification failed after application-only rollback.');
                }
            }
        } finally {
            $this->cleanupPreparedBackupSet($prepared);
        }
    }

    public function stageDatabaseFromBackupSet(PDO $pdo, string $archivePath): array
    {
        $prepared = $this->prepareBackupSet($archivePath);
        try {
            $manifest = $prepared['manifest'];
            $expectedObjects = is_array($manifest['database']['objects'] ?? null)
                ? $manifest['database']['objects']
                : [];
            $runtime = new EnterpriseRecovery($this->root);
            return $runtime->stageDatabase(
                $pdo,
                (string)$prepared['database_path'],
                $expectedObjects,
                true
            );
        } finally {
            $this->cleanupPreparedBackupSet($prepared);
        }
    }

    public function verifyDatabaseFromBackupSet(PDO $pdo, string $archivePath): void
    {
        $prepared = $this->prepareBackupSet($archivePath);
        try {
            $manifest = $prepared['manifest'];
            $expectedObjects = is_array($manifest['database']['objects'] ?? null)
                ? $manifest['database']['objects']
                : [];
            $runtime = new EnterpriseRecovery($this->root);
            $runtime->stageDatabase(
                $pdo,
                (string)$prepared['database_path'],
                $expectedObjects,
                false
            );
        } finally {
            $this->cleanupPreparedBackupSet($prepared);
        }
    }

    public function relativeToRoot(string $path): string
    {
        $root = $this->root . DIRECTORY_SEPARATOR;
        if (strpos($path, $root) === 0) {
            return ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
        }

        return $path;
    }

    private function restorePreparedBackupSet(PDO $pdo, array $prepared, array $preservePaths): void
    {
        $manifest = $prepared['manifest'];
        $dbPath = $prepared['database_path'];
        $appPath = $prepared['application_path'];

        $this->restoreDatabaseExact($pdo, $dbPath);
        $this->verifyDatabaseState($pdo, is_array($manifest['database']['objects'] ?? null)
            ? $manifest['database']['objects']
            : []);

        $this->restoreApplicationExact($appPath, $preservePaths);

        $expectedTreeHash = (string)($manifest['application']['tree_hash'] ?? '');
        if ($expectedTreeHash !== '') {
            $current = $this->applicationTreeMetadata($preservePaths);
            if (!hash_equals($expectedTreeHash, (string)$current['tree_hash'])) {
                throw new RuntimeException('Application verification failed after restore.');
            }
        }

        $installed = $manifest['installed_release'] ?? null;
        if (is_array($installed) && $installed !== []) {
            $this->writeInstalledRelease($installed);
        }
    }

    private function prepareBackupSet(string $archivePath): array
    {
        $validation = $this->validateBackupSet($archivePath);
        if (!$validation['valid']) {
            throw new RuntimeException('Backup set preflight failed: ' . implode('; ', $validation['errors']));
        }

        $working = $this->temporaryDirectory('restore_set_');
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            $this->removeDirectory($working);
            throw new RuntimeException('Unable to open backup set.');
        }

        try {
            $this->assertSafeArchiveEntries($zip);
            if (!$zip->extractTo($working, ['application.zip', 'database/backup.sql'])) {
                throw new RuntimeException('Unable to extract backup set components.');
            }
        } catch (Throwable $e) {
            $zip->close();
            $this->removeDirectory($working);
            throw $e;
        }
        $zip->close();

        return [
            'working_directory' => $working,
            'application_path' => $working . DIRECTORY_SEPARATOR . 'application.zip',
            'database_path' => $working . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backup.sql',
            'manifest' => $validation['manifest'],
        ];
    }

    private function cleanupPreparedBackupSet(array $prepared): void
    {
        $working = (string)($prepared['working_directory'] ?? '');
        if ($working !== '') {
            $this->removeDirectory($working);
        }
    }

    private function createApplicationSnapshot(string $destination, array $preservePaths): array
    {
        $this->ensureZipSupport();
        $archive = new ZipArchive();
        if ($archive->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create application snapshot.');
        }

        $files = [];
        $directories = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $relative = substr($fileInfo->getPathname(), strlen($this->root) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }

            $relative = str_replace('\\', '/', $relative);
            if ($this->shouldSkip($relative, $preservePaths)) {
                continue;
            }

            if ($fileInfo->isDir()) {
                $directories[] = $relative;
            } elseif ($fileInfo->isFile()) {
                $files[$relative] = $fileInfo->getPathname();
            }
        }

        ksort($files, SORT_STRING);
        sort($directories, SORT_STRING);
        $hash = hash_init('sha256');

        try {
            foreach ($directories as $relative) {
                $archive->addEmptyDir($relative);
            }

            foreach ($files as $relative => $absolute) {
                if ($archive->addFile($absolute, $relative) !== true) {
                    throw new RuntimeException('Unable to add application file to snapshot: ' . $relative);
                }

                $fileHash = hash_file('sha256', $absolute);
                if (!is_string($fileHash)) {
                    throw new RuntimeException('Unable to hash application file: ' . $relative);
                }
                hash_update($hash, $relative . "\0" . $fileHash . "\n");
            }
        } catch (Throwable $e) {
            $archive->close();
            @unlink($destination);
            throw $e;
        }

        if ($archive->close() !== true) {
            @unlink($destination);
            throw new RuntimeException('Unable to finalise application snapshot.');
        }

        return [
            'file_count' => count($files),
            'tree_hash' => hash_final($hash),
        ];
    }

    private function applicationTreeMetadata(array $preservePaths): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $relative = substr($fileInfo->getPathname(), strlen($this->root) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }

            $relative = str_replace('\\', '/', $relative);
            if ($this->shouldSkip($relative, $preservePaths)) {
                continue;
            }
            $files[$relative] = $fileInfo->getPathname();
        }

        ksort($files, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($files as $relative => $absolute) {
            $fileHash = hash_file('sha256', $absolute);
            if (!is_string($fileHash)) {
                throw new RuntimeException('Unable to hash restored application file: ' . $relative);
            }
            hash_update($hash, $relative . "\0" . $fileHash . "\n");
        }

        return [
            'file_count' => count($files),
            'tree_hash' => hash_final($hash),
        ];
    }

    private function restoreApplicationExact(string $archivePath, array $preservePaths): void
    {
        $staging = $this->temporaryDirectory('app_restore_');
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            $this->removeDirectory($staging);
            throw new RuntimeException('Unable to open application snapshot.');
        }

        try {
            $this->assertSafeArchiveEntries($zip);
            if (!$zip->extractTo($staging)) {
                throw new RuntimeException('Unable to extract application snapshot.');
            }
        } catch (Throwable $e) {
            $zip->close();
            $this->removeDirectory($staging);
            throw $e;
        }
        $zip->close();

        try {
            $currentIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($currentIterator as $fileInfo) {
                $relative = substr($fileInfo->getPathname(), strlen($this->root) + 1);
                if ($relative === false || $relative === '') {
                    continue;
                }

                $relative = str_replace('\\', '/', $relative);
                if ($this->shouldSkip($relative, $preservePaths)) {
                    continue;
                }

                $snapshotPath = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (file_exists($snapshotPath) || is_link($snapshotPath)) {
                    continue;
                }

                if ($fileInfo->isDir()) {
                    if (!@rmdir($fileInfo->getPathname()) && is_dir($fileInfo->getPathname())) {
                        throw new RuntimeException('Unable to remove application directory during exact restore: ' . $relative);
                    }
                } else {
                    if (!@unlink($fileInfo->getPathname()) && file_exists($fileInfo->getPathname())) {
                        throw new RuntimeException('Unable to remove application file during exact restore: ' . $relative);
                    }
                }
            }

            $sourceIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($sourceIterator as $fileInfo) {
                $relative = substr($fileInfo->getPathname(), strlen($staging) + 1);
                if ($relative === false || $relative === '') {
                    continue;
                }

                $relative = str_replace('\\', '/', $relative);
                if ($this->shouldSkip($relative, $preservePaths)) {
                    continue;
                }

                $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if ($fileInfo->isDir()) {
                    $this->ensureDirectory($target);
                    continue;
                }

                $this->ensureDirectory(dirname($target));
                if (!copy($fileInfo->getPathname(), $target)) {
                    throw new RuntimeException('Unable to restore application file: ' . $relative);
                }
            }
        } finally {
            $this->removeDirectory($staging);
        }
    }

    private function createDatabaseSnapshot(PDO $pdo, string $destination): array
    {
        $runtime = new EnterpriseRecovery($this->root);
        return $runtime->dumpDatabase($pdo, $destination);
    }

    private function databaseObjects(PDO $pdo, bool $includeRowCounts): array
    {
        $statement = $pdo->query('SHOW FULL TABLES');
        if (!$statement) {
            throw new RuntimeException('Unable to list database tables.');
        }

        $objects = [];
        while ($row = $statement->fetch(PDO::FETCH_NUM)) {
            if (!is_array($row) || !isset($row[0])) {
                continue;
            }

            $name = (string)$row[0];
            $rawType = strtoupper((string)($row[1] ?? 'BASE TABLE'));
            $type = str_contains($rawType, 'VIEW') ? 'VIEW' : 'BASE TABLE';
            $entry = [
                'name' => $name,
                'type' => $type,
                'row_count' => null,
            ];

            if ($includeRowCounts && $type === 'BASE TABLE') {
                $count = $pdo->query('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($name));
                if (!$count) {
                    throw new RuntimeException('Unable to count rows in table ' . $name);
                }
                $entry['row_count'] = (int)$count->fetchColumn();
            }

            $objects[] = $entry;
        }

        usort($objects, static function (array $a, array $b): int {
            $aType = (string)($a['type'] ?? 'BASE TABLE');
            $bType = (string)($b['type'] ?? 'BASE TABLE');
            if ($aType !== $bType) {
                return $aType === 'BASE TABLE' ? -1 : 1;
            }
            return strcmp((string)$a['name'], (string)$b['name']);
        });
        return $objects;
    }

    private function restoreDatabaseExact(PDO $pdo, string $dumpPath): void
    {
        if (!is_file($dumpPath)) {
            throw new RuntimeException('Database snapshot is missing.');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $objects = $this->databaseObjects($pdo, false);

            foreach ($objects as $object) {
                if (($object['type'] ?? '') !== 'VIEW') {
                    continue;
                }
                $pdo->exec('DROP VIEW IF EXISTS ' . $this->quoteIdentifier((string)$object['name']));
            }

            foreach ($objects as $object) {
                if (($object['type'] ?? '') === 'VIEW') {
                    continue;
                }
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier((string)$object['name']));
            }

            $this->executeSqlFile($pdo, $dumpPath);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function verifyDatabaseState(PDO $pdo, array $expectedObjects): void
    {
        $actual = $this->databaseObjects($pdo, true);

        $normalize = static function (array $objects): array {
            $result = [];
            foreach ($objects as $object) {
                $name = (string)($object['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $result[$name] = [
                    'type' => (string)($object['type'] ?? 'BASE TABLE'),
                    'row_count' => ($object['type'] ?? '') === 'VIEW'
                        ? null
                        : (int)($object['row_count'] ?? 0),
                ];
            }
            ksort($result, SORT_STRING);
            return $result;
        };

        if ($normalize($expectedObjects) !== $normalize($actual)) {
            throw new RuntimeException('Database verification failed after restore: schema or row counts do not match the backup.');
        }
    }

    private function executeSqlFile(?PDO $pdo, string $path, bool $validateOnly = false): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open database SQL snapshot.');
        }

        $buffer = '';
        $quote = null;
        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                if ($quote === null && trim($buffer) === '') {
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '-- ') || str_starts_with($trimmed, '#')) {
                        continue;
                    }
                }

                $length = strlen($line);
                for ($i = 0; $i < $length; $i++) {
                    $char = $line[$i];

                    if ($quote !== null) {
                        $buffer .= $char;

                        if ($char === $quote) {
                            // MySQL can escape string delimiters either with a backslash
                            // (for example: 'text\\'' when the value ends in an apostrophe)
                            // or by doubling the delimiter. Check backslash escaping first;
                            // otherwise the escaped apostrophe plus the real closing quote
                            // can be misread as a doubled-quote pair.
                            if ($quote !== '`') {
                                $backslashes = 0;
                                for ($j = strlen($buffer) - 2; $j >= 0 && $buffer[$j] === '\\'; $j--) {
                                    $backslashes++;
                                }
                                if ($backslashes % 2 === 1) {
                                    continue;
                                }
                            }

                            if ($i + 1 < $length && $line[$i + 1] === $quote) {
                                $buffer .= $line[$i + 1];
                                $i++;
                                continue;
                            }

                            $quote = null;
                        }
                        continue;
                    }

                    if ($char === "'" || $char === '"' || $char === '`') {
                        $quote = $char;
                        $buffer .= $char;
                        continue;
                    }

                    if ($char === ';') {
                        $statement = trim($buffer);
                        $buffer = '';
                        if ($statement === '') {
                            continue;
                        }

                        if (!$validateOnly) {
                            if (!$pdo instanceof PDO) {
                                throw new RuntimeException('Database connection is required to execute SQL.');
                            }
                            $pdo->exec($statement);
                        }
                        $count++;
                        continue;
                    }

                    $buffer .= $char;
                }
            }

            if ($quote !== null) {
                throw new RuntimeException('Database SQL snapshot contains an unterminated quoted value.');
            }

            $statement = trim($buffer);
            if ($statement !== '') {
                if (!$validateOnly) {
                    if (!$pdo instanceof PDO) {
                        throw new RuntimeException('Database connection is required to execute SQL.');
                    }
                    $pdo->exec($statement);
                }
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    private function validateSqlDumpStructure(string $path): void
    {
        $count = $this->executeSqlFile(null, $path, true);
        if ($count < 1) {
            throw new RuntimeException('Database SQL snapshot contains no executable statements.');
        }
    }

    private function readManifestFromArchive(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) {
                return null;
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        } finally {
            $zip->close();
        }
    }

    private function assertSafeArchiveEntries(ZipArchive $archive): void
    {
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $name = $archive->getNameIndex($i);
            if (!is_string($name)) {
                throw new RuntimeException('Unable to inspect ZIP entry.');
            }

            $normalized = str_replace('\\', '/', $name);
            if ($normalized === '' || str_contains($normalized, "\0")) {
                throw new RuntimeException('Backup archive contains an invalid path.');
            }
            if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized)) {
                throw new RuntimeException('Backup archive contains an absolute path.');
            }

            $segments = explode('/', $normalized);
            foreach ($segments as $segment) {
                if ($segment === '..') {
                    throw new RuntimeException('Backup archive contains path traversal.');
                }
            }
        }
    }

    private function normalizePreservePaths(array $preserve): array
    {
        $result = $this->defaultPreservePaths();
        foreach ($preserve as $path) {
            $normalized = trim(str_replace('\\', '/', (string)$path), '/');
            if ($normalized !== '') {
                $result[] = $normalized;
            }
        }

        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    private function shouldSkip(string $relative, array $preservePaths): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        foreach ($preservePaths as $path) {
            $path = trim(str_replace('\\', '/', (string)$path), '/');
            if ($path === '') {
                continue;
            }
            if ($relative === $path || str_starts_with($relative, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    private function readInstalledRelease(): ?array
    {
        if (!is_file($this->installedFile)) {
            return null;
        }

        $raw = file_get_contents($this->installedFile);
        if ($raw === false) {
            throw new RuntimeException('Unable to read installed release metadata.');
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeInstalledRelease(array $release): void
    {
        $this->ensureDirectory(dirname($this->installedFile));
        if (file_put_contents($this->installedFile, $this->encodeJson($release)) === false) {
            throw new RuntimeException('Unable to restore installed release metadata.');
        }
    }

    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode backup metadata.');
        }

        return $json . "\n";
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function path(string $relative): string
    {
        $normalized = ltrim(str_replace(['\\', "\0"], '/', $relative), '/');
        return $this->root . ($normalized !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized) : '');
    }

    private function temporaryDirectory(string $prefix): string
    {
        $this->ensureDirectory($this->tmpDirectory);
        $temp = tempnam($this->tmpDirectory, $prefix);
        if ($temp === false) {
            throw new RuntimeException('Unable to create temporary backup directory.');
        }
        @unlink($temp);
        if (!mkdir($temp, 0755, true) && !is_dir($temp)) {
            throw new RuntimeException('Unable to initialise temporary backup directory.');
        }

        return $temp;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory ' . $path);
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

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }

        @rmdir($path);
    }

    private function ensureZipSupport(): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ext-zip is required for system backup and restore.');
        }
    }
}

function system_backup_manager(): SystemBackupManager
{
    static $manager = null;
    if (!$manager instanceof SystemBackupManager) {
        $manager = new SystemBackupManager();
    }

    return $manager;
}
