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

$departmentChoices = department_options($pdo);
$assignmentsByDepartment = [];
try {
    $assignmentStmt = $pdo->query("SELECT qd.department_slug, q.id, q.title, q.description FROM questionnaire_department qd JOIN questionnaire q ON q.id = qd.questionnaire_id WHERE q.status='published' ORDER BY qd.department_slug ASC, q.title ASC");
    if ($assignmentStmt) {
        foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dep = trim((string)($row['department_slug'] ?? ''));
            if ($dep === '') {
                continue;
            }
            $assignmentsByDepartment[$dep][] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => trim((string)($row['title'] ?? '')),
                'description' => trim((string)($row['description'] ?? '')),
            ];
        }
    }
} catch (PDOException $e) {
    error_log('questionnaire_assignments default fetch failed: ' . $e->getMessage());
}


if (!$assignmentsByDepartment) {
    try {
        $legacyStmt = $pdo->query("SELECT qwf.work_function, q.id, q.title, q.description FROM questionnaire_work_function qwf JOIN questionnaire q ON q.id = qwf.questionnaire_id WHERE q.status='published' ORDER BY qwf.work_function ASC, q.title ASC");
        if ($legacyStmt) {
            foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dep = resolve_department_slug($pdo, (string)($row['work_function'] ?? ''));
                if ($dep === '') {
                    continue;
                }
                $assignmentsByDepartment[$dep][] = [
                    'id' => (int)($row['id'] ?? 0),
                    'title' => trim((string)($row['title'] ?? '')),
                    'description' => trim((string)($row['description'] ?? '')),
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('questionnaire_assignments legacy fallback failed: ' . $e->getMessage());
    }
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
    <p>
      <a href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(t($t,'work_function_defaults_title','Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></a>
    </p>

    <table class="md-table">
      <thead>
        <tr>
          <th><?=t($t,'department','Department')?></th>
          <th><?=t($t,'questionnaires','Questionnaires')?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($departmentChoices as $depSlug => $depLabel): ?>
        <?php $items = $assignmentsByDepartment[$depSlug] ?? []; ?>
        <tr>
          <td><?=htmlspecialchars($depLabel, ENT_QUOTES, 'UTF-8')?></td>
          <td>
            <?php if (!$items): ?>
              <span class="md-muted"><?=t($t,'assignment_no_defaults_for_function','No questionnaires are assigned yet.')?></span>
            <?php else: ?>
              <ul>
                <?php foreach ($items as $item): ?>
                  <li><strong><?=htmlspecialchars($item['title'] ?: t($t,'untitled_questionnaire','Untitled questionnaire'), ENT_QUOTES, 'UTF-8')?></strong></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
</body>
</html>
