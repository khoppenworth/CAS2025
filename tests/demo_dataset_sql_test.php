<?php

declare(strict_types=1);

$path = __DIR__ . '/../dummy_data.sql';
$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "Unable to read dummy_data.sql\n");
    exit(1);
}

$checks = [
    'granular response items' => "INSERT INTO questionnaire_response_item",
    'work location assignment' => 'location_id',
    'EPSS location master usage' => 'FROM epss_location',
    'department catalogue coverage' => 'FROM department_catalog',
    'team catalogue coverage' => 'FROM department_team_catalog',
    '2026 trend period' => "('2026', '2026-01-01', '2026-12-31')",
    '2027 trend period' => "('2027', '2027-01-01', '2027-12-31')",
    '2028 trend period' => "('2028', '2028-01-01', '2028-12-31')",
    'synthetic staff naming' => "CONCAT('demo_staff_', LPAD(n.n, 3, '0'))",
    'non-routable demo email domain' => '@example.invalid',
    'Likert answer payloads' => 'valueInteger',
    'boolean answer payloads' => 'valueBoolean',
    'choice answer payloads' => 'valueString',
    'intentional incomplete assessment coverage' => '<> 0',
    'independent ones digit source' => ') AS ones',
    'independent tens digit source' => ') AS tens',
];

$failed = [];
foreach ($checks as $label => $needle) {
    if (!str_contains($sql, $needle)) {
        $failed[] = $label;
    }
}

if (!preg_match('/<=\s*80\s*;/', $sql)) {
    $failed[] = '80-staff generation cap';
}

// Regression for MariaDB/MySQL error 1137: the same TEMPORARY digit table must
// never be reopened through multiple aliases in one statement.
if (str_contains($sql, 'tmp_demo_digit')) {
    $failed[] = 'temporary digit table must not be reused';
}
if (preg_match('/FROM\s+(tmp_demo_[a-z0-9_]+)\s+\w+\s+CROSS\s+JOIN\s+\1\b/i', $sql)) {
    $failed[] = 'same temporary table reopened in one statement';
}

// Demo generation may read the location master, but it must not overwrite real
// EPSS location names, coordinates, addresses or verification status.
if (preg_match('/\b(?:UPDATE|DELETE\s+FROM)\s+epss_location\b/i', $sql)) {
    $failed[] = 'location master must remain read-only';
}
if (preg_match('/\bINSERT\s+INTO\s+epss_location\b/i', $sql)) {
    $failed[] = 'location master must not be seeded by demo data';
}

if (!str_contains($sql, "username LIKE 'demo_staff_%'")) {
    $failed[] = 'demo row marker';
}

if ($failed !== []) {
    fwrite(STDERR, "Demo dataset SQL contract failed:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "Demo dataset SQL contract passed.\n");

// Optional engine-level regression. CI runs this against both MySQL and MariaDB.
if (!in_array('--mysql', $argv, true)) {
    exit(0);
}

$dsn = getenv('DEMO_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$user = getenv('DEMO_DB_USER') ?: 'root';
$pass = getenv('DEMO_DB_PASS') ?: 'root';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS cas_demo_generator_test');
    $pdo->exec('USE cas_demo_generator_test');

    $startMarker = 'DROP TEMPORARY TABLE IF EXISTS tmp_demo_numbers;';
    $endMarker = 'DROP TEMPORARY TABLE IF EXISTS tmp_demo_departments;';
    $start = strpos($sql, $startMarker);
    $end = strpos($sql, $endMarker);
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Unable to isolate the demo number generator SQL.');
    }

    $generatorSql = substr($sql, $start, $end - $start);
    foreach (array_filter(array_map('trim', explode(';', $generatorSql))) as $statement) {
        $pdo->exec($statement);
    }

    $stats = $pdo->query('SELECT COUNT(*) AS c, MIN(n) AS min_n, MAX(n) AS max_n FROM tmp_demo_numbers')->fetch();
    if ((int)($stats['c'] ?? 0) !== 80 || (int)($stats['min_n'] ?? 0) !== 1 || (int)($stats['max_n'] ?? 0) !== 80) {
        throw new RuntimeException('Demo number generator did not produce exactly 1 through 80.');
    }

    fwrite(STDOUT, "Demo number generator passed against " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ".\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Demo number generator database test failed: " . $e->getMessage() . "\n");
    exit(1);
}
