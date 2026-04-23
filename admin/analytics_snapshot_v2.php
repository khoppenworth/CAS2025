<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_snapshot_v2.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$viewer = current_user();
$viewerRole = (string)($viewer['role'] ?? ($_SESSION['user']['role'] ?? ''));
$viewerId = (int)($viewer['id'] ?? ($_SESSION['user']['id'] ?? 0));

$locale = ensure_locale();
$t = load_lang($locale);
$drawerKey = 'team.analytics';
$pageTitle = t($t, 'analytics_snapshot_v2_title', 'Analytics Snapshot v2');

$flash = ['ok' => '', 'error' => ''];
if (isset($_SESSION['analytics_snapshot_v2_flash']) && is_array($_SESSION['analytics_snapshot_v2_flash'])) {
    $flash = array_merge($flash, $_SESSION['analytics_snapshot_v2_flash']);
    unset($_SESSION['analytics_snapshot_v2_flash']);
}

$questionnaireId = isset($_GET['questionnaire_id']) ? max(0, (int)$_GET['questionnaire_id']) : 0;
$requestedFilters = analytics_snapshot_v2_normalize_filters([
    'business_role' => $_GET['business_role'] ?? '',
    'directorate' => $_GET['directorate'] ?? '',
    'work_function' => $_GET['work_function'] ?? '',
    'user_id' => $_GET['user_id'] ?? 0,
]);
$effectiveFilters = analytics_snapshot_v2_apply_viewer_scope($viewer, $requestedFilters);
$selectedBusinessRole = $effectiveFilters['business_role'];
$selectedDirectorate = $effectiveFilters['directorate'];
$selectedWorkFunction = $effectiveFilters['work_function'];
$selectedUserId = $effectiveFilters['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('invalid csrf');
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'generate') {
            $qid = isset($_POST['questionnaire_id']) ? (int)$_POST['questionnaire_id'] : 0;
            $qid = $qid > 0 ? $qid : null;
            $generatedBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
            $requestedPostFilters = [
                'business_role' => trim((string)($_POST['business_role'] ?? '')),
                'directorate' => trim((string)($_POST['directorate'] ?? '')),
                'work_function' => trim((string)($_POST['work_function'] ?? '')),
                'user_id' => max(0, (int)($_POST['user_id'] ?? 0)),
            ];
            $filters = analytics_snapshot_v2_apply_viewer_scope($viewer, $requestedPostFilters);
            $snapshot = analytics_snapshot_v2_generate($pdo, $qid, $generatedBy, $filters);
            $_SESSION['analytics_snapshot_v2_flash'] = [
                'ok' => t($t, 'analytics_snapshot_v2_generated', 'Snapshot generated successfully.')
                    . (!empty($snapshot['snapshot_id']) ? ' #' . (int)$snapshot['snapshot_id'] : ''),
                'error' => '',
            ];
        } elseif ($action === 'finalize') {
            $snapshotId = isset($_POST['snapshot_id']) ? (int)$_POST['snapshot_id'] : 0;
            if ($snapshotId <= 0 || !analytics_snapshot_v2_finalize_for_viewer($pdo, $snapshotId, $viewer)) {
                throw new RuntimeException(t($t, 'analytics_snapshot_v2_finalize_failed', 'Unable to finalize snapshot.'));
            }
            $_SESSION['analytics_snapshot_v2_flash'] = [
                'ok' => t($t, 'analytics_snapshot_v2_finalized', 'Snapshot finalized and locked.'),
                'error' => '',
            ];
        } else {
            throw new RuntimeException(t($t, 'invalid_request', 'Invalid request.'));
        }
    } catch (Throwable $e) {
        $_SESSION['analytics_snapshot_v2_flash'] = [
            'ok' => '',
            'error' => $e->getMessage(),
        ];
    }

    header('Location: ' . url_for('admin/analytics_snapshot_v2.php'));
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

