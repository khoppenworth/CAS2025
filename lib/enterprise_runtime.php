<?php
declare(strict_types=1);

/**
 * Minimal runtime hooks used by the verified upgrade/restore system.
 *
 * This file is loaded from lib/path.php before .env is processed so an
 * already-validated database pointer can override DB_NAME without modifying
 * the deployment-specific config.php file.
 */

$casRuntimeRoot = dirname(__DIR__);
$casPointerPath = $casRuntimeRoot . '/storage/upgrades/active_database.json';

if (is_file($casPointerPath)) {
    $casPointerRaw = file_get_contents($casPointerPath);
    $casPointer = is_string($casPointerRaw) ? json_decode($casPointerRaw, true) : null;
    $casDatabase = is_array($casPointer) ? trim((string)($casPointer['db_name'] ?? '')) : '';

    if ($casDatabase === '' || preg_match('/^[A-Za-z0-9_]+$/', $casDatabase) !== 1) {
        $message = 'CAS active database pointer is invalid. Refusing to guess the active database.';
        error_log($message);
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException($message);
        }
        http_response_code(503);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Service unavailable</title></head>'
            . '<body><h1>Service unavailable</h1><p>Please contact the system administrator.</p></body></html>';
        exit;
    }

    putenv('DB_NAME=' . $casDatabase);
    $_ENV['DB_NAME'] = $casDatabase;
}

if (PHP_SAPI !== 'cli') {
    $casMaintenancePath = $casRuntimeRoot . '/storage/upgrades/maintenance.flag';
    $casScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $casUpgradePage = substr($casScript, -18) === '/admin/upgrade.php';

    if (is_file($casMaintenancePath) && !$casUpgradePage) {
        http_response_code(503);
        header('Retry-After: 30');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Maintenance</title></head>'
            . '<body><h1>System maintenance</h1>'
            . '<p>CAS is completing a verified system operation. Please try again shortly.</p></body></html>';
        exit;
    }
}

unset(
    $casRuntimeRoot,
    $casPointerPath,
    $casPointerRaw,
    $casPointer,
    $casDatabase,
    $casMaintenancePath,
    $casScript,
    $casUpgradePage
);
