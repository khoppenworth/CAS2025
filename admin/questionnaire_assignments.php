<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin','supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$workFunctionChoices = work_function_choices($pdo);

$publishedQuestionnaires = [];
try {
    $questionnaireStmt = $pdo->query("SELECT id, title, description FROM questionnaire WHERE status='published' ORDER BY title ASC");
    $publishedQuestionnaires = $questionnaireStmt ? $questionnaireStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    error_log('questionnaire_assignments questionnaire fetch failed: ' . $e->getMessage());
    $publishedQuestionnaires = [];
}

$flashMsg = $_SESSION['questionnaire_assignment_flash'] ?? '';
if ($flashMsg !== '') {
    unset($_SESSION['questionnaire_assignment_flash']);
}

try {
    $staffStmt = $pdo->query("SELECT id, username, full_name, work_function FROM users WHERE role='staff' AND account_status='active' ORDER BY full_name ASC, username ASC");
    $staffMembers = $staffStmt ? $staffStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    error_log('questionnaire_assignments staff fetch failed: ' . $e->getMessage());
    $staffMembers = [];
}

$staffById = [];
foreach ($staffMembers as $member) {
    $staffById[(int)$member['id']] = $member;
}

$selectedStaffId = (int)($_GET['staff_id'] ?? ($staffMembers[0]['id'] ?? 0));
$selectedStaffRecord = $staffById[$selectedStaffId] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $selectedStaffId = (int)($_POST['staff_id'] ?? 0);
    $selectedStaffRecord = $staffById[$selectedStaffId] ?? null;
    $assignerId = (int)(current_user()['id'] ?? 0);

    $allowedIds = array_map(static fn($row) => (int)($row['id'] ?? 0), $publishedQuestionnaires);
    $allowedSet = array_fill_keys($allowedIds, true);

    $rawIds = $_POST['questionnaire_ids'] ?? [];
    $normalizedIds = [];
    if (is_array($rawIds)) {
        foreach ($rawIds as $id) {
            $id = (int)$id;
            if ($id > 0 && isset($allowedSet[$id])) {
                $normalizedIds[$id] = $id;
            }
        }
    }
    $normalizedIds = array_values($normalizedIds);

    if ($selectedStaffRecord) {
        $pdo->beginTransaction();
        try {
            $deleteStmt = $pdo->prepare('DELETE FROM questionnaire_assignment WHERE staff_id=?');
            $deleteStmt->execute([$selectedStaffId]);

            if ($normalizedIds !== []) {
                $insertStmt = $pdo->prepare('INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by) VALUES (?, ?, ?)');
                foreach ($normalizedIds as $qid) {
                    $insertStmt->execute([$selectedStaffId, $qid, $assignerId ?: null]);
                }
            }

            $pdo->commit();
            $_SESSION['questionnaire_assignment_flash'] = t($t, 'assignment_saved', 'Questionnaire assignments updated.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('questionnaire_assignments save failed: ' . $e->getMessage());
            $_SESSION['questionnaire_assignment_flash'] = t($t, 'assignment_save_failed', 'Assignments could not be saved. Please try again.');
        }
    }

    header('Location: ' . url_for('admin/questionnaire_assignments.php?staff_id=' . $selectedStaffId));
    exit;
}

