<?php
declare(strict_types=1);

require_once __DIR__ . '/../i18n.php';
require_once __DIR__ . '/../lib/work_functions.php';
require_once __DIR__ . '/../lib/analytics_report.php';

$_SESSION = [];
$_SESSION['enabled_locales'] = ['en'];

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE questionnaire (id INTEGER PRIMARY KEY, title TEXT, status TEXT)');
$pdo->exec('CREATE TABLE questionnaire_section (id INTEGER PRIMARY KEY, questionnaire_id INT, title TEXT, order_index INT, is_active INT DEFAULT 1)');
$pdo->exec('CREATE TABLE questionnaire_item (id INTEGER PRIMARY KEY, questionnaire_id INT, section_id INT, linkId TEXT, type TEXT, allow_multiple INT, weight_percent REAL, order_index INT, is_active INT)');
$pdo->exec('CREATE TABLE questionnaire_item_option (id INTEGER PRIMARY KEY, questionnaire_item_id INT, value TEXT, is_correct INT DEFAULT 0, order_index INT)');
$pdo->exec('CREATE TABLE questionnaire_response (id INTEGER PRIMARY KEY, user_id INT, questionnaire_id INT, performance_period_id INT, status TEXT, score REAL, created_at TEXT, reviewed_at TEXT)');
$pdo->exec('CREATE TABLE questionnaire_response_item (id INTEGER PRIMARY KEY, response_id INT, linkId TEXT, answer TEXT)');
$pdo->exec('CREATE TABLE performance_period (id INTEGER PRIMARY KEY, label TEXT, period_start TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, work_function TEXT)');
$pdo->exec('CREATE TABLE questionnaire_work_function (questionnaire_id INT, work_function TEXT)');

$pdo->exec("INSERT INTO questionnaire (id, title, status) VALUES (1, 'Annual Review', 'published')");
$pdo->exec("INSERT INTO questionnaire_section (id, questionnaire_id, title, order_index, is_active) VALUES (1, 1, 'Core Competencies', 1, 1)");
$pdo->exec("INSERT INTO questionnaire_item (id, questionnaire_id, section_id, linkId, type, allow_multiple, weight_percent, order_index, is_active) VALUES\n    (1, 1, 1, 'likert_a', 'likert', 0, NULL, 1, 1),\n    (2, 1, 1, 'bool_a', 'boolean', 0, 20, 2, 1)");
$pdo->exec("INSERT INTO performance_period (id, label, period_start) VALUES (1, 'FY2023', '2023-01-01'), (2, 'FY2024', '2024-01-01')");
$pdo->exec("INSERT INTO users (id, username, full_name, work_function) VALUES\n    (1, 'staff1', 'Staff One', 'finance'),\n    (2, 'staff2', 'Staff Two', 'hrm')");
$pdo->exec("INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (1, 'finance')");

$pdo->exec("INSERT INTO questionnaire_response (id, user_id, questionnaire_id, performance_period_id, status, score, created_at, reviewed_at) VALUES\n    (1, 1, 1, 1, 'approved', 85, '2024-01-01 09:00:00', '2024-01-02 00:00:00'),\n    (2, 2, 1, 2, 'submitted', 60, '2024-02-15 08:00:00', NULL)");
$pdo->exec("INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES\n    (1, 'likert_a', '[{\"valueInteger\":5,\"valueString\":\"5\"}]'),\n    (1, 'bool_a', '[{\"valueBoolean\":true}]'),\n    (2, 'likert_a', '[{\"valueInteger\":3,\"valueString\":\"3\"}]'),\n    (2, 'bool_a', '[{\"valueBoolean\":false}]')");

$snapshot = analytics_report_snapshot($pdo, 1, true);

if ($snapshot['summary']['total_responses'] !== 2) {
    fwrite(STDERR, "Expected two responses in summary.\n");
    exit(1);
}

if ($snapshot['summary']['approved_count'] !== 1 || $snapshot['summary']['submitted_count'] !== 1) {
    fwrite(STDERR, "Status counts did not match expected values.\n");
    exit(1);
}

if ($snapshot['total_participants'] !== 2) {
    fwrite(STDERR, "Participant count mismatch.\n");
    exit(1);
}

if ($snapshot['selected_questionnaire_id'] !== 1) {
    fwrite(STDERR, "Selected questionnaire ID should resolve to 1.\n");
    exit(1);
}

if (empty($snapshot['section_breakdowns'][1]['sections'])) {
    fwrite(STDERR, "Section breakdowns were not generated.\n");
    exit(1);
}

$workFunctions = [];
foreach ($snapshot['work_functions'] as $entry) {
    $workFunctions[$entry['label']] = $entry;
}

if (!isset($workFunctions['Finance & Grants'])) {
    fwrite(STDERR, "Finance work function summary missing.\n");
    exit(1);
}

if (!($snapshot['generated_at'] instanceof DateTimeImmutable)) {
    fwrite(STDERR, "Snapshot should include a DateTimeImmutable timestamp.\n");
    exit(1);
}

echo "Analytics snapshot tests passed.\n";
