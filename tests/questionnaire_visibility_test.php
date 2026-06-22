<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/questionnaire_visibility.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("CREATE TABLE questionnaire (id INTEGER PRIMARY KEY, title TEXT, status TEXT)");
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, department TEXT, cadre TEXT)");
$pdo->exec("CREATE TABLE questionnaire_department (questionnaire_id INTEGER NOT NULL, department_slug TEXT NOT NULL)");
$pdo->exec("CREATE TABLE questionnaire_assignment (staff_id INTEGER NOT NULL, questionnaire_id INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE questionnaire_team (questionnaire_id INTEGER NOT NULL, team_slug TEXT NOT NULL)");
$pdo->exec("CREATE TABLE questionnaire_work_function (questionnaire_id INTEGER NOT NULL, work_function TEXT NOT NULL)");
$pdo->exec("INSERT INTO questionnaire (id, title, status) VALUES
    (1, 'Finance Review', 'published'),
    (2, 'HR Review', 'published'),
    (3, 'Draft Review', 'draft'),
    (4, 'Director Review', 'published'),
    (5, 'Finance Grants Team Review', 'published')");
$pdo->exec("INSERT INTO users (id, department, cadre) VALUES (10, 'finance', 'Grants'), (11, 'finance', 'Accounting')");
$pdo->exec("INSERT INTO questionnaire_department (questionnaire_id, department_slug) VALUES (1, 'finance'), (2, 'hrm'), (4, 'finance')");
$pdo->exec("INSERT INTO questionnaire_team (questionnaire_id, team_slug) VALUES (5, 'grants')");
$pdo->exec("INSERT INTO questionnaire_assignment (staff_id, questionnaire_id) VALUES (20, 2), (1, 4), (1, 2), (1, 3)");
$pdo->exec("INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (4, 'director')");

$financeStaff = [
    'id' => 10,
    'role' => 'staff',
    'department' => 'finance',
    'work_function' => 'finance',
    'cadre' => 'Grants',
];
$financeIds = array_map(static fn(array $row): int => (int)$row['id'], available_questionnaires_for_user($pdo, $financeStaff));
if ($financeIds !== [1, 5]) {
    fwrite(STDERR, 'Finance grants staff should see department and team questionnaires after role filtering. Got: ' . json_encode($financeIds) . PHP_EOL);
    exit(1);
}


$accountingStaff = [
    'id' => 11,
    'role' => 'staff',
    'department' => 'finance',
    'work_function' => 'finance',
    'cadre' => 'Accounting',
];
$accountingIds = array_map(static fn(array $row): int => (int)$row['id'], available_questionnaires_for_user($pdo, $accountingStaff));
if ($accountingIds !== [1]) {
    fwrite(STDERR, 'Finance accounting staff should not see grants team-only questionnaires. Got: ' . json_encode($accountingIds) . PHP_EOL);
    exit(1);
}

$directStaff = [
    'id' => 20,
    'role' => 'staff',
    'department' => '',
    'work_function' => 'hrm',
];
$directIds = array_map(static fn(array $row): int => (int)$row['id'], available_questionnaires_for_user($pdo, $directStaff));
if ($directIds !== [2]) {
    fwrite(STDERR, 'Directly assigned staff should only see their direct published assignment. Got: ' . json_encode($directIds) . PHP_EOL);
    exit(1);
}

$admin = ['id' => 1, 'role' => 'admin'];
$adminIds = array_map(static fn(array $row): int => (int)$row['id'], available_questionnaires_for_user($pdo, $admin));
if ($adminIds !== [4, 2]) {
    fwrite(STDERR, 'Admins should only see directly assigned published questionnaires ordered by title. Got: ' . json_encode($adminIds) . PHP_EOL);
    exit(1);
}

$unassignedAdmin = ['id' => 2, 'role' => 'admin'];
$unassignedAdminIds = array_map(static fn(array $row): int => (int)$row['id'], available_questionnaires_for_user($pdo, $unassignedAdmin));
if ($unassignedAdminIds !== []) {
    fwrite(STDERR, 'Admins without direct assignments should not see questionnaires. Got: ' . json_encode($unassignedAdminIds) . PHP_EOL);
    exit(1);
}

$pdo->exec('DROP TABLE questionnaire_department');
$pdo->exec('DROP TABLE questionnaire_team');
$closedIds = array_map(static fn(array $row): int => (int)$row['id'], available_questionnaires_for_user($pdo, $financeStaff));
if ($closedIds !== []) {
    fwrite(STDERR, 'Staff visibility must not fall back to all questionnaires when assignment lookup fails. Got: ' . json_encode($closedIds) . PHP_EOL);
    exit(1);
}

echo "Questionnaire visibility tests passed.\n";
