<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/enterprise_recovery.php';

if (!in_array('--mysql', $argv, true)) {
    fwrite(STDOUT, "Candidate migration test requires --mysql.\n");
    exit(0);
}

$dsn = getenv('BACKUP_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = getenv('BACKUP_DB_USER') ?: 'root';
$pass = getenv('BACKUP_DB_PASS') ?: 'root';
$dbName = getenv('BACKUP_DB_NAME') ?: 'cas_candidate_migration_test';

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

$root = sys_get_temp_dir() . '/cas-candidate-migration-' . bin2hex(random_bytes(5));
$dump = $root . '/baseline.sql';
$migration = $root . '/migration.sql';

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

    $server = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $safeDb = preg_replace('/[^A-Za-z0-9_]/', '_', $dbName);
    $server->exec('DROP DATABASE IF EXISTS `' . $safeDb . '`');
    $server->exec('CREATE DATABASE `' . $safeDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE `' . $safeDb . '`');
    $pdo = $server;

    foreach (['users', 'site_config', 'questionnaire', 'questionnaire_response'] as $table) {
        $pdo->exec(
            'CREATE TABLE `' . $table . '` ('
            . 'id INT PRIMARY KEY, value_text VARCHAR(255) NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec("INSERT INTO `" . $table . "` (id, value_text) VALUES (1, 'baseline')");
    }

    $runtime = new EnterpriseRecovery($root);
    $metadata = $runtime->dumpDatabase($pdo, $dump);
    $assert(($metadata['verified_restore'] ?? false) === true, 'Native dump was not proven by isolated restore.');

    file_put_contents($migration, <<<'SQL'
CREATE TABLE migration_probe (
    id INT PRIMARY KEY,
    value_text VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migration_probe (id, value_text)
VALUES (1, 'literal;with apostrophe '' and backslash \\');

SET @probe_sql = 'INSERT INTO migration_probe (id, value_text) VALUES (2, ''prepared;quoted''''value'')';
PREPARE probe_stmt FROM @probe_sql;
EXECUTE probe_stmt;
DEALLOCATE PREPARE probe_stmt;
SQL
    );

    $candidateNames = [];
    for ($i = 0; $i < 4; $i++) {
        $candidate = $runtime->stageDatabase($pdo, $dump, $metadata['objects'], true);
        $candidateName = (string)$candidate['database'];
        $candidateNames[] = $candidateName;

        if ($i === 0) {
            $runtime->executeSqlFileOnDatabase($pdo, $candidateName, $migration);
            /** @var PDO $candidatePdo */
            $candidatePdo = $candidate['pdo'];
            $count = (int)$candidatePdo->query('SELECT COUNT(*) FROM migration_probe')->fetchColumn();
            $assert($count === 2, 'Native candidate migration did not execute all statements.');
            $value = $candidatePdo->query('SELECT value_text FROM migration_probe WHERE id=1')->fetchColumn();
            $assert(
                $value === "literal;with apostrophe ' and backslash \\",
                'Native migration did not preserve quoted/semicolon/backslash content.'
            );

            $liveProbe = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_probe'"
            )->fetchColumn();
            $assert((int)$liveProbe === 0, 'Candidate migration modified the original live database.');
        }

        $runtime->switchActiveDatabase($candidateName, [
            'operation' => 'candidate_migration_test',
            'previous_database' => $i === 0 ? $safeDb : $candidateNames[$i - 1],
            'release' => 'test-' . $i,
        ]);
        usleep(10000);
    }

    $activePdo = $runtime->connectApplicationDatabase($pdo, $candidateNames[3]);
    $removed = $runtime->cleanupDatabaseGenerations($activePdo, 3);

    $assert(in_array($candidateNames[0], $removed, true), 'Oldest managed candidate was not removed by retention.');
    foreach (array_slice($candidateNames, 1) as $name) {
        $exists = (int)$server->query(
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = "
            . $server->quote($name)
        )->fetchColumn();
        $assert($exists === 1, 'Retention removed a protected recent/active candidate: ' . $name);
    }

    $baselineExists = (int)$server->query(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = "
        . $server->quote($safeDb)
    )->fetchColumn();
    $assert($baselineExists === 1, 'Retention removed the original baseline database.');

    fwrite(
        STDOUT,
        "Native candidate migration/retention test passed against "
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
        . ".\n"
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Candidate migration test failed: ' . $e->getMessage() . "\n");
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
