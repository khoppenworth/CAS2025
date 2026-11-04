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

// Native backup/restore utilities intentionally use the same environment contract
// as production. Adapt the CI/test connection values to that contract.
$dsnParts = [];
if (preg_match('/host=([^;]+)/', $dsn, $m)) $dsnParts['host'] = $m[1];
if (preg_match('/port=([0-9]+)/', $dsn, $m)) $dsnParts['port'] = $m[1];
putenv('DB_HOST=' . ($dsnParts['host'] ?? '127.0.0.1'));
putenv('DB_PORT=' . ($dsnParts['port'] ?? '3306'));
putenv('DB_USER=' . $user);
putenv('DB_PASS=' . $pass);
putenv('DB_ADMIN_USER=' . $user);
putenv('DB_ADMIN_PASS=' . $pass);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cas-backup-test-' . bin2hex(random_bytes(5));
$externalBackup = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cas-backup-import-' . bin2hex(random_bytes(5)) . '.zip';
$unsafeZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cas-backup-unsafe-' . bin2hex(random_bytes(5)) . '.zip';
$downloadedCopy = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cas-backup-downloaded-' . bin2hex(random_bytes(5)) . '.zip';

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
    file_put_contents($root . '/.env', "LOCAL_ENV=baseline\n");
    file_put_contents($root . '/.htaccess', "RewriteEngine On\n# local\n");
    file_put_contents($root . '/.user.ini', "upload_max_filesize=64M\n");
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
        'CREATE TABLE users ('
        . 'id INT PRIMARY KEY AUTO_INCREMENT, '
        . 'email VARCHAR(255) NOT NULL UNIQUE, '
        . 'role VARCHAR(50) NOT NULL DEFAULT "staff"'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec("INSERT INTO users (email, role) VALUES ('restore-test@example.invalid', 'admin')");

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

    $edgeValues = [
        "ends with apostrophe '",
        "ends with backslash \\",
        "backslash then apostrophe \\'",
        "line one\\\nline two ends with apostrophe '",
        "doubled-looking apostrophes '' inside",
        "semicolon; inside; text",
        "double quote \" and apostrophe ' together",
        "backtick ` and SQL-looking text DROP TABLE nope;",
        "tabs\tcarriage\rreturn\nnewline",
        "Unicode café – Amharic ሰላም – emoji 😀",
        "\\\\''\\\\'' repeated escape-looking sequence",
        "quote at line end '\nnext line",
    ];

    $fragments = ["plain", "'", "\\", ";", "''", "\\'", "\\\\", "\n", "\t", "\"", "`", "é", "ሰላም", "😀"];
    $generated = [];
    foreach ($fragments as $leftIndex => $left) {
        foreach ($fragments as $rightIndex => $right) {
            if (($leftIndex + $rightIndex) % 5 !== 0) {
                continue;
            }
            $generated[] = "case-" . $leftIndex . "-" . $rightIndex . ":" . $left . "|middle;quoted'\\\\|" . $right;
        }
    }
    $edgeValues = array_merge($edgeValues, $generated);

    foreach ($edgeValues as $index => $edgeValue) {
        $insert->execute(['edge-' . $index, $edgeValue]);
    }

    $pdo->exec("INSERT INTO related_data (baseline_id, value_text) VALUES (1, 'child value')");
    $pdo->exec("CREATE VIEW baseline_view AS SELECT id, CONCAT(label, ';quoted''value') AS decorated FROM baseline_data");

    $manager = new SystemBackupManager($root);

    $backupTarget = $root . '/storage/upgrades/manual/roundtrip.zip';
    $backup = $manager->createBackupSet($pdo, 'manual', $backupTarget, [
        'id' => 'roundtrip_test',
        'label' => 'Round-trip test backup',
    ]);

    $assert(is_file($backup['absolute_path']), 'Backup set was not created.');
    $validation = $manager->validateBackupSet($backup['absolute_path']);
    $assert($validation['valid'] === true, 'Backup set did not pass integrity validation.');

    // Repeated backups must not recursively include prior backups under storage/upgrades.
    $repeatTarget = $root . '/storage/upgrades/manual/repeat.zip';
    $repeat = $manager->createBackupSet($pdo, 'manual', $repeatTarget, [
        'id' => 'repeat_test',
        'label' => 'Repeat backup exclusion test',
    ]);
    $assert(
        ($backup['manifest']['application']['file_count'] ?? null) === ($repeat['manifest']['application']['file_count'] ?? null),
        'Repeated backup changed the application file count, suggesting recursive backup inclusion.'
    );
    $assert(
        ($backup['manifest']['application']['tree_hash'] ?? '') === ($repeat['manifest']['application']['tree_hash'] ?? ''),
        'Repeated backup changed the application tree hash even though only storage/upgrades changed.'
    );

    // A separately downloaded/copied already-created backup must remain independently valid.
    $listedBackup = $manager->findBackupSet('roundtrip_test');
    $assert($listedBackup !== null, 'Created backup set is not listed for later download.');
    $assert((int)($listedBackup['size'] ?? 0) > 0, 'Listed backup set does not expose a valid download size.');
    $assert(copy((string)$listedBackup['backup_set_path'], $downloadedCopy), 'Unable to simulate separate backup download.');
    $downloadValidation = $manager->validateBackupSet($downloadedCopy);
    $assert($downloadValidation['valid'] === true, 'Separately downloaded/copied backup failed validation.');
    $assert(
        hash_file('sha256', $downloadedCopy) === hash_file('sha256', (string)$listedBackup['backup_set_path']),
        'Downloaded/copied backup bytes differ from the validated server backup.'
    );

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

    // config.php is versioned application code and should restore from the backup.
    // Server-owned environment/runtime files should survive restore unchanged.
    file_put_contents($root . '/config.php', "<?php return ['env' => 'post-backup-config-change'];\n");
    file_put_contents($root . '/.env', "LOCAL_ENV=production-secret-change\n");
    file_put_contents($root . '/.htaccess', "RewriteEngine On\n# changed-local\n");
    file_put_contents($root . '/.user.ini', "upload_max_filesize=96M\n");
    file_put_contents($root . '/assets/uploads/keep.txt', "new upload retained\n");
    file_put_contents($root . '/storage/runtime.txt', "new runtime retained\n");

    // Mutate database schema and data aggressively.
    $pdo->exec("UPDATE baseline_data SET label='mutated' WHERE id=1");
    $pdo->exec('ALTER TABLE baseline_data ADD COLUMN experimental_flag INT NOT NULL DEFAULT 0');
    $pdo->exec('CREATE TABLE experimental_table (id INT PRIMARY KEY, payload VARCHAR(100))');
    $pdo->exec("INSERT INTO experimental_table (id, payload) VALUES (1, 'remove me')");
    $pdo->exec('DELETE FROM related_data');

    $originalDbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $restore = $manager->restoreBackupSet($pdo, $backup['absolute_path']);

    $assert(($restore['restored_id'] ?? '') === 'roundtrip_test', 'Restore returned the wrong backup identifier.');
    $assert(($restore['previous_database'] ?? '') === $originalDbName, 'Restore did not retain the original database identity.');
    $assert(($restore['active_database'] ?? '') !== $originalDbName, 'Restore should cut over to a verified candidate database, not overwrite the live database.');
    $pointer = json_decode((string)file_get_contents($root . '/storage/upgrades/active_database.json'), true);
    $assert(($pointer['db_name'] ?? '') === ($restore['active_database'] ?? ''), 'Active database pointer was not switched atomically.');
    $assert(!empty($restore['emergency_backup_id']), 'Restore did not create an emergency pre-restore backup.');

    // Enterprise invariant: successful restore cutover must not rewrite or drop the
    // previously active database. It remains available exactly as it was at cutover.
    $server->exec('USE `' . $safeDb . '`');
    $oldLabel = (string)$server->query('SELECT label FROM baseline_data WHERE id=1')->fetchColumn();
    $assert($oldLabel === 'mutated', 'Original live database was modified during candidate restore.');
    $oldExperimental = (int)$server->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'experimental_table'"
    )->fetchColumn();
    $assert($oldExperimental === 1, 'Original live database lost post-backup schema changes during restore.');

    $activeDb = (string)$restore['active_database'];
    $pdo = new PDO(
        preg_replace('/dbname=[^;]+;?/', '', $dsn) . ';dbname=' . $activeDb,
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $assert(file_get_contents($root . '/index.php') === "<?php echo 'baseline';\n", 'Changed application file was not restored.');
    $assert(is_file($root . '/lib/baseline.php'), 'Deleted baseline application file was not restored.');
    $assert(!file_exists($root . '/experimental.php'), 'New experimental application file survived exact restore.');

    $assert(
        file_get_contents($root . '/config.php') === "<?php return ['env' => 'baseline-config'];\n",
        'Versioned config.php was not restored from the backup.'
    );
    $assert(
        file_get_contents($root . '/.env') === "LOCAL_ENV=production-secret-change\n",
        '.env should be preserved across restore.'
    );
    $assert(
        file_get_contents($root . '/.htaccess') === "RewriteEngine On\n# changed-local\n",
        '.htaccess should be preserved across restore.'
    );
    $assert(
        file_get_contents($root . '/.user.ini') === "upload_max_filesize=96M\n",
        '.user.ini should be preserved across restore.'
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
        $tableNames === ['baseline_data', 'baseline_view', 'related_data', 'users'],
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
    $edgeSelect = $pdo->prepare('SELECT notes FROM baseline_data WHERE label = ?');
    foreach ($edgeValues as $index => $expectedEdgeValue) {
        $edgeSelect->execute(['edge-' . $index]);
        $actualEdgeValue = $edgeSelect->fetchColumn();
        $assert(
            $actualEdgeValue === $expectedEdgeValue,
            'SQL parser did not preserve edge-case quoted value at index ' . $index . '.'
        );
    }

    $relatedCount = (int)$pdo->query('SELECT COUNT(*) FROM related_data')->fetchColumn();
    $assert($relatedCount === 1, 'Deleted related row was not restored.');

    $viewValue = $pdo->query('SELECT decorated FROM baseline_view WHERE id=1')->fetchColumn();
    $assert(
        $viewValue === "alpha;beta;quoted'value",
        'Database view with semicolon and quoted literal was not restored correctly.'
    );

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
            $leftovers = $server->query("SHOW DATABASES LIKE 'cas_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($leftovers ?: [] as $leftover) {
                if (preg_match('/^cas_(restore|verify|upgrade)_/', (string)$leftover)) {
                    $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', (string)$leftover) . '`');
                }
            }
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . "\n");
    }

    $removeDirectory($root);
    @unlink($externalBackup);
    @unlink($unsafeZip);
    @unlink($downloadedCopy);
}
