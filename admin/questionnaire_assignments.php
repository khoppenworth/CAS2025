<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/work_functions.php';
auth_required(['admin','supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$workFunctionChoices = work_function_choices($pdo);

$assignmentsByWorkFunction = [];
try {
    $assignmentStmt = $pdo->query("SELECT qwf.work_function, q.id, q.title, q.description FROM questionnaire_work_function qwf JOIN questionnaire q ON q.id = qwf.questionnaire_id WHERE q.status='published' ORDER BY qwf.work_function ASC, q.title ASC");
    if ($assignmentStmt) {
        foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $wf = canonical_work_function_key((string)($row['work_function'] ?? ''));
            if ($wf === '') {
                continue;
            }
            $assignmentsByWorkFunction[$wf][] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => trim((string)($row['title'] ?? '')),
                'description' => trim((string)($row['description'] ?? '')),
            ];
        }
    }
} catch (PDOException $e) {
    error_log('questionnaire_assignments default fetch failed: ' . $e->getMessage());
    $assignmentsByWorkFunction = [];
}

?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'assign_questionnaires','Assign Questionnaires'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style>
    .md-assignment-intro {
      margin-bottom: 1rem;
      padding: 0.85rem 1rem;
      border-radius: 8px;
      border: 1px solid rgba(37, 99, 235, 0.35);
      background: rgba(37, 99, 235, 0.08);
      color: var(--app-text-primary, #1f2937);
    }
    .md-work-function-list {
      margin-top: 2rem;
    }
    .md-work-function-list table {
      width: 100%;
      border-collapse: collapse;
    }
    .md-work-function-list th,
    .md-work-function-list td {
      padding: 0.65rem 0.75rem;
      text-align: left;
      border-bottom: 1px solid var(--app-border, #d0d5dd);
      vertical-align: top;
    }
    .md-work-function-list th {
      font-weight: 600;
      background: var(--app-surface-alt, rgba(229, 231, 235, 0.45));
    }
    .md-work-function-empty {
      margin: 0;
      color: var(--app-muted, #6b7280);
      font-style: italic;
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'assign_questionnaires','Assign Questionnaires')?></h2>
    <div class="md-assignment-intro">
      <p><?=t($t,'assignment_work_function_only','Assign questionnaires by work function. Update the defaults to control which questionnaires each team receives.')?></p>
      <p>
        <a href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(t($t,'work_function_defaults_title','Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></a>
        ·
        <a href="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(t($t,'manage_users','Manage users'), ENT_QUOTES, 'UTF-8')?></a>
      </p>
    </div>

    <div class="md-work-function-list">
      <h3><?=t($t,'assignment_overview','Work function overview')?></h3>
      <?php if (!$workFunctionChoices): ?>
        <p class="md-work-function-empty"><?=t($t,'work_function_defaults_none','No work functions are available yet. Staff members can continue to receive questionnaires assigned directly to them.')?></p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th><?=t($t,'work_function','Work Function / Cadre')?></th>
              <th><?=t($t,'questionnaires','Questionnaires')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($workFunctionChoices as $wfKey => $wfLabel): ?>
              <?php $items = $assignmentsByWorkFunction[$wfKey] ?? []; ?>
              <tr>
                <td><?=htmlspecialchars($wfLabel ?? $wfKey, ENT_QUOTES, 'UTF-8')?></td>
                <td>
                  <?php if (!$items): ?>
                    <span class="md-work-function-empty"><?=t($t,'assignment_no_defaults_for_function','No questionnaires are assigned to this work function yet.')?></span>
                  <?php else: ?>
                    <ul>
                      <?php foreach ($items as $item): ?>
                        <?php
                          $title = $item['title'] !== '' ? $item['title'] : t($t,'questionnaire','Questionnaire');
                          $description = $item['description'];
                        ?>
                        <li><?=htmlspecialchars($title, ENT_QUOTES, 'UTF-8')?><?php if ($description !== ''): ?> — <?=htmlspecialchars($description, ENT_QUOTES, 'UTF-8')?><?php endif; ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