$assignmentsByWorkFunction = [];
try {
    $assignmentStmt = $pdo->query("SELECT qwf.work_function, q.id, q.title, q.description FROM questionnaire_work_function qwf JOIN questionnaire q ON q.id = qwf.questionnaire_id WHERE q.status='published' ORDER BY qwf.work_function ASC, q.title ASC");
    if ($assignmentStmt) {
        foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $wf = trim((string)($row['work_function'] ?? ''));
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

$selectedAssignments = [];
$selectedWorkFunction = '';
$directAssignments = [];
$directAssignmentIds = [];
if ($selectedStaffRecord) {
    $selectedWorkFunction = trim((string)($selectedStaffRecord['work_function'] ?? ''));
    if ($selectedWorkFunction !== '') {
        $selectedAssignments = $assignmentsByWorkFunction[$selectedWorkFunction] ?? [];
    }

    try {
        $directStmt = $pdo->prepare(
            'SELECT qa.questionnaire_id, q.title, q.description ' .
            'FROM questionnaire_assignment qa ' .
            'JOIN questionnaire q ON q.id = qa.questionnaire_id ' .
            'WHERE qa.staff_id=? AND q.status="published" ' .
            'ORDER BY q.title ASC'
        );
        $directStmt->execute([(int)$selectedStaffId]);
        $directAssignments = $directStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $directAssignmentIds = array_map(static fn($row) => (int)($row['questionnaire_id'] ?? 0), $directAssignments);
    } catch (PDOException $e) {
        error_log('questionnaire_assignments direct fetch failed: ' . $e->getMessage());
        $directAssignments = [];
        $directAssignmentIds = [];
    }
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
    .md-assignment-summary {
      margin: 1.5rem 0;
      padding: 1rem;
      border-radius: 8px;
      border: 1px solid var(--app-border, #d0d5dd);
      background: var(--app-surface, #ffffff);
    }
    .md-assignment-summary h3 {
      margin-top: 0;
      margin-bottom: 0.35rem;
    }
    .md-assignment-summary ul {
      margin: 0.5rem 0 0;
      padding-left: 1.1rem;
      columns: 2;
      column-gap: 1.25rem;
      list-style: disc;
    }
    .md-assignment-summary p {
      margin: 0.35rem 0;
    }
    @media (max-width: 720px) {
      .md-assignment-summary ul {
        columns: 1;
      }
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
    .md-assignment-form {
      margin: 1.25rem 0 0.25rem;
      padding: 1rem;
      border: 1px solid var(--app-border, #d0d5dd);
      border-radius: 8px;
      background: var(--app-surface, #ffffff);
    }
    .md-assignment-form h4 {
      margin: 0 0 0.5rem;
    }
    .md-assignment-form p {
      margin: 0.35rem 0 0.75rem;
      color: var(--app-muted, #6b7280);
    }
    .md-assignment-form .md-field {
      display: block;
      margin: 0.75rem 0;
    }
    .md-assignment-form select[multiple] {
      width: 100%;
      min-height: 12rem;
      padding: 0.75rem;
    }
    .md-assignment-form-actions {
      display: flex;
      gap: 0.75rem;
      align-items: center;
    }
    .md-assignment-flash {
      margin: 0 0 1rem;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      background: rgba(16, 185, 129, 0.12);
      border: 1px solid rgba(16, 185, 129, 0.4);
      color: #065f46;
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'assign_questionnaires','Assign Questionnaires')?></h2>
    <?php if ($flashMsg !== ''): ?>
      <div class="md-assignment-flash" role="status"><?=htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8')?></div>
    <?php endif; ?>
    <div class="md-assignment-intro">
      <p><?=t($t,'assignment_work_function_only','Questionnaires are assigned automatically based on work function. Use direct assignments to tailor the list for individual staff without changing the defaults.')?></p>
    </div>
    <?php if (!$staffMembers): ?>
      <p><?=t($t,'no_active_staff','No active staff records available.')?></p>
    <?php else: ?>
      <form method="get" class="md-inline-form md-assignment-select" action="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>">
        <label for="staff_id"><?=t($t,'select_staff_member','Select staff member')?>:</label>
        <select name="staff_id" id="staff_id" onchange="this.form.submit()">
          <?php foreach ($staffMembers as $staff): ?>
            <?php
              $name = trim((string)($staff['full_name'] ?? ''));
              if ($name === '') {
                  $name = (string)($staff['username'] ?? '');
              }
            ?>
            <option value="<?=$staff['id']?>" <?=$selectedStaffId === (int)$staff['id'] ? 'selected' : ''?>><?=htmlspecialchars($name, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($selectedStaffRecord): ?>
        <?php
          $displayName = trim((string)($selectedStaffRecord['full_name'] ?? ''));
          if ($displayName === '') {
              $displayName = (string)($selectedStaffRecord['username'] ?? '');
          }
          $workFunctionKey = $selectedWorkFunction;
          $workFunctionLabel = $workFunctionKey !== '' ? ($workFunctionChoices[$workFunctionKey] ?? $workFunctionKey) : '';
        ?>
        <div class="md-assignment-summary">
          <h3><?=htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8')?></h3>
          <?php if ($workFunctionKey === ''): ?>
            <p><?=t($t,'assignment_missing_work_function','This staff member does not have a work function assigned yet. Assign a work function to provide questionnaires automatically.')?></p>
          <?php else: ?>
            <p><strong><?=t($t,'current_work_function','Current work function:')?></strong> <?=htmlspecialchars($workFunctionLabel, ENT_QUOTES, 'UTF-8')?></p>
            <?php if ($selectedAssignments): ?>
              <p><?=t($t,'assignment_current_defaults','The following questionnaires are currently provided based on this work function:')?></p>
              <ul>
                <?php foreach ($selectedAssignments as $assignment): ?>
                  <?php
                    $title = $assignment['title'] !== '' ? $assignment['title'] : t($t,'questionnaire','Questionnaire');
                    $description = $assignment['description'];
                  ?>
                  <li>
                    <?=htmlspecialchars($title, ENT_QUOTES, 'UTF-8')?><?php if ($description !== ''): ?> — <?=htmlspecialchars($description, ENT_QUOTES, 'UTF-8')?><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p><?=t($t,'assignment_no_defaults_for_function','No questionnaires are assigned to this work function yet.')?></p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="md-assignment-form" id="direct-assignments">
          <h4><?=t($t,'direct_assignments','Direct assignments')?></h4>
          <p><?=t($t,'direct_assignments_hint','Add or remove questionnaires for this staff member without changing the work function defaults.')?></p>
          <form method="post" action="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="staff_id" value="<?=$selectedStaffId?>">
            <label class="md-field"><span><?=t($t,'select_questionnaires','Select questionnaires')?></span>
              <select name="questionnaire_ids[]" multiple aria-describedby="direct-assignment-help">
                <?php foreach ($publishedQuestionnaires as $questionnaire): ?>
                  <?php
                    $qid = (int)($questionnaire['id'] ?? 0);
                    $title = trim((string)($questionnaire['title'] ?? ''));
                    $desc = trim((string)($questionnaire['description'] ?? ''));
                    $displayTitle = $title !== '' ? $title : t($t,'questionnaire','Questionnaire');
                  ?>
                  <option value="<?=$qid?>" <?=$qid && in_array($qid, $directAssignmentIds, true) ? 'selected' : ''?>>
                    <?=htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8')?><?php if ($desc !== ''): ?> — <?=htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')?><?php endif; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <p class="md-muted" id="direct-assignment-help"><?=t($t,'direct_assignment_help','Hold Ctrl (Windows) or Command (Mac) to select multiple questionnaires.')?></p>
            <?php if ($directAssignments): ?>
              <p><strong><?=t($t,'currently_assigned','Currently assigned questionnaires:')?></strong></p>
              <ul>
                <?php foreach ($directAssignments as $assignment): ?>
                  <?php
                    $title = trim((string)($assignment['title'] ?? ''));
                    $desc = trim((string)($assignment['description'] ?? ''));
                    $displayTitle = $title !== '' ? $title : t($t,'questionnaire','Questionnaire');
                  ?>
                  <li><?=htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8')?><?php if ($desc !== ''): ?> — <?=htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')?><?php endif; ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="md-work-function-empty"><?=t($t,'assignment_defaults_hint','These questionnaires are automatically available because of the staff member\'s work function. They cannot be removed here.')?></p>
            <?php endif; ?>
            <div class="md-assignment-form-actions">
              <button type="submit" name="save_assignments" class="md-button md-primary md-elev-2"><?=t($t,'save','Save')?></button>
              <span class="md-muted"><?=t($t,'direct_assignment_note','Direct assignments supplement the work function defaults and can be changed at any time.')?></span>
            </div>
          </form>
        </div>
      <?php endif; ?>
    <?php endif; ?>

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
