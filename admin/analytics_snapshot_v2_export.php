<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_snapshot_v2.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$viewer = current_user();
$viewerRole = (string)($viewer['role'] ?? ($_SESSION['user']['role'] ?? ''));
$viewerId = (int)($viewer['id'] ?? ($_SESSION['user']['id'] ?? 0));

$questionnaireId = isset($_GET['questionnaire_id']) ? max(0, (int)$_GET['questionnaire_id']) : 0;
$businessRole = isset($_GET['business_role']) ? trim((string)$_GET['business_role']) : '';
$directorate = isset($_GET['directorate']) ? trim((string)$_GET['directorate']) : '';
$workFunction = isset($_GET['work_function']) ? trim((string)$_GET['work_function']) : '';
$userId = isset($_GET['user_id']) ? max(0, (int)$_GET['user_id']) : 0;
$effectiveFilters = analytics_snapshot_v2_apply_viewer_scope($viewer, [
    'business_role' => $businessRole,
    'directorate' => $directorate,
    'work_function' => $workFunction,
    'user_id' => $userId,
]);
$businessRole = $effectiveFilters['business_role'];
$directorate = $effectiveFilters['directorate'];
$workFunction = $effectiveFilters['work_function'];
$userId = $effectiveFilters['user_id'];

if (!analytics_snapshot_v2_table_exists($pdo, 'analytics_report_snapshot_v2')) {
    http_response_code(404);
    echo 'Snapshot table not available.';
    exit;
}

$sql = 'SELECT id, questionnaire_id, generated_by, status, locked, filters_json, summary_json, generated_at, finalized_at FROM analytics_report_snapshot_v2 ';
$params = [];
$where = [];
if ($questionnaireId > 0) {
    $where[] = 'questionnaire_id = ?';
    $params[] = $questionnaireId;
}
if ($where) {
    $sql .= 'WHERE ' . implode(' AND ', $where) . ' ';
}
$sql .= 'ORDER BY generated_at DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="analytics_snapshot_v2.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['snapshot_id', 'questionnaire_id', 'status', 'locked', 'business_role', 'directorate', 'work_function', 'user_id', 'average_score', 'competency_level', 'gap_pct_required', 'generated_at', 'finalized_at']);
foreach ($rows as $row) {
    $summary = json_decode((string)($row['summary_json'] ?? '{}'), true);
    $filters = json_decode((string)($row['filters_json'] ?? '{}'), true);
    if ($businessRole !== '' && (string)($filters['business_role'] ?? '') !== $businessRole) {
        continue;
    }
    if ($directorate !== '' && (string)($filters['directorate'] ?? '') !== $directorate) {
        continue;
    }
    if ($workFunction !== '' && (string)($filters['work_function'] ?? '') !== $workFunction) {
        continue;
    }
    if ($userId > 0 && (int)($filters['user_id'] ?? 0) !== $userId) {
        continue;
    }
    if ($viewerRole === 'supervisor') {
        $rowDirectorate = (string)($filters['directorate'] ?? '');
        $rowGeneratedBy = (int)($row['generated_by'] ?? 0);
        if ($rowGeneratedBy !== $viewerId && ($directorate === '' || $rowDirectorate !== $directorate)) {
            continue;
        }
    }
    fputcsv($out, [
        (int)($row['id'] ?? 0),
        (int)($row['questionnaire_id'] ?? 0),
        (string)($row['status'] ?? ''),
        !empty($row['locked']) ? 1 : 0,
        (string)($filters['business_role'] ?? ''),
        (string)($filters['directorate'] ?? ''),
        (string)($filters['work_function'] ?? ''),
        (int)($filters['user_id'] ?? 0),
        isset($summary['average_score']) ? (float)$summary['average_score'] : null,
        (string)($summary['competency_level'] ?? ''),
        isset($summary['gap_pct_required']) ? (float)$summary['gap_pct_required'] : null,
        (string)($row['generated_at'] ?? ''),
        (string)($row['finalized_at'] ?? ''),
    ]);
}
fclose($out);
exit;
