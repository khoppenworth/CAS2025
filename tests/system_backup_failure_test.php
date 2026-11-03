<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/system_backup.php';

if (!in_array('--mysql', $argv, true)) {
    fwrite(STDOUT, "Enterprise recovery failure test requires --mysql.\n");
    exit(0);
}

$dsn = getenv('BACKUP_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = getenv('BACKUP_DB_USER') ?: 'root';
$pass = getenv('BACKUP_DB_PASS') ?: 'root';
$dbName = getenv('BACKUP_DB_NAME') ?: 'cas_recovery_failure_test';

$parsed = [];
foreach (explode(';', str_replace('mysql:', '', $dsn)) as $part) {
    if (strpos($part, '=') !== false) {
        [$key, $value] = explode('=', $part, 2);
        $parsed[$key] = $value;
    }
}
putenv('DB_HOST=' . ($parsed['host'] ?? '127.0.0.1'));
putenv('DB_PORT=' . ($parsed['port'] ?? '3306'));
putenv('DB_NAME=' . $dbName);
putenv('DB_USER=' . $user);
putenv('DB_PASS=' . $pass);
putenv('DB_MAINT_HOST=' . ($parsed['host'] ?? '127.0.0.1'));
putenv('DB_MAINT_PORT=' . ($parsed['port'] ?? '3306'));
putenv('DB_MAINT_USER=' . $user);
putenv('DB_MAINT_PASS=' . $pass);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . '/cas-recovery-failure-' . bin2hex(random_bytes(5));
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
    mkdir($root . '/storage/upgrades', 0755, true);
    mkdir($root . '/assets/uploads', 0755, true);
    file_put_contents($root . '/index.php', "<?php echo 'baseline';\n");
    file_put_contents($root . '/config.php', "<?php return ['test' => true];\n");
    file_put_contents($root . '/assets/uploads/keep.txt', "upload\n");
    file_put_contents($root . '/storage/runtime.txt', "runtime\n");
    file_put_contents(
        $root . '/storage/upgrades/installed.json',
        json_encode([
            'tag' => '0.failure.baseline',
            'name' => 'Failure Baseline',
            'repo' => 'test/repo',
            'installed_at' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );

    $server = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $safeDb = preg_replace('/[^A-Za-z0-9_]/', '_', $dbName);
    $server->exec('DROP DATABASE IF EXISTS `' . $safeDb . '`');
    $server->exec('CREATE DATABASE `' . $safeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE `' . $safeDb . '`');
    $pdo = $server;

    $pdo->exec('CREATE TABLE baseline_data (id INT PRIMARY KEY, value_text VARCHAR(255) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO baseline_data (id, value_text) VALUES (1, 'baseline')");

    $manager = new SystemBackupManager($root);
    $backup = $manager->createBackupSet(
        $pdo,
        'manual',
        $root . '/storage/upgrades/manual/failure-target.zip',
        ['id' => 'failure_target', 'label' => 'Failure injection target']
    );

    // Create a pre-restore live state that MUST survive a failed cutover.
    file_put_contents($root . '/index.php', "<?php echo 'pre-restore-live';\n");
    $pdo->exec("UPDATE baseline_data SET value_text='pre-restore-live' WHERE id=1");
    $pdo->exec('CREATE TABLE live_only_table (id INT PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO live_only_table (id) VALUES (1)');

    // Force a failure after application restore and database pointer switch.
    // The target backup contains installed-release metadata, so making this path
    // a directory causes writeInstalledRelease() to fail at the final cutover stage.
    $installedPath = $root . '/storage/upgrades/installed.json';
    @unlink($installedPath);
    mkdir($installedPath, 0755);

    $failed = false;
    try {
        $manager->restoreBackupSet($pdo, $backup['absolute_path']);
    } catch (RuntimeException $e) {
        $failed = true;
        $assert(
            str_contains($e->getMessage(), 'Restore failed before a verified cutover could complete'),
            'Failure was not reported through the enterprise rollback path.'
        );
    }
    $assert($failed, 'Failure injection unexpectedly completed successfully.');

    // Rollback must restore the pre-restore application, not the target backup.
    $assert(
        file_get_contents($root . '/index.php') === "<?php echo 'pre-restore-live';\n",
        'Application rollback did not restore the exact pre-restore state.'
    );
    $assert(!is_file($root . '/storage/upgrades/maintenance.json'), 'Maintenance mode remained active after a proven rollback.');

    $pointer = json_decode((string)file_get_contents($root . '/storage/upgrades/active_database.json'), true);
    $assert(is_array($pointer), 'Rollback did not restore the active database pointer.');
    $assert(($pointer['database'] ?? '') === $safeDb, 'Rollback pointer does not reference the untouched original database.');

    // Most important invariant: original live DB retains the mutations.
    $pdo->exec('USE `' . $safeDb . '`');
    $liveValue = $pdo->query('SELECT value_text FROM baseline_data WHERE id=1')->fetchColumn();
    $assert($liveValue === 'pre-restore-live', 'Failed restore modified original live database data.');
    $liveOnly = (int)$pdo->query('SELECT COUNT(*) FROM live_only_table')->fetchColumn();
    $assert($liveOnly === 1, 'Failed restore modified original live database schema.');

    // Failed staged candidates must be removed after rollback.
    $stageDbs = $server->query("SHOW DATABASES LIKE 'cas_stage_%'")->fetchAll(PDO::FETCH_COLUMN);
    $assert($stageDbs === [], 'Failed restore left disposable staged databases behind.');

    fwrite(STDOUT, "Enterprise rollback failure injection passed against "
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ".\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Enterprise rollback failure test failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        if (isset($server, $safeDb) && $server instanceof PDO) {
            $server->exec('USE information_schema');
            foreach ($server->query("SHOW DATABASES LIKE 'cas_stage_%'")->fetchAll(PDO::FETCH_COLUMN) as $stageDb) {
                if (preg_match('/^cas_stage_[A-Za-z0-9_]+$/', (string)$stageDb) === 1) {
                    $server->exec('DROP DATABASE IF EXISTS `' . $stageDb . '`');
                }
            }
            $server->exec('DROP DATABASE IF EXISTS `' . $safeDb . '`');
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . "\n");
    }
    $removeDirectory($root);
}
