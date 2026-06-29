<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/department_teams.php';
require_once __DIR__ . '/../lib/department_catalog_sync.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, department TEXT, cadre TEXT)');

$payload = [
    'version' => 1,
    'departments' => [
        ['slug' => 'finance', 'label' => 'Finance & Grants Live', 'sort_order' => 1, 'archived_at' => null],
        ['slug' => 'ict', 'label' => 'ICT', 'sort_order' => 2, 'archived_at' => '2026-01-01 00:00:00'],
    ],
    'teams' => [
        ['slug' => 'finance__accounts', 'department_slug' => 'finance', 'label' => 'Accounts', 'sort_order' => 10, 'archived_at' => null],
    ],
];

$validation = validate_department_catalog_import_payload($payload);
if (!$validation['valid']) {
    fwrite(STDERR, 'Expected payload to validate: ' . json_encode($validation['errors']) . PHP_EOL);
    exit(1);
}

$preview = preview_department_catalog_import($pdo, $validation['departments'], $validation['teams'], false);
if (count($preview['departments']['update']) < 1 || count($preview['teams']['create']) !== 1) {
    fwrite(STDERR, 'Expected preview to identify department updates and team creates.' . PHP_EOL);
    exit(1);
}
if (count($preview['departments']['archive_missing']) !== 0) {
    fwrite(STDERR, 'Import should not archive missing departments unless requested.' . PHP_EOL);
    exit(1);
}

$result = apply_department_catalog_import($pdo, $validation['departments'], $validation['teams'], false);
if ($result['teams']['created'] !== 1) {
    fwrite(STDERR, 'Expected one team to be created.' . PHP_EOL);
    exit(1);
}

$financeLabel = $pdo->query("SELECT label FROM department_catalog WHERE slug = 'finance'")->fetchColumn();
if ($financeLabel !== 'Finance & Grants Live') {
    fwrite(STDERR, 'Expected finance department label to be updated.' . PHP_EOL);
    exit(1);
}

$teamDepartment = $pdo->query("SELECT department_slug FROM department_team_catalog WHERE slug = 'finance__accounts'")->fetchColumn();
if ($teamDepartment !== 'finance') {
    fwrite(STDERR, 'Expected imported team to be linked to finance.' . PHP_EOL);
    exit(1);
}


$labelOnly = validate_department_catalog_import_payload([
    'departments' => [
        ['label' => 'Operations & Planning'],
    ],
    'teams' => [
        ['label' => 'Rapid Response Team', 'department' => 'Operations & Planning'],
    ],
]);
if (!$labelOnly['valid'] || !isset($labelOnly['departments']['operations_planning'], $labelOnly['teams']['rapid_response_team'])) {
    fwrite(STDERR, 'Expected label-only mixed-case imports to normalize into slugs.' . PHP_EOL);
    exit(1);
}
if (($labelOnly['teams']['rapid_response_team']['department_slug'] ?? '') !== 'operations_planning') {
    fwrite(STDERR, 'Expected team department labels to resolve against imported department labels.' . PHP_EOL);
    exit(1);
}

$invalid = validate_department_catalog_import_payload([
    'departments' => [['slug' => 'finance', 'label' => 'Finance'], ['slug' => 'finance', 'label' => 'Duplicate']],
    'teams' => [['slug' => 'orphan_team', 'department_slug' => 'missing', 'label' => 'Orphan']],
]);
if ($invalid['valid'] || count($invalid['errors']) < 2) {
    fwrite(STDERR, 'Expected duplicate departments and missing team department to be rejected.' . PHP_EOL);
    exit(1);
}


$invalidMetadata = validate_department_catalog_import_payload([
    'departments' => [
        ['slug' => 'bad_metadata', 'label' => 'Bad Metadata', 'sort_order' => -1, 'archived_at' => 'not-a-date'],
    ],
    'teams' => [],
]);
if ($invalidMetadata['valid'] || count($invalidMetadata['errors']) < 2) {
    fwrite(STDERR, 'Expected invalid date and negative sort order to be rejected.' . PHP_EOL);
    exit(1);
}

$emptyImport = validate_department_catalog_import_payload(['departments' => [], 'teams' => []]);
if ($emptyImport['valid']) {
    fwrite(STDERR, 'Expected empty imports to be rejected.' . PHP_EOL);
    exit(1);
}

$export = parse_department_catalog_import_json(department_catalog_export_json($pdo));
if (!isset($export['departments'], $export['teams'])) {
    fwrite(STDERR, 'Expected export JSON to include departments and teams arrays.' . PHP_EOL);
    exit(1);
}

$decisionPdo = new PDO('sqlite::memory:');
$decisionPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$decisionPdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, department TEXT, cadre TEXT)');
department_catalog($decisionPdo);
$decisionPdo->exec("INSERT INTO department_catalog (slug, label, sort_order, archived_at) VALUES ('custom_keep', 'Custom Keep', 50, NULL)");
$decisionPayload = [
    'departments' => [
        ['slug' => 'finance', 'label' => 'Finance Overwrite', 'sort_order' => 20, 'archived_at' => null],
        ['slug' => 'new_department', 'label' => 'New Department', 'sort_order' => 30, 'archived_at' => null],
    ],
    'teams' => [
        ['slug' => 'finance__new_team', 'department_slug' => 'finance', 'label' => 'New Team', 'sort_order' => 5, 'archived_at' => null],
    ],
];
$decisionValidation = validate_department_catalog_import_payload($decisionPayload);
if (!$decisionValidation['valid']) {
    fwrite(STDERR, 'Expected decision payload to validate.' . PHP_EOL);
    exit(1);
}
$decisionResult = apply_department_catalog_import_decisions(
    $decisionPdo,
    $decisionValidation['departments'],
    $decisionValidation['teams'],
    true,
    [
        'departments' => [
            'update' => ['finance' => 'keep'],
            'create' => ['new_department' => 'ignore'],
            'archive_missing' => ['custom_keep' => 'keep'],
        ],
        'teams' => [
            'create' => ['finance__new_team' => 'ignore'],
        ],
    ]
);
if ($decisionResult['departments']['kept'] < 2 || $decisionResult['teams']['kept'] < 1) {
    fwrite(STDERR, 'Expected selected keep/ignore decisions to be counted.' . PHP_EOL);
    exit(1);
}
$keptFinanceLabel = $decisionPdo->query("SELECT label FROM department_catalog WHERE slug = 'finance'")->fetchColumn();
if ($keptFinanceLabel === 'Finance Overwrite') {
    fwrite(STDERR, 'Expected keep decision to preserve existing finance department.' . PHP_EOL);
    exit(1);
}
$newDepartmentExists = $decisionPdo->query("SELECT COUNT(1) FROM department_catalog WHERE slug = 'new_department'")->fetchColumn();
if ((int)$newDepartmentExists !== 0) {
    fwrite(STDERR, 'Expected ignore decision to skip new department creation.' . PHP_EOL);
    exit(1);
}


echo "Department catalog sync tests passed.\n";
