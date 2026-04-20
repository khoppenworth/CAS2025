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

$snapshot = analytics_snapshot_v2_generate($pdo, 1, 99);

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

echo "Analytics snapshot v2 tests passed.\n";
