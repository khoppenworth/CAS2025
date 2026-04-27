<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_data_viewer.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$viewer = current_user();
$viewerRole = (string)($viewer['role'] ?? ($_SESSION['user']['role'] ?? ''));

$locale = ensure_locale();
$t = load_lang($locale);
$drawerKey = 'team.analytics';
$pageTitle = t($t, 'analytics_report_explorer_title', 'Data Explorer');

$questionnaireId = isset($_GET['questionnaire_id']) ? max(0, (int)$_GET['questionnaire_id']) : 0;
$rawFilters = [
    'business_role' => $_GET['business_role'] ?? '',
    'directorate' => $_GET['directorate'] ?? '',
    'work_function' => $_GET['work_function'] ?? '',
    'user_id' => $_GET['user_id'] ?? 0,
];
$scopeFilters = analytics_data_viewer_apply_scope($viewer, $rawFilters);
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('invalid csrf');
    }

    $requestedPostFilters = [
        'business_role' => $_POST['business_role'] ?? '',
        'directorate' => $_POST['directorate'] ?? '',
        'work_function' => $_POST['work_function'] ?? '',
        'user_id' => $_POST['user_id'] ?? 0,
    ];
    $effectivePostFilters = analytics_data_viewer_apply_scope($viewer, $requestedPostFilters);

    $query = [
        'questionnaire_id' => max(0, (int)($_POST['questionnaire_id'] ?? 0)),
        'business_role' => (string)$effectivePostFilters['business_role'],
        'directorate' => (string)$effectivePostFilters['directorate'],
        'work_function' => (string)$effectivePostFilters['work_function'],
        'user_id' => max(0, (int)$effectivePostFilters['user_id']),
        'status' => trim((string)($_POST['status'] ?? '')),
        'date_from' => trim((string)($_POST['date_from'] ?? '')),
        'date_to' => trim((string)($_POST['date_to'] ?? '')),
    ];

    $query = array_filter($query, static function ($value) {
        if (is_int($value)) {
            return $value > 0;
        }
        return $value !== '';
    });

    $url = url_for('admin/analytics_data_viewer.php');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    header('Location: ' . $url);
    exit;
}

$questionnaires = [];
$qStmt = $pdo->query('SELECT id, title FROM questionnaire ORDER BY title ASC');
if ($qStmt) {
    $questionnaires = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$businessRoleOptions = [];
$directorateOptions = [];
$workFunctionOptions = [];
$userOptions = [];
$stripPlaceholderOptions = static function (array $options, array $placeholders): array {
    $filtered = [];
    foreach ($options as $option) {
        if (!in_array($option, $placeholders, true)) {
            $filtered[] = $option;
        }
    }
    return $filtered !== [] ? $filtered : $options;
};

$roleStmt = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(u.business_role, ''), NULLIF(u.profile_role, ''), 'Unspecified') AS role_label
    FROM questionnaire_response qr
    JOIN users u ON u.id = qr.user_id
    ORDER BY role_label ASC");
if ($roleStmt) {
    foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)($row['role_label'] ?? ''));
        if ($value !== '') {
            $businessRoleOptions[] = $value;
        }
    }
}
$directorateStmt = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(u.directorate, ''), NULLIF(u.department, ''), 'Unknown') AS directorate_label
    FROM questionnaire_response qr
    JOIN users u ON u.id = qr.user_id
    ORDER BY directorate_label ASC");
if ($directorateStmt) {
    foreach ($directorateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)($row['directorate_label'] ?? ''));
        if ($value !== '') {
            $directorateOptions[] = $value;
        }
    }
}
$workFunctionStmt = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(u.work_function, ''), NULLIF(u.department, ''), 'Unspecified') AS wf_label
    FROM questionnaire_response qr
    JOIN users u ON u.id = qr.user_id
    ORDER BY wf_label ASC");
