<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('resolve_department_slug')) {
    require_once __DIR__ . '/../lib/department_teams.php';
}
require_once __DIR__ . '/../lib/department_catalog_sync.php';

if (!function_exists('admin_catalog_sync_record_summary')) {
    function admin_catalog_sync_record_summary(array $record, string $type, array $allDepartmentOptions): string
    {
        $status = normalize_catalog_sync_archived_at($record['archived_at'] ?? null) === null ? 'Active' : 'Archived';
        $parts = [];
        $parts[] = 'Label: ' . (string)($record['label'] ?? '');
        if ($type === 'team') {
            $departmentSlug = (string)($record['department_slug'] ?? '');
            $parts[] = 'Directorate: ' . (string)($allDepartmentOptions[$departmentSlug] ?? $departmentSlug);
        }
        $parts[] = 'Slug: ' . (string)($record['slug'] ?? '');
        $parts[] = 'Sort: ' . (string)((int)($record['sort_order'] ?? 0));
        $parts[] = 'Status: ' . $status;
        return implode(' · ', $parts);
    }
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
$catalogSyncPreview = null;
$catalogSyncPreviewToken = '';
$initialPane = 'departments';

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
$allDepartmentOptions = [];
foreach ($departments as $depSlug => $depRecord) {
    $allDepartmentOptions[$depSlug] = (string)($depRecord['label'] ?? $depSlug);
}
ensure_questionnaire_team_schema($pdo);
$teams = department_team_catalog($pdo);
$workRoles = work_function_catalog($pdo);

if (($_GET['action'] ?? '') === 'export_department_catalog') {
    $filename = 'department-team-catalog-' . gmdate('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo department_catalog_export_json($pdo);
    exit;
}

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'active')));
if (!in_array($statusFilter, ['active', 'inactive', 'all'], true)) {
    $statusFilter = 'active';
}
$buildRedirect = static function (?string $tab = null) use ($statusFilter): string {
    $path = url_for('admin/work_function_defaults.php');
    $url = $statusFilter === 'active' ? $path : $path . '?status=' . urlencode($statusFilter);
    if ($tab !== null && $tab !== '') {
        $url .= '#' . rawurlencode($tab);
    }
    return $url;
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
    $currentTab = trim((string)($_POST['current_tab'] ?? ''));
    if (!in_array($currentTab, ['departments', 'teams', 'roles', 'catalog-sync', 'defaults', 'team-defaults'], true)) {
        $currentTab = '';
    }
    try {
        if ($mode === 'department_add') {
            $label = trim((string)($_POST['label'] ?? ''));
            $slug = canonical_department_slug($label);
            if ($label === '' || $slug === '') {
                throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            }
            $exists = isset($departments[$slug]);
            if ($exists) {
                throw new InvalidArgumentException(t($t,'department_exists','Directorate already exists.'));
            }
            $sort = count($departments) + 1;
            $pdo->prepare('INSERT INTO department_catalog (slug,label,sort_order) VALUES (?,?,?)')->execute([$slug,$label,$sort]);
            $_SESSION[$metadataFlashKey] = t($t,'department_created','Directorate added.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'department_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($slug === '' || $label === '') throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            $pdo->prepare('UPDATE department_catalog SET label=? WHERE slug=?')->execute([$label,$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'department_updated','Directorate updated.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
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
            $_SESSION[$metadataFlashKey] = t($t,'department_archived','Directorate archived.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'department_activate') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            $pdo->prepare('UPDATE department_catalog SET archived_at = NULL WHERE slug=?')->execute([$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'department_updated','Directorate updated.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'team_add') {
            $label = trim((string)($_POST['label'] ?? ''));
            $departmentSlug = trim((string)($_POST['department_slug'] ?? ''));
            if ($label === '' || !isset($departmentOptions[$departmentSlug])) {
                throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the directorate.'));
            }
            $slug = canonical_department_team_slug($label);
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the directorate.'));
            if (isset($teams[$slug])) throw new InvalidArgumentException(t($t,'team_catalog_duplicate','That team already exists.'));
            $sort = count($teams) + 1;
            $pdo->prepare('INSERT INTO department_team_catalog (slug,department_slug,label,sort_order) VALUES (?,?,?,?)')->execute([$slug,$departmentSlug,$label,$sort]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_created','Team added.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'team_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $departmentSlug = trim((string)($_POST['department_slug'] ?? ''));
            if ($slug === '' || $label === '' || !isset($allDepartmentOptions[$departmentSlug])) throw new InvalidArgumentException(t($t,'invalid_team_department','Select a valid team in the directorate.'));
            $pdo->prepare('UPDATE department_team_catalog SET label=?, department_slug=? WHERE slug=?')->execute([$label,$departmentSlug,$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_updated','Team updated.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'team_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'team_catalog_missing','Team does not exist.'));
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug=?')->execute([$slug]);
            $teamLabel = (string)($teams[$slug]['label'] ?? '');
            $pdo->prepare('UPDATE users SET cadre = NULL WHERE cadre = ? OR cadre = ?')->execute([$slug, $teamLabel]);
            $pdo->prepare('DELETE FROM questionnaire_team WHERE team_slug = ?')->execute([$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_archived','Team archived.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'team_activate') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'team_catalog_missing','Team does not exist.'));
            $pdo->prepare('UPDATE department_team_catalog SET archived_at = NULL WHERE slug=?')->execute([$slug]);
            $_SESSION[$metadataFlashKey] = t($t,'team_catalog_updated','Team updated.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'catalog_import_preview') {
            $initialPane = 'catalog-sync';
            $archiveMissing = isset($_POST['archive_missing']);
            $upload = $_FILES['catalog_file'] ?? null;
            if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException(t($t, 'catalog_sync_file_required', 'Upload a department/team catalog JSON file.'));
            }
            $size = (int)($upload['size'] ?? 0);
            if ($size <= 0 || $size > DEPARTMENT_CATALOG_SYNC_MAX_UPLOAD_BYTES) {
                throw new InvalidArgumentException(t($t, 'catalog_sync_file_size', 'Upload a non-empty catalog JSON file no larger than 2 MB.'));
            }
            $tmpName = (string)($upload['tmp_name'] ?? '');
            $json = $tmpName !== '' ? file_get_contents($tmpName) : false;
            if ($json === false || trim($json) === '') {
                throw new InvalidArgumentException(t($t, 'catalog_sync_file_empty', 'The uploaded catalog file is empty.'));
            }
            $payload = parse_department_catalog_import_json($json);
            $validation = validate_department_catalog_import_payload($payload);
            if (!$validation['valid']) {
                foreach ($validation['errors'] as $validationError) {
                    $metadataErrors[] = $validationError;
                }
            } else {
                $catalogSyncPreview = [
                    'archive_missing' => $archiveMissing,
                    'departments' => $validation['departments'],
                    'teams' => $validation['teams'],
                    'changes' => preview_department_catalog_import($pdo, $validation['departments'], $validation['teams'], $archiveMissing),
                ];
                $catalogSyncPreviewToken = bin2hex(random_bytes(16));
                $initialPane = 'catalog-sync';
                $_SESSION['department_catalog_sync_preview'] = $catalogSyncPreview;
                $_SESSION['department_catalog_sync_preview_token'] = $catalogSyncPreviewToken;
            }
        }
        if ($mode === 'catalog_import_apply') {
            $token = trim((string)($_POST['preview_token'] ?? ''));
            $storedToken = (string)($_SESSION['department_catalog_sync_preview_token'] ?? '');
            $storedPreview = $_SESSION['department_catalog_sync_preview'] ?? null;
            if ($token === '' || $storedToken === '' || !hash_equals($storedToken, $token) || !is_array($storedPreview)) {
                throw new InvalidArgumentException(t($t, 'catalog_sync_preview_expired', 'Preview the catalog import again before applying it.'));
            }
            $decisions = $_POST['catalog_decisions'] ?? [];
            if (!is_array($decisions)) {
                $decisions = [];
            }
            $result = apply_department_catalog_import_decisions(
                $pdo,
                is_array($storedPreview['departments'] ?? null) ? $storedPreview['departments'] : [],
                is_array($storedPreview['teams'] ?? null) ? $storedPreview['teams'] : [],
                !empty($storedPreview['archive_missing']),
                $decisions
            );
            unset($_SESSION['department_catalog_sync_preview'], $_SESSION['department_catalog_sync_preview_token']);
            $_SESSION[$metadataFlashKey] = sprintf(
                'Catalog import applied. Departments: %d created, %d updated, %d archived, %d kept. Teams: %d created, %d updated, %d archived, %d kept.',
                $result['departments']['created'],
                $result['departments']['updated'],
                $result['departments']['archived'],
                $result['departments']['kept'],
                $result['teams']['created'],
                $result['teams']['updated'],
                $result['teams']['archived'],
                $result['teams']['kept']
            );
            header('Location: ' . $buildRedirect('catalog-sync')); exit;
        }
        if ($mode === 'role_update') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($slug === '' || $label === '') throw new InvalidArgumentException(t($t,'invalid_work_function','Select a valid work function.'));
            update_work_function_label($pdo, $slug, $label);
            $_SESSION[$metadataFlashKey] = t($t,'work_function_catalog_updated','Work function updated.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'role_archive') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_work_function','Select a valid work function.'));
            archive_work_function($pdo, $slug);
            $_SESSION[$metadataFlashKey] = t($t,'work_function_catalog_archived','Work function archived.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'role_activate') {
            $slug = trim((string)($_POST['slug'] ?? ''));
            if ($slug === '') throw new InvalidArgumentException(t($t,'invalid_work_function','Select a valid work function.'));
            $pdo->prepare('UPDATE work_function_catalog SET archived_at = NULL WHERE slug=?')->execute([$slug]);
            reset_work_function_caches($pdo);
            $_SESSION[$metadataFlashKey] = t($t,'work_function_catalog_updated','Work function updated.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
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
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'team_assignments_save') {
            $input = $_POST['team_assignments'] ?? [];
            if (!is_array($input)) {
                throw new InvalidArgumentException(t($t,'work_function_defaults_invalid_payload','The selections could not be processed.'));
            }
            $activeTeams = [];
            foreach ($teams as $teamSlug => $record) {
                if (($record['archived_at'] ?? null) === null) {
                    $activeTeams[$teamSlug] = true;
                }
            }
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM questionnaire_team');
            $insert = $pdo->prepare('INSERT INTO questionnaire_team (questionnaire_id, team_slug) VALUES (?, ?)');
            foreach ($input as $teamSlug => $qidList) {
                $teamSlug = trim((string)$teamSlug);
                if (!isset($activeTeams[$teamSlug]) || !is_array($qidList)) continue;
                $seen = [];
                foreach ($qidList as $qidRaw) {
                    $qid = (int)$qidRaw;
                    if ($qid <= 0 || !isset($questionnaireIds[$qid]) || isset($seen[$qid])) continue;
                    $insert->execute([$qid, $teamSlug]);
                    $seen[$qid] = true;
                }
            }
            $pdo->commit();
            $_SESSION[$flashKey] = t($t,'team_questionnaire_defaults_saved','Team questionnaire assignments updated.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'team_assignments_clone_department') {
            $sourceDepartment = trim((string)($_POST['source_department'] ?? ''));
            if (!isset($departmentOptions[$sourceDepartment])) {
                throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            }
            $targetTeams = [];
            foreach ($teams as $teamSlug => $record) {
                if (($record['archived_at'] ?? null) === null && (string)($record['department_slug'] ?? '') === $sourceDepartment) {
                    $targetTeams[$teamSlug] = true;
                }
            }
            if ($targetTeams === []) {
                throw new InvalidArgumentException(t($t,'team_defaults_no_target_teams','No active teams were found for that directorate.'));
            }
            $sourceQuestionnaireIds = [];
            $sourceAssignStmt = $pdo->prepare('SELECT questionnaire_id FROM questionnaire_department WHERE department_slug = ?');
            $sourceAssignStmt->execute([$sourceDepartment]);
            foreach ($sourceAssignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $qid = (int)($row['questionnaire_id'] ?? 0);
                if ($qid > 0 && isset($questionnaireIds[$qid])) {
                    $sourceQuestionnaireIds[$qid] = true;
                }
            }
            $pdo->beginTransaction();
            $deleteStmt = $pdo->prepare('DELETE FROM questionnaire_team WHERE team_slug = ?');
            $insertStmt = $pdo->prepare('INSERT INTO questionnaire_team (questionnaire_id, team_slug) VALUES (?, ?)');
            foreach (array_keys($targetTeams) as $teamSlug) {
                $deleteStmt->execute([$teamSlug]);
                foreach (array_keys($sourceQuestionnaireIds) as $qid) {
                    $insertStmt->execute([$qid, $teamSlug]);
                }
            }
            $pdo->commit();
            $_SESSION[$flashKey] = t($t,'team_questionnaire_defaults_cloned','Directorate assignments copied to its teams.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
        if ($mode === 'assignments_bulk_clone') {
            $sourceDepartment = trim((string)($_POST['source_department'] ?? ''));
            $targetDepartments = $_POST['target_departments'] ?? [];
            if (!isset($departmentOptions[$sourceDepartment])) {
                throw new InvalidArgumentException(t($t,'invalid_department','Select a valid department.'));
            }
            if (!is_array($targetDepartments)) {
                $targetDepartments = [];
            }
            $validTargets = [];
            foreach ($targetDepartments as $depSlugRaw) {
                $depSlug = trim((string)$depSlugRaw);
                if ($depSlug === '' || $depSlug === $sourceDepartment || !isset($departmentOptions[$depSlug])) {
                    continue;
                }
                $validTargets[$depSlug] = true;
            }
            if ($validTargets === []) {
                throw new InvalidArgumentException(t($t,'work_function_defaults_bulk_target_required','Select at least one target department.'));
            }
            $sourceQuestionnaireIds = [];
            $sourceAssignStmt = $pdo->prepare('SELECT questionnaire_id FROM questionnaire_department WHERE department_slug = ?');
            $sourceAssignStmt->execute([$sourceDepartment]);
            foreach ($sourceAssignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $qid = (int)($row['questionnaire_id'] ?? 0);
                if ($qid > 0) {
                    $sourceQuestionnaireIds[$qid] = true;
                }
            }
            $pdo->beginTransaction();
            $deleteStmt = $pdo->prepare('DELETE FROM questionnaire_department WHERE department_slug = ?');
            $insertStmt = $pdo->prepare('INSERT INTO questionnaire_department (questionnaire_id, department_slug) VALUES (?, ?)');
            foreach (array_keys($validTargets) as $targetDepartment) {
                $deleteStmt->execute([$targetDepartment]);
                foreach (array_keys($sourceQuestionnaireIds) as $qid) {
                    $insertStmt->execute([$qid, $targetDepartment]);
                }
            }
            $pdo->commit();
            $_SESSION[$flashKey] = t($t,'work_function_defaults_bulk_cloned','Assignments copied to selected directorates.');
            header('Location: ' . $buildRedirect($currentTab)); exit;
        }
    } catch (InvalidArgumentException $e) {
        $metadataErrors[] = $e->getMessage();
        if (in_array((string)($_POST['mode'] ?? ''), ['catalog_import_preview', 'catalog_import_apply'], true)) {
            $initialPane = 'catalog-sync';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('work_function_defaults fatal error: ' . $e->getMessage());
        $metadataErrors[] = t($t, 'work_function_defaults_save_failed', 'Unable to save work function defaults. Please try again.');
        if (in_array((string)($_POST['mode'] ?? ''), ['catalog_import_preview', 'catalog_import_apply'], true)) {
            $initialPane = 'catalog-sync';
        }
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


$teamAssignments = [];
try {
    $teamAssignStmt = $pdo->query('SELECT questionnaire_id, team_slug FROM questionnaire_team');
    if ($teamAssignStmt) {
        foreach ($teamAssignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $teamSlug = trim((string)($row['team_slug'] ?? ''));
            $qid = (int)($row['questionnaire_id'] ?? 0);
            if ($teamSlug !== '' && $qid > 0) {
                $teamAssignments[$teamSlug][$qid] = true;
            }
        }
    }
} catch (PDOException $e) {
    error_log('work_function_defaults team assignment fetch failed: ' . $e->getMessage());
}

$teamAssignmentCounts = [];
try {
    $teamAssignmentCountStmt = $pdo->query(
        "SELECT team_slug, COUNT(DISTINCT questionnaire_id) AS selected_count
         FROM questionnaire_team
         GROUP BY team_slug"
    );
    if ($teamAssignmentCountStmt) {
        foreach ($teamAssignmentCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $teamSlug = trim((string)($row['team_slug'] ?? ''));
            if ($teamSlug !== '') {
                $teamAssignmentCounts[$teamSlug] = (int)($row['selected_count'] ?? 0);
            }
        }
    }
} catch (PDOException $e) {
    error_log('work_function_defaults team assignment count failed: ' . $e->getMessage());
}
foreach ($teams as $teamSlug => $_teamRecord) {
    if (!isset($teamAssignmentCounts[$teamSlug])) {
        $teamAssignmentCounts[$teamSlug] = 0;
    }
}

$teamsByDepartment = [];
foreach ($teams as $teamSlug => $record) {
    if (($record['archived_at'] ?? null) !== null) {
        continue;
    }
    $depSlug = trim((string)($record['department_slug'] ?? ''));
    if ($depSlug === '' || !isset($departmentOptions[$depSlug])) {
        continue;
    }
    $teamsByDepartment[$depSlug][$teamSlug] = $record;
}


$assignmentCounts = [];
$assignmentCountStmt = $pdo->query(
    "SELECT department_slug, COUNT(DISTINCT questionnaire_id) AS selected_count
     FROM questionnaire_department
     GROUP BY department_slug"
);
if ($assignmentCountStmt) {
    foreach ($assignmentCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $depSlug = trim((string)($row['department_slug'] ?? ''));
        if ($depSlug === '') {
            continue;
        }
        $assignmentCounts[$depSlug] = (int)($row['selected_count'] ?? 0);
    }
}
foreach ($departmentOptions as $depSlug => $_depLabel) {
    if (!isset($assignmentCounts[$depSlug])) {
        $assignmentCounts[$depSlug] = 0;
    }
}

$catalogSyncRecordSummary = static fn (array $record, string $type): string => admin_catalog_sync_record_summary($record, $type, $allDepartmentOptions);
?>

<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
<style>
  .md-defaults-group { margin-bottom: .9rem; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; background: rgba(255,255,255,.72); }
  .md-defaults-header { padding: .75rem .9rem; font-weight: 700; display: flex; align-items: center; gap: .5rem; border-bottom: 1px solid rgba(0,0,0,.08); }
  .md-defaults-meta { margin-left: auto; }
  .md-defaults-group-body { padding: .35rem .9rem .85rem; }
  .md-defaults-meta { color: #6b7280; font-size: .86rem; font-weight: 500; }
  .md-work-function-row { margin-bottom: .6rem; }
  .md-compact-actions { display: flex; flex-wrap: wrap; gap: .65rem; align-items: flex-end; }
  .md-compact-actions .md-field { margin: 0; flex: 1 1 220px; min-width: 0; }
  .md-compact-actions .md-field span,
  .md-compact-actions .md-field input,
  .md-compact-actions .md-field select,
  .md-assignment-options span,
  .md-table td,
  .md-table th {
    overflow-wrap: anywhere;
    word-break: normal;
  }
  .md-table {
    table-layout: fixed;
    width: 100%;
  }
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
  .md-list { margin-top: .45rem; border: 1px solid rgba(0,0,0,.08); border-radius: 8px; overflow: hidden; }
  .md-list-head, .md-list-row { display: grid; gap: .5rem; padding: .55rem .6rem; align-items: start; }
  .md-list-head { background: #f8fafc; border-bottom: 1px solid rgba(0,0,0,.08); font-size: .81rem; text-transform: uppercase; color: #6b7280; letter-spacing: .03em; font-weight: 700; }
  .md-list-row { border-bottom: 1px solid rgba(0,0,0,.08); font-size: .93rem; }
  .md-list-row:last-child { border-bottom: 0; }
  .md-list-col,
  .md-list-col code,
  .md-list-head > div {
    min-width: 0;
    overflow-wrap: anywhere;
    word-break: normal;
  }
  .md-list-col code { font-size: .85rem; white-space: normal; }
  .md-list-department { grid-template-columns: minmax(150px,1.2fr) minmax(120px,.9fr) minmax(90px,.6fr) minmax(150px,1fr); }
  .md-list-team { grid-template-columns: minmax(140px,1fr) minmax(140px,1fr) minmax(120px,.9fr) minmax(90px,.6fr) minmax(150px,1fr); }
  .md-list-role { grid-template-columns: minmax(150px,1.2fr) minmax(120px,.9fr) minmax(90px,.6fr) minmax(150px,1fr); }
  .md-tab-row { margin-bottom: .95rem; border-bottom: 1px solid rgba(0,0,0,.1); }
  .md-tab-chip { display: inline-block; text-decoration: none; padding: .45rem .8rem; border-radius: 8px 8px 0 0; color: inherit; border: 0; background: transparent; cursor: pointer; }
  .md-tab-chip.is-active { background: #1f6feb; color: #fff; }
  .md-pane { display: none; }
  .md-pane.is-active { display: block; }
  .md-assignment-options { width: 100%; max-height: 160px; overflow: auto; padding: .45rem .55rem; border: 1px solid rgba(0,0,0,.22); border-radius: 6px; background: #fff; }
  .md-assignment-options label { display: flex; gap: .45rem; align-items: flex-start; margin-bottom: .35rem; font-size: .92rem; }
  .md-assignment-options label:last-child { margin-bottom: 0; }
  .md-assignment-options input[type="checkbox"] { margin-top: .1rem; }
  .md-inline-editor { margin-top: .4rem; padding-top: .4rem; border-top: 1px dashed rgba(0,0,0,.15); }
  .md-status-chip { display: inline-block; padding: .15rem .45rem; border-radius: 999px; font-size: .8rem; font-weight: 600; }
  .md-status-chip.active { background: #e7f8ee; color: #136c3a; }
  .md-status-chip.inactive { background: #f3f4f6; color: #4b5563; }
  .md-saving-indicator { display: none; margin-left: .45rem; font-size: .78rem; color: #6b7280; }
  .md-list-row.is-saving .md-saving-indicator { display: inline-block; }
  .md-list-row.is-saving { opacity: .72; }
  .md-form-saving { display: none; flex: 0 1 auto; align-self: center; color: #6b7280; font-size: .86rem; overflow-wrap: anywhere; }
  form.is-saving .md-form-saving { display: inline-block; }
  @media (max-width: 900px) {
    .md-list-head { display: none; }
    .md-list-row { grid-template-columns: 1fr !important; gap: .35rem; }
    .md-list-col::before { content: attr(data-label); display: block; font-size: .76rem; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; margin-bottom: .08rem; }
  }
</style>
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></h2>
    <?php if ($metadataMsg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($metadataMsg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($msg !== ''): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($metadataErrors): ?><div class="md-alert error"><?php foreach ($metadataErrors as $err): ?><p><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></p><?php endforeach; ?></div><?php endif; ?>

    <div class="md-tab-row" role="tablist" aria-label="Work function defaults sections">
      <a class="md-tab-chip is-active" id="tab-departments" data-tab-target="departments" href="#departments" role="tab" aria-controls="departments" aria-selected="true">Directorates</a>
      <a class="md-tab-chip" id="tab-teams" data-tab-target="teams" href="#teams" role="tab" aria-controls="teams" aria-selected="false">Teams</a>
      <a class="md-tab-chip" id="tab-roles" data-tab-target="roles" href="#roles" role="tab" aria-controls="roles" aria-selected="false">Work Roles</a>
      <a class="md-tab-chip" id="tab-catalog-sync" data-tab-target="catalog-sync" href="#catalog-sync" role="tab" aria-controls="catalog-sync" aria-selected="false">Catalog Sync</a>
      <a class="md-tab-chip" id="tab-defaults" data-tab-target="defaults" href="#defaults" role="tab" aria-controls="defaults" aria-selected="false">Directorate Defaults</a>
      <a class="md-tab-chip" id="tab-team-defaults" data-tab-target="team-defaults" href="#team-defaults" role="tab" aria-controls="team-defaults" aria-selected="false">Team Defaults</a>
    </div>

    <div class="md-filter-row">
      <a class="md-filter-chip <?=$statusFilter==='active'?'is-active':''?>" href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>">Active</a>
      <a class="md-filter-chip <?=$statusFilter==='inactive'?'is-active':''?>" href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php') . '?status=inactive', ENT_QUOTES, 'UTF-8')?>">Inactive</a>
      <a class="md-filter-chip <?=$statusFilter==='all'?'is-active':''?>" href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php') . '?status=all', ENT_QUOTES, 'UTF-8')?>">All</a>
    </div>

    <section class="md-defaults-group md-pane is-active" id="departments" data-pane role="tabpanel" aria-labelledby="tab-departments">
      <div class="md-defaults-header">
        <span><?=htmlspecialchars(t($t,'department','Directorate'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=$statusFilter === 'active' ? $activeDepartmentCount : ($statusFilter === 'inactive' ? ($totalDepartmentCount - $activeDepartmentCount) : $totalDepartmentCount)?> <?=htmlspecialchars(t($t,'items','items'), ENT_QUOTES, 'UTF-8')?></span>
      </div>
      <div class="md-defaults-group-body">
        <form method="post" class="md-compact-actions"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="department_add"><label class="md-field"><span><?=t($t,'department','Directorate')?></span><input name="label" required></label><button type="submit" class="md-button md-primary"><?=t($t,'create','Create')?></button></form>
        <div class="md-search-block">
          <label class="md-field"><span><?=htmlspecialchars(t($t, 'search_catalog', 'Search this list'), ENT_QUOTES, 'UTF-8')?></span><input type="search" class="js-catalog-search" data-target="department" placeholder="<?=htmlspecialchars(t($t, 'search_department_placeholder', 'Search directorates'), ENT_QUOTES, 'UTF-8')?>"></label>
        </div>
        <div class="md-list">
          <div class="md-list-head md-list-department"><div>Directorate</div><div>Slug</div><div>Active</div><div>Actions</div></div>
            <?php foreach ($departments as $slug => $record): if (!$matchesStatusFilter($record['archived_at'] ?? null)) continue; ?>
              <div class="md-work-function-row md-list-row md-list-department" data-search-group="department" data-search-text="<?=htmlspecialchars(strtolower(trim($slug . ' ' . (string)($record['label'] ?? ''))), ENT_QUOTES, 'UTF-8')?>">
                <div class="md-list-col" data-label="Directorate"><?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?></div>
                <div class="md-list-col" data-label="Slug"><code><?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?></code></div>
                <div class="md-list-col" data-label="Active">
                  <form method="post" class="js-active-toggle-form">
                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                    <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
                    <input type="hidden" name="mode" value="<?=($record['archived_at'] ?? null) === null ? 'department_archive' : 'department_activate'?>">
                    <input type="checkbox" aria-label="Toggle active state for department <?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>" <?=($record['archived_at'] ?? null) === null ? 'checked' : ''?>>
                    <span class="md-saving-indicator" aria-live="polite">Saving…</span>
                  </form>
                </div>
                <div class="md-list-col" data-label="Actions">
                  <details>
                    <summary><?=htmlspecialchars(t($t,'manage','Manage'), ENT_QUOTES, 'UTF-8')?></summary>
                    <div class="md-inline-editor">
                      <form method="post" class="md-compact-actions">
                        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                        <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
                        <input type="hidden" name="mode" value="department_update">
                        <label class="md-field"><span><?=t($t,'department','Directorate')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
                        <button type="submit" class="md-button md-primary"><?=t($t,'save','Save Changes')?></button>
                      </form>
                    </div>
                  </details>
                </div>
              </div>
            <?php endforeach; ?>
        </div>
        <p class="md-search-empty" data-search-empty="department"><?=htmlspecialchars(t($t, 'search_no_results', 'No matching items found.'), ENT_QUOTES, 'UTF-8')?></p>
      </div>
    </section>

    <section class="md-defaults-group md-pane" id="teams" data-pane role="tabpanel" aria-labelledby="tab-teams">
      <div class="md-defaults-header">
        <span><?=htmlspecialchars(t($t,'team_catalog_title','Manage Teams in the Directorate'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=$statusFilter === 'active' ? $activeTeamCount : ($statusFilter === 'inactive' ? ($totalTeamCount - $activeTeamCount) : $totalTeamCount)?> <?=htmlspecialchars(t($t,'items','items'), ENT_QUOTES, 'UTF-8')?></span>
      </div>
      <div class="md-defaults-group-body">
        <form method="post" class="md-compact-actions"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="team_add"><label class="md-field"><span><?=t($t,'department','Directorate')?></span><select name="department_slug" required><?php foreach ($departmentOptions as $depSlug => $depLabel): ?><option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label><label class="md-field"><span><?=t($t,'team_catalog_label','Team name')?></span><input name="label" required></label><button type="submit" class="md-button md-primary"><?=t($t,'team_catalog_add','Add team')?></button></form>
        <div class="md-search-block">
          <label class="md-field"><span><?=htmlspecialchars(t($t, 'search_catalog', 'Search this list'), ENT_QUOTES, 'UTF-8')?></span><input type="search" class="js-catalog-search" data-target="team" placeholder="<?=htmlspecialchars(t($t, 'search_team_placeholder', 'Search teams'), ENT_QUOTES, 'UTF-8')?>"></label>
        </div>
        <div class="md-list"><div class="md-list-head md-list-team"><div>Team</div><div>Directorate</div><div>Slug</div><div>Active</div><div>Actions</div></div>
        <?php foreach ($teams as $slug => $record): if (!$matchesStatusFilter($record['archived_at'] ?? null)) continue; ?>
          <div class="md-work-function-row md-list-row md-list-team" data-search-group="team" data-search-text="<?=htmlspecialchars(strtolower(trim($slug . ' ' . (string)($record['label'] ?? '') . ' ' . (string)($allDepartmentOptions[$record['department_slug'] ?? ''] ?? ''))), ENT_QUOTES, 'UTF-8')?>">
            <div class="md-list-col" data-label="Team"><?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?></div>
            <div class="md-list-col" data-label="Directorate"><?=htmlspecialchars((string)($allDepartmentOptions[$record['department_slug'] ?? ''] ?? '—'), ENT_QUOTES, 'UTF-8')?></div>
            <div class="md-list-col" data-label="Slug"><code><?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?></code></div>
            <div class="md-list-col" data-label="Active">
              <form method="post" class="js-active-toggle-form">
                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
                <input type="hidden" name="mode" value="<?=($record['archived_at'] ?? null) === null ? 'team_archive' : 'team_activate'?>">
                <input type="checkbox" aria-label="Toggle active state for team <?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>" <?=($record['archived_at'] ?? null) === null ? 'checked' : ''?>>
                <span class="md-saving-indicator" aria-live="polite">Saving…</span>
              </form>
            </div>
            <div class="md-list-col" data-label="Actions">
              <details>
                <summary><?=htmlspecialchars(t($t,'manage','Manage'), ENT_QUOTES, 'UTF-8')?></summary>
                <div class="md-inline-editor">
                  <form method="post" class="md-compact-actions">
                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                    <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
                    <input type="hidden" name="mode" value="team_update">
                    <label class="md-field"><span><?=t($t,'team_catalog_label','Team name')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
                    <label class="md-field"><span><?=t($t,'department','Directorate')?></span><select name="department_slug" required><?php foreach ($allDepartmentOptions as $depSlug => $depLabel): ?><option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>" <?=$depSlug===($record['department_slug'] ?? '')?'selected':''?>><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?></select></label>
                    <button type="submit" class="md-button md-primary"><?=t($t,'save','Save Changes')?></button>
                  </form>
                </div>
              </details>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <p class="md-search-empty" data-search-empty="team"><?=htmlspecialchars(t($t, 'search_no_results', 'No matching items found.'), ENT_QUOTES, 'UTF-8')?></p>
      </div>
    </section>

    <section class="md-defaults-group md-pane" id="roles" data-pane role="tabpanel" aria-labelledby="tab-roles">
      <div class="md-defaults-header">
        <span><?=htmlspecialchars(t($t,'work_function','Work Role'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=$statusFilter === 'active' ? $activeWorkRoleCount : ($statusFilter === 'inactive' ? ($totalWorkRoleCount - $activeWorkRoleCount) : $totalWorkRoleCount)?> <?=htmlspecialchars(t($t,'items','items'), ENT_QUOTES, 'UTF-8')?></span>
      </div>
      <div class="md-defaults-group-body">
        <div class="md-search-block">
          <label class="md-field"><span><?=htmlspecialchars(t($t, 'search_catalog', 'Search this list'), ENT_QUOTES, 'UTF-8')?></span><input type="search" class="js-catalog-search" data-target="role" placeholder="<?=htmlspecialchars(t($t, 'search_work_role_placeholder', 'Search work roles'), ENT_QUOTES, 'UTF-8')?>"></label>
        </div>
        <div class="md-list"><div class="md-list-head md-list-role"><div>Work role</div><div>Slug</div><div>Active</div><div>Actions</div></div>
        <?php foreach ($workRoles as $slug => $record): if (!$matchesStatusFilter($record['archived_at'] ?? null)) continue; ?>
          <div class="md-work-function-row md-list-row md-list-role" data-search-group="role" data-search-text="<?=htmlspecialchars(strtolower(trim($slug . ' ' . (string)($record['label'] ?? ''))), ENT_QUOTES, 'UTF-8')?>">
            <div class="md-list-col" data-label="Work role"><?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?></div>
            <div class="md-list-col" data-label="Slug"><code><?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?></code></div>
            <div class="md-list-col" data-label="Active">
              <form method="post" class="js-active-toggle-form">
                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
                <input type="hidden" name="mode" value="<?=($record['archived_at'] ?? null) === null ? 'role_archive' : 'role_activate'?>">
                <input type="checkbox" aria-label="Toggle active state for work role <?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>" <?=($record['archived_at'] ?? null) === null ? 'checked' : ''?> <?=($record['archived_at'] ?? null) === null ? "data-confirm=\"".htmlspecialchars(t($t,'work_function_archive_confirm','Archive this work function? Existing assignments will be removed.'), ENT_QUOTES, 'UTF-8')."\"" : ''?>>
                <span class="md-saving-indicator" aria-live="polite">Saving…</span>
              </form>
            </div>
            <div class="md-list-col" data-label="Actions">
              <details>
                <summary><?=htmlspecialchars(t($t,'manage','Manage'), ENT_QUOTES, 'UTF-8')?></summary>
                <div class="md-inline-editor">
                  <form method="post" class="md-compact-actions">
                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                    <input type="hidden" name="slug" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>">
                    <input type="hidden" name="mode" value="role_update">
                    <label class="md-field"><span><?=t($t,'work_function_label_name','Work function name')?></span><input name="label" value="<?=htmlspecialchars((string)($record['label'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
                    <button type="submit" class="md-button md-primary"><?=t($t,'save','Save Changes')?></button>
                  </form>
                </div>
              </details>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <p class="md-search-empty" data-search-empty="role"><?=htmlspecialchars(t($t, 'search_no_results', 'No matching items found.'), ENT_QUOTES, 'UTF-8')?></p>
      </div>
    </section>


    <section class="md-defaults-group md-pane" id="catalog-sync" data-pane role="tabpanel" aria-labelledby="tab-catalog-sync">
      <div class="md-defaults-header">
        <span><?=htmlspecialchars(t($t,'catalog_sync_title','Department/team catalog sync'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=count($departments)?> directorates · <?=count($teams)?> teams</span>
      </div>
      <div class="md-defaults-group-body">
        <p><?=htmlspecialchars(t($t, 'catalog_sync_help', 'Export department and team catalog data from one instance, preview it on another instance, then apply it as non-destructive upserts. The Apply import button appears only after a valid preview.'), ENT_QUOTES, 'UTF-8')?></p>
        <div class="md-alert info"><p><?=htmlspecialchars(t($t, 'catalog_sync_format_help', 'Use the exported JSON when possible. Imports also normalize mixed-case names and labels into safe slugs, but every team must include a department_slug or department label that matches a department in the same file.'), ENT_QUOTES, 'UTF-8')?></p></div>
        <div class="md-compact-actions" style="margin-bottom:.8rem;">
          <a class="md-button md-outline" href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php') . '?action=export_department_catalog', ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(t($t, 'catalog_sync_export', 'Export catalog JSON'), ENT_QUOTES, 'UTF-8')?></a>
        </div>
        <form method="post" enctype="multipart/form-data" class="md-compact-actions" style="align-items:flex-end; margin-bottom:1rem; padding:.8rem; border:1px solid rgba(0,0,0,.08); border-radius:8px;">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="mode" value="catalog_import_preview">
          <label class="md-field"><span><?=htmlspecialchars(t($t, 'catalog_sync_file', 'Catalog JSON file'), ENT_QUOTES, 'UTF-8')?></span><input type="file" name="catalog_file" accept="application/json,.json" required></label>
          <label style="display:inline-flex; gap:.35rem; align-items:center; margin-bottom:.5rem;"><input type="checkbox" name="archive_missing" value="1"> <span><?=htmlspecialchars(t($t, 'catalog_sync_archive_missing', 'Archive live rows missing from the import'), ENT_QUOTES, 'UTF-8')?></span></label>
          <button type="submit" class="md-button md-primary"><?=htmlspecialchars(t($t, 'catalog_sync_preview', 'Preview import'), ENT_QUOTES, 'UTF-8')?></button>
        </form>
        <?php if (!is_array($catalogSyncPreview)): ?>
          <p><em><?=htmlspecialchars(t($t, 'catalog_sync_apply_waiting', 'No import is ready to apply yet. Upload a file and run Preview import first.'), ENT_QUOTES, 'UTF-8')?></em></p>
        <?php endif; ?>
        <?php if (is_array($catalogSyncPreview)): $changes = $catalogSyncPreview['changes']; ?>
          <div class="md-alert success"><p><?=htmlspecialchars(t($t, 'catalog_sync_preview_ready', 'Preview ready. Review the changes below before applying.'), ENT_QUOTES, 'UTF-8')?></p></div>
          <div class="md-table-wrap">
            <table class="md-table">
              <thead><tr><th>Catalog</th><th>Create</th><th>Update</th><th>Archive missing</th><th>Unchanged</th></tr></thead>
              <tbody>
                <tr><td>Directorates</td><td><?=count($changes['departments']['create'])?></td><td><?=count($changes['departments']['update'])?></td><td><?=count($changes['departments']['archive_missing'])?></td><td><?=count($changes['departments']['unchanged'])?></td></tr>
                <tr><td>Teams</td><td><?=count($changes['teams']['create'])?></td><td><?=count($changes['teams']['update'])?></td><td><?=count($changes['teams']['archive_missing'])?></td><td><?=count($changes['teams']['unchanged'])?></td></tr>
              </tbody>
            </table>
          </div>
          <form method="post" style="margin-top:1rem;">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="mode" value="catalog_import_apply">
            <input type="hidden" name="preview_token" value="<?=htmlspecialchars($catalogSyncPreviewToken, ENT_QUOTES, 'UTF-8')?>">
            <p><?=htmlspecialchars(t($t, 'catalog_sync_decision_help', 'Review each mapped row. Existing/live values are on the left, incoming/imported values are on the right, and the Decision column controls what will be applied.'), ENT_QUOTES, 'UTF-8')?></p>
            <?php $decisionSections = [
              ['title' => 'Directorate updates', 'group' => 'departments', 'type' => 'department', 'action' => 'update', 'rows' => $changes['departments']['update'], 'default' => 'overwrite', 'options' => ['overwrite' => 'Overwrite with incoming', 'keep' => 'Keep existing']],
              ['title' => 'New directorates', 'group' => 'departments', 'type' => 'department', 'action' => 'create', 'rows' => $changes['departments']['create'], 'default' => 'create', 'options' => ['create' => 'Create', 'ignore' => 'Ignore']],
              ['title' => 'Missing directorates', 'group' => 'departments', 'type' => 'department', 'action' => 'archive_missing', 'rows' => $changes['departments']['archive_missing'], 'default' => 'archive', 'options' => ['archive' => 'Archive', 'keep' => 'Keep existing']],
              ['title' => 'Team updates', 'group' => 'teams', 'type' => 'team', 'action' => 'update', 'rows' => $changes['teams']['update'], 'default' => 'overwrite', 'options' => ['overwrite' => 'Overwrite with incoming', 'keep' => 'Keep existing']],
              ['title' => 'New teams', 'group' => 'teams', 'type' => 'team', 'action' => 'create', 'rows' => $changes['teams']['create'], 'default' => 'create', 'options' => ['create' => 'Create', 'ignore' => 'Ignore']],
              ['title' => 'Missing teams', 'group' => 'teams', 'type' => 'team', 'action' => 'archive_missing', 'rows' => $changes['teams']['archive_missing'], 'default' => 'archive', 'options' => ['archive' => 'Archive', 'keep' => 'Keep existing']],
            ]; ?>
            <?php foreach ($decisionSections as $section): if (count($section['rows']) === 0) continue; ?>
              <h4><?=htmlspecialchars((string)$section['title'], ENT_QUOTES, 'UTF-8')?></h4>
              <div class="md-table-wrap" style="margin-bottom:1rem;">
                <table class="md-table">
                  <thead><tr><th>Existing/live</th><th>Incoming/imported</th><th>Decision</th></tr></thead>
                  <tbody>
                  <?php foreach ($section['rows'] as $row):
                    $slug = (string)($row['slug'] ?? '');
                    $existing = $section['action'] === 'update' ? ($row['from'] ?? []) : ($row['from'] ?? []);
                    $incoming = $section['action'] === 'update' ? ($row['to'] ?? []) : ($section['action'] === 'create' ? $row : []);
                    if ($slug === '' && isset($incoming['slug'])) $slug = (string)$incoming['slug'];
                  ?>
                    <tr>
                      <td><?=htmlspecialchars($existing ? $catalogSyncRecordSummary($existing, (string)$section['type']) : '—', ENT_QUOTES, 'UTF-8')?></td>
                      <td><?=htmlspecialchars($incoming ? $catalogSyncRecordSummary($incoming, (string)$section['type']) : '—', ENT_QUOTES, 'UTF-8')?></td>
                      <td>
                        <select name="catalog_decisions[<?=htmlspecialchars((string)$section['group'], ENT_QUOTES, 'UTF-8')?>][<?=htmlspecialchars((string)$section['action'], ENT_QUOTES, 'UTF-8')?>][<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>]">
                          <?php foreach ($section['options'] as $value => $label): ?>
                            <option value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=$value===$section['default']?'selected':''?>><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>
            <button type="submit" class="md-button md-primary"><?=htmlspecialchars(t($t, 'catalog_sync_apply', 'Apply selected changes'), ENT_QUOTES, 'UTF-8')?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="md-defaults-group md-pane" id="defaults" data-pane role="tabpanel" aria-labelledby="tab-defaults">
      <div class="md-defaults-header">
        <span><?=htmlspecialchars(t($t,'assignment_overview','Directorate questionnaire defaults'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=count($questionnaires)?> <?=htmlspecialchars(t($t,'questionnaires','Questionnaires'), ENT_QUOTES, 'UTF-8')?></span>
      </div>
      <div class="md-defaults-group-body md-assignment-picker">
        <form method="post" class="md-compact-actions" style="margin-bottom:.8rem; padding:.65rem; border:1px solid rgba(0,0,0,.08); border-radius:8px;">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="mode" value="assignments_bulk_clone">
          <label class="md-field">
            <span><?=htmlspecialchars(t($t,'bulk_clone_from','Copy assignments from'), ENT_QUOTES, 'UTF-8')?></span>
            <select name="source_department" required>
              <option value=""><?=htmlspecialchars(t($t,'select','Select'), ENT_QUOTES, 'UTF-8')?></option>
              <?php foreach ($departmentOptions as $depSlug => $depLabel): ?>
                <option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="md-field" style="flex:2 1 380px;">
            <span><?=htmlspecialchars(t($t,'bulk_clone_to','Apply to directorates'), ENT_QUOTES, 'UTF-8')?></span>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem; max-height:120px; overflow:auto; padding:.35rem; border:1px solid rgba(0,0,0,.1); border-radius:6px;">
              <?php foreach ($departmentOptions as $depSlug => $depLabel): ?>
                <label style="display:inline-flex; align-items:center; gap:.25rem;">
                  <input type="checkbox" name="target_departments[]" value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>">
                  <span><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <button type="submit" class="md-button md-outline"><?=htmlspecialchars(t($t,'copy_assignments','Copy Assignments'), ENT_QUOTES, 'UTF-8')?></button>
        </form>
        <form method="post" class="md-compact-actions">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="assignments_save">
          <div class="md-table-wrap" style="width:100%;">
            <table class="md-table">
              <thead><tr><th>Directorate</th><th>Questionnaires</th><th>Selected</th></tr></thead>
              <tbody>
              <?php foreach ($departmentOptions as $depSlug => $depLabel): ?>
                <tr>
                  <td><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></td>
                  <td>
                    <div class="md-assignment-options" data-assignment-options>
                      <?php foreach ($questionnaires as $q): $qid=(int)$q['id']; ?>
                        <label>
                          <input type="checkbox" name="assignments[<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>][]" value="<?=$qid?>" <?=isset($assignments[$depSlug][$qid])?'checked':''?>>
                          <span><?=htmlspecialchars((string)($q['title'] ?: t($t,'untitled_questionnaire','Untitled questionnaire')), ENT_QUOTES, 'UTF-8')?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </td>
                  <td data-selected-count><?= (int)($assignmentCounts[$depSlug] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="submit" class="md-button md-primary"><?=t($t,'save','Save Changes')?></button>
        </form>
      </div>
    </section>

    <section class="md-defaults-group md-pane" id="team-defaults" data-pane role="tabpanel" aria-labelledby="tab-team-defaults">
      <div class="md-defaults-header">
        <span><?=htmlspecialchars(t($t,'team_questionnaire_defaults','Team questionnaire defaults'), ENT_QUOTES, 'UTF-8')?></span>
        <span class="md-defaults-meta"><?=count($questionnaires)?> <?=htmlspecialchars(t($t,'questionnaires','Questionnaires'), ENT_QUOTES, 'UTF-8')?></span>
      </div>
      <div class="md-defaults-group-body md-assignment-picker">
        <form method="post" class="md-compact-actions" style="margin-bottom:.8rem; padding:.65rem; border:1px solid rgba(0,0,0,.08); border-radius:8px;">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="mode" value="team_assignments_clone_department">
          <label class="md-field">
            <span><?=htmlspecialchars(t($t,'copy_department_assignments_to_teams','Copy directorate assignments to teams in'), ENT_QUOTES, 'UTF-8')?></span>
            <select name="source_department" required>
              <option value=""><?=htmlspecialchars(t($t,'select','Select'), ENT_QUOTES, 'UTF-8')?></option>
              <?php foreach ($departmentOptions as $depSlug => $depLabel): ?>
                <option value="<?=htmlspecialchars($depSlug, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="md-button md-outline"><?=htmlspecialchars(t($t,'copy_assignments','Copy Assignments'), ENT_QUOTES, 'UTF-8')?></button>
        </form>
        <form method="post" class="md-compact-actions">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="mode" value="team_assignments_save">
          <div class="md-table-wrap" style="width:100%;">
            <table class="md-table">
              <thead><tr><th>Directorate</th><th>Team</th><th>Questionnaires</th><th>Selected</th></tr></thead>
              <tbody>
              <?php foreach ($teamsByDepartment as $depSlug => $departmentTeams): ?>
                <?php foreach ($departmentTeams as $teamSlug => $teamRecord): ?>
                  <tr>
                    <td><?=htmlspecialchars($departmentOptions[$depSlug] ?? $depSlug, ENT_QUOTES, 'UTF-8')?></td>
                    <td><?=htmlspecialchars((string)($teamRecord['label'] ?? $teamSlug), ENT_QUOTES, 'UTF-8')?></td>
                    <td>
                      <div class="md-assignment-options" data-assignment-options>
                        <?php foreach ($questionnaires as $q): $qid=(int)$q['id']; ?>
                          <label>
                            <input type="checkbox" name="team_assignments[<?=htmlspecialchars($teamSlug, ENT_QUOTES, 'UTF-8')?>][]" value="<?=$qid?>" <?=isset($teamAssignments[$teamSlug][$qid])?'checked':''?>>
                            <span><?=htmlspecialchars((string)($q['title'] ?: t($t,'untitled_questionnaire','Untitled questionnaire')), ENT_QUOTES, 'UTF-8')?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </td>
                    <td data-selected-count><?= (int)($teamAssignmentCounts[$teamSlug] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="submit" class="md-button md-primary"><?=t($t,'save','Save Changes')?></button>
        </form>
      </div>
    </section>
  </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  document.addEventListener('DOMContentLoaded', function () {
    var activePaneId = <?=json_encode($initialPane)?>;
    var tabLinks = document.querySelectorAll('.md-tab-chip');
    var panes = document.querySelectorAll('[data-pane]');
    function showPaneById(paneId) {
      var hasMatch = false;
      panes.forEach(function (pane) {
        var isMatch = pane.id === paneId;
        pane.classList.toggle('is-active', isMatch);
        if (isMatch) hasMatch = true;
      });
      tabLinks.forEach(function (link) {
        var isActiveTab = link.getAttribute('data-tab-target') === paneId;
        link.classList.toggle('is-active', isActiveTab);
        link.setAttribute('aria-selected', isActiveTab ? 'true' : 'false');
      });
      activePaneId = paneId;
      return hasMatch;
    }
    tabLinks.forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var paneId = link.getAttribute('data-tab-target');
        if (!paneId) return;
        showPaneById(paneId);
        if (history.replaceState) history.replaceState(null, '', '#' + paneId);
      });
    });
    var paneFromHash = window.location.hash ? window.location.hash.replace('#', '') : '';
    if (!paneFromHash || !showPaneById(paneFromHash)) {
      showPaneById(activePaneId || 'departments');
    }
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
    var activeToggles = document.querySelectorAll('.js-active-toggle-form input[type="checkbox"]');
    activeToggles.forEach(function (checkbox) {
      checkbox.addEventListener('change', function (event) {
        var confirmMsg = checkbox.getAttribute('data-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) {
          event.preventDefault();
          checkbox.checked = true;
          return;
        }
        checkbox.disabled = true;
        var row = checkbox.closest('.md-list-row');
        if (row) {
          row.classList.add('is-saving');
        }
        checkbox.form.submit();
      });
    });
    var postForms = document.querySelectorAll('form[method="post"]');
    postForms.forEach(function (form) {
      form.addEventListener('submit', function () {
        if (form.classList.contains('is-saving')) {
          return;
        }
        var input = form.querySelector('input[name="current_tab"]');
        if (!input) {
          input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'current_tab';
          form.appendChild(input);
        }
        input.value = activePaneId || 'departments';
        form.classList.add('is-saving');
        var savingMessage = form.querySelector('.md-form-saving');
        if (!savingMessage) {
          savingMessage = document.createElement('span');
          savingMessage.className = 'md-form-saving';
          savingMessage.setAttribute('aria-live', 'polite');
          savingMessage.textContent = 'Saving…';
          form.appendChild(savingMessage);
        }
        var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        submitButtons.forEach(function (button) {
          button.disabled = true;
        });
      });
    });
    var assignmentTables = document.querySelectorAll('.md-assignment-picker table');
    assignmentTables.forEach(function (assignmentTable) {
      var syncSelectedCounts = function () {
        var rows = assignmentTable.querySelectorAll('tbody tr');
        rows.forEach(function (row) {
          var countCell = row.querySelector('[data-selected-count]');
          if (!countCell) return;
          var checked = row.querySelectorAll('[data-assignment-options] input[type="checkbox"]:checked').length;
          countCell.textContent = String(checked);
        });
      };
      assignmentTable.addEventListener('change', function (event) {
        var target = event.target;
        if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox') return;
        syncSelectedCounts();
      });
      syncSelectedCounts();
    });
  });
</script>
</body></html>
