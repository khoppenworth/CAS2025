<?php
declare(strict_types=1);

/**
 * Minimal request-scoped runtime for verified upgrade/restore cutover.
 *
 * Responsibilities only:
 * - select a validated candidate DB from storage/upgrades/active_database.json;
 * - block web requests while storage/upgrades/maintenance.flag exists;
 * - restore the process DB environment at request shutdown so reused PHP-FPM
 *   workers cannot leak a candidate DB selection into a later request.
 */

$casRuntimeRoot = dirname(__DIR__);
$casPointerPath = $casRuntimeRoot . '/storage/upgrades/active_database.json';

if (is_file($casPointerPath)) {
    $raw = file_get_contents($casPointerPath);
    $pointer = is_string($raw) ? json_decode($raw, true) : null;
    $dbName = is_array($pointer) ? trim((string)($pointer['db_name'] ?? '')) : '';

    if ($dbName === '' || preg_match('/^[A-Za-z0-9_]+$/', $dbName) !== 1) {
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

    $previousDbName = getenv('DB_NAME');
    $previousEnvExists = array_key_exists('DB_NAME', $_ENV);
    $previousEnvDbName = $_ENV['DB_NAME'] ?? null;

    putenv('DB_NAME=' . $dbName);
    $_ENV['DB_NAME'] = $dbName;

    $restoreDbEnvironment = static function () use (
        $previousDbName,
        $previousEnvExists,
        $previousEnvDbName
    ): void {
        if ($previousDbName === false) {
            putenv('DB_NAME');
        } else {
            putenv('DB_NAME=' . (string)$previousDbName);
        }

        if ($previousEnvExists) {
            $_ENV['DB_NAME'] = $previousEnvDbName;
        } else {
            unset($_ENV['DB_NAME']);
        }
    };

    $GLOBALS['CAS_ENTERPRISE_DB_ENV_RESTORE'] = $restoreDbEnvironment;
    register_shutdown_function($restoreDbEnvironment);
}

if (PHP_SAPI !== 'cli') {
    $maintenanceFlag = $casRuntimeRoot . '/storage/upgrades/maintenance.flag';
    if (is_file($maintenanceFlag)) {
        http_response_code(503);
        header('Retry-After: 30');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Maintenance</title></head><body>';
        echo '<h1>System maintenance</h1><p>CAS is completing a verified system operation. Please try again shortly.</p>';
        echo '</body></html>';
        exit;
    }
}

unset(
    $casRuntimeRoot,
    $casPointerPath,
    $raw,
    $pointer,
    $dbName,
    $previousDbName,
    $previousEnvExists,
    $previousEnvDbName,
    $restoreDbEnvironment,
    $maintenanceFlag
);
