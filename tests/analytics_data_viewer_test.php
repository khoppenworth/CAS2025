<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/analytics_data_viewer.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, department TEXT, directorate TEXT, cadre TEXT, work_function TEXT, business_role TEXT, profile_role TEXT, role TEXT)');
$pdo->exec('CREATE TABLE questionnaire (id INTEGER PRIMARY KEY, title TEXT)');
$pdo->exec('CREATE TABLE questionnaire_response (id INTEGER PRIMARY KEY, user_id INT, questionnaire_id INT, status TEXT, score REAL, created_at TEXT, reviewed_at TEXT)');

$pdo->exec("INSERT INTO users (id, username, full_name, department, directorate, cadre, work_function, business_role, profile_role, role) VALUES
    (1, 'ops_mgr', 'Ops Manager', 'Operations', 'Ops', 'ops_team', 'ops', 'manager', NULL, 'supervisor'),
    (2, 'ops_staff', 'Ops Staff', 'Operations', 'Ops', 'ops_team', 'ops', 'staff', NULL, 'staff'),
    (3, 'fin_staff', 'Finance Staff', 'Finance', 'Finance', 'finance_team', 'finance', 'staff', NULL, 'staff'),
    (4, 'legacy_ops', 'Legacy Ops', 'Operations', '', 'legacy_team', 'ops', 'staff', NULL, 'staff')");
$pdo->exec("INSERT INTO questionnaire (id, title) VALUES (1, 'Quarterly Review')");
$pdo->exec("INSERT INTO questionnaire_response (id, user_id, questionnaire_id, status, score, created_at, reviewed_at) VALUES
    (1, 2, 1, 'approved', 75, '2026-01-02 10:00:00', NULL),
    (2, 3, 1, 'submitted', 67, '2026-01-03 11:00:00', NULL),
    (3, 4, 1, 'approved', 72, '2026-01-04 11:00:00', NULL)");

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

[$fallbackParts, $fallbackParams] = analytics_data_viewer_query(
    $pdo,
    ['role' => 'supervisor', 'directorate' => '', 'department' => 'Operations'],
    ['business_role' => '', 'directorate' => '', 'work_function' => '', 'user_id' => 0],
    1,
    '',
    '',
    ''
);
[$fallbackSql] = $fallbackParts;
$fallbackSql .= 'ORDER BY qr.id ASC';
$fallbackStmt = $pdo->prepare($fallbackSql);
$fallbackStmt->execute($fallbackParams);
$fallbackRows = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (count($fallbackRows) !== 1) {
    fwrite(STDERR, "Expected one row when supervisor scope falls back to department.\n");
    exit(1);
}
if ((int)($fallbackRows[0]['response_id'] ?? 0) !== 3) {
    fwrite(STDERR, "Expected department fallback to include legacy user without directorate.\n");
    exit(1);
}


[$departmentParts, $departmentParams] = analytics_data_viewer_query(
    $pdo,
    ['role' => 'admin'],
    ['business_role' => '', 'department' => 'Operations', 'directorate' => '', 'team' => 'ops_team', 'work_function' => '', 'user_id' => 0],
    1,
    '',
    '',
    ''
);
[$departmentSql] = $departmentParts;
$departmentSql .= 'ORDER BY qr.id ASC';
$departmentStmt = $pdo->prepare($departmentSql);
$departmentStmt->execute($departmentParams);
$departmentRows = $departmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($departmentRows) !== 1 || (int)($departmentRows[0]['response_id'] ?? 0) !== 1) {
    fwrite(STDERR, "Expected department and team filters to narrow explorer rows.\n");
    exit(1);
}
if (($departmentRows[0]['team'] ?? '') !== 'ops_team') {
    fwrite(STDERR, "Expected explorer rows to include the team/cadre value.\n");
    exit(1);
}

[$dateParts, $dateParams] = analytics_data_viewer_query(
    $pdo,
    ['role' => 'admin'],
    ['business_role' => '', 'department' => '', 'directorate' => '', 'team' => '', 'work_function' => '', 'user_id' => 0],
    1,
    '',
    '2026-01-03',
    '2026-01-03'
);
[$dateSql] = $dateParts;
$dateSql .= 'ORDER BY qr.id ASC';
$dateStmt = $pdo->prepare($dateSql);
$dateStmt->execute($dateParams);
$dateRows = $dateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($dateRows) !== 1 || (int)($dateRows[0]['response_id'] ?? 0) !== 2) {
    fwrite(STDERR, "Expected inclusive date range to match only the January 3 response.\n");
    exit(1);
}
if (in_array('2026-01-03', $dateParams, true)) {
    fwrite(STDERR, "Expected date filters to use timestamp range parameters, not DATE() values.\n");
    exit(1);
}

if (analytics_data_viewer_csv_safe_cell('=sum(1,1)') !== "'=sum(1,1)") {
    fwrite(STDERR, "Expected CSV sanitizer to neutralize formulas.\n");
    exit(1);
}
if (analytics_data_viewer_csv_safe_cell('safe text') !== 'safe text') {
    fwrite(STDERR, "Expected CSV sanitizer to preserve safe text.\n");
    exit(1);
}

echo "Analytics data viewer tests passed.\n";
