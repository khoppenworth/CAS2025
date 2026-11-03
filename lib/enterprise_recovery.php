<?php

declare(strict_types=1);

/**
 * Enterprise-safe primitives for CAS backup/restore and upgrade operations.
 *
 * Design rules:
 * - database dumps/restores use native MySQL/MariaDB clients, never a PHP SQL parser;
 * - every new dump is test-restored into an isolated candidate database before it is trusted;
 * - the currently active database is never dropped or migrated in place;
 * - active database cutover is an atomic JSON pointer update under storage/upgrades;
 * - live application mutation happens only while the maintenance gate is active.
 */
final class EnterpriseRecovery
{
    private string $root;
    private string $storage;
    private string $tmp;
    private string $activeDatabaseFile;
    private string $databaseGenerationsFile;
    private string $maintenanceFile;

    public function __construct(?string $root = null)
    {
        $resolved = $root ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__));
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
        $this->storage = $this->root . '/storage/upgrades';
        $this->tmp = $this->storage . '/tmp';
        $this->activeDatabaseFile = $this->storage . '/active_database.json';
        $this->databaseGenerationsFile = $this->storage . '/database_generations.json';
        $this->maintenanceFile = $this->storage . '/maintenance.json';

        foreach ([$this->storage, $this->tmp] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create upgrade runtime directory: ' . $directory);
            }
        }
    }

    public function activeDatabaseName(PDO $pdo): string
    {
        $name = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException('Unable to determine the active database name.');
        }
        return $name;
    }

    public function appDatabaseConfig(PDO $pdo): array
    {
        return [
            'host' => (string)(getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => $this->normalizePort(getenv('DB_PORT')),
            'database' => $this->activeDatabaseName($pdo),
            'user' => (string)(getenv('DB_USER') ?: 'epss_user'),
            'password' => (string)(getenv('DB_PASS') ?: ''),
        ];
    }

    public function maintenanceDatabaseConfig(PDO $pdo): array
    {
        $app = $this->appDatabaseConfig($pdo);
        return [
            'host' => (string)(getenv('DB_MAINT_HOST') ?: $app['host']),
            'port' => $this->normalizePort(getenv('DB_MAINT_PORT')) ?? $app['port'],
            'database' => $app['database'],
            'user' => (string)(getenv('DB_MAINT_USER') ?: $app['user']),
            'password' => (string)(getenv('DB_MAINT_PASS') ?: $app['password']),
        ];
    }

    public function dumpDatabase(PDO $pdo, string $destination): array
    {
        $app = $this->appDatabaseConfig($pdo);
        $config = $this->maintenanceDatabaseConfig($pdo);
        $config['database'] = $app['database'];
        $tool = $this->findExecutable(['mysqldump', 'mariadb-dump']);
        $defaults = $this->createDefaultsFile($config);

        try {
            $command = [
                $tool,
                '--defaults-extra-file=' . $defaults,
                '--host=' . $config['host'],
                '--user=' . $config['user'],
                '--single-transaction',
                '--quick',
                '--routines',
                '--events',
                '--triggers',
                '--hex-blob',
                '--skip-comments',
                '--default-character-set=utf8mb4',
            ];
            if ($config['port'] !== null) {
                $command[] = '--port=' . (string)$config['port'];
            }

            $serverVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            if (stripos($serverVersion, 'mariadb') === false) {
                $command[] = '--no-tablespaces';
            }
            $command[] = $config['database'];

            $this->runCommand($command, null, $destination);

            clearstatcache(true, $destination);
            if (!is_file($destination) || (filesize($destination) ?: 0) < 32) {
                throw new RuntimeException('Native database dump did not produce a usable SQL file.');
            }

            // A backup is not trusted until the native client has restored it successfully.
            $verified = $this->verifyDumpByStaging($pdo, $destination);

            return [
                'engine' => 'native-mysql-client',
                'tool' => basename($tool),
                'server_version' => $serverVersion,
                'objects' => $verified['objects'],
                'object_count' => count($verified['objects']),
                'verified_restore' => true,
            ];
        } finally {
            @unlink($defaults);
        }
    }

    /**
     * Restore a dump into an isolated database, validate it, and optionally keep it for cutover.
     */
    public function stageDatabase(PDO $livePdo, string $dumpPath, ?array $expectedObjects = null, bool $keep = true): array
    {
        if (!is_file($dumpPath) || (filesize($dumpPath) ?: 0) <= 0) {
            throw new RuntimeException('Database dump is missing or empty.');
        }

        $app = $this->appDatabaseConfig($livePdo);
        $maintenance = $this->maintenanceDatabaseConfig($livePdo);
        $admin = $this->serverConnection($maintenance);
        $candidate = 'cas_stage_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));

        if (preg_match('/^[A-Za-z0-9_]+$/', $candidate) !== 1) {
            throw new RuntimeException('Unsafe candidate database name generated.');
        }

        try {
            $defaults = $this->sourceDatabaseDefaults($livePdo);
            $admin->exec(
                'CREATE DATABASE ' . $this->quoteIdentifier($candidate)
                . ' CHARACTER SET ' . $defaults['charset']
                . ' COLLATE ' . $defaults['collation']
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to create isolated staging database. Configure DB_MAINT_USER/DB_MAINT_PASS with CREATE/DROP/GRANT privileges. '
                . $e->getMessage(),
                0,
                $e
            );
        }

        $restoreConfig = $maintenance;
        $restoreConfig['database'] = $candidate;
        $defaults = $this->createDefaultsFile($restoreConfig);
        $tool = $this->findExecutable(['mysql', 'mariadb']);

        try {
            $command = [
                $tool,
                '--defaults-extra-file=' . $defaults,
                '--host=' . $restoreConfig['host'],
                '--user=' . $restoreConfig['user'],
                '--default-character-set=utf8mb4',
            ];
            if ($restoreConfig['port'] !== null) {
                $command[] = '--port=' . (string)$restoreConfig['port'];
            }
            $command[] = $candidate;
            $this->runCommand($command, $dumpPath, null);

            $candidateAdminPdo = $this->databaseConnection($maintenance, $candidate);
            $objects = $this->databaseObjects($candidateAdminPdo, true);
            if ($objects === []) {
                throw new RuntimeException('Staging restore completed but produced no database objects.');
            }

            if ($expectedObjects !== null && $expectedObjects !== []) {
                if ($this->normalizeObjects($expectedObjects) !== $this->normalizeObjects($objects)) {
                    throw new RuntimeException('Staging database does not match the backup manifest object/row counts.');
                }
            }

            $this->grantCandidateToApplicationAccount($livePdo, $admin, $candidate);
            $candidateAppPdo = $this->databaseConnection($app, $candidate);
            $candidateAppPdo->query('SELECT 1')->fetchColumn();

            $tableNames = array_map(static fn(array $object): string => (string)$object['name'], $objects);
            if (in_array('users', $tableNames, true)) {
                $candidateAppPdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            }

            $result = [
                'database' => $candidate,
                'objects' => $objects,
                'pdo' => $candidateAppPdo,
                'source_database' => $app['database'],
            ];

            if (!$keep) {
                $this->dropDatabase($admin, $candidate);
            }

            return $result;
        } catch (Throwable $e) {
            try {
                $this->dropDatabase($admin, $candidate);
            } catch (Throwable $cleanupError) {
                error_log('Candidate database cleanup failed: ' . $cleanupError->getMessage());
            }
            throw $e;
        } finally {
            @unlink($defaults);
        }
    }

    public function verifyDumpByStaging(PDO $livePdo, string $dumpPath): array
    {
        return $this->stageDatabase($livePdo, $dumpPath, null, false);
    }

    public function discardStagedDatabase(PDO $livePdo, string $database): void
    {
        $maintenance = $this->maintenanceDatabaseConfig($livePdo);
        $admin = $this->serverConnection($maintenance);
        $this->dropDatabase($admin, $database);
    }

    public function executeSqlFileOnDatabase(PDO $livePdo, string $database, string $sqlPath): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('Unsafe database name for native migration execution.');
        }
        if (!is_file($sqlPath) || (filesize($sqlPath) ?: 0) <= 0) {
            throw new RuntimeException('Migration SQL file is missing or empty: ' . $sqlPath);
        }

        $maintenance = $this->maintenanceDatabaseConfig($livePdo);
        $config = $maintenance;
        $config['database'] = $database;
        $defaults = $this->createDefaultsFile($config);
        $tool = $this->findExecutable(['mysql', 'mariadb']);

        try {
            $command = [
                $tool,
                '--defaults-extra-file=' . $defaults,
                '--host=' . $config['host'],
                '--user=' . $config['user'],
                '--default-character-set=utf8mb4',
            ];
            if ($config['port'] !== null) {
                $command[] = '--port=' . (string)$config['port'];
            }
            $command[] = $database;
            $this->runCommand($command, $sqlPath, null);
        } finally {
            @unlink($defaults);
        }
    }

    public function connectApplicationDatabase(PDO $livePdo, string $database): PDO
    {
        return $this->databaseConnection($this->appDatabaseConfig($livePdo), $database);
    }

    public function switchActiveDatabase(string $database, array $metadata = []): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('Refusing to activate an unsafe database name.');
        }

        $payload = array_merge([
            'database' => $database,
            'activated_at' => date(DATE_ATOM),
        ], $metadata);

        $this->atomicJsonWrite($this->activeDatabaseFile, $payload);
        $this->recordDatabaseGeneration($database, $payload);
    }

    public function cleanupDatabaseGenerations(PDO $livePdo, int $keep = 3): array
    {
        $keep = max(2, $keep);
        $active = $this->readActiveDatabasePointer();
        $activeName = (string)($active['database'] ?? $this->activeDatabaseName($livePdo));

        $registry = $this->readDatabaseGenerations();
        usort($registry, static function (array $a, array $b): int {
            return strcmp((string)($b['activated_at'] ?? ''), (string)($a['activated_at'] ?? ''));
        });

        $keepNames = [$activeName => true];
        foreach ($registry as $entry) {
            $name = (string)($entry['database'] ?? '');
            if ($name === '' || isset($keepNames[$name])) {
                continue;
            }
            if (count($keepNames) >= $keep) {
                break;
            }
            $keepNames[$name] = true;
        }

        $maintenance = $this->maintenanceDatabaseConfig($livePdo);
        $admin = $this->serverConnection($maintenance);
        $existing = $admin->query("SHOW DATABASES LIKE 'cas_stage_%'")->fetchAll(PDO::FETCH_COLUMN);
        $existingSet = [];
        foreach ($existing as $name) {
            $existingSet[(string)$name] = true;
        }

        $removed = [];
        $remainingRegistry = [];
        foreach ($registry as $entry) {
            $name = (string)($entry['database'] ?? '');
            if ($name === '') {
                continue;
            }

            // Never drop the baseline/original DB, the active DB, an unregistered
            // database, or anything outside our generated staging namespace.
            if (!isset($existingSet[$name])
                || isset($keepNames[$name])
                || preg_match('/^cas_stage_[A-Za-z0-9_]+$/', $name) !== 1) {
                $remainingRegistry[] = $entry;
                continue;
            }

            $this->dropDatabase($admin, $name);
            $removed[] = $name;
        }

        $this->atomicJsonWrite($this->databaseGenerationsFile, [
            'updated_at' => date(DATE_ATOM),
            'generations' => array_values($remainingRegistry),
        ]);

        return $removed;
    }

    public function clearActiveDatabasePointer(): void
    {
        if (is_file($this->activeDatabaseFile) && !@unlink($this->activeDatabaseFile)) {
            throw new RuntimeException('Unable to remove active database pointer.');
        }
    }

    public function enterMaintenance(string $reason, array $metadata = []): void
    {
        $this->atomicJsonWrite($this->maintenanceFile, array_merge([
            'active' => true,
            'reason' => $reason,
            'started_at' => date(DATE_ATOM),
        ], $metadata));
    }

    public function exitMaintenance(): void
    {
        if (is_file($this->maintenanceFile) && !@unlink($this->maintenanceFile)) {
            throw new RuntimeException('Unable to clear maintenance mode.');
        }
    }

    public function maintenanceFile(): string
    {
        return $this->maintenanceFile;
    }

    public function validateReleaseTree(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        foreach (['config.php', 'admin/upgrade.php', 'lib/upgrade.php', 'scripts/system_upgrade.php'] as $required) {
            if (!is_file($root . DIRECTORY_SEPARATOR . $required)) {
                throw new RuntimeException('Release package is missing required file: ' . $required);
            }
        }

        $php = PHP_BINARY ?: $this->findExecutable(['php']);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $count = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $this->runCommand([$php, '-l', $file->getPathname()], null, null, false);
            $count++;
        }
        if ($count < 1) {
            throw new RuntimeException('Release validation found no PHP files.');
        }

        return ['php_files_linted' => $count];
    }

    public function cleanupRetention(int $keepRuns = 5, int $keepEmergency = 3): array
    {
        $removed = [];
        $removed = array_merge(
            $removed,
            $this->trimDirectories($this->storage . '/runs', $keepRuns),
            $this->trimFiles($this->storage . '/emergency', '*.zip', $keepEmergency)
        );
        return $removed;
    }

    public function databaseObjects(PDO $pdo, bool $includeRowCounts): array
    {
        $statement = $pdo->query('SHOW FULL TABLES');
        $objects = [];
        while ($row = $statement->fetch(PDO::FETCH_NUM)) {
            $name = (string)($row[0] ?? '');
            if ($name === '') {
                continue;
            }
            $rawType = strtoupper((string)($row[1] ?? 'BASE TABLE'));
            $type = str_contains($rawType, 'VIEW') ? 'VIEW' : 'BASE TABLE';
            $entry = ['name' => $name, 'type' => $type, 'row_count' => null];
            if ($includeRowCounts && $type === 'BASE TABLE') {
                $entry['row_count'] = (int)$pdo->query(
                    'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($name)
                )->fetchColumn();
            }
            $objects[] = $entry;
        }
        usort($objects, static function (array $a, array $b): int {
            if (($a['type'] ?? '') !== ($b['type'] ?? '')) {
                return ($a['type'] ?? '') === 'BASE TABLE' ? -1 : 1;
            }
            return strcmp((string)$a['name'], (string)$b['name']);
        });
        return $objects;
    }

    private function normalizeObjects(array $objects): array
    {
        $normalized = [];
        foreach ($objects as $object) {
            $name = (string)($object['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = (string)($object['type'] ?? 'BASE TABLE');
            $normalized[$name] = [
                'type' => $type,
                'row_count' => $type === 'VIEW' ? null : (int)($object['row_count'] ?? 0),
            ];
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private function grantCandidateToApplicationAccount(PDO $livePdo, PDO $admin, string $candidate): void
    {
        $currentUser = (string)$livePdo->query('SELECT CURRENT_USER()')->fetchColumn();
        if ($currentUser === '' || strpos($currentUser, '@') === false) {
            throw new RuntimeException('Unable to determine the authenticated application database account.');
        }

        [$user, $host] = explode('@', $currentUser, 2);
        $account = $admin->quote($user) . '@' . $admin->quote($host);
        try {
            $admin->exec('GRANT ALL PRIVILEGES ON ' . $this->quoteIdentifier($candidate) . '.* TO ' . $account);
        } catch (Throwable $e) {
            // If the maintenance and application account are the same, explicit GRANT may not be necessary.
            $app = $this->appDatabaseConfig($livePdo);
            if ($app['user'] !== $this->maintenanceDatabaseConfig($livePdo)['user']) {
                throw new RuntimeException('Unable to grant the application account access to the candidate database: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    private function sourceDatabaseDefaults(PDO $pdo): array
    {
        $statement = $pdo->query(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME '
            . 'FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
        );
        $row = $statement ? $statement->fetch(PDO::FETCH_ASSOC) : false;
        $charset = is_array($row) ? (string)($row['DEFAULT_CHARACTER_SET_NAME'] ?? '') : '';
        $collation = is_array($row) ? (string)($row['DEFAULT_COLLATION_NAME'] ?? '') : '';

        if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1) {
            throw new RuntimeException('Unable to determine safe source database character set/collation.');
        }

        return ['charset' => $charset, 'collation' => $collation];
    }

    private function serverConnection(array $config): PDO
    {
        $dsn = 'mysql:host=' . $config['host'] . ';'
            . ($config['port'] !== null ? 'port=' . $config['port'] . ';' : '')
            . 'charset=utf8mb4';
        return new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function databaseConnection(array $config, string $database): PDO
    {
        $dsn = 'mysql:host=' . $config['host'] . ';'
            . ($config['port'] !== null ? 'port=' . $config['port'] . ';' : '')
            . 'dbname=' . $database . ';charset=utf8mb4';
        return new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function createDefaultsFile(array $config): string
    {
        $path = $this->tmp . '/mysql-' . bin2hex(random_bytes(8)) . '.cnf';
        $contents = "[client]\n"
            . 'user=' . $this->optionValue((string)$config['user']) . "\n"
            . 'password=' . $this->optionValue((string)$config['password']) . "\n";
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to create temporary MySQL client configuration.');
        }
        @chmod($path, 0600);
        return $path;
    }

    private function optionValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function findExecutable(array $names): string
    {
        $paths = ['/usr/bin', '/usr/local/bin', '/bin'];
        foreach ($names as $name) {
            foreach ($paths as $path) {
                $candidate = $path . '/' . $name;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach ($names as $name) {
            $result = [];
            $code = 1;
            @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $result, $code);
            if ($code === 0 && !empty($result[0]) && is_executable($result[0])) {
                return $result[0];
            }
        }

        throw new RuntimeException('Required native database client is not installed: ' . implode(' or ', $names));
    }

    private function runCommand(
        array $command,
        ?string $stdinPath = null,
        ?string $stdoutPath = null,
        bool $captureOutput = true
    ): string {
        $stderrPath = $this->tmp . '/command-stderr-' . bin2hex(random_bytes(6)) . '.log';
        $descriptors = [
            0 => $stdinPath !== null ? ['file', $stdinPath, 'rb'] : ['file', '/dev/null', 'rb'],
            1 => $stdoutPath !== null ? ['file', $stdoutPath, 'wb'] : ['pipe', 'w'],
            2 => ['file', $stderrPath, 'wb'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->root);
        if (!is_resource($process)) {
            @unlink($stderrPath);
            throw new RuntimeException('Unable to start native database command.');
        }

        $stdout = '';
        if ($stdoutPath === null && isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
        }

        $exit = proc_close($process);
        $stderr = is_file($stderrPath) ? ((string)file_get_contents($stderrPath)) : '';
        @unlink($stderrPath);

        if ($exit !== 0) {
            throw new RuntimeException(
                'Native database command failed with exit code ' . $exit . ($stderr !== '' ? ': ' . trim($stderr) : '')
            );
        }

        return $captureOutput ? $stdout : '';
    }

    private function dropDatabase(PDO $admin, string $database): void
    {
        if (preg_match('/^cas_stage_[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException('Refusing to drop a database outside the CAS staging namespace.');
        }
        $admin->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));
    }

    private function quoteIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    private function normalizePort($value): ?int
    {
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        $port = (int)$value;
        return $port > 0 && $port <= 65535 ? $port : null;
    }

    private function readActiveDatabasePointer(): array
    {
        if (!is_file($this->activeDatabaseFile)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($this->activeDatabaseFile), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function readDatabaseGenerations(): array
    {
        if (!is_file($this->databaseGenerationsFile)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($this->databaseGenerationsFile), true);
        if (!is_array($decoded) || !is_array($decoded['generations'] ?? null)) {
            return [];
        }
        return array_values(array_filter($decoded['generations'], 'is_array'));
    }

    private function recordDatabaseGeneration(string $database, array $metadata): void
    {
        // Only generated candidates participate in automatic DB retention.
        // Baseline DB names are deliberately never registered for deletion.
        if (preg_match('/^cas_stage_[A-Za-z0-9_]+$/', $database) !== 1) {
            return;
        }

        $registry = $this->readDatabaseGenerations();
        $registry = array_values(array_filter(
            $registry,
            static fn(array $entry): bool => (string)($entry['database'] ?? '') !== $database
        ));
        array_unshift($registry, [
            'database' => $database,
            'activated_at' => (string)($metadata['activated_at'] ?? date(DATE_ATOM)),
            'operation' => (string)($metadata['operation'] ?? ''),
            'previous_database' => (string)($metadata['previous_database'] ?? ''),
            'release' => (string)($metadata['release'] ?? ''),
        ]);

        $this->atomicJsonWrite($this->databaseGenerationsFile, [
            'updated_at' => date(DATE_ATOM),
            'generations' => $registry,
        ]);
    }

    private function atomicJsonWrite(string $path, array $payload): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write runtime state file: ' . basename($path));
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to atomically activate runtime state file: ' . basename($path));
        }
    }

    private function trimDirectories(string $directory, int $keep): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $entries = array_values(array_filter(glob($directory . '/*') ?: [], 'is_dir'));
        usort($entries, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $removed = [];
        foreach (array_slice($entries, max(0, $keep)) as $path) {
            $this->removeDirectory($path);
            $removed[] = $path;
        }
        return $removed;
    }

    private function trimFiles(string $directory, string $pattern, int $keep): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $entries = glob($directory . '/' . $pattern) ?: [];
        usort($entries, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $removed = [];
        foreach (array_slice($entries, max(0, $keep)) as $path) {
            if (@unlink($path)) {
                $removed[] = $path;
            }
        }
        return $removed;
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
