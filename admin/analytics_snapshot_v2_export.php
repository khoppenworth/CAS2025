<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_snapshot_v2.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$questionnaireId = isset($_GET['questionnaire_id']) ? max(0, (int)$_GET['questionnaire_id']) : 0;

if (!analytics_snapshot_v2_table_exists($pdo, 'analytics_report_snapshot_v2')) {
    http_response_code(404);
    echo 'Snapshot table not available.';
    exit;
}

$sql = 'SELECT id, questionnaire_id, status, locked, summary_json, generated_at, finalized_at FROM analytics_report_snapshot_v2 ';
$params = [];
if ($questionnaireId > 0) {
    $sql .= 'WHERE questionnaire_id = ? ';
    $params[] = $questionnaireId;
}
$sql .= 'ORDER BY generated_at DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="analytics_snapshot_v2.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['snapshot_id', 'questionnaire_id', 'status', 'locked', 'average_score', 'competency_level', 'gap_pct_required', 'generated_at', 'finalized_at']);
foreach ($rows as $row) {
    $summary = json_decode((string)($row['summary_json'] ?? '{}'), true);
    fputcsv($out, [
        (int)($row['id'] ?? 0),
        (int)($row['questionnaire_id'] ?? 0),
        (string)($row['status'] ?? ''),
        !empty($row['locked']) ? 1 : 0,
        isset($summary['average_score']) ? (float)$summary['average_score'] : null,
        (string)($summary['competency_level'] ?? ''),
        isset($summary['gap_pct_required']) ? (float)$summary['gap_pct_required'] : null,
        (string)($row['generated_at'] ?? ''),
        (string)($row['finalized_at'] ?? ''),
    ]);
}
fclose($out);
exit;
