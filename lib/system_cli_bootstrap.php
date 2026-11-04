<?php
declare(strict_types=1);

/**
 * Minimal bootstrap for backup/upgrade/restore workers.
 *
 * Intentionally does NOT load config.php, sessions, application schema
 * initialization, or request middleware. This keeps system maintenance jobs
 * from mutating the live database merely by starting.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once __DIR__ . '/path.php';

$casEnvPath = BASE_PATH . '/.env';
if (is_readable($casEnvPath)) {
    foreach (file($casEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $casEnvLine) {
        $casEnvLine = trim($casEnvLine);
        if ($casEnvLine === '' || $casEnvLine[0] === '#' || strpos($casEnvLine, '=') === false) {
            continue;
        }
        [$casEnvKey, $casEnvValue] = explode('=', $casEnvLine, 2);
        $casEnvKey = trim($casEnvKey);
        $casEnvValue = trim($casEnvValue);
        if (strlen($casEnvValue) >= 2
            && (($casEnvValue[0] === '"' && $casEnvValue[strlen($casEnvValue) - 1] === '"')
                || ($casEnvValue[0] === "'" && $casEnvValue[strlen($casEnvValue) - 1] === "'"))
        ) {
            $casEnvValue = substr($casEnvValue, 1, -1);
        }
        if ($casEnvKey !== '' && getenv($casEnvKey) === false) {
            putenv($casEnvKey . '=' . $casEnvValue);
            $_ENV[$casEnvKey] = $casEnvValue;
        }
    }
}

$casDbHost = (string)(getenv('DB_HOST') ?: '127.0.0.1');
$casDbPort = (int)(getenv('DB_PORT') ?: 3306);
$casDbName = (string)(getenv('DB_NAME') ?: '');
$casDbUser = (string)(getenv('DB_USER') ?: '');
$casDbPass = (string)(getenv('DB_PASS') ?: '');

if ($casDbName === '' || preg_match('/^[A-Za-z0-9_]+$/', $casDbName) !== 1 || $casDbUser === '') {
    throw new RuntimeException('System CLI database configuration is incomplete or unsafe.');
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $casDbHost,
        $casDbPort > 0 ? $casDbPort : 3306,
        $casDbName
    ),
    $casDbUser,
    $casDbPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

unset(
    $casEnvPath,
    $casEnvLine,
    $casEnvKey,
    $casEnvValue,
    $casDbHost,
    $casDbPort,
    $casDbName,
    $casDbUser,
    $casDbPass
);
