<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/course_recommendations.php';
if (!function_exists('work_function_definitions')) {
    require_once __DIR__ . '/../lib/work_functions.php';
}

auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

ensure_course_recommendation_schema($pdo);
$workFunctions = work_function_definitions($pdo);
$errors = [];
$msg = $_SESSION['course_mapping_flash'] ?? '';
unset($_SESSION['course_mapping_flash']);

$questionnairesStmt = $pdo->query("SELECT id, title FROM questionnaire WHERE status='published' ORDER BY title ASC");
$questionnaires = $questionnairesStmt ? $questionnairesStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$questionnaireOptions = [];
foreach ($questionnaires as $questionnaire) {
    $qid = (int)($questionnaire['id'] ?? 0);
    if ($qid <= 0) {
        continue;
    }
    $questionnaireOptions[$qid] = (string)($questionnaire['title'] ?? ('#' . $qid));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $code = trim((string)($_POST['code'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $courseObjective = trim((string)($_POST['course_objective'] ?? ''));
        $expectedCompetency = trim((string)($_POST['expected_competency'] ?? ''));
        $thematicArea = trim((string)($_POST['thematic_area'] ?? ''));
        $modeOfDelivery = trim((string)($_POST['mode_of_delivery'] ?? ''));
        $duration = trim((string)($_POST['duration'] ?? ''));
        $ceu = trim((string)($_POST['ceu'] ?? ''));
        $courseOwner = trim((string)($_POST['course_owner'] ?? ''));
        $moodleUrl = trim((string)($_POST['moodle_url'] ?? ''));
        $recommendedFor = trim((string)($_POST['recommended_for'] ?? ''));
        $questionnaireId = (int)($_POST['questionnaire_id'] ?? 0);
        $questionnaireIdValue = $questionnaireId > 0 ? $questionnaireId : null;
        $minScore = max(0, min(100, (int)($_POST['min_score'] ?? 0)));
        $maxScore = max(0, min(100, (int)($_POST['max_score'] ?? 100)));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($code === '' || $title === '') {
            $errors[] = t($t, 'course_mapping_error_required', 'Code and title are required.');
        }
        if (!isset($workFunctions[$recommendedFor])) {
            $errors[] = t($t, 'course_mapping_error_work_function', 'Select a valid work function.');
        }
        if ($questionnaireIdValue !== null && !isset($questionnaireOptions[$questionnaireIdValue])) {
            $errors[] = t($t, 'course_mapping_error_questionnaire', 'Select a valid questionnaire or choose All questionnaires.');
        }
        if ($minScore > $maxScore) {
            $errors[] = t($t, 'course_mapping_error_score_range', 'Minimum score must be less than or equal to maximum score.');
        }

        if ($errors === []) {
            $stmt = $pdo->prepare(
                'INSERT INTO course_catalogue (code, title, course_objective, expected_competency, thematic_area, mode_of_delivery, duration, ceu, course_owner, moodle_url, recommended_for, questionnaire_id, min_score, max_score, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$code, $title, $courseObjective, $expectedCompetency, $thematicArea, $modeOfDelivery, $duration, $ceu, $courseOwner, $moodleUrl !== '' ? $moodleUrl : null, $recommendedFor, $questionnaireIdValue, $minScore, $maxScore, $isActive]);
            $_SESSION['course_mapping_flash'] = t($t, 'course_mapping_saved', 'Course mapping saved.');
            header('Location: ' . url_for('admin/course_mappings.php'));
            exit;
        }
    }

    if ($action === 'update') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        $code = trim((string)($_POST['code'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $courseObjective = trim((string)($_POST['course_objective'] ?? ''));
        $expectedCompetency = trim((string)($_POST['expected_competency'] ?? ''));
        $thematicArea = trim((string)($_POST['thematic_area'] ?? ''));
        $modeOfDelivery = trim((string)($_POST['mode_of_delivery'] ?? ''));
        $duration = trim((string)($_POST['duration'] ?? ''));
        $ceu = trim((string)($_POST['ceu'] ?? ''));
        $courseOwner = trim((string)($_POST['course_owner'] ?? ''));
        $moodleUrl = trim((string)($_POST['moodle_url'] ?? ''));
        $recommendedFor = trim((string)($_POST['recommended_for'] ?? ''));
        $questionnaireId = (int)($_POST['questionnaire_id'] ?? 0);
        $questionnaireIdValue = $questionnaireId > 0 ? $questionnaireId : null;
        $minScore = max(0, min(100, (int)($_POST['min_score'] ?? 0)));
        $maxScore = max(0, min(100, (int)($_POST['max_score'] ?? 100)));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($courseId <= 0) {
            $errors[] = t($t, 'course_mapping_error_not_found', 'Select a valid course mapping.');
        }
        if ($code === '' || $title === '') {
            $errors[] = t($t, 'course_mapping_error_required', 'Code and title are required.');
        }
        if (!isset($workFunctions[$recommendedFor])) {
            $errors[] = t($t, 'course_mapping_error_work_function', 'Select a valid work function.');
        }
        if ($questionnaireIdValue !== null && !isset($questionnaireOptions[$questionnaireIdValue])) {
            $errors[] = t($t, 'course_mapping_error_questionnaire', 'Select a valid questionnaire or choose All questionnaires.');
        }
        if ($minScore > $maxScore) {
            $errors[] = t($t, 'course_mapping_error_score_range', 'Minimum score must be less than or equal to maximum score.');
        }

        if ($errors === []) {
            $pdo->prepare('UPDATE course_catalogue SET code = ?, title = ?, course_objective = ?, expected_competency = ?, thematic_area = ?, mode_of_delivery = ?, duration = ?, ceu = ?, course_owner = ?, moodle_url = ?, recommended_for = ?, questionnaire_id = ?, min_score = ?, max_score = ?, is_active = ? WHERE id = ?')
                ->execute([$code, $title, $courseObjective, $expectedCompetency, $thematicArea, $modeOfDelivery, $duration, $ceu, $courseOwner, $moodleUrl !== '' ? $moodleUrl : null, $recommendedFor, $questionnaireIdValue, $minScore, $maxScore, $isActive, $courseId]);
            $_SESSION['course_mapping_flash'] = t($t, 'course_mapping_updated', 'Course mapping updated.');
            header('Location: ' . url_for('admin/course_mappings.php'));
            exit;
        }
    }

    if ($action === 'delete') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if ($courseId > 0) {
            $pdo->prepare('DELETE FROM course_catalogue WHERE id = ?')->execute([$courseId]);
            $_SESSION['course_mapping_flash'] = t($t, 'course_mapping_deleted', 'Course mapping removed.');
            header('Location: ' . url_for('admin/course_mappings.php'));
            exit;
        }
    }
}

$listStmt = $pdo->query(
    'SELECT id, code, title, course_objective, expected_competency, thematic_area, mode_of_delivery, duration, ceu, course_owner, moodle_url, recommended_for, questionnaire_id, min_score, max_score, is_active '
    . 'FROM course_catalogue ORDER BY recommended_for, questionnaire_id IS NULL, questionnaire_id, min_score, title'
);
$mappings = $listStmt ? $listStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$pageHelpKey = 'admin.course_mappings';
$drawerKey = 'admin.course_mappings';
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t, 'course_mapping_title', 'Moodle Course Mapping'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'course_mapping_title', 'Moodle Course Mapping')?></h2>
    <p><?=t($t, 'course_mapping_summary', 'Map questionnaire + score ranges to external Moodle courses.')?></p>
    <p class="md-muted"><?=t($t, 'course_mapping_summary_tiers', 'Tip: create multiple rows per questionnaire (basic, advanced, optional advanced) using different score bands.')?></p>

    <?php if ($msg): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?>
      <div class="md-alert warning"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div>
    <?php endforeach; ?>

    <form method="post" class="md-form-grid">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="create">
      <label><?=t($t, 'course_code', 'Course Code')?>
        <input class="md-input" type="text" name="code" required>
      </label>
      <label><?=t($t, 'course', 'Course')?>
        <input class="md-input" type="text" name="title" required>
      </label>
      <label><?=t($t, 'thematic_area', 'Thematic Area')?>
        <input class="md-input" type="text" name="thematic_area">
      </label>
      <label><?=t($t, 'mode_of_delivery', 'Mode of Delivery')?>
        <input class="md-input" type="text" name="mode_of_delivery">
      </label>
      <label><?=t($t, 'duration', 'Duration')?>
        <input class="md-input" type="text" name="duration">
      </label>
      <label><?=t($t, 'ceu', 'CEU')?>
        <input class="md-input" type="text" name="ceu">
      </label>
      <label><?=t($t, 'course_owner', 'Course Owner')?>
        <input class="md-input" type="text" name="course_owner">
      </label>
      <label style="grid-column:1/-1;"><?=t($t, 'course_objective', 'Course Objective')?>
        <textarea class="md-input" name="course_objective" rows="3"></textarea>
      </label>
      <label style="grid-column:1/-1;"><?=t($t, 'expected_competency', 'Expected Competency')?>
        <textarea class="md-input" name="expected_competency" rows="3"></textarea>
      </label>
      <label><?=t($t, 'moodle_url', 'Moodle URL')?>
        <input class="md-input" type="url" name="moodle_url" placeholder="https://moodle.example/course/view.php?id=123">
      </label>
      <label><?=t($t, 'work_function', 'Work Function')?>
        <select class="md-select" name="recommended_for" required>
          <option value=""><?=t($t, 'select', 'Select')?></option>
          <?php foreach ($workFunctions as $slug => $label): ?>
            <option value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?=t($t, 'questionnaire', 'Questionnaire')?>
        <select class="md-select" name="questionnaire_id">
          <option value="0"><?=t($t, 'all_questionnaires', 'All Questionnaires')?></option>
          <?php foreach ($questionnaireOptions as $qid => $qTitle): ?>
            <option value="<?= (int)$qid ?>"><?=htmlspecialchars($qTitle, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?=t($t, 'min_score', 'Minimum Score')?>
        <input class="md-input" type="number" name="min_score" min="0" max="100" value="0" required>
      </label>
      <label><?=t($t, 'max_score', 'Maximum Score')?>
        <input class="md-input" type="number" name="max_score" min="0" max="100" value="100" required>
      </label>
      <label class="md-check">
        <input type="checkbox" name="is_active" value="1" checked> <?=t($t, 'active', 'Active')?>
      </label>
      <button type="submit" class="md-button"><?=t($t, 'save', 'Save')?></button>
    </form>
  </div>

  <div class="md-card md-elev-2">
    <h3 class="md-card-title"><?=t($t, 'configured_course_mappings', 'Configured Mappings')?></h3>
    <table class="md-table">
      <thead><tr><th><?=t($t, 'course_code', 'Course Code')?></th><th><?=t($t, 'course', 'Course')?></th><th><?=t($t, 'course_details', 'Course Details')?></th><th><?=t($t, 'delivery', 'Delivery')?></th><th><?=t($t, 'work_function', 'Work Function')?></th><th><?=t($t, 'questionnaire', 'Questionnaire')?></th><th><?=t($t, 'score_band', 'Score Band')?></th><th><?=t($t, 'status', 'Status')?></th><th><?=t($t, 'moodle_url', 'Moodle URL')?></th><th><?=t($t, 'actions', 'Actions')?></th></tr></thead>
      <tbody>
      <?php foreach ($mappings as $row): ?>
        <?php $rowQuestionnaireId = (int)($row['questionnaire_id'] ?? 0); ?>
        <tr>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="course_id" value="<?= (int)$row['id'] ?>">
            <td><input class="md-input" type="text" name="code" value="<?=htmlspecialchars((string)$row['code'], ENT_QUOTES, 'UTF-8')?>" required></td>
            <td><input class="md-input" type="text" name="title" value="<?=htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8')?>" required></td>
            <td>
              <label class="md-muted"><?=t($t, 'thematic_area', 'Thematic Area')?><input class="md-input" type="text" name="thematic_area" value="<?=htmlspecialchars((string)($row['thematic_area'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
              <label class="md-muted"><?=t($t, 'course_objective', 'Course Objective')?><textarea class="md-input" name="course_objective" rows="3"><?=htmlspecialchars((string)($row['course_objective'] ?? ''), ENT_QUOTES, 'UTF-8')?></textarea></label>
              <label class="md-muted"><?=t($t, 'expected_competency', 'Expected Competency')?><textarea class="md-input" name="expected_competency" rows="3"><?=htmlspecialchars((string)($row['expected_competency'] ?? ''), ENT_QUOTES, 'UTF-8')?></textarea></label>
            </td>
            <td>
              <label class="md-muted"><?=t($t, 'mode_of_delivery', 'Mode of Delivery')?><input class="md-input" type="text" name="mode_of_delivery" value="<?=htmlspecialchars((string)($row['mode_of_delivery'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
              <label class="md-muted"><?=t($t, 'duration', 'Duration')?><input class="md-input" type="text" name="duration" value="<?=htmlspecialchars((string)($row['duration'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
              <label class="md-muted"><?=t($t, 'ceu', 'CEU')?><input class="md-input" type="text" name="ceu" value="<?=htmlspecialchars((string)($row['ceu'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
              <label class="md-muted"><?=t($t, 'course_owner', 'Course Owner')?><input class="md-input" type="text" name="course_owner" value="<?=htmlspecialchars((string)($row['course_owner'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></label>
            </td>
            <td>
              <select class="md-select" name="recommended_for" required>
                <?php foreach ($workFunctions as $slug => $label): ?>
                  <option value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')?>" <?=((string)$row['recommended_for'] === (string)$slug ? 'selected' : '')?>><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select class="md-select" name="questionnaire_id">
                <option value="0" <?=($rowQuestionnaireId <= 0 ? 'selected' : '')?>><?=t($t, 'all_questionnaires', 'All Questionnaires')?></option>
                <?php foreach ($questionnaireOptions as $qid => $qTitle): ?>
                  <option value="<?= (int)$qid ?>" <?=($rowQuestionnaireId === (int)$qid ? 'selected' : '')?>><?=htmlspecialchars($qTitle, ENT_QUOTES, 'UTF-8')?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <input class="md-input" style="width:70px" type="number" name="min_score" min="0" max="100" value="<?= (int)$row['min_score'] ?>" required>
              -
              <input class="md-input" style="width:70px" type="number" name="max_score" min="0" max="100" value="<?= (int)$row['max_score'] ?>" required>%
            </td>
            <td>
              <label class="md-check"><input type="checkbox" name="is_active" value="1" <?=((int)($row['is_active'] ?? 1) === 1 ? 'checked' : '')?>> <?=((int)($row['is_active'] ?? 1) === 1 ? t($t, 'active', 'Active') : t($t, 'inactive', 'Inactive'))?></label>
            </td>
            <td><input class="md-input" type="url" name="moodle_url" value="<?=htmlspecialchars((string)($row['moodle_url'] ?? ''), ENT_QUOTES, 'UTF-8')?>"></td>
            <td>
              <button type="submit" class="md-button md-outline"><?=t($t, 'save', 'Save')?></button>
          </form>
            <form method="post" onsubmit="return confirm('<?=htmlspecialchars(t($t, 'confirm_delete_mapping', 'Delete this mapping?'), ENT_QUOTES, 'UTF-8')?>');" style="margin-top:8px;">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="course_id" value="<?= (int)$row['id'] ?>">
              <button type="submit" class="md-button md-outline danger"><?=t($t, 'delete', 'Delete')?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
</body></html>
