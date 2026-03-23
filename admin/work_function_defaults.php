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
try {
    $stmt = $pdo->query("SELECT id, title, description FROM questionnaire WHERE status='published' ORDER BY title ASC");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;
            $questionnaires[] = $row;
            $questionnaireIds[$id] = true;
        }
    }
} catch (PDOException $e) {
    error_log('work_function_defaults questionnaire fetch failed: ' . $e->getMessage());
}

$departments = department_catalog($pdo);
$departmentOptions = department_options($pdo);
$teams = department_team_catalog($pdo);
$workRoles = work_function_catalog($pdo);

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'active')));
if (!in_array($statusFilter, ['active', 'inactive', 'all'], true)) {
    $statusFilter = 'active';
}
$buildRedirect = static function () use ($statusFilter): string {
    $path = url_for('admin/work_function_defaults.php');
    return $statusFilter === 'active' ? $path : $path . '?status=' . urlencode($statusFilter);
};


$matchesStatusFilter = static function (?string $archivedAt) use ($statusFilter): bool {
    if ($statusFilter === 'all') {
        return true;
    }
    $isArchived = $archivedAt !== null && trim((string)$archivedAt) !== '';
    if ($statusFilter === 'inactive') {
        return $isArchived;
    }
    return !$isArchived;
};

$activeDepartmentCount = count($departmentOptions);
$totalDepartmentCount = count($departments);
$activeTeamCount = 0;
foreach ($teams as $record) {
    if (($record['archived_at'] ?? null) === null) {
        $activeTeamCount++;
    }
}
$totalTeamCount = count($teams);
$activeWorkRoleCount = 0;
foreach ($workRoles as $record) {
    if (($record['archived_at'] ?? null) === null) {
        $activeWorkRoleCount++;
    }
}
$totalWorkRoleCount = count($workRoles);

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
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'department_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($slug === '' || $label === '') throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            $pdo->prepare('UPDATE department_catalog SET label=? WHERE slug=?')->execute([$label,$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'department_updated','Department updated.');
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'department_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE department_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug=?')->execute([$slug]);
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE department_slug=?')->execute([$slug]);
            $depLabel = (string)($departments[$slug]['label'] ?? '');
            $pdo->prepare('UPDATE users SET department = NULL, cadre = NULL WHERE department = ? OR department = ?')->execute([$slug, $depLabel]);
            $pdo->prepare('DELETE FROM questionnaire_department WHERE department_slug = ?')->execute([$slug]);
            $pdo->commit();
            $_SESSION[$metadataFlashKey] = t($t,'department_archived','Department archived.');
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'department_activate') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            $pdo->prepare('UPDATE department_catalog SET archived_at = NULL WHERE slug=?')->execute([$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'department_updated','Department updated.');
            header('Location: ' . $buildRedirect()); exit;
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
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'team_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $departmentSlug = trim((string)($_POST['department_slug'] ?? ''));
            if ($slug === '' || $label === '' || !isset($departmentOptions[$departmentSlug])) throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the department.'));
            $pdo->prepare('UPDATE department_team_catalog SET label=?, department_slug=? WHERE slug=?')->execute([$label,$departmentSlug,$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_updated','Team updated.');
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'team_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'team_catalog_missing','Team does not exist.'));
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug=?')->execute([$slug]);
            $teamLabel = (string)($teams[$slug]['label'] ?? '');
            $pdo->prepare('UPDATE users SET cadre = NULL WHERE cadre = ? OR cadre = ?')->execute([$slug, $teamLabel]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_archived','Team archived.');
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'team_activate') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'team_catalog_missing','Team does not exist.'));
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = NULL WHERE slug=?')->execute([$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_updated','Team updated.');
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'role_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($slug === '' || $label === '') throw new InvalidArgumentException(t($t,'invalid_work_function','Select a valid work function.'));
            update_work_function_label($pdo, $slug, $label);
            $_SESSION[$metadataFlashKey] = t($t,'work_function_catalog_updated','Work function updated.');
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'role_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_work_function','Select a valid work function.'));
            archive_work_function($pdo, $slug);
            $_SESSION[$metadataFlashKey] = t($t,'work_function_catalog_archived','Work function archived.');
            header('Location: ' . $buildRedirect()); exit;
        }
        if ($mode === 'role_activate') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_work_function','Select a valid work function.'));
            $pdo->prepare('UPDATE work_function_catalog SET archived_at = NULL WHERE slug=?')->execute([$slug]);
            reset_work_function_caches($pdo);
            $_SESSION[$metadataFlashKey] = t($t,'work_function_catalog_updated','Work function updated.');
            header('Location: ' . $buildRedirect()); exit;
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
            header('Location: ' . $buildRedirect()); exit;
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
if ($assignments === []) {
    try {
        $legacyStmt = $pdo->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function');
        if ($legacyStmt) {
            foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dep = resolve_department_slug($pdo, (string)($row['work_function'] ?? ''));
                $qid = (int)($row['questionnaire_id'] ?? 0);
                if ($dep !== '' && $qid > 0) {
                    $assignments[$dep][$qid] = true;
                }
            }
        }
    } catch (PDOException $e) {
        error_log('work_function_defaults legacy fallback failed: ' . $e->getMessage());
    }
}


