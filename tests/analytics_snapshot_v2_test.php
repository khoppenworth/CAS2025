<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/analytics_snapshot_v2.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, department TEXT, business_role TEXT, profile_role TEXT)');
$pdo->exec('CREATE TABLE questionnaire_response (id INTEGER PRIMARY KEY, user_id INT, questionnaire_id INT, status TEXT, score REAL)');
$pdo->exec('CREATE TABLE competency_benchmark_policy (id INTEGER PRIMARY KEY, scope_type TEXT, scope_id TEXT, required_pct REAL)');
$pdo->exec('CREATE TABLE analytics_report_snapshot_v2 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    questionnaire_id INT NULL,
    generated_by INT NULL,
    status TEXT NOT NULL DEFAULT "draft",
    locked INT NOT NULL DEFAULT 0,
    filters_json TEXT NULL,
    summary_json TEXT NOT NULL,
    details_json TEXT NOT NULL,
    generated_at TEXT NOT NULL,
    finalized_at TEXT NULL
)');

$pdo->exec("INSERT INTO users (id, department, business_role, profile_role) VALUES
    (1, 'Finance', 'manager', NULL),
    (2, 'Operations', 'staff', NULL),
    (3, 'Operations', NULL, 'team_leader')");

$pdo->exec("INSERT INTO questionnaire_response (id, user_id, questionnaire_id, status, score) VALUES
    (1, 1, 1, 'approved', 88),
    (2, 2, 1, 'submitted', 54),
    (3, 3, 1, 'approved', 45)");

$pdo->exec("INSERT INTO competency_benchmark_policy (scope_type, scope_id, required_pct) VALUES
    ('organization', NULL, 80.0)");

$supervisorScoped = analytics_snapshot_v2_apply_viewer_scope(
    ['role' => 'supervisor', 'directorate' => 'Operations'],
    ['directorate' => 'Finance', 'business_role' => 'staff', 'work_function' => '', 'user_id' => 0]
);
if (($supervisorScoped['directorate'] ?? '') !== 'Operations') {
    fwrite(STDERR, "Supervisor scope should enforce own directorate.\n");
    exit(1);
}

$snapshot = analytics_snapshot_v2_generate($pdo, 1, 99, ['business_role' => 'staff']);

if (empty($snapshot['snapshot_id']) || (int)$snapshot['snapshot_id'] <= 0) {
    fwrite(STDERR, "Snapshot ID should be generated and persisted.\n");
    exit(1);
}

if (($snapshot['summary']['competency_level'] ?? '') !== 'Basic Proficiency') {
    fwrite(STDERR, "Unexpected summary competency level.\n");
    exit(1);
}

if (empty($snapshot['critical_gaps'])) {
    fwrite(STDERR, "Expected at least one critical gap entry.\n");
    exit(1);
}

$finalized = analytics_snapshot_v2_finalize($pdo, (int)$snapshot['snapshot_id']);
if ($finalized !== true) {
    fwrite(STDERR, "Snapshot finalize should return true.\n");
    exit(1);
}

$locked = $pdo->query('SELECT locked, status FROM analytics_report_snapshot_v2 WHERE id = ' . (int)$snapshot['snapshot_id'])->fetch(PDO::FETCH_ASSOC);
if ((int)($locked['locked'] ?? 0) !== 1 || (string)($locked['status'] ?? '') !== 'finalized') {
    fwrite(STDERR, "Finalized snapshot should be locked and marked finalized.\n");
    exit(1);
}
$persistedFilters = $pdo->query('SELECT filters_json FROM analytics_report_snapshot_v2 WHERE id = ' . (int)$snapshot['snapshot_id'])->fetchColumn();
$decodedFilters = json_decode((string)$persistedFilters, true);
if (($decodedFilters['business_role'] ?? '') !== 'staff') {
    fwrite(STDERR, "Expected persisted business_role filter.\n");
    exit(1);
}

$insertScoped = $pdo->prepare(
    'INSERT INTO analytics_report_snapshot_v2 (questionnaire_id, generated_by, status, locked, filters_json, summary_json, details_json, generated_at) '
    . 'VALUES (1, 777, "draft", 0, :filters_json, "{}", "{}", CURRENT_TIMESTAMP)'
);
$insertScoped->execute([':filters_json' => json_encode(['directorate' => 'Finance'])]);
$forgedSnapshotId = (int)$pdo->lastInsertId();

$supervisorViewer = ['id' => 500, 'role' => 'supervisor', 'directorate' => 'Operations'];
if (analytics_snapshot_v2_finalize_for_viewer($pdo, $forgedSnapshotId, $supervisorViewer)) {
    fwrite(STDERR, "Supervisor should not finalize snapshot outside their directorate.\n");
    exit(1);
}

$forgedStatus = $pdo->query('SELECT locked, status FROM analytics_report_snapshot_v2 WHERE id = ' . $forgedSnapshotId)->fetch(PDO::FETCH_ASSOC);
if ((int)($forgedStatus['locked'] ?? 0) !== 0 || (string)($forgedStatus['status'] ?? '') !== 'draft') {
    fwrite(STDERR, "Out-of-scope snapshot should remain draft and unlocked.\n");
    exit(1);
}

$insertOwn = $pdo->prepare(
    'INSERT INTO analytics_report_snapshot_v2 (questionnaire_id, generated_by, status, locked, filters_json, summary_json, details_json, generated_at) '
    . 'VALUES (1, 500, "draft", 0, :filters_json, "{}", "{}", CURRENT_TIMESTAMP)'
);
$insertOwn->execute([':filters_json' => json_encode(['directorate' => 'Finance'])]);
$ownSnapshotId = (int)$pdo->lastInsertId();
if (!analytics_snapshot_v2_finalize_for_viewer($pdo, $ownSnapshotId, $supervisorViewer)) {
    fwrite(STDERR, "Supervisor should finalize their own snapshot.\n");
    exit(1);
}

echo "Analytics snapshot v2 tests passed.\n";
