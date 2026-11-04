<?php

declare(strict_types=1);

const SYSTEM_BACKUP_FORMAT_VERSION = 2;

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
            '.htaccess',
            '.user.ini',
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

    public function importBackupSet(string $uploadedPath, string $originalName = ''): array
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
                if ((int)($manifest['format_version'] ?? 0) !== SYSTEM_BACKUP_FORMAT_VERSION) {
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
        $cutoverState = $this->captureCutoverState();
        $currentDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($currentDatabase === '' || !preg_match('/^[A-Za-z0-9_]+$/', $currentDatabase)) {
            $this->cleanupPreparedBackupSet($prepared);
            throw new RuntimeException('Unable to determine the current live database safely.');
        }

        $emergencyId = 'pre_restore_' . date('Ymd_His');
        $emergencyPath = $this->emergencyDirectory . DIRECTORY_SEPARATOR . $emergencyId . '.zip';
        $emergency = $this->createBackupSet($pdo, 'emergency', $emergencyPath, [
            'id' => $emergencyId,
            'label' => 'Automatic pre-restore safety backup',
            'restore_target' => (string)($targetManifest['id'] ?? ''),
        ], $preservePaths);

        $candidate = null;
        $maintenanceEnabled = false;
        try {
            // Restore and verify the target DB in isolation. The current live DB is untouched.
            $expectedObjects = is_array($targetManifest['database']['objects'] ?? null)
                ? $targetManifest['database']['objects']
                : [];
            $candidate = $this->createVerifiedCandidateDatabase(
                $prepared['database_path'],
                $expectedObjects,
                'restore'
            );

            // Verify the application snapshot before entering maintenance mode.
            $this->verifyApplicationArchive(
                $prepared['application_path'],
                (string)($targetManifest['application']['tree_hash'] ?? '')
            );

            $this->setMaintenanceMode(true, 'restore');
            $maintenanceEnabled = true;

            // Application restore is the only live-file mutation. Database cutover happens
            // only after the application tree has restored and verified successfully.
            $this->restoreApplicationExact($prepared['application_path'], $preservePaths);
            $expectedTreeHash = (string)($targetManifest['application']['tree_hash'] ?? '');
            if ($expectedTreeHash !== '') {
                $current = $this->applicationTreeMetadata($preservePaths);
                if (!hash_equals($expectedTreeHash, (string)$current['tree_hash'])) {
                    throw new RuntimeException('Application verification failed after restore.');
                }
            }

            $this->switchActiveDatabase($candidate['db_name']);

            $installed = $targetManifest['installed_release'] ?? null;
            if (is_array($installed) && $installed !== []) {
                $this->writeInstalledRelease($installed);
            }

            $this->setMaintenanceMode(false);
            $maintenanceEnabled = false;

            return [
                'restored_id' => (string)($targetManifest['id'] ?? ''),
                'emergency_backup_id' => $emergency['id'],
                'emergency_backup_path' => $emergency['relative_path'],
                'previous_database' => $currentDatabase,
                'active_database' => $candidate['db_name'],
            ];
        } catch (Throwable $restoreError) {
            // Restore the exact pre-operation pointer and installed-release metadata.
            // The original live database itself is never rewritten.
            $stateRollbackError = null;
            try {
                $this->restoreCutoverState($cutoverState);
            } catch (Throwable $stateFailure) {
                $stateRollbackError = $stateFailure;
            }

            // Restore application files from the independently verified emergency backup.
            $rollbackError = null;
            try {
                $rollbackPrepared = $this->prepareBackupSet($emergency['absolute_path']);
                try {
                    $this->restoreApplicationExact($rollbackPrepared['application_path'], $preservePaths);
                } finally {
                    $this->cleanupPreparedBackupSet($rollbackPrepared);
                }
            } catch (Throwable $rollbackFailure) {
                $rollbackError = $rollbackFailure;
            }

            $cleanupError = null;
            if (is_array($candidate) && !empty($candidate['db_name'])) {
                try {
                    $this->dropCandidateDatabase((string)$candidate['db_name']);
                } catch (Throwable $cleanupFailure) {
                    $cleanupError = $cleanupFailure;
                }
            }

            $rollbackComplete = $stateRollbackError === null && $rollbackError === null;
            if ($rollbackComplete && $maintenanceEnabled) {
                try {
                    $this->setMaintenanceMode(false);
                    $maintenanceEnabled = false;
                } catch (Throwable $maintenanceFailure) {
                    $rollbackComplete = false;
                    $stateRollbackError = $maintenanceFailure;
                }
            }

            $message = 'Restore failed before a verified cutover completed: ' . $restoreError->getMessage();
            if ($rollbackComplete) {
                $message .= ' The previous database pointer, release metadata, and application files were restored exactly.';
                if ($cleanupError !== null) {
                    $message .= ' Candidate cleanup warning: ' . $cleanupError->getMessage();
                }
            } else {
                $message .= ' CAS remains in maintenance mode because rollback did not complete.';
                if ($stateRollbackError !== null) {
                    $message .= ' Runtime-state rollback: ' . $stateRollbackError->getMessage();
                }
                if ($rollbackError !== null) {
                    $message .= ' Application rollback: ' . $rollbackError->getMessage();
                }
                if ($cleanupError !== null) {
                    $message .= ' Candidate cleanup: ' . $cleanupError->getMessage();
                }
            }
            throw new RuntimeException($message, 0, $restoreError);
        } finally {
            // Never clear maintenance here. Success and a fully verified rollback
            // are the only paths allowed to reopen CAS.
            $this->cleanupPreparedBackupSet($prepared);
        }
    }

    public function createVerifiedCandidateDatabase(
        string $dumpPath,
        array $expectedObjects,
        string $purpose = 'candidate'
    ): array {
        if (!is_file($dumpPath) || (filesize($dumpPath) ?: 0) <= 0) {
            throw new RuntimeException('Database snapshot is missing or empty.');
        }

        $base = preg_replace('/[^A-Za-z0-9_]+/', '_', $purpose) ?: 'candidate';
        $dbName = 'cas_' . trim($base, '_') . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $admin = $this->adminConnection();
        $quotedDb = $this->quoteIdentifier($dbName);

        $admin->exec('CREATE DATABASE ' . $quotedDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            $this->restoreNativeDump($dumpPath, $dbName);
            $this->grantApplicationUserAccess($admin, $dbName);
            $candidatePdo = $this->connectToDatabase($dbName);
            $this->verifyDatabaseState($candidatePdo, $expectedObjects);

            // Critical application invariant: a CAS backup must contain the users table.
            $critical = $candidatePdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
            );
            if (!$critical || (int)$critical->fetchColumn() !== 1) {
                throw new RuntimeException('Candidate database validation failed: critical table users is missing.');
            }

            return ['db_name' => $dbName, 'pdo' => $candidatePdo];
        } catch (Throwable $e) {
            try {
                $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDb);
            } catch (Throwable $cleanupError) {
                // Do not hide the primary verification error.
            }
            throw $e;
        }
    }

    public function stageCandidateFromBackupSet(string $archivePath, string $purpose = 'upgrade'): array
    {
        $prepared = $this->prepareBackupSet($archivePath);
        try {
            $manifest = $prepared['manifest'];
            $expectedObjects = is_array($manifest['database']['objects'] ?? null)
                ? $manifest['database']['objects']
                : [];
            return $this->createVerifiedCandidateDatabase(
                $prepared['database_path'],
                $expectedObjects,
                $purpose
            );
        } finally {
            $this->cleanupPreparedBackupSet($prepared);
        }
    }

    public function switchActiveDatabase(string $dbName): void
    {
        if ($dbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException('Refusing to activate an unsafe database name.');
        }

        // Prove the target exists and is connectable before changing the pointer.
        $this->connectToDatabase($dbName)->query('SELECT 1');

        $target = $this->storageDirectory . DIRECTORY_SEPARATOR . 'active_database.json';
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
        $payload = $this->encodeJson([
            'db_name' => $dbName,
            'activated_at' => date(DATE_ATOM),
        ]);
        if (file_put_contents($tmp, $payload) === false) {
            throw new RuntimeException('Unable to write active database pointer.');
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to atomically switch active database pointer.');
        }
    }

    public function setMaintenanceMode(bool $enabled, string $reason = ''): void
    {
        $flag = $this->storageDirectory . DIRECTORY_SEPARATOR . 'maintenance.flag';
        if (!$enabled) {
            if (is_file($flag) && !@unlink($flag)) {
                throw new RuntimeException('Unable to disable maintenance mode.');
            }
            return;
        }

        $payload = $this->encodeJson([
            'enabled_at' => date(DATE_ATOM),
            'reason' => $reason,
        ]);
        if (file_put_contents($flag, $payload) === false) {
            throw new RuntimeException('Unable to enable maintenance mode.');
        }
    }

    public function captureCutoverState(): array
    {
        return [
            'active_database' => $this->captureFileState(
                $this->storageDirectory . DIRECTORY_SEPARATOR . 'active_database.json'
            ),
            'installed_release' => $this->captureFileState($this->installedFile),
        ];
    }

    public function restoreCutoverState(array $state): void
    {
        if (!isset($state['active_database'], $state['installed_release'])
            || !is_array($state['active_database'])
            || !is_array($state['installed_release'])
        ) {
            throw new RuntimeException('Cutover rollback state is incomplete.');
        }

        $this->restoreFileState(
            $this->storageDirectory . DIRECTORY_SEPARATOR . 'active_database.json',
            $state['active_database']
        );
        $this->restoreFileState($this->installedFile, $state['installed_release']);
    }

    public function restoreApplicationFromBackupSet(string $archivePath, array $preserve = []): void
    {
        $preservePaths = $this->normalizePreservePaths($preserve);
        $prepared = $this->prepareBackupSet($archivePath);
        try {
            $this->restoreApplicationExact($prepared['application_path'], $preservePaths);
            $expectedTreeHash = (string)($prepared['manifest']['application']['tree_hash'] ?? '');
            if ($expectedTreeHash !== '') {
                $current = $this->applicationTreeMetadata($preservePaths);
                if (!hash_equals($expectedTreeHash, (string)$current['tree_hash'])) {
                    throw new RuntimeException('Application rollback verification failed.');
                }
            }
        } finally {
            $this->cleanupPreparedBackupSet($prepared);
        }
    }

    public function dropCandidateDatabase(string $dbName): void
    {
        if (preg_match('/^cas_(restore|verify|upgrade)_/', $dbName) !== 1) {
            throw new RuntimeException('Refusing to drop a database outside the CAS candidate namespace.');
        }

        $active = $this->readActiveDatabaseName();
        if ($active !== '' && $active === $dbName) {
            throw new RuntimeException('Refusing to drop the currently active CAS database.');
        }

        $this->adminConnection()->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($dbName));
    }

    public function cleanupRetention(int $keepRuns = 5, int $keepEmergency = 3, int $keepCandidates = 3): array
    {
        $removed = [];
        $this->pruneDirectories($this->runsDirectory, $keepRuns, $removed);
        $this->pruneFiles($this->emergencyDirectory, '*.zip', $keepEmergency, $removed);

        $active = $this->readActiveDatabaseName();
        $connection = $this->databaseConnectionSettings();
        $admin = $this->adminConnection();
        $rows = $admin->query("SHOW DATABASES LIKE 'cas_%'")->fetchAll(PDO::FETCH_COLUMN);
        $candidates = array_values(array_filter(array_map('strval', $rows ?: []), static function (string $name) use ($active): bool {
            return $name !== $active && preg_match('/^cas_(restore|verify|upgrade)_/', $name) === 1;
        }));
        rsort($candidates, SORT_STRING);
        foreach (array_slice($candidates, max(0, $keepCandidates)) as $name) {
            $admin->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($name));
            $removed[] = 'database:' . $name;
        }

        return $removed;
    }

    private function verifyNativeDumpInStaging(string $dumpPath, array $expectedObjects): void
    {
        $candidate = $this->createVerifiedCandidateDatabase($dumpPath, $expectedObjects, 'verify');
        try {
            // Successful construction is the verification.
        } finally {
            $admin = $this->adminConnection();
            $admin->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($candidate['db_name']));
        }
    }

    private function restoreNativeDump(string $dumpPath, string $dbName): void
    {
        $binary = $this->findExecutable(['mysql', 'mariadb']);
        if ($binary === null) {
            throw new RuntimeException('Native MySQL/MariaDB client is required (mysql or mariadb).');
        }

        $connection = $this->databaseConnectionSettings(true);
        $args = [
            $binary,
            '--host=' . $connection['host'],
            '--port=' . (string)$connection['port'],
            '--user=' . $connection['user'],
            '--default-character-set=utf8mb4',
            $dbName,
        ];
        $this->runNativeCommand($args, ['MYSQL_PWD' => $connection['pass']], $dumpPath);
    }

    private function grantApplicationUserAccess(PDO $admin, string $dbName): void
    {
        $app = $this->databaseConnectionSettings(false);
        $probe = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $app['host'], $app['port']),
            $app['user'],
            $app['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $identity = (string)$probe->query('SELECT CURRENT_USER()')->fetchColumn();
        if (!preg_match('/^([^@]+)@(.+)$/', $identity, $m)) {
            throw new RuntimeException('Unable to determine application database account identity for candidate access.');
        }

        $quotedUser = $admin->quote($m[1]);
        $quotedHost = $admin->quote($m[2]);
        if (!is_string($quotedUser) || !is_string($quotedHost)) {
            throw new RuntimeException('Unable to quote application database account identity safely.');
        }
        $admin->exec(
            'GRANT ALL PRIVILEGES ON ' . $this->quoteIdentifier($dbName) . '.* TO '
            . $quotedUser . '@' . $quotedHost
        );
    }

    private function databaseConnectionSettings(bool $admin = false): array
    {
        $host = (string)(getenv('DB_HOST') ?: '127.0.0.1');
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $user = (string)(getenv($admin ? 'DB_ADMIN_USER' : 'DB_USER') ?: '');
        $pass = (string)(getenv($admin ? 'DB_ADMIN_PASS' : 'DB_PASS') ?: '');

        if ($admin && $user === '') {
            $user = (string)(getenv('DB_USER') ?: '');
            $pass = (string)(getenv('DB_PASS') ?: '');
        }
        if ($user === '') {
            throw new RuntimeException(($admin ? 'DB_ADMIN_USER/DB_USER' : 'DB_USER') . ' is required for native database operations.');
        }

        return ['host' => $host, 'port' => $port > 0 ? $port : 3306, 'user' => $user, 'pass' => $pass];
    }

    private function adminConnection(): PDO
    {
        $s = $this->databaseConnectionSettings(true);
        return new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $s['host'], $s['port']),
            $s['user'],
            $s['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    private function connectToDatabase(string $dbName): PDO
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException('Unsafe database name.');
        }
        $s = $this->databaseConnectionSettings(false);
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $s['host'], $s['port'], $dbName),
            $s['user'],
            $s['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    private function findExecutable(array $names): ?string
    {
        $paths = explode(PATH_SEPARATOR, (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'));
        foreach ($names as $name) {
            foreach ($paths as $path) {
                $candidate = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function runNativeCommand(array $command, array $extraEnv = [], ?string $stdinFile = null): void
    {
        $descriptor = [
            0 => $stdinFile !== null ? ['file', $stdinFile, 'rb'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge($_ENV, $extraEnv);
        foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
            $value = getenv($key);
            if ($value !== false && !array_key_exists($key, $env)) {
                $env[$key] = $value;
            }
        }

        $process = proc_open($command, $descriptor, $pipes, $this->root, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start native database utility.');
        }
        if ($stdinFile === null && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException(
                'Native database utility failed with exit code ' . $exit . ': ' . trim((string)$stderr ?: (string)$stdout)
            );
        }
    }

    private function verifyApplicationArchive(string $archivePath, string $expectedTreeHash): void
    {
        $staging = $this->temporaryDirectory('app_verify_');
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            $this->removeDirectory($staging);
            throw new RuntimeException('Unable to open application snapshot for verification.');
        }
        try {
            $this->assertSafeArchiveEntries($zip);
            if (!$zip->extractTo($staging)) {
                throw new RuntimeException('Unable to extract application snapshot for verification.');
            }
        } finally {
            $zip->close();
        }

        try {
            if ($expectedTreeHash !== '') {
                $files = [];
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile()) continue;
                    $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($staging) + 1));
                    $files[$relative] = $fileInfo->getPathname();
                }
                ksort($files, SORT_STRING);
                $hash = hash_init('sha256');
                foreach ($files as $relative => $absolute) {
                    hash_update($hash, $relative . "\0" . hash_file('sha256', $absolute) . "\n");
                }
                $actual = hash_final($hash);
                if (!hash_equals($expectedTreeHash, $actual)) {
                    throw new RuntimeException('Application snapshot tree hash verification failed before cutover.');
                }
            }
        } finally {
            $this->removeDirectory($staging);
        }
    }

    private function readActiveDatabaseName(): string
    {
        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . 'active_database.json';
        if (!is_file($path)) return '';
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? trim((string)($data['db_name'] ?? '')) : '';
    }

    private function pruneDirectories(string $directory, int $keep, array &$removed): void
    {
        $entries = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        usort($entries, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($entries, max(0, $keep)) as $entry) {
            $this->removeDirectory($entry);
            $removed[] = $this->relativeToRoot($entry);
        }
    }

    private function pruneFiles(string $directory, string $pattern, int $keep, array &$removed): void
    {
        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pattern) ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, max(0, $keep)) as $file) {
            if (@unlink($file)) {
                $removed[] = $this->relativeToRoot($file);
            }
        }
    }

    private function captureFileState(string $path): array
    {
        if (!file_exists($path)) {
            return ['exists' => false, 'contents' => null, 'mode' => null];
        }
        if (!is_file($path)) {
            throw new RuntimeException('Expected runtime state path to be a regular file: ' . $path);
        }

        $contents = file_get_contents($path);
        $mode = fileperms($path);
        if (!is_string($contents) || $mode === false) {
            throw new RuntimeException('Unable to capture runtime state file: ' . $path);
        }

        return [
            'exists' => true,
            'contents' => $contents,
            'mode' => $mode & 0777,
        ];
    }

    private function restoreFileState(string $path, array $state): void
    {
        $exists = (bool)($state['exists'] ?? false);
        if (!$exists) {
            if (file_exists($path) && !@unlink($path)) {
                throw new RuntimeException('Unable to remove runtime state file during rollback: ' . $path);
            }
            return;
        }

        $contents = $state['contents'] ?? null;
        if (!is_string($contents)) {
            throw new RuntimeException('Runtime rollback contents are invalid for ' . $path);
        }

        $tmp = $path . '.rollback-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to prepare runtime state rollback for ' . $path);
        }

        $mode = isset($state['mode']) ? (int)$state['mode'] : 0640;
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to atomically restore runtime state file: ' . $path);
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
        $objects = $this->databaseObjects($pdo, true);
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException('Unable to determine a safe source database name.');
        }

        $binary = $this->findExecutable(['mysqldump', 'mariadb-dump']);
        if ($binary === null) {
            throw new RuntimeException('Native MySQL/MariaDB dump utility is required (mysqldump or mariadb-dump).');
        }

        $connection = $this->databaseConnectionSettings();
        $args = [
            $binary,
            '--host=' . $connection['host'],
            '--port=' . (string)$connection['port'],
            '--user=' . $connection['user'],
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--skip-lock-tables',
            '--routines',
            '--events',
            '--triggers',
            '--hex-blob',
            '--no-tablespaces',
            '--result-file=' . $destination,
            $dbName,
        ];

        $this->runNativeCommand($args, ['MYSQL_PWD' => $connection['pass']]);

        clearstatcache(true, $destination);
        if (!is_file($destination) || (filesize($destination) ?: 0) <= 0) {
            @unlink($destination);
            throw new RuntimeException('Native database dump completed without producing a usable snapshot.');
        }

        // Prove the dump can be restored before it is accepted as a backup.
        $this->verifyNativeDumpInStaging($destination, $objects);

        return [
            'objects' => $objects,
            'object_count' => count($objects),
            'dump_engine' => basename($binary),
            'source_database' => $dbName,
            'verified_restore' => true,
        ];
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
