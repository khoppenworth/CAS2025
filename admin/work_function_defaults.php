<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$flashKey = 'department_defaults_flash';
$metadataFlashKey = 'metadata_catalog_flash';
$msg = $_SESSION[$flashKey] ?? '';
unset($_SESSION[$flashKey]);
$metadataMsg = $_SESSION[$metadataFlashKey] ?? '';
unset($_SESSION[$metadataFlashKey]);
$errors = [];
$metadataErrors = [];

$questionnaires = [];
$questionnaireIds = [];
$stmt = $pdo->query("SELECT id, title, description FROM questionnaire WHERE status='published' ORDER BY title ASC");
if ($stmt) {
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $questionnaires[] = $row;
        $questionnaireIds[$id] = true;
    }
}

$departments = department_catalog($pdo);
$departmentOptions = department_options($pdo);
$teams = department_team_catalog($pdo);
$workRoles = work_function_catalog($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = (string)($_POST['mode'] ?? '');
    try {
        if ($mode === 'department_add') {
            $label = trim((string)($_POST['label'] ?? ''));
            $slug = canonical_department_slug($label);
            if ($label === '' || $slug === '') {
                throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            }
            $exists = isset($departments[$slug]);
            if ($exists) {
                throw new InvalidArgumentException(t($t,'department_exists','Department already exists.'));
            }
            $sort = count($departments) + 1;
            $pdo->prepare('INSERT INTO department_catalog (slug,label,sort_order) VALUES (?,?,?)')->execute([$slug,$label,$sort]);
            $_SESSION[$metadataFlashKey] = t($t,'department_created','Department added.');
            header('Location: ' . url_for('admin/work_function_defaults.php')); exit;
        }
        if ($mode === 'department_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($slug === '' || $label === '') throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            $pdo->prepare('UPDATE department_catalog SET label=? WHERE slug=?')->execute([$label,$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'department_updated','Department updated.');
            header('Location: ' . url_for('admin/work_function_defaults.php')); exit;
        }
        if ($mode === 'department_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE department_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug=?')->execute([$slug]);
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE department_slug=?')->execute([$slug]);
            $pdo->prepare('UPDATE users SET department = NULL, cadre = NULL WHERE department = ?')->execute([$slug]);
            $pdo->prepare('DELETE FROM questionnaire_department WHERE department_slug = ?')->execute([$slug]);
            $pdo->commit();
            $_SESSION[$metadataFlashKey] = t($t,'department_archived','Department archived.');
            header('Location: ' . url_for('admin/work_function_defaults.php')); exit;
        }
        if ($mode === 'team_add') {
            $label = trim((string)($_POST['label'] ?? ''));
            $departmentSlug = trim((string)($_POST['department_slug'] ?? ''));
            if ($label === '' || !isset($departmentOptions[$departmentSlug])) {
                throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the department.'));
            }
            $slug = canonical_department_team_slug($label);
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the department.'));
            if (isset($teams[$slug])) throw new InvalidArgumentException(t($t,'team_catalog_duplicate','That team already exists.'));
            $sort = count($teams) + 1;
            $pdo->prepare('INSERT INTO department_team_catalog (slug,department_slug,label,sort_order) VALUES (?,?,?,?)')->execute([$slug,$departmentSlug,$label,$sort]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_created','Team added.');
            header('Location: ' . url_for('admin/work_function_defaults.php')); exit;
        }
        if ($mode === 'team_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $departmentSlug = trim((string)($_POST['department_slug'] ?? ''));
            if ($slug === '' || $label === '' || !isset($departmentOptions[$departmentSlug])) throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the department.'));
            $pdo->prepare('UPDATE department_team_catalog SET label=?, department_slug=? WHERE slug=?')->execute([$label,$departmentSlug,$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_updated','Team updated.');
            header('Location: ' . url_for('admin/work_function_defaults.php')); exit;
        }
        if ($mode === 'team_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'team_catalog_missing','Team does not exist.'));
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug=?')->execute([$slug]);
            $pdo->prepare('UPDATE users SET cadre = NULL WHERE cadre = ?')->execute([$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_archived','Team archived.');
            header('Location: ' . url_for('admin/work_function_defaults.php')); exit;
        }
        if ($mode === 'role_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($slug === '' || $label === '') throw new InvalidArgumentException(t($t,'invalid_work_function','Select a valid work function.'));
            $pdo->prepare('UPDATE work_function_catalog SET label=? WHERE slug=?')->execute([$label,$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'work_function_catalog_updated','Work function updated.');
            header('Location: ' . url_for('admin/work_function_defaults.php')); exit;
        }

        if ($mode === 'assignments_save') {
            $input = $_POST['assignments'] ?? [];
            if (!is_array($input)) {
                throw new InvalidArgumentException(t($t,'work_function_defaults_invalid_payload','The selections could not be processed.'));
            }
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM questionnaire_department');
            $insert = $pdo->prepare('INSERT INTO questionnaire_department (questionnaire_id, department_slug) VALUES (?, ?)');
            foreach ($input as $depSlug => $qidList) {
                $depSlug = trim((string)$depSlug);
                if (!isset($departmentOptions[$depSlug]) || !is_array($qidList)) continue;
                $seen = [];
                foreach ($qidList as $qidRaw) {
                    $qid = (int)$qidRaw;
                    if ($qid <= 0 || !isset($questionnaireIds[$qid]) || isset($seen[$qid])) continue;
                    $insert->execute([$qid, $depSlug]);
                    $seen[$qid] = true;
                }
            }
            $pdo->commit();
            $_SESSION[$flashKey] = t($t,'work_function_defaults_saved','Default questionnaire assignments updated.');
            header('Location: ' . url_for('admin/work_function_defaults.php')); exit;
        }
    } catch (InvalidArgumentException $e) {
        $metadataErrors[] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $metadataErrors[] = $e->getMessage();
    }
}

$assignments = [];
$assignStmt = $pdo->query('SELECT questionnaire_id, department_slug FROM questionnaire_department');
if ($assignStmt) {
    foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dep = trim((string)($row['department_slug'] ?? ''));
        $qid = (int)($row['questionnaire_id'] ?? 0);
        if ($dep !== '' && $qid > 0) {
            $assignments[$dep][$qid] = true;
        }
    }
}
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></h2>
    <?php if ($metadataMsg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($metadataMsg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($msg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($metadataErrors): ?><div class="md-alert error"><?php foreach ($metadataErrors as $err): ?><p><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></p><?php endforeach; ?></div><?php endif; ?>

    <h3><?=htmlspecialchars(t($t,'department','Department'), ENT_QUOTES, 'UTF-8')?></h3>
    <form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="department_add"><label class="md-field"><span><?=t($t,'department','Department')?></span><input name="label" required></label><button class="md-button md-primary"><?=t($t,'create','Create')?></button></form>
    <?php foreach ($departments as $slug => $record): if (($record['archived_at'] ?? null) !== null) continue; ?>
      <form method="post" class="md-work-function-row"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>"><label class="md-field"><span><?=t($t,'department','Department')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label><button class="md-button md-primary" name="mode" value="department_update"><?=t($t,'save','Save Changes')?></button><button class="md-button md-outline" name="mode" value="department_archive"><?=t($t,'archive','Archive')?></button></form>
    <?php endforeach; ?>

    <h3><?=htmlspecialchars(t($t,'team_catalog_title','Manage Teams in the Department'), ENT_QUOTES, 'UTF-8')?></h3>
    <form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="team_add"><label class="md-field"><span><?=t($t,'department','Department')?></span><select name="department_slug" required><?php foreach ($departmentOptions as $depSlug => $depLabel): ?><option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label><label class="md-field"><span><?=t($t,'team_catalog_label','Team name')?></span><input name="label" required></label><button class="md-button md-primary"><?=t($t,'team_catalog_add','Add team')?></button></form>
    <?php foreach ($teams as $slug => $record): if (($record['archived_at'] ?? null) !== null) continue; ?>
      <form method="post" class="md-work-function-row"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>"><label class="md-field"><span><?=t($t,'department','Department')?></span><select name="department_slug" required><?php foreach ($departmentOptions as $depSlug => $depLabel): ?><option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>" <?=$depSlug===($record['department_slug'] ?? '')?'selected':''?>><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label><label class="md-field"><span><?=t($t,'team_catalog_label','Team name')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label><button class="md-button md-primary" name="mode" value="team_update"><?=t($t,'save','Save Changes')?></button><button class="md-button md-outline" name="mode" value="team_archive"><?=t($t,'archive','Archive')?></button></form>
    <?php endforeach; ?>

    <h3><?=htmlspecialchars(t($t,'work_function','Work Role'), ENT_QUOTES, 'UTF-8')?></h3>
    <?php foreach ($workRoles as $slug => $record): if (($record['archived_at'] ?? null)!==null) continue; ?>
      <form method="post" class="md-work-function-row"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="role_update"><input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>"><label class="md-field"><span><?=t($t,'work_function_label_name','Work function name')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label><button class="md-button md-primary"><?=t($t,'save','Save Changes')?></button></form>
    <?php endforeach; ?>

    <h3><?=htmlspecialchars(t($t,'assignment_overview','Department questionnaire defaults'), ENT_QUOTES, 'UTF-8')?></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="assignments_save">
      <?php foreach ($departmentOptions as $depSlug => $depLabel): ?>
        <fieldset style="margin-bottom:1rem"><legend><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></legend>
          <?php foreach ($questionnaires as $q): $qid=(int)$q['id']; ?>
            <label><input type="checkbox" name="assignments[<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>][]" value="<?=$qid?>" <?=isset($assignments[$depSlug][$qid])?'checked':''?>> <?=htmlspecialchars((string)($q['title'] ?: t($t,'untitled_questionnaire','Untitled questionnaire')), ENT_QUOTES, 'UTF-8')?></label><br>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>
      <button class="md-button md-primary"><?=t($t,'save','Save Changes')?></button>
    </form>
  </div>
</section>
</body></html>
