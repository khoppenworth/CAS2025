<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/department_teams.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, department TEXT, cadre TEXT)');

$pdo->exec("INSERT INTO users (department, cadre) VALUES
    ('none', 'none'),
    ('none_2', 'none_7'),
    ('None', 'N/A'),
    ('Finance & Grants', 'Logistics'),
    ('finance', 'logistics'),
    ('Unknown', 'Unknown'),
    ('', 'Dispatch')");

$catalog = department_catalog($pdo);
if (isset($catalog['none'])) {
    fwrite(STDERR, "Placeholder department 'none' should not be backfilled into catalog.\n");
    exit(1);
}

if (isset($catalog['none_2'])) {
    fwrite(STDERR, "Placeholder department variants like 'none_2' should not be backfilled into catalog.\n");
    exit(1);
}

if (resolve_department_slug($pdo, 'none') !== '') {
    fwrite(STDERR, "'none' should resolve to an empty department slug.\n");
    exit(1);
}

$teams = department_team_catalog($pdo);
$matches = [];
foreach ($teams as $row) {
    if (strcasecmp((string)($row['label'] ?? ''), 'logistics') === 0 && (string)($row['department_slug'] ?? '') === 'finance') {
        $matches[] = $row;
    }
    if (strcasecmp((string)($row['label'] ?? ''), 'none') === 0) {
        fwrite(STDERR, "Placeholder team 'none' should not be backfilled into catalog.\n");
        exit(1);
    }
    if (strcasecmp((string)($row['label'] ?? ''), 'none_7') === 0) {
        fwrite(STDERR, "Placeholder team variants like 'none_7' should not be backfilled into catalog.\n");
        exit(1);
    }
}

if (count($matches) !== 1) {
    fwrite(STDERR, "Expected one normalized logistics team for finance department, found " . count($matches) . ".\n");
    exit(1);
}

echo "Department/team catalog tests passed.\n";