if ($workFunctionStmt) {
    foreach ($workFunctionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)($row['wf_label'] ?? ''));
        if ($value !== '') {
            $workFunctionOptions[] = $value;
        }
    }
}
$userStmt = $pdo->query("SELECT DISTINCT u.id, COALESCE(NULLIF(u.full_name,''), u.username) AS display_name
    FROM questionnaire_response qr
    JOIN users u ON u.id = qr.user_id
    ORDER BY display_name ASC
    LIMIT 500");
if ($userStmt) {
    $userOptions = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($viewerRole === 'supervisor' && $scopeFilters['directorate'] !== '') {
    $directorateOptions = [$scopeFilters['directorate']];
}
$businessRoleOptions = $stripPlaceholderOptions($businessRoleOptions, ['Unspecified']);
$directorateOptions = $stripPlaceholderOptions($directorateOptions, ['Unknown']);
$workFunctionOptions = $stripPlaceholderOptions($workFunctionOptions, ['Unspecified']);

[$queryParts, $params] = analytics_data_viewer_query($pdo, $viewer, $rawFilters, $questionnaireId, $statusFilter, $dateFrom, $dateTo);
[$sql, $scopeFilters] = $queryParts;
$sql .= 'ORDER BY qr.created_at DESC, qr.id DESC LIMIT 1500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$exportHref = url_for('admin/analytics_data_viewer_export.php');
$filtersForExport = [];
foreach ([
    'questionnaire_id' => $questionnaireId,
    'business_role' => $scopeFilters['business_role'],
    'directorate' => $scopeFilters['directorate'],
    'work_function' => $scopeFilters['work_function'],
    'user_id' => $scopeFilters['user_id'],
    'status' => $statusFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
] as $key => $value) {
    if ((is_int($value) && $value > 0) || (is_string($value) && $value !== '')) {
        $filtersForExport[$key] = $value;
    }
}
if ($filtersForExport) {
    $exportHref .= '?' . http_build_query($filtersForExport);
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=htmlspecialchars($pageTitle)?></title>
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style>
    .data-explorer,
    .data-explorer button,
    .data-explorer input,
    .data-explorer select,
    .data-explorer table {
      font-family: var(--app-font-sans, "Segoe UI", system-ui, -apple-system, Roboto, Helvetica, Arial, sans-serif);
    }
    .data-explorer .explorer-filters { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; }
    .data-explorer .explorer-filters .md-field { min-width: 180px; }
    .data-explorer .explorer-filters .md-button { margin-bottom: .2rem; }
  </style>
</head>
<body class="md-app-shell">
<?php require_once __DIR__ . '/../templates/header.php'; ?>
<main class="md-content data-explorer">
  <section class="md-card md-elev-2" style="padding:1rem;">
    <h1 class="md-card-title"><?=htmlspecialchars($pageTitle)?></h1>
    <p class="md-upgrade-meta"><?=t($t, 'analytics_report_explorer_hint', 'Use filters then select Show Report to view data and export CSV.')?></p>

    <form method="post" class="explorer-filters">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8')?>">
      <label class="md-field"><span><?=t($t, 'questionnaire', 'Questionnaire')?></span>
        <select name="questionnaire_id"><option value="0"><?=t($t, 'all_questionnaires', 'All questionnaires')?></option>
          <?php foreach ($questionnaires as $q): $qid = (int)($q['id'] ?? 0); ?>
            <option value="<?=$qid?>" <?=$questionnaireId === $qid ? 'selected' : ''?>><?=htmlspecialchars((string)($q['title'] ?? ''), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field"><span><?=t($t, 'role', 'Role')?></span><select name="business_role"><option value=""><?=t($t, 'all_roles', 'All roles')?></option><?php foreach ($businessRoleOptions as $value): ?><option value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=$scopeFilters['business_role'] === $value ? 'selected' : ''?>><?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
      <label class="md-field"><span><?=t($t, 'directorate', 'Directorate')?></span><select name="directorate"><option value=""><?=t($t, 'all_directorates', 'All directorates')?></option><?php foreach ($directorateOptions as $value): ?><option value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=$scopeFilters['directorate'] === $value ? 'selected' : ''?>><?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
      <label class="md-field"><span><?=t($t, 'work_function', 'Work Role')?></span><select name="work_function"><option value=""><?=t($t, 'all_work_roles', 'All work roles')?></option><?php foreach ($workFunctionOptions as $value): ?><option value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=$scopeFilters['work_function'] === $value ? 'selected' : ''?>><?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
      <label class="md-field"><span><?=t($t, 'individual', 'Individual')?></span><select name="user_id"><option value="0"><?=t($t, 'all_individuals', 'All individuals')?></option><?php foreach ($userOptions as $u): $uid = (int)($u['id'] ?? 0); ?><option value="<?=$uid?>" <?=$scopeFilters['user_id'] === $uid ? 'selected' : ''?>><?=htmlspecialchars((string)($u['display_name'] ?? ('#' . $uid)), ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
      <label class="md-field"><span><?=t($t, 'status', 'Status')?></span><select name="status"><option value=""><?=t($t, 'all_statuses', 'All statuses')?></option><?php foreach (['draft', 'submitted', 'approved', 'approved_late', 'rejected'] as $status): ?><option value="<?=$status?>" <?=$statusFilter === $status ? 'selected' : ''?>><?=htmlspecialchars($status, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
      <label class="md-field"><span><?=t($t, 'from_date', 'From date')?></span><input type="date" name="date_from" value="<?=htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8')?>"></label>
      <label class="md-field"><span><?=t($t, 'to_date', 'To date')?></span><input type="date" name="date_to" value="<?=htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8')?>"></label>
      <button class="md-button md-primary md-elev-2" type="submit"><?=t($t, 'show_report', 'Show Report')?></button>
      <a class="md-button" href="<?=htmlspecialchars($exportHref, ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'export_csv', 'Export CSV')?></a>
    </form>
  </section>

  <section class="md-card md-elev-2" style="padding:1rem;margin-top:1rem;">
    <h2 class="md-card-title"><?=t($t, 'report_results', 'Report Results')?></h2>
    <p class="md-upgrade-meta"><?=count($rows)?> <?=t($t, 'records', 'records')?> <?=t($t, 'shown', 'shown')?></p>
    <div style="overflow:auto;">
      <table class="md-table"><thead><tr><th>ID</th><th><?=t($t, 'questionnaire', 'Questionnaire')?></th><th><?=t($t, 'individual', 'Individual')?></th><th><?=t($t, 'role', 'Role')?></th><th><?=t($t, 'directorate', 'Directorate')?></th><th><?=t($t, 'department', 'Department')?></th><th><?=t($t, 'work_function', 'Work Role')?></th><th><?=t($t, 'score', 'Score')?></th><th><?=t($t, 'created_at', 'Created')?></th></tr></thead>
      <tbody><?php foreach ($rows as $row): ?><tr><td><?= (int)($row['response_id'] ?? 0) ?></td><td><?=htmlspecialchars((string)($row['questionnaire_title'] ?? ('#' . (int)($row['questionnaire_id'] ?? 0))), ENT_QUOTES, 'UTF-8')?></td><td><?=htmlspecialchars(trim((string)($row['full_name'] ?? '')) !== '' ? (string)$row['full_name'] : (string)($row['username'] ?? ''), ENT_QUOTES, 'UTF-8')?></td><td><?=htmlspecialchars((string)($row['business_role'] ?? ''), ENT_QUOTES, 'UTF-8')?></td><td><?=htmlspecialchars((string)($row['directorate'] ?? ''), ENT_QUOTES, 'UTF-8')?></td><td><?=htmlspecialchars((string)($row['department'] ?? ''), ENT_QUOTES, 'UTF-8')?></td><td><?=htmlspecialchars((string)($row['work_function'] ?? ''), ENT_QUOTES, 'UTF-8')?></td><td><?= isset($row['score']) && $row['score'] !== null ? htmlspecialchars((string)$row['score'], ENT_QUOTES, 'UTF-8') : '—' ?></td><td><?=htmlspecialchars((string)($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8')?></td></tr><?php endforeach; ?></tbody>
      </table>
    </div>
  </section>
</main>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
