<?php
declare(strict_types=1);

/**
 * Minimal runtime hook for enterprise-safe system operations.
 *
 * Responsibilities are intentionally limited to:
 * 1) honoring the maintenance flag for web requests;
 * 2) honoring the active database pointer before .env is loaded.
 *
 * This file never writes config.php, .env, database contents, or release metadata.
 */

function cas_enterprise_runtime_apply(string $root): void
{
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    $storage = $root . '/storage/upgrades';

    if (PHP_SAPI !== 'cli') {
        $flag = $storage . '/maintenance.flag';
        if (is_file($flag)) {
            $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $isUpgradePage = substr($scriptName, -18) === '/admin/upgrade.php';

            if (!$isUpgradePage) {
                http_response_code(503);
                if (!headers_sent()) {
                    header('Retry-After: 30');
                }
                echo '<!doctype html><html><head><meta charset="utf-8"><title>Maintenance</title></head><body>';
                echo '<h1>System maintenance</h1><p>CAS is completing a verified system operation. Please try again shortly.</p>';
                echo '</body></html>';
                exit;
            }
        }
    }

    $pointer = $storage . '/active_database.json';
    if (!is_file($pointer)) {
        return;
    }

    $raw = @file_get_contents($pointer);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $dbName = is_array($data) ? trim((string)($data['db_name'] ?? '')) : '';

    if ($dbName === '' || preg_match('/^[A-Za-z0-9_]+$/', $dbName) !== 1) {
        $message = 'CAS active database pointer is invalid.';
        error_log($message);

        if (PHP_SAPI === 'cli') {
            throw new RuntimeException($message);
        }

        http_response_code(503);
        if (!headers_sent()) {
            header('Retry-After: 30');
        }
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Service unavailable</title></head><body>';
        echo '<h1>Service unavailable</h1><p>CAS database routing requires administrator attention.</p>';
        echo '</body></html>';
        exit;
    }

    putenv('DB_NAME=' . $dbName);
    $_ENV['DB_NAME'] = $dbName;
}
