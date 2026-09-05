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
    (1, 'Finance General Review', 'published'),
    (2, 'HR General Review', 'published'),
    (3, 'Draft Review', 'draft'),
    (4, 'Finance Director Review', 'published'),
    (5, 'Finance Grants Team Review', 'published'),
    (6, 'Finance Expert Review', 'published'),
    (7, 'Orphan Director Review', 'published'),
    (8, 'Direct Director Review', 'published'),
    (9, 'Direct General Review', 'published'),
    (10, 'HR Expert Review', 'published')");

$pdo->exec("INSERT INTO users (id, department, cadre) VALUES
    (1, 'finance', 'Grants'),
    (2, '', ''),
    (3, 'finance', 'Accounting'),
    (10, 'finance', 'Grants'),
    (11, 'finance', 'Accounting'),
    (12, 'finance', 'Accounting'),
    (13, 'hrm', 'Accounting'),
    (20, '', ''),
    (21, '', ''),
    (30, 'finance', 'Accounting')");

$pdo->exec("INSERT INTO questionnaire_department (questionnaire_id, department_slug) VALUES
    (1, 'finance'),
    (2, 'hrm'),
    (4, 'finance'),
    (6, 'finance'),
    (10, 'hrm')");
$pdo->exec("INSERT INTO questionnaire_team (questionnaire_id, team_slug) VALUES (5, 'grants')");
$pdo->exec("INSERT INTO questionnaire_assignment (staff_id, questionnaire_id) VALUES
    (20, 2),
    (20, 3),
    (20, 8),
    (20, 9),
    (21, 8),
    (1, 2),
    (1, 3),
    (30, 8)");
$pdo->exec("INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES
    (4, 'director'),
    (6, 'expert'),
    (7, 'director'),
    (8, 'director'),
    (10, 'expert')");

/** @param array<string,mixed> $user */
function visible_questionnaire_ids(PDO $pdo, array $user): array
{
    $ids = array_map(
        static fn(array $row): int => (int)$row['id'],
        available_questionnaires_for_user($pdo, $user)
    );
    sort($ids, SORT_NUMERIC);
    return $ids;
}

$financeExpert = [
    'id' => 10,
    'role' => 'staff',
    'department' => 'finance',
    'work_function' => 'expert',
    'cadre' => 'Grants',
];
$financeExpertIds = visible_questionnaire_ids($pdo, $financeExpert);
if ($financeExpertIds !== [1, 5, 6]) {
    fwrite(STDERR, 'Finance grants expert should see matching department/team questionnaires and expert-scoped review. Got: ' . json_encode($financeExpertIds) . PHP_EOL);
    exit(1);
}

$financeAccountingExpert = [
    'id' => 11,
    'role' => 'staff',
    'department' => 'finance',
    'work_function' => 'expert',
    'cadre' => 'Accounting',
];
$financeAccountingExpertIds = visible_questionnaire_ids($pdo, $financeAccountingExpert);
if ($financeAccountingExpertIds !== [1, 6]) {
    fwrite(STDERR, 'Finance accounting expert should not see grants-team or director-only questionnaires. Got: ' . json_encode($financeAccountingExpertIds) . PHP_EOL);
    exit(1);
}

$financeDirector = [
    'id' => 12,
    'role' => 'staff',
    'department' => 'finance',
    'work_function' => 'director',
    'cadre' => 'Accounting',
];
$financeDirectorIds = visible_questionnaire_ids($pdo, $financeDirector);
if ($financeDirectorIds !== [1, 4]) {
    fwrite(STDERR, 'Finance director should see unrestricted and director-scoped finance questionnaires only. Got: ' . json_encode($financeDirectorIds) . PHP_EOL);
    exit(1);
}

$hrExpert = [
    'id' => 13,
    'role' => 'staff',
    'department' => 'hrm',
    'work_function' => 'expert',
    'cadre' => 'Accounting',
];
$hrExpertIds = visible_questionnaire_ids($pdo, $hrExpert);
if ($hrExpertIds !== [2, 10]) {
    fwrite(STDERR, 'HR expert should see only HR department questionnaires allowed for the expert role. Got: ' . json_encode($hrExpertIds) . PHP_EOL);
    exit(1);
}

$directExpert = [
    'id' => 20,
    'role' => 'staff',
    'department' => '',
    'work_function' => 'expert',
    'cadre' => '',
];
$directExpertIds = visible_questionnaire_ids($pdo, $directExpert);
if ($directExpertIds !== [2, 9]) {
    fwrite(STDERR, 'Direct assignments should still require publication and matching work-role restrictions. Got: ' . json_encode($directExpertIds) . PHP_EOL);
    exit(1);
}

$directDirector = [
    'id' => 21,
    'role' => 'staff',
    'department' => '',
    'work_function' => 'director',
    'cadre' => '',
];
$directDirectorIds = visible_questionnaire_ids($pdo, $directDirector);
if ($directDirectorIds !== [8]) {
    fwrite(STDERR, 'Directly assigned director should see the matching director-scoped questionnaire. Got: ' . json_encode($directDirectorIds) . PHP_EOL);
    exit(1);
}

$admin = [
    'id' => 1,
    'role' => 'admin',
    'department' => 'finance',
    'work_function' => 'expert',
    'cadre' => 'Grants',
];
$adminIds = visible_questionnaire_ids($pdo, $admin);
if ($adminIds !== [1, 2, 5, 6]) {
    fwrite(STDERR, 'Admin should combine profile scope and direct assignments while respecting work-role filters. Got: ' . json_encode($adminIds) . PHP_EOL);
    exit(1);
}

$directorAdmin = [
    'id' => 3,
    'role' => 'admin',
    'department' => 'finance',
    'work_function' => 'director',
    'cadre' => 'Accounting',
];
$directorAdminIds = visible_questionnaire_ids($pdo, $directorAdmin);
if ($directorAdminIds !== [1, 4]) {
    fwrite(STDERR, 'Admin should use profile work role when filtering department questionnaires. Got: ' . json_encode($directorAdminIds) . PHP_EOL);
    exit(1);
}

$unassignedAdmin = [
    'id' => 2,
    'role' => 'admin',
    'department' => '',
    'work_function' => 'director',
    'cadre' => '',
];
$unassignedAdminIds = visible_questionnaire_ids($pdo, $unassignedAdmin);
if ($unassignedAdminIds !== []) {
    fwrite(STDERR, 'A work-role row must never grant access without department, team, or direct assignment. Got: ' . json_encode($unassignedAdminIds) . PHP_EOL);
    exit(1);
}

$supervisor = [
    'id' => 30,
    'role' => 'supervisor',
    'department' => 'finance',
    'work_function' => 'director',
    'cadre' => 'Accounting',
];
$supervisorIds = visible_questionnaire_ids($pdo, $supervisor);
if ($supervisorIds !== [8]) {
    fwrite(STDERR, 'Supervisor submission access should remain direct-assignment-only and respect work-role restrictions. Got: ' . json_encode($supervisorIds) . PHP_EOL);
    exit(1);
}

$pdo->exec('DROP TABLE questionnaire_department');
$pdo->exec('DROP TABLE questionnaire_team');
$closedIds = visible_questionnaire_ids($pdo, $financeExpert);
if ($closedIds !== []) {
    fwrite(STDERR, 'Missing assignment tables must not activate legacy work-role fallback. Got: ' . json_encode($closedIds) . PHP_EOL);
    exit(1);
}

$schemaRows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('questionnaire_department', 'questionnaire_team') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
if ($schemaRows !== ['questionnaire_department', 'questionnaire_team']) {
    fwrite(STDERR, 'Visibility lookup should bootstrap missing department/team assignment tables. Got: ' . json_encode($schemaRows) . PHP_EOL);
    exit(1);
}

echo "Questionnaire visibility tests passed.\n";
