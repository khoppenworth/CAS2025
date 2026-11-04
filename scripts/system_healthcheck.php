#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['expect-db:', 'full']);
$expectedDb = trim((string)($options['expect-db'] ?? ''));
$full = isset($options['full']);

if ($full) {
    require_once __DIR__ . '/../config.php';
} else {
    require_once __DIR__ . '/../lib/system_cli_bootstrap.php';
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "CAS health check could not establish the application database connection.\n");
    exit(2);
}

$actualDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($actualDb === '' || ($expectedDb !== '' && !hash_equals($expectedDb, $actualDb))) {
    fwrite(STDERR, 'CAS health check database mismatch. Expected '
        . ($expectedDb !== '' ? $expectedDb : '(connected database)')
        . ', got ' . ($actualDb !== '' ? $actualDb : '(none)') . ".\n");
    exit(3);
}

$usersTable = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
);
if (!$usersTable || (int)$usersTable->fetchColumn() !== 1) {
    fwrite(STDERR, "CAS health check failed: users table is missing.\n");
    exit(4);
}

$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

fwrite(STDOUT, 'CAS_HEALTHCHECK_OK database=' . $actualDb . ($full ? ' mode=full' : ' mode=db-only') . PHP_EOL);
