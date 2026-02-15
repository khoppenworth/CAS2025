<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('resolve_department_slug')) {
    require_once __DIR__ . '/../lib/department_teams.php';
}

auth_required(['admin','supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$flashKey = 'questionnaire_assignments_flash';
$msg = $_SESSION[$flashKey] ?? '';
unset($_SESSION[$flashKey]);
$errors = [];

$departmentChoices = department_options($pdo);
$questionnaires = [];
$questionnaireIds = [];

try {
    $stmt = $pdo->query("SELECT id, title, description FROM questionnaire WHERE status='published' ORDER BY title ASC");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $questionnaires[] = $row;
            $questionnaireIds[$id] = true;
        }
    }
} catch (PDOException $e) {
    error_log('questionnaire_assignments questionnaire fetch failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignments'])) {
    csrf_check();
    $submitted = $_POST['assignments'] ?? [];
    if (!is_array($submitted)) {
        $submitted = [];
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM questionnaire_department');
        $insert = $pdo->prepare('INSERT INTO questionnaire_department (questionnaire_id, department_slug) VALUES (?, ?)');
        foreach ($submitted as $depSlug => $qidList) {
            if (!isset($departmentChoices[$depSlug]) || !is_array($qidList)) {
                continue;
            }
            foreach ($qidList as $qidRaw) {
                $qid = (int)$qidRaw;
                if ($qid <= 0 || !isset($questionnaireIds[$qid])) {
                    continue;
                }
                $insert->execute([$qid, $depSlug]);
            }
        }
        $pdo->commit();
        $_SESSION[$flashKey] = t($t,'work_function_defaults_saved','Default questionnaire assignments updated.');
        header('Location: ' . url_for('admin/questionnaire_assignments.php'));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('questionnaire_assignments save failed: ' . $e->getMessage());
        $errors[] = t($t,'work_function_defaults_save_failed','Unable to save defaults. Please try again.');
    }
}

$assignments = [];
try {
    $assignStmt = $pdo->query('SELECT questionnaire_id, department_slug FROM questionnaire_department');
    if ($assignStmt) {
        while ($row = $assignStmt->fetch(PDO::FETCH_ASSOC)) {
            $dep = trim((string)($row['department_slug'] ?? ''));
            $qid = (int)($row['questionnaire_id'] ?? 0);
            if ($dep === '' || $qid <= 0) {
                continue;
            }
            $assignments[$dep][$qid] = true;
        }
    }
} catch (PDOException $e) {
    error_log('questionnaire_assignments assignment fetch failed: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'assign_questionnaires','Assign Questionnaires'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'assign_questionnaires','Assign Questionnaires')?></h2>
    <p class="md-hint"><?=t($t,'assignment_work_function_only','Questionnaire defaults are configured at department level.')?></p>
    <p><a href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(t($t,'work_function_defaults_title','Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></a></p>

    <?php if ($msg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($errors): ?><div class="md-alert error"><?php foreach ($errors as $err): ?><p><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></p><?php endforeach; ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <?php foreach ($departmentChoices as $depSlug => $depLabel): ?>
        <fieldset style="margin-bottom:1rem">
          <legend><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></legend>
          <?php foreach ($questionnaires as $q): $qid=(int)$q['id']; ?>
            <label>
              <input type="checkbox" name="assignments[<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>][]" value="<?=$qid?>" <?=isset($assignments[$depSlug][$qid])?'checked':''?>>
              <?=htmlspecialchars((string)($q['title'] ?: t($t,'untitled_questionnaire','Untitled questionnaire')), ENT_QUOTES, 'UTF-8')?>
            </label><br>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>
      <button name="save_assignments" class="md-button md-primary"><?=t($t,'save','Save Changes')?></button>
    </form>
  </div>
</section>
</body>
</html>
