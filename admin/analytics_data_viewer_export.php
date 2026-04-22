<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_data_viewer.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$viewer = current_user();

$questionnaireId = isset($_GET['questionnaire_id']) ? max(0, (int)$_GET['questionnaire_id']) : 0;
$filters = [
    'business_role' => $_GET['business_role'] ?? '',
    'directorate' => $_GET['directorate'] ?? '',
    'work_function' => $_GET['work_function'] ?? '',
    'user_id' => $_GET['user_id'] ?? 0,
];
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

[$parts, $params] = analytics_data_viewer_query($pdo, $viewer, $filters, $questionnaireId, $statusFilter, $dateFrom, $dateTo);
[$sql] = $parts;
$sql .= 'ORDER BY qr.created_at DESC, qr.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="analytics_report_raw_data.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['response_id', 'questionnaire_id', 'questionnaire_title', 'user_id', 'username', 'full_name', 'business_role', 'directorate', 'department', 'work_function', 'status', 'score', 'created_at', 'reviewed_at']);
foreach ($rows as $row) {
    fputcsv($out, [
        (int)($row['response_id'] ?? 0),
        (int)($row['questionnaire_id'] ?? 0),
        (string)($row['questionnaire_title'] ?? ''),
        (int)($row['user_id'] ?? 0),
        (string)($row['username'] ?? ''),
        (string)($row['full_name'] ?? ''),
        (string)($row['business_role'] ?? ''),
        (string)($row['directorate'] ?? ''),
        (string)($row['department'] ?? ''),
        (string)($row['work_function'] ?? ''),
        (string)($row['status'] ?? ''),
        isset($row['score']) ? (string)$row['score'] : '',
        (string)($row['created_at'] ?? ''),
        (string)($row['reviewed_at'] ?? ''),
    ]);
}
fclose($out);
exit;
