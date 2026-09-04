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

// Demo generation may read the location master, but it must not overwrite real
// EPSS location names, coordinates, addresses or verification status.
if (preg_match('/\b(?:UPDATE|DELETE\s+FROM)\s+epss_location\b/i', $sql)) {
    $failed[] = 'location master must remain read-only';
}
if (preg_match('/\bINSERT\s+INTO\s+epss_location\b/i', $sql)) {
    $failed[] = 'location master must not be seeded by demo data';
}

// Demo rows must remain easy to remove without relying on human-looking names.
if (!str_contains($sql, "username LIKE 'demo_%'")) {
    $failed[] = 'demo row cleanup marker';
}

if ($failed !== []) {
    fwrite(STDERR, "Demo dataset SQL contract failed:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "Demo dataset SQL contract passed.\n");
