<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('resolve_department_slug')) {
    require_once __DIR__ . '/../lib/department_teams.php';
}

auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

$flashKey = 'department_defaults_flash';
$metadataFlashKey = 'metadata_catalog_flash';
$msg = $_SESSION[$flashKey] ?? '';
unset($_SESSION[$flashKey]);
$metadataMsg = $_SESSION[$metadataFlashKey] ?? '';
unset($_SESSION[$metadataFlashKey]);
$metadataErrors = [];

$departments = department_catalog($pdo);
$departmentOptions = department_options($pdo);
$teams = department_team_catalog($pdo);
$workRoles = work_function_catalog($pdo);
$builtInDepartments = built_in_department_definitions();

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
            if (isset($departments[$slug])) {
                throw new InvalidArgumentException(t($t,'department_exists','Department already exists.'));
            }
            $sort = count($departments) + 1;
            $pdo->prepare('INSERT INTO department_catalog (slug,label,sort_order) VALUES (?,?,?)')->execute([$slug, $label, $sort]);
            $_SESSION[$metadataFlashKey] = t($t,'department_created','Department added.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
        }

        if ($mode === 'department_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($slug === '' || $label === '') {
                throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            }
            $pdo->prepare('UPDATE department_catalog SET label=? WHERE slug=?')->execute([$label, $slug]);
            $_SESSION[$metadataFlashKey] = t($t,'department_updated','Department updated.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
        }

        if ($mode === 'department_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') {
                throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE department_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug=?')->execute([$slug]);
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE department_slug=?')->execute([$slug]);
            $depLabel = (string)($departments[$slug]['label'] ?? '');
            $pdo->prepare('UPDATE users SET department = NULL, cadre = NULL WHERE department = ? OR department = ?')->execute([$slug, $depLabel]);
            $pdo->prepare('DELETE FROM questionnaire_department WHERE department_slug = ?')->execute([$slug]);
            $pdo->commit();
            $_SESSION[$metadataFlashKey] = t($t,'department_archived','Department archived.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
        }

        if ($mode === 'department_normalize') {
            $pdo->beginTransaction();

            $insertDep = $pdo->prepare('INSERT INTO department_catalog (slug, label, sort_order, archived_at) VALUES (?, ?, ?, NULL)');
            $updateDep = $pdo->prepare('UPDATE department_catalog SET label=?, sort_order=?, archived_at=NULL WHERE slug=?');
            $sort = 1;
            foreach ($builtInDepartments as $slug => $label) {
                if (isset($departments[$slug])) {
                    $updateDep->execute([$label, $sort, $slug]);
                } else {
                    $insertDep->execute([$slug, $label, $sort]);
                }
                $sort++;
            }

            $mergeAssignmentsSql = $driver === 'sqlite'
                ? 'INSERT OR IGNORE INTO questionnaire_department (questionnaire_id, department_slug) SELECT questionnaire_id, ? FROM questionnaire_department WHERE department_slug = ?'
                : 'INSERT IGNORE INTO questionnaire_department (questionnaire_id, department_slug) SELECT questionnaire_id, ? FROM questionnaire_department WHERE department_slug = ?';
            $mergeAssignments = $pdo->prepare($mergeAssignmentsSql);
            $deleteAssignments = $pdo->prepare('DELETE FROM questionnaire_department WHERE department_slug = ?');
            $moveTeams = $pdo->prepare('UPDATE department_team_catalog SET department_slug = ? WHERE department_slug = ?');
            $archiveDep = $pdo->prepare('UPDATE department_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug = ?');
            $usersMove = $pdo->prepare('UPDATE users SET department = ? WHERE department = ? OR department = ?');
            $usersFallback = $pdo->prepare("UPDATE users SET department = 'general_service', cadre = NULL WHERE department = ? OR department = ?");
            $archiveTeamsInDepartment = $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE department_slug = ?');

            foreach ($departments as $slug => $record) {
                if (isset($builtInDepartments[$slug])) {
                    continue;
                }

                $label = trim((string)($record['label'] ?? ''));
                $target = '';
                $canonicalLabel = canonical_department_slug($label);
                $canonicalSlug = canonical_department_slug($slug);
                foreach ($builtInDepartments as $builtInSlug => $builtInLabel) {
                    if ($canonicalLabel === $builtInSlug || $canonicalSlug === $builtInSlug || strcasecmp($label, $builtInLabel) === 0) {
                        $target = $builtInSlug;
                        break;
                    }
                }

                if ($target !== '') {
                    $usersMove->execute([$target, $slug, $label]);
                    $moveTeams->execute([$target, $slug]);
                    $mergeAssignments->execute([$target, $slug]);
                    $deleteAssignments->execute([$slug]);
                } else {
                    $usersFallback->execute([$slug, $label]);
                    $archiveTeamsInDepartment->execute([$slug]);
                    $deleteAssignments->execute([$slug]);
                }

                $archiveDep->execute([$slug]);
            }

            $pdo->commit();
            $_SESSION[$metadataFlashKey] = t($t, 'department_catalog_normalized', 'Department catalog normalized to default list.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
        }

        if ($mode === 'team_add') {
            $label = trim((string)($_POST['label'] ?? ''));
            $departmentSlug = trim((string)($_POST['department_slug'] ?? ''));
            if ($label === '' || !isset($departmentOptions[$departmentSlug])) {
                throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the department.'));
            }
            $slug = canonical_department_team_slug($label);
            if ($slug === '') {
                throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the department.'));
            }
            if (isset($teams[$slug])) {
                throw new InvalidArgumentException(t($t,'team_catalog_duplicate','That team already exists.'));
            }
            $sort = count($teams) + 1;
            $pdo->prepare('INSERT INTO department_team_catalog (slug,department_slug,label,sort_order) VALUES (?,?,?,?)')->execute([$slug, $departmentSlug, $label, $sort]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_created','Team added.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
        }

        if ($mode === 'team_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $departmentSlug = trim((string)($_POST['department_slug'] ?? ''));
            if ($slug === '' || $label === '' || !isset($departmentOptions[$departmentSlug])) {
                throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the department.'));
            }
            $pdo->prepare('UPDATE department_team_catalog SET label=?, department_slug=? WHERE slug=?')->execute([$label, $departmentSlug, $slug]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_updated','Team updated.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
        }

        if ($mode === 'team_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') {
                throw new InvalidArgumentException(t($t,'team_catalog_missing','Team does not exist.'));
            }
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug=?')->execute([$slug]);
            $teamLabel = (string)($teams[$slug]['label'] ?? '');
            $pdo->prepare('UPDATE users SET cadre = NULL WHERE cadre = ? OR cadre = ?')->execute([$slug, $teamLabel]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_archived','Team archived.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
        }

        if ($mode === 'role_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($slug === '' || $label === '') {
                throw new InvalidArgumentException(t($t,'invalid_work_function','Select a valid work function.'));
            }
            $pdo->prepare('UPDATE work_function_catalog SET label=? WHERE slug=?')->execute([$label, $slug]);
            $_SESSION[$metadataFlashKey] = t($t,'work_function_catalog_updated','Work function updated.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
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

$activeDepartmentCount = count($departmentOptions);
$activeTeamCount = 0;
foreach ($teams as $team) {
    if (($team['archived_at'] ?? null) === null) {
        $activeTeamCount++;
    }
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style>
    .md-section-grid { display: grid; gap: 1rem; }
    @media (min-width: 980px) { .md-section-grid { grid-template-columns: 1fr 1fr; } }
    .md-scroll-panel { max-height: 360px; overflow: auto; padding-right: .25rem; }
    .md-inline-form { display: grid; grid-template-columns: 1fr auto auto; gap: .5rem; align-items: end; margin-bottom: .5rem; }
    .md-inline-form select, .md-inline-form input { margin: 0; }
    .md-summary { color: var(--app-muted, #556); margin-bottom: .75rem; }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></h2>
    <p class="md-summary">Active departments: <?=$activeDepartmentCount?> • Active teams: <?=$activeTeamCount?></p>

    <?php if ($metadataMsg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($metadataMsg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($msg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($metadataErrors): ?><div class="md-alert error"><?php foreach ($metadataErrors as $err): ?><p><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></p><?php endforeach; ?></div><?php endif; ?>

    <form method="post" onsubmit="return confirm('Normalize department list back to defaults and archive extras?');" style="margin-bottom:1rem;">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="mode" value="department_normalize">
      <button class="md-button md-outline"><?=htmlspecialchars(t($t, 'department_catalog_normalize_action', 'Normalize department list to defaults'), ENT_QUOTES, 'UTF-8')?></button>
    </form>

    <div class="md-section-grid">
      <div>
        <h3><?=htmlspecialchars(t($t,'department','Department'), ENT_QUOTES, 'UTF-8')?></h3>
        <form method="post" class="md-inline-form">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="mode" value="department_add">
          <label class="md-field"><span><?=t($t,'department','Department')?></span><input name="label" required></label>
          <button class="md-button md-primary"><?=t($t,'create','Create')?></button>
          <span></span>
        </form>
        <div class="md-scroll-panel">
          <?php foreach ($departments as $slug => $record): if (($record['archived_at'] ?? null) !== null) continue; ?>
            <form method="post" class="md-inline-form">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
              <label class="md-field"><span><?=t($t,'department','Department')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
              <button class="md-button md-primary" name="mode" value="department_update"><?=t($t,'save','Save Changes')?></button>
              <button class="md-button md-outline" name="mode" value="department_archive"><?=t($t,'archive','Archive')?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <h3><?=htmlspecialchars(t($t,'team_catalog_title','Manage Teams in the Department'), ENT_QUOTES, 'UTF-8')?></h3>
        <form method="post" class="md-inline-form">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="mode" value="team_add">
          <label class="md-field"><span><?=t($t,'team_catalog_label','Team name')?></span><input name="label" required></label>
          <label class="md-field"><span><?=t($t,'department','Department')?></span><select name="department_slug" required><?php foreach ($departmentOptions as $depSlug => $depLabel): ?><option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
          <button class="md-button md-primary"><?=t($t,'team_catalog_add','Add team')?></button>
        </form>
        <div class="md-scroll-panel">
          <?php foreach ($teams as $slug => $record): if (($record['archived_at'] ?? null) !== null) continue; ?>
            <form method="post" class="md-inline-form">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
              <label class="md-field"><span><?=t($t,'team_catalog_label','Team name')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
              <label class="md-field"><span><?=t($t,'department','Department')?></span><select name="department_slug" required><?php foreach ($departmentOptions as $depSlug => $depLabel): ?><option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>" <?=$depSlug===($record['department_slug'] ?? '')?'selected':''?>><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
              <div>
                <button class="md-button md-primary" name="mode" value="team_update"><?=t($t,'save','Save Changes')?></button>
                <button class="md-button md-outline" name="mode" value="team_archive"><?=t($t,'archive','Archive')?></button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="md-card md-elev-1" style="margin-top:1rem;">
      <h3 class="md-card-title"><?=htmlspecialchars(t($t,'assign_questionnaires','Assign Questionnaires'), ENT_QUOTES, 'UTF-8')?></h3>
      <p class="md-summary"><?=htmlspecialchars(t($t,'assignment_work_function_only','Questionnaire defaults are configured at department level.'), ENT_QUOTES, 'UTF-8')?></p>
      <p><a class="md-button md-primary" href="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(t($t,'manage','Manage'), ENT_QUOTES, 'UTF-8')?> <?=htmlspecialchars(t($t,'assign_questionnaires','Assign Questionnaires'), ENT_QUOTES, 'UTF-8')?></a></p>
    </div>
  </div>
</section>
</body>
</html>
