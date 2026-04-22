<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/analytics_data_viewer.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, department TEXT, directorate TEXT, work_function TEXT, business_role TEXT, profile_role TEXT, role TEXT)');
$pdo->exec('CREATE TABLE questionnaire (id INTEGER PRIMARY KEY, title TEXT)');
$pdo->exec('CREATE TABLE questionnaire_response (id INTEGER PRIMARY KEY, user_id INT, questionnaire_id INT, status TEXT, score REAL, created_at TEXT, reviewed_at TEXT)');

$pdo->exec("INSERT INTO users (id, username, full_name, department, directorate, work_function, business_role, profile_role, role) VALUES
    (1, 'ops_mgr', 'Ops Manager', 'Operations', 'Ops', 'ops', 'manager', NULL, 'supervisor'),
    (2, 'ops_staff', 'Ops Staff', 'Operations', 'Ops', 'ops', 'staff', NULL, 'staff'),
    (3, 'fin_staff', 'Finance Staff', 'Finance', 'Finance', 'finance', 'staff', NULL, 'staff')");
$pdo->exec("INSERT INTO questionnaire (id, title) VALUES (1, 'Quarterly Review')");
$pdo->exec("INSERT INTO questionnaire_response (id, user_id, questionnaire_id, status, score, created_at, reviewed_at) VALUES
    (1, 2, 1, 'approved', 75, '2026-01-02 10:00:00', NULL),
    (2, 3, 1, 'submitted', 67, '2026-01-03 11:00:00', NULL)");

$scope = analytics_data_viewer_apply_scope(
    ['role' => 'supervisor', 'directorate' => 'Ops', 'department' => 'Operations'],
    ['directorate' => 'Finance', 'business_role' => '', 'work_function' => '', 'user_id' => 0]
);
if (($scope['directorate'] ?? '') !== 'Ops') {
    fwrite(STDERR, "Supervisor scope must enforce own directorate.\n");
    exit(1);
}

[$parts, $params] = analytics_data_viewer_query(
    $pdo,
    ['role' => 'supervisor', 'directorate' => 'Ops', 'department' => 'Operations'],
    ['business_role' => '', 'directorate' => '', 'work_function' => '', 'user_id' => 0],
    1,
    '',
    '',
    ''
);
[$sql] = $parts;
$sql .= 'ORDER BY qr.id ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (count($rows) !== 1) {
    fwrite(STDERR, "Expected one row in supervisor-scoped query.\n");
    exit(1);
}
if ((int)($rows[0]['response_id'] ?? 0) !== 1) {
    fwrite(STDERR, "Expected operations response only.\n");
    exit(1);
}

echo "Analytics data viewer tests passed.\n";