$assignmentCounts = [];
foreach ($departmentOptions as $depSlug => $_depLabel) {
    $assignmentCounts[$depSlug] = isset($assignments[$depSlug]) ? count($assignments[$depSlug]) : 0;
}
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
<style>
  .md-defaults-group { margin-bottom: .9rem; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; background: rgba(255,255,255,.72); }
  .md-defaults-group > summary { cursor: pointer; padding: .7rem .9rem; font-weight: 700; list-style: none; display: flex; justify-content: flex-start; align-items: center; gap: .5rem; }
  .md-defaults-group > summary::before { content: '▸'; font-size: .95rem; color: rgba(0,0,0,.65); transition: transform .2s ease; }
  .md-defaults-group[open] > summary::before { transform: rotate(90deg); }
  .md-defaults-group > summary::-webkit-details-marker { display: none; }
  .md-defaults-meta { margin-left: auto; }
  .md-defaults-group-body { padding: .35rem .9rem .85rem; }
  .md-defaults-meta { color: #6b7280; font-size: .86rem; font-weight: 500; }
  .md-work-function-row { margin-bottom: .6rem; }
  .md-compact-actions { display: flex; flex-wrap: wrap; gap: .65rem; align-items: flex-end; }
  .md-compact-actions .md-field { margin: 0; flex: 1 1 220px; }
  .md-work-function-row .md-button, .md-compact-actions .md-button { padding: .38rem .68rem; min-height: 32px; line-height: 1.1; font-size: .88rem; white-space: nowrap; align-self: flex-end; }
  .md-assignment-picker details { border: 1px dashed rgba(0,0,0,.14); border-radius: 8px; margin-bottom: .55rem; }
  .md-assignment-picker summary { padding: .5rem .7rem; cursor: pointer; font-weight: 600; }
  .md-assignment-options { max-height: 220px; overflow: auto; padding: .2rem .7rem .6rem; }
  .md-assignment-options label { display: block; margin-bottom: .28rem; font-size: .92rem; }
  .md-filter-row { display: flex; gap: .4rem; margin: .35rem 0 .9rem; flex-wrap: wrap; }
  .md-filter-chip { padding: .3rem .65rem; border: 1px solid rgba(0,0,0,.2); border-radius: 999px; text-decoration: none; color: inherit; font-size: .86rem; }
  .md-filter-chip.is-active { background: #1f6feb; color: #fff; border-color: #1f6feb; }
  .md-search-block { margin-bottom: .8rem; }
  .md-search-block .md-field { margin: 0; max-width: 360px; }
  .md-search-empty { display: none; margin: .4rem 0 0; color: #6b7280; font-size: .9rem; }
  .md-search-empty.is-visible { display: block; }
</style>
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></h2>
    <?php if ($metadataMsg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($metadataMsg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($msg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($metadataErrors): ?><div class="md-alert error"><?php foreach ($metadataErrors as $err): ?><p><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></p><?php endforeach; ?></div><?php endif; ?>

    <div class="md-filter-row">
      <a class="md-filter-chip <?=$statusFilter==='active'?'is-active':''?>" href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>">Active</a>
      <a class="md-filter-chip <?=$statusFilter==='inactive'?'is-active':''?>" href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php') . '?status=inactive', ENT_QUOTES, 'UTF-8')?>">Inactive</a>
      <a class="md-filter-chip <?=$statusFilter==='all'?'is-active':''?>" href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php') . '?status=all', ENT_QUOTES, 'UTF-8')?>">All</a>
    </div>

    <details class="md-defaults-group">
      <summary>
        <span><?=htmlspecialchars(t($t,'department','Department'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=$statusFilter === 'active' ? $activeDepartmentCount : ($statusFilter === 'inactive' ? ($totalDepartmentCount - $activeDepartmentCount) : $totalDepartmentCount)?> <?=htmlspecialchars(t($t,'items','items'), ENT_QUOTES, 'UTF-8')?></span>
      </summary>
      <div class="md-defaults-group-body">
        <form method="post" class="md-compact-actions"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="department_add"><label class="md-field"><span><?=t($t,'department','Department')?></span><input name="label" required></label><button type="submit" class="md-button md-primary"><?=t($t,'create','Create')?></button></form>
        <div class="md-search-block">
          <label class="md-field"><span><?=htmlspecialchars(t($t, 'search_catalog', 'Search this list'), ENT_QUOTES, 'UTF-8')?></span><input type="search" class="js-catalog-search" data-target="department" placeholder="<?=htmlspecialchars(t($t, 'search_department_placeholder', 'Search departments'), ENT_QUOTES, 'UTF-8')?>"></label>
        </div>
        <?php foreach ($departments as $slug => $record): if (!$matchesStatusFilter($record['archived_at'] ?? null)) continue; ?>
          <form method="post" class="md-work-function-row md-compact-actions" data-search-group="department" data-search-text="<?=htmlspecialchars(strtolower(trim($slug . ' ' . (string)($record['label'] ?? ''))), ENT_QUOTES, 'UTF-8')?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>"><label class="md-field"><span><?=t($t,'department','Department')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label><button type="submit" class="md-button md-primary" name="mode" value="department_update"><?=t($t,'save','Save Changes')?></button><button type="submit" class="md-button md-outline" name="mode" value="<?=($record['archived_at'] ?? null) === null ? 'department_archive' : 'department_activate'?>"><?=($record['archived_at'] ?? null) === null ? t($t,'archive','Archive') : t($t,'save','Activate')?></button></form>
        <?php endforeach; ?>
        <p class="md-search-empty" data-search-empty="department"><?=htmlspecialchars(t($t, 'search_no_results', 'No matching items found.'), ENT_QUOTES, 'UTF-8')?></p>
      </div>
    </details>

    <details class="md-defaults-group">
      <summary>
        <span><?=htmlspecialchars(t($t,'team_catalog_title','Manage Teams in the Department'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=$statusFilter === 'active' ? $activeTeamCount : ($statusFilter === 'inactive' ? ($totalTeamCount - $activeTeamCount) : $totalTeamCount)?> <?=htmlspecialchars(t($t,'items','items'), ENT_QUOTES, 'UTF-8')?></span>
      </summary>
      <div class="md-defaults-group-body">
        <form method="post" class="md-compact-actions"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="team_add"><label class="md-field"><span><?=t($t,'department','Department')?></span><select name="department_slug" required><?php foreach ($departmentOptions as $depSlug => $depLabel): ?><option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label><label class="md-field"><span><?=t($t,'team_catalog_label','Team name')?></span><input name="label" required></label><button type="submit" class="md-button md-primary"><?=t($t,'team_catalog_add','Add team')?></button></form>
        <div class="md-search-block">
          <label class="md-field"><span><?=htmlspecialchars(t($t, 'search_catalog', 'Search this list'), ENT_QUOTES, 'UTF-8')?></span><input type="search" class="js-catalog-search" data-target="team" placeholder="<?=htmlspecialchars(t($t, 'search_team_placeholder', 'Search teams'), ENT_QUOTES, 'UTF-8')?>"></label>
        </div>
        <?php foreach ($teams as $slug => $record): if (!$matchesStatusFilter($record['archived_at'] ?? null)) continue; ?>
          <form method="post" class="md-work-function-row md-compact-actions" data-search-group="team" data-search-text="<?=htmlspecialchars(strtolower(trim($slug . ' ' . (string)($record['label'] ?? '') . ' ' . (string)($departmentOptions[$record['department_slug'] ?? ''] ?? ''))), ENT_QUOTES, 'UTF-8')?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>"><label class="md-field"><span><?=t($t,'department','Department')?></span><select name="department_slug" required><?php foreach ($departmentOptions as $depSlug => $depLabel): ?><option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>" <?=$depSlug===($record['department_slug'] ?? '')?'selected':''?>><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label><label class="md-field"><span><?=t($t,'team_catalog_label','Team name')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label><button type="submit" class="md-button md-primary" name="mode" value="team_update"><?=t($t,'save','Save Changes')?></button><button type="submit" class="md-button md-outline" name="mode" value="<?=($record['archived_at'] ?? null) === null ? 'team_archive' : 'team_activate'?>"><?=($record['archived_at'] ?? null) === null ? t($t,'archive','Archive') : t($t,'save','Activate')?></button></form>
        <?php endforeach; ?>
        <p class="md-search-empty" data-search-empty="team"><?=htmlspecialchars(t($t, 'search_no_results', 'No matching items found.'), ENT_QUOTES, 'UTF-8')?></p>
      </div>
    </details>

    <details class="md-defaults-group">
      <summary>
        <span><?=htmlspecialchars(t($t,'work_function','Work Role'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=$statusFilter === 'active' ? $activeWorkRoleCount : ($statusFilter === 'inactive' ? ($totalWorkRoleCount - $activeWorkRoleCount) : $totalWorkRoleCount)?> <?=htmlspecialchars(t($t,'items','items'), ENT_QUOTES, 'UTF-8')?></span>
      </summary>
      <div class="md-defaults-group-body">
        <div class="md-search-block">
          <label class="md-field"><span><?=htmlspecialchars(t($t, 'search_catalog', 'Search this list'), ENT_QUOTES, 'UTF-8')?></span><input type="search" class="js-catalog-search" data-target="role" placeholder="<?=htmlspecialchars(t($t, 'search_work_role_placeholder', 'Search work roles'), ENT_QUOTES, 'UTF-8')?>"></label>
        </div>
        <?php foreach ($workRoles as $slug => $record): if (!$matchesStatusFilter($record['archived_at'] ?? null)) continue; ?>
          <form method="post" class="md-work-function-row md-compact-actions" data-search-group="role" data-search-text="<?=htmlspecialchars(strtolower(trim($slug . ' ' . (string)($record['label'] ?? ''))), ENT_QUOTES, 'UTF-8')?>"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>"><label class="md-field"><span><?=t($t,'work_function_label_name','Work function name')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label><button type="submit" class="md-button md-primary" name="mode" value="role_update"><?=t($t,'save','Save Changes')?></button><button type="submit" class="md-button md-outline" name="mode" value="<?=($record['archived_at'] ?? null) === null ? 'role_archive' : 'role_activate'?>" <?=($record['archived_at'] ?? null) === null ? "onclick=\"return confirm('<?=htmlspecialchars(t($t,'work_function_archive_confirm','Archive this work function? Existing assignments will be removed.'), ENT_QUOTES, 'UTF-8')?>');\"" : ''?>><?=($record['archived_at'] ?? null) === null ? t($t,'work_function_archive','Archive') : t($t,'save','Activate')?></button></form>
        <?php endforeach; ?>
        <p class="md-search-empty" data-search-empty="role"><?=htmlspecialchars(t($t, 'search_no_results', 'No matching items found.'), ENT_QUOTES, 'UTF-8')?></p>
      </div>
    </details>

    <details class="md-defaults-group">
      <summary>
        <span><?=htmlspecialchars(t($t,'assignment_overview','Department questionnaire defaults'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=count($questionnaires)?> <?=htmlspecialchars(t($t,'questionnaires','Questionnaires'), ENT_QUOTES, 'UTF-8')?></span>
      </summary>
      <div class="md-defaults-group-body md-assignment-picker">
        <form method="post" class="md-compact-actions">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="assignments_save">
          <?php foreach ($departmentOptions as $depSlug => $depLabel): ?>
            <details>
              <summary><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?> <span class="md-defaults-meta">(<?= (int)($assignmentCounts[$depSlug] ?? 0) ?> selected)</span></summary>
              <div class="md-assignment-options">
                <?php foreach ($questionnaires as $q): $qid=(int)$q['id']; ?>
                  <label><input type="checkbox" name="assignments[<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>][]" value="<?=$qid?>" <?=isset($assignments[$depSlug][$qid])?'checked':''?>> <?=htmlspecialchars((string)($q['title'] ?: t($t,'untitled_questionnaire','Untitled questionnaire')), ENT_QUOTES, 'UTF-8')?></label>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endforeach; ?>
          <button type="submit" class="md-button md-primary"><?=t($t,'save','Save Changes')?></button>
        </form>
      </div>
    </details>
  </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var searchInputs = document.querySelectorAll('.js-catalog-search');
    searchInputs.forEach(function (input) {
      input.addEventListener('input', function () {
        var group = input.getAttribute('data-target');
        var query = (input.value || '').toLowerCase().trim();
        var rows = document.querySelectorAll('[data-search-group="' + group + '"]');
        var matchCount = 0;
        rows.forEach(function (row) {
          var haystack = (row.getAttribute('data-search-text') || '').toLowerCase();
          var isMatch = query === '' || haystack.indexOf(query) !== -1;
          row.style.display = isMatch ? '' : 'none';
          if (isMatch) {
            matchCount += 1;
          }
        });
        var emptyState = document.querySelector('[data-search-empty="' + group + '"]');
        if (emptyState) {
          emptyState.classList.toggle('is-visible', matchCount === 0);
        }
      });
    });
  });
</script>
</body></html>