$roleStmt = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(business_role, ''), NULLIF(profile_role, ''), 'Unspecified') AS role_label FROM users ORDER BY role_label ASC");
if ($roleStmt) {
    foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)($row['role_label'] ?? ''));
        if ($value !== '') {
            $businessRoleOptions[] = $value;
        }
    }
}
$directorateStmt = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(directorate, ''), 'Unknown') AS directorate_label FROM users ORDER BY directorate_label ASC");
if ($directorateStmt) {
    foreach ($directorateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)($row['directorate_label'] ?? ''));
        if ($value !== '') {
            $directorateOptions[] = $value;
        }
    }
}
$workFunctionStmt = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(work_function, ''), 'Unspecified') AS wf_label FROM users ORDER BY wf_label ASC");
if ($workFunctionStmt) {
    foreach ($workFunctionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = trim((string)($row['wf_label'] ?? ''));
        if ($value !== '') {
            $workFunctionOptions[] = $value;
        }
    }
}
$userStmt = $pdo->query("SELECT id, COALESCE(NULLIF(full_name,''), username) AS display_name FROM users ORDER BY display_name ASC LIMIT 500");
if ($userStmt) {
    $userOptions = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
if ($viewerRole === 'supervisor' && $selectedDirectorate !== '') {
    $directorateOptions = [$selectedDirectorate];
}

$snapshots = [];
if (analytics_snapshot_v2_table_exists($pdo, 'analytics_report_snapshot_v2')) {
    $sql = 'SELECT id, questionnaire_id, generated_by, status, locked, filters_json, summary_json, generated_at, finalized_at '
        . 'FROM analytics_report_snapshot_v2 ';
    $params = [];
    if ($questionnaireId > 0) {
        $sql .= 'WHERE questionnaire_id = ? ';
        $params[] = $questionnaireId;
    }
    $sql .= 'ORDER BY generated_at DESC, id DESC LIMIT 50';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $snapshotRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($snapshotRows as $row) {
        if ($viewerRole !== 'supervisor') {
            $snapshots[] = $row;
            continue;
        }
        if (analytics_snapshot_v2_viewer_can_access_snapshot($viewer, $row)) {
            $snapshots[] = $row;
        }
    }
}

$questionnaireMap = [];
foreach ($questionnaires as $q) {
    $questionnaireMap[(int)($q['id'] ?? 0)] = (string)($q['title'] ?? '');
}

$csvHref = url_for('admin/analytics_snapshot_v2_export.php');
$csvParams = [];
if ($questionnaireId > 0) {
    $csvParams[] = 'questionnaire_id=' . $questionnaireId;
}
if ($selectedBusinessRole !== '') {
    $csvParams[] = 'business_role=' . rawurlencode($selectedBusinessRole);
}
if ($selectedDirectorate !== '') {
    $csvParams[] = 'directorate=' . rawurlencode($selectedDirectorate);
}
if ($selectedWorkFunction !== '') {
    $csvParams[] = 'work_function=' . rawurlencode($selectedWorkFunction);
}
if ($selectedUserId > 0) {
    $csvParams[] = 'user_id=' . $selectedUserId;
}
if ($csvParams) {
    $csvHref .= '?' . implode('&', $csvParams);
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=htmlspecialchars($pageTitle)?></title>
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
</head>
<body class="md-app-shell">
<?php require_once __DIR__ . '/../templates/header.php'; ?>
<main class="md-content">
  <section class="md-card md-elev-2" style="padding:1rem;">
    <h1 class="md-card-title"><?=htmlspecialchars($pageTitle)?></h1>
    <p class="md-upgrade-meta"><?=t($t, 'analytics_snapshot_v2_subtitle', 'Generate and lock role-based analytics snapshots.')?></p>

    <?php if ($flash['ok'] !== ''): ?>
      <p style="color:#156d2f;font-weight:600;"><?=htmlspecialchars($flash['ok'], ENT_QUOTES, 'UTF-8')?></p>
    <?php endif; ?>
    <?php if ($flash['error'] !== ''): ?>
      <p style="color:#b42318;font-weight:600;"><?=htmlspecialchars($flash['error'], ENT_QUOTES, 'UTF-8')?></p>
    <?php endif; ?>

    <form method="post" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="action" value="generate">
      <label class="md-field">
        <span><?=t($t, 'questionnaire', 'Questionnaire')?></span>
        <select name="questionnaire_id">
          <option value="0"><?=t($t, 'all_questionnaires', 'All questionnaires')?></option>
          <?php foreach ($questionnaires as $q): ?>
            <?php $qid = (int)($q['id'] ?? 0); ?>
            <option value="<?=$qid?>" <?=$questionnaireId === $qid ? 'selected' : ''?>><?=htmlspecialchars((string)($q['title'] ?? ''), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t, 'role', 'Role')?></span>
        <select name="business_role">
          <option value=""><?=t($t, 'all_roles', 'All roles')?></option>
          <?php foreach ($businessRoleOptions as $value): ?>
            <option value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=$selectedBusinessRole === $value ? 'selected' : ''?>><?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t, 'directorate', 'Directorate')?></span>
        <select name="directorate">
          <option value=""><?=t($t, 'all_directorates', 'All directorates')?></option>
          <?php foreach ($directorateOptions as $value): ?>
            <option value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=$selectedDirectorate === $value ? 'selected' : ''?>><?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t, 'work_function', 'Work Role')?></span>
        <select name="work_function">
          <option value=""><?=t($t, 'all_work_roles', 'All work roles')?></option>
          <?php foreach ($workFunctionOptions as $value): ?>
            <option value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=$selectedWorkFunction === $value ? 'selected' : ''?>><?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t, 'individual', 'Individual')?></span>
        <select name="user_id">
          <option value="0"><?=t($t, 'all_individuals', 'All individuals')?></option>
          <?php foreach ($userOptions as $u): ?>
            <?php $uid = (int)($u['id'] ?? 0); ?>
            <option value="<?=$uid?>" <?=$selectedUserId === $uid ? 'selected' : ''?>><?=htmlspecialchars((string)($u['display_name'] ?? ('#' . $uid)), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="md-button md-primary md-elev-2"><?=t($t, 'generate_snapshot', 'Generate snapshot')?></button>
      <a class="md-button" href="<?=htmlspecialchars($csvHref, ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'export_csv', 'Export CSV')?></a>
    </form>
  </section>

  <section class="md-card md-elev-2" style="padding:1rem;margin-top:1rem;">
    <h2 class="md-card-title"><?=t($t, 'recent_snapshots', 'Recent snapshots')?></h2>
    <?php if (!$snapshots): ?>
      <p><?=t($t, 'no_snapshot_data', 'No snapshots available yet.')?></p>
    <?php else: ?>
      <div style="overflow:auto;">
        <table class="md-table">
          <thead>
            <tr>
              <th>ID</th>
              <th><?=t($t, 'questionnaire', 'Questionnaire')?></th>
              <th><?=t($t, 'status', 'Status')?></th>
              <th><?=t($t, 'average_score', 'Average score (%)')?></th>
              <th><?=t($t, 'competency_level', 'Competency level')?></th>
              <th><?=t($t, 'generated_at', 'Generated at')?></th>
              <th><?=t($t, 'filters', 'Filters')?></th>
              <th><?=t($t, 'actions', 'Actions')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($snapshots as $row): ?>
              <?php
                $summary = json_decode((string)($row['summary_json'] ?? '{}'), true);
                $filters = json_decode((string)($row['filters_json'] ?? '{}'), true);
                $avg = isset($summary['average_score']) ? round((float)$summary['average_score'], 1) : null;
                $level = (string)($summary['competency_level'] ?? '—');
                $qid = (int)($row['questionnaire_id'] ?? 0);
                $filterParts = [];
                if (!empty($filters['business_role'])) { $filterParts[] = 'Role: ' . (string)$filters['business_role']; }
                if (!empty($filters['directorate'])) { $filterParts[] = 'Directorate: ' . (string)$filters['directorate']; }
                if (!empty($filters['work_function'])) { $filterParts[] = 'Work role: ' . (string)$filters['work_function']; }
                if (!empty($filters['user_id'])) { $filterParts[] = 'User ID: ' . (int)$filters['user_id']; }
              ?>
              <tr>
                <td><?= (int)($row['id'] ?? 0) ?></td>
                <td><?=htmlspecialchars($questionnaireMap[$qid] ?? ($qid > 0 ? '#' . $qid : t($t, 'all_questionnaires', 'All questionnaires')), ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars((string)($row['status'] ?? 'draft'), ENT_QUOTES, 'UTF-8')?><?= !empty($row['locked']) ? ' · ' . t($t, 'locked', 'Locked') : '' ?></td>
                <td><?= $avg === null ? '—' : htmlspecialchars((string)$avg, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?=htmlspecialchars($level !== '' ? $level : '—', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars((string)($row['generated_at'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($filterParts ? implode(' · ', $filterParts) : t($t, 'all', 'All'), ENT_QUOTES, 'UTF-8')?></td>
                <td>
                  <?php if (empty($row['locked'])): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8')?>">
                      <input type="hidden" name="action" value="finalize">
                      <input type="hidden" name="snapshot_id" value="<?= (int)($row['id'] ?? 0) ?>">
                      <button class="md-button" type="submit"><?=t($t, 'finalize', 'Finalize')?></button>
                    </form>
                  <?php else: ?>
                    <span><?=t($t, 'locked', 'Locked')?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php require_once __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
