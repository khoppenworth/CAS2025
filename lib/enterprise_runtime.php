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

    // putenv() changes the process environment, and PHP-FPM workers are reused.
    // Scope the override to this request/process execution and restore the prior
    // value at shutdown so a later request cannot inherit a stale candidate DB.
    $casPreviousDbName = getenv('DB_NAME');
    $casPreviousEnvDbNameExists = array_key_exists('DB_NAME', $_ENV);
    $casPreviousEnvDbName = $_ENV['DB_NAME'] ?? null;

    putenv('DB_NAME=' . $casDatabase);
    $_ENV['DB_NAME'] = $casDatabase;

    $casRestoreDbEnvironment = static function () use (
        $casPreviousDbName,
        $casPreviousEnvDbNameExists,
        $casPreviousEnvDbName
    ): void {
        if ($casPreviousDbName === false) {
            putenv('DB_NAME');
        } else {
            putenv('DB_NAME=' . (string)$casPreviousDbName);
        }

        if ($casPreviousEnvDbNameExists) {
            $_ENV['DB_NAME'] = $casPreviousEnvDbName;
        } else {
            unset($_ENV['DB_NAME']);
        }
    };

    // Expose the exact restore closure for deterministic regression testing and
    // register the same closure for real request shutdown.
    $GLOBALS['CAS_ENTERPRISE_DB_ENV_RESTORE'] = $casRestoreDbEnvironment;
    register_shutdown_function($casRestoreDbEnvironment);
}

if (PHP_SAPI !== 'cli') {
    $casMaintenancePath = $casRuntimeRoot . '/storage/upgrades/maintenance.flag';

    // Fail closed for every web request. System jobs run through CLI and are not
    // blocked, so the authoritative snapshot/cutover window has no application
    // request path that can read from or write to the live database.
    if (is_file($casMaintenancePath)) {
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
    $casPreviousDbName,
    $casPreviousEnvDbNameExists,
    $casPreviousEnvDbName,
    $casRestoreDbEnvironment
);
