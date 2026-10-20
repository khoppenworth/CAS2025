<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/upgrade.php';

function restore_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function restore_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

function run_filesystem_restore_regression(): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required for restore regression tests.');
    }

    $root = sys_get_temp_dir() . '/cas-restore-files-' . bin2hex(random_bytes(5));
    if (!mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create filesystem restore test directory.');
    }

    try {
        $engine = new UpgradeEngine($root);

        @mkdir($root . '/app', 0755, true);
        @mkdir($root . '/assets/uploads', 0755, true);
        @mkdir($root . '/storage', 0755, true);
        @mkdir($root . '/storage2', 0755, true);

        file_put_contents($root . '/config.php', 'current-config');
        file_put_contents($root . '/app/existing.txt', 'newer-content');
        file_put_contents($root . '/app/newer-only.txt', 'must-disappear');
        file_put_contents($root . '/assets/current.css', 'newer-style');
        file_put_contents($root . '/assets/uploads/keep.txt', 'current-upload');
        file_put_contents($root . '/storage/keep.txt', 'current-storage');
        file_put_contents($root . '/storage2/orphan.txt', 'must-disappear');

        $snapshot = $root . '/storage/upgrades/restore-regression.zip';
        $zip = new ZipArchive();
        restore_assert(
            $zip->open($snapshot, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true,
            'Unable to create application restore regression archive.'
        );
        $zip->addFromString('app/existing.txt', 'baseline-content');
        $zip->addFromString('app/restored.txt', 'restored-file');
        $zip->addFromString('assets/current.css', 'baseline-style');
        restore_assert($zip->close() === true, 'Unable to close application restore regression archive.');

        $engine->restoreApplicationSnapshot($snapshot, [
            'config.php',
            'assets/uploads',
            'storage',
            'storage/upgrades',
        ]);

        restore_assert(
            file_get_contents($root . '/app/existing.txt') === 'baseline-content',
            'Existing application files were not rolled back.'
        );
        restore_assert(
            file_get_contents($root . '/app/restored.txt') === 'restored-file',
            'Files deleted after the backup were not restored.'
        );
        restore_assert(
            !file_exists($root . '/app/newer-only.txt'),
            'A file introduced after the backup survived restore.'
        );
        restore_assert(
            file_get_contents($root . '/assets/current.css') === 'baseline-style',
            'Snapshot asset content was not restored.'
        );
        restore_assert(
            file_get_contents($root . '/config.php') === 'current-config',
            'config.php was not preserved.'
        );
        restore_assert(
            file_get_contents($root . '/assets/uploads/keep.txt') === 'current-upload',
            'Uploaded content was not preserved.'
        );
        restore_assert(
            file_get_contents($root . '/storage/keep.txt') === 'current-storage',
            'Storage content was not preserved.'
        );
        restore_assert(
            !file_exists($root . '/storage2/orphan.txt'),
            'Preserving storage incorrectly preserved similarly-prefixed storage2.'
        );
    } finally {
        restore_remove_tree($root);
    }
}

function run_database_restore_regression(): void
{
    $dsn = trim((string)(getenv('RESTORE_DB_DSN') ?: ''));
    if ($dsn === '') {
        echo "Database restore regression skipped (RESTORE_DB_DSN not set).\n";
        return;
    }

    $user = (string)(getenv('RESTORE_DB_USER') ?: 'root');
    $pass = (string)(getenv('RESTORE_DB_PASS') ?: '');
    $admin = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $database = 'cas_restore_' . strtolower(bin2hex(random_bytes(4)));
    $safeDatabase = str_replace('`', '``', $database);
    $admin->exec('CREATE DATABASE `' . $safeDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $root = sys_get_temp_dir() . '/cas-restore-db-' . bin2hex(random_bytes(5));
    if (!mkdir($root, 0755, true) && !is_dir($root)) {
        $admin->exec('DROP DATABASE IF EXISTS `' . $safeDatabase . '`');
        throw new RuntimeException('Unable to create database restore test directory.');
    }

    try {
        $dbDsn = rtrim($dsn, ';') . ';dbname=' . $database;
        $pdo = new PDO($dbDsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $engine = new UpgradeEngine($root);

        $pdo->exec('CREATE TABLE original_record (id INT PRIMARY KEY, label VARCHAR(100) NOT NULL)');
        $pdo->exec("INSERT INTO original_record (id, label) VALUES (1, 'baseline')");
        $pdo->exec('CREATE VIEW original_view AS SELECT id, label FROM original_record');

        $dump = $root . '/baseline.sql';
        $engine->createDatabaseDumpFile($pdo, $dump);
        restore_assert(is_file($dump) && filesize($dump) > 0, 'Database backup was not created.');

        $pdo->exec('DROP VIEW original_view');
        $pdo->exec("UPDATE original_record SET label='mutated' WHERE id=1");
        $pdo->exec('ALTER TABLE original_record ADD COLUMN newer_column VARCHAR(30) NULL');
        $pdo->exec('CREATE TABLE post_backup_table (id INT PRIMARY KEY)');
        $pdo->exec('CREATE VIEW post_backup_view AS SELECT id FROM post_backup_table');

        $engine->restoreDatabaseFromDump($pdo, $dump);

        $label = $pdo->query('SELECT label FROM original_record WHERE id=1')->fetchColumn();
        restore_assert($label === 'baseline', 'Backed-up database data was not restored.');

        $columns = $pdo->query("SHOW COLUMNS FROM original_record LIKE 'newer_column'")->fetchAll();
        restore_assert($columns === [], 'A column introduced after backup survived restore.');

        $postTable = $pdo->query("SHOW FULL TABLES LIKE 'post_backup_table'")->fetchAll();
        restore_assert($postTable === [], 'A table introduced after backup survived restore.');

        $postView = $pdo->query("SHOW FULL TABLES LIKE 'post_backup_view'")->fetchAll();
        restore_assert($postView === [], 'A view introduced after backup survived restore.');

        $originalView = $pdo->query("SHOW FULL TABLES LIKE 'original_view'")->fetchAll();
        restore_assert($originalView !== [], 'A backed-up view was not restored.');

        // Validation must happen before destructive work. A malformed dump must
        // never clear the currently-running database.
        $pdo->exec('CREATE TABLE safety_sentinel (id INT PRIMARY KEY)');
        $badDump = $root . '/invalid.sql';
        file_put_contents($badDump, "-- Database backup generated for regression\n-- deliberately invalid\n");

        $rejected = false;
        try {
            $engine->restoreDatabaseFromDump($pdo, $badDump);
        } catch (RuntimeException $expected) {
            $rejected = true;
        }
        restore_assert($rejected, 'A dump without schema definitions was not rejected.');

        $sentinel = $pdo->query("SHOW FULL TABLES LIKE 'safety_sentinel'")->fetchAll();
        restore_assert($sentinel !== [], 'Invalid dump validation occurred after destructive database changes.');
    } finally {
        try {
            $admin->exec('DROP DATABASE IF EXISTS `' . $safeDatabase . '`');
        } catch (Throwable $ignored) {
        }
        restore_remove_tree($root);
    }
}

run_filesystem_restore_regression();
run_database_restore_regression();

echo "System restore regression checks passed.\n";
