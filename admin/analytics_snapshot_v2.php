<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_snapshot_v2.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);

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
            $snapshot = analytics_snapshot_v2_generate($pdo, $qid, $generatedBy);
            $_SESSION['analytics_snapshot_v2_flash'] = [
                'ok' => t($t, 'analytics_snapshot_v2_generated', 'Snapshot generated successfully.')
                    . (!empty($snapshot['snapshot_id']) ? ' #' . (int)$snapshot['snapshot_id'] : ''),
                'error' => '',
            ];
        } elseif ($action === 'finalize') {
            $snapshotId = isset($_POST['snapshot_id']) ? (int)$_POST['snapshot_id'] : 0;
            if ($snapshotId <= 0 || !analytics_snapshot_v2_finalize($pdo, $snapshotId)) {
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

$snapshots = [];
if (analytics_snapshot_v2_table_exists($pdo, 'analytics_report_snapshot_v2')) {
    $sql = 'SELECT id, questionnaire_id, generated_by, status, locked, summary_json, generated_at, finalized_at '
        . 'FROM analytics_report_snapshot_v2 ';
    $params = [];
    if ($questionnaireId > 0) {
        $sql .= 'WHERE questionnaire_id = ? ';
        $params[] = $questionnaireId;
    }
    $sql .= 'ORDER BY generated_at DESC, id DESC LIMIT 50';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$questionnaireMap = [];
foreach ($questionnaires as $q) {
    $questionnaireMap[(int)($q['id'] ?? 0)] = (string)($q['title'] ?? '');
}

$csvHref = url_for('admin/analytics_snapshot_v2_export.php');
if ($questionnaireId > 0) {
    $csvHref .= '?questionnaire_id=' . $questionnaireId;
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
              <th><?=t($t, 'actions', 'Actions')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($snapshots as $row): ?>
              <?php
                $summary = json_decode((string)($row['summary_json'] ?? '{}'), true);
                $avg = isset($summary['average_score']) ? round((float)$summary['average_score'], 1) : null;
                $level = (string)($summary['competency_level'] ?? '—');
                $qid = (int)($row['questionnaire_id'] ?? 0);
              ?>
              <tr>
                <td><?= (int)($row['id'] ?? 0) ?></td>
                <td><?=htmlspecialchars($questionnaireMap[$qid] ?? ($qid > 0 ? '#' . $qid : t($t, 'all_questionnaires', 'All questionnaires')), ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars((string)($row['status'] ?? 'draft'), ENT_QUOTES, 'UTF-8')?><?= !empty($row['locked']) ? ' · ' . t($t, 'locked', 'Locked') : '' ?></td>
                <td><?= $avg === null ? '—' : htmlspecialchars((string)$avg, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?=htmlspecialchars($level !== '' ? $level : '—', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars((string)($row['generated_at'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
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
