<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/system_backup.php';

if (!in_array('--mysql', $argv, true)) {
    fwrite(STDOUT, "System backup restore test requires --mysql.\n");
    exit(0);
}

$dsn = getenv('BACKUP_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = getenv('BACKUP_DB_USER') ?: 'root';
$pass = getenv('BACKUP_DB_PASS') ?: 'root';
$dbName = getenv('BACKUP_DB_NAME') ?: 'cas_system_backup_test';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cas-backup-test-' . bin2hex(random_bytes(5));
$externalBackup = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cas-backup-import-' . bin2hex(random_bytes(5)) . '.zip';
$unsafeZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cas-backup-unsafe-' . bin2hex(random_bytes(5)) . '.zip';

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
    mkdir($root, 0755, true);
    mkdir($root . '/lib', 0755, true);
    mkdir($root . '/assets/uploads', 0755, true);
    mkdir($root . '/storage/upgrades', 0755, true);

    file_put_contents($root . '/index.php', "<?php echo 'baseline';\n");
    file_put_contents($root . '/lib/baseline.php', "<?php return 'baseline-lib';\n");
    file_put_contents($root . '/config.php', "<?php return ['env' => 'baseline-config'];\n");
    file_put_contents($root . '/assets/uploads/keep.txt', "baseline upload\n");
    file_put_contents($root . '/storage/runtime.txt', "baseline runtime\n");
    file_put_contents(
        $root . '/storage/upgrades/installed.json',
        json_encode([
            'tag' => 'v0.test.baseline',
            'name' => 'Baseline Test Release',
            'repo' => 'test/repo',
            'url' => 'https://example.invalid/test/repo',
            'installed_at' => '2026-11-02T12:00:00-05:00',
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

    $pdo->exec(
        'CREATE TABLE baseline_data ('
        . 'id INT PRIMARY KEY AUTO_INCREMENT, '
        . 'label VARCHAR(255) NOT NULL, '
        . 'notes TEXT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE related_data ('
        . 'id INT PRIMARY KEY AUTO_INCREMENT, '
        . 'baseline_id INT NOT NULL, '
        . 'value_text VARCHAR(255) NOT NULL, '
        . 'CONSTRAINT fk_related_baseline FOREIGN KEY (baseline_id) REFERENCES baseline_data(id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $insert = $pdo->prepare('INSERT INTO baseline_data (label, notes) VALUES (?, ?)');
    $insert->execute(["alpha;beta", "line one\nline two; still same value 'quoted' \\ slash"]);
    $insert->execute(['second row', null]);
    $pdo->exec("INSERT INTO related_data (baseline_id, value_text) VALUES (1, 'child value')");

    $manager = new SystemBackupManager($root);

    $backupTarget = $root . '/storage/upgrades/manual/roundtrip.zip';
    $backup = $manager->createBackupSet($pdo, 'manual', $backupTarget, [
        'id' => 'roundtrip_test',
        'label' => 'Round-trip test backup',
    ]);

    $assert(is_file($backup['absolute_path']), 'Backup set was not created.');
    $validation = $manager->validateBackupSet($backup['absolute_path']);
    $assert($validation['valid'] === true, 'Backup set did not pass integrity validation.');

    // Create an external backup and prove that a downloaded set can be imported.
    $external = $manager->createBackupSet($pdo, 'manual', $externalBackup, [
        'id' => 'import_test',
        'label' => 'External import test',
    ]);
    $imported = $manager->importBackupSet($external['absolute_path'], 'downloaded-full-backup.zip');
    $assert(($imported['id'] ?? '') === 'import_test', 'Imported backup set lost its identifier.');
    $assert($manager->findBackupSet('import_test') !== null, 'Imported backup set is not listed as restorable.');

    // Security regression: reject path traversal before extraction.
    $zip = new ZipArchive();
    $zip->open($unsafeZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../escape.txt', 'unsafe');
    $zip->addFromString('manifest.json', '{}');
    $zip->addFromString('application.zip', 'not-a-zip');
    $zip->addFromString('database/backup.sql', 'SELECT 1;');
    $zip->close();
    $unsafeValidation = $manager->validateBackupSet($unsafeZip);
    $assert($unsafeValidation['valid'] === false, 'Unsafe ZIP path traversal was not rejected.');

    // Mutate application state. Files outside preserved paths must roll back exactly.
    file_put_contents($root . '/index.php', "<?php echo 'experimental';\n");
    @unlink($root . '/lib/baseline.php');
    file_put_contents($root . '/experimental.php', "<?php echo 'remove me';\n");

    // Preserved environment/runtime data should survive restore.
    file_put_contents($root . '/config.php', "<?php return ['env' => 'production-secret-change'];\n");
    file_put_contents($root . '/assets/uploads/keep.txt', "new upload retained\n");
    file_put_contents($root . '/storage/runtime.txt', "new runtime retained\n");

    // Mutate database schema and data aggressively.
    $pdo->exec("UPDATE baseline_data SET label='mutated' WHERE id=1");
    $pdo->exec('ALTER TABLE baseline_data ADD COLUMN experimental_flag INT NOT NULL DEFAULT 0');
    $pdo->exec('CREATE TABLE experimental_table (id INT PRIMARY KEY, payload VARCHAR(100))');
    $pdo->exec("INSERT INTO experimental_table (id, payload) VALUES (1, 'remove me')");
    $pdo->exec('DELETE FROM related_data');

    $restore = $manager->restoreBackupSet($pdo, $backup['absolute_path']);

    $assert(($restore['restored_id'] ?? '') === 'roundtrip_test', 'Restore returned the wrong backup identifier.');
    $assert(!empty($restore['emergency_backup_id']), 'Restore did not create an emergency pre-restore backup.');

    $assert(file_get_contents($root . '/index.php') === "<?php echo 'baseline';\n", 'Changed application file was not restored.');
    $assert(is_file($root . '/lib/baseline.php'), 'Deleted baseline application file was not restored.');
    $assert(!file_exists($root . '/experimental.php'), 'New experimental application file survived exact restore.');

    $assert(
        file_get_contents($root . '/config.php') === "<?php return ['env' => 'production-secret-change'];\n",
        'config.php should be preserved across restore.'
    );
    $assert(
        file_get_contents($root . '/assets/uploads/keep.txt') === "new upload retained\n",
        'Uploads should be preserved across restore.'
    );
    $assert(
        file_get_contents($root . '/storage/runtime.txt') === "new runtime retained\n",
        'Storage runtime data should be preserved across restore.'
    );

    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $tableNames = array_map(static fn(array $row): string => (string)$row[0], $tables);
    sort($tableNames);
    $assert(
        $tableNames === ['baseline_data', 'related_data'],
        'Database exact restore did not remove experimental tables.'
    );

    $columns = $pdo->query('SHOW COLUMNS FROM baseline_data')->fetchAll(PDO::FETCH_COLUMN);
    $assert(!in_array('experimental_flag', $columns, true), 'Experimental database column survived restore.');

    $row = $pdo->query('SELECT label, notes FROM baseline_data WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $assert(($row['label'] ?? '') === 'alpha;beta', 'Baseline database value was not restored.');
    $assert(
        ($row['notes'] ?? '') === "line one\nline two; still same value 'quoted' \\ slash",
        'SQL parser did not preserve complex multiline/semicolon data.'
    );
    $relatedCount = (int)$pdo->query('SELECT COUNT(*) FROM related_data')->fetchColumn();
    $assert($relatedCount === 1, 'Deleted related row was not restored.');

    $emergency = $manager->findBackupSet((string)$restore['emergency_backup_id']);
    $assert($emergency !== null, 'Emergency pre-restore backup is not retained in the backup catalog.');
    $emergencyValidation = $manager->validateBackupSet((string)$emergency['backup_set_path']);
    $assert($emergencyValidation['valid'] === true, 'Emergency pre-restore backup failed validation.');

    fwrite(
        STDOUT,
        "Verified full backup/restore round-trip passed against "
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
        . ".\n"
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'System backup restore test failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        if (isset($server, $safeDb) && $server instanceof PDO) {
            $server->exec('USE information_schema');
            $server->exec('DROP DATABASE IF EXISTS `' . $safeDb . '`');
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . "\n");
    }

    $removeDirectory($root);
    @unlink($externalBackup);
    @unlink($unsafeZip);
}
