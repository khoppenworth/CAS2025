<?php
declare(strict_types=1);

/**
 * Minimal enterprise runtime hook.
 *
 * Responsibilities only:
 * 1. honour the maintenance flag for web requests (except admin/upgrade.php);
 * 2. honour the verified active-database pointer before config.php loads .env.
 *
 * This file deliberately does not read or modify config.php.
 */
$casRoot = dirname(__DIR__);

if (PHP_SAPI !== 'cli') {
    $maintenanceFlag = $casRoot . '/storage/upgrades/maintenance.flag';
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $maintenanceExempt = str_ends_with($scriptName, '/admin/upgrade.php');

    if (is_file($maintenanceFlag) && !$maintenanceExempt) {
        http_response_code(503);
        header('Retry-After: 30');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Maintenance</title></head><body>';
        echo '<h1>System maintenance</h1><p>CAS is completing a verified system operation. Please try again shortly.</p>';
        echo '</body></html>';
        exit;
    }
}

$activeDbPointer = $casRoot . '/storage/upgrades/active_database.json';
if (is_readable($activeDbPointer)) {
    $raw = file_get_contents($activeDbPointer);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $dbName = is_array($data) ? trim((string)($data['db_name'] ?? '')) : '';

    if ($dbName !== '' && preg_match('/^[A-Za-z0-9_]+$/', $dbName) === 1) {
        putenv('DB_NAME=' . $dbName);
        $_ENV['DB_NAME'] = $dbName;
    }
}
