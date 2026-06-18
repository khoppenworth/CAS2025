<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/performance_sections.php';
require_once __DIR__ . '/lib/course_recommendations.php';
require_once __DIR__ . '/lib/secure_links.php';
auth_required(['staff','supervisor','admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$userWorkFunctionLabel = work_function_label($pdo, (string)($user['work_function'] ?? ''));
ensure_course_recommendation_schema($pdo);

$performanceDownloadUrl = url_for('my_performance_download.php');
if ($userId > 0) {
    try {
        $performanceDownloadUrl = secure_links_build_url(
            $pdo,
            'performance_pdf',
            ['user_id' => $userId],
            $userId,
            900
        );
    } catch (Throwable $secureLinkError) {
        error_log('my_performance secure link generation failed: ' . $secureLinkError->getMessage());
    }
}

$hasPeriodStartColumn = false;
try {
    $periodColumnsStmt = $pdo->query('SHOW COLUMNS FROM performance_period');
    $periodColumns = $periodColumnsStmt ? $periodColumnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($periodColumns as $periodColumn) {
        if (strcasecmp((string)($periodColumn['Field'] ?? ''), 'period_start') === 0) {
            $hasPeriodStartColumn = true;
            break;
        }
    }
} catch (Throwable $periodSchemaError) {
    error_log('my_performance performance_period schema lookup failed: ' . $periodSchemaError->getMessage());
}

$periodStartSelect = $hasPeriodStartColumn ? 'pp.period_start' : 'NULL AS period_start';

function resolve_timeline_label(array $row): string
{
    $periodStart = $row['period_start'] ?? null;
    if ($periodStart) {
        $startTime = strtotime((string)$periodStart);
        if ($startTime) {
            return date('Y', $startTime);
        }
    }

    $periodLabel = trim((string)($row['period_label'] ?? ''));
    if ($periodLabel !== '') {
        if (preg_match('/(20\d{2}|19\d{2})/u', $periodLabel, $matches)) {
            return $matches[1];
        }

        return $periodLabel;
    }

    $createdAt = (string)($row['created_at'] ?? '');
    $createdTime = strtotime($createdAt);
    if ($createdTime) {
        return date('Y', $createdTime);
    }

    return $createdAt;
}

function resolve_questionnaire_family_key(array $row): string
{
    $familyKey = trim((string)($row['questionnaire_family_key'] ?? ''));
    if ($familyKey !== '') {
        return $familyKey;
    }

    $questionnaireId = (int)($row['questionnaire_id'] ?? 0);
    if ($questionnaireId > 0) {
        return 'questionnaire-' . $questionnaireId;
    }

    return 'questionnaire-unknown';
}

$stmt = $pdo->prepare(
    "SELECT qr.id, qr.questionnaire_id, qr.performance_period_id, qr.status, qr.score, qr.created_at, " .
    "q.title, COALESCE(q.family_key, CONCAT('questionnaire-', q.id)) AS questionnaire_family_key, COALESCE(pp.label, '') AS period_label, {$periodStartSelect} " .
    "FROM questionnaire_response qr " .
    "JOIN questionnaire q ON q.id = qr.questionnaire_id " .
    "LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id " .
    "WHERE qr.user_id = ? AND qr.status IN ('submitted', 'approved') ORDER BY qr.created_at ASC, qr.id ASC"
);
$stmt->execute([$user['id']]);

$responses = [];
$responsesByQuestionnaire = [];
$questionnaireOptions = [];
$latestScores = [];
$latestEntry = null;
$currentTrainingFocus = [];

while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
    $responses[] = $row;

    $latestScores[resolve_questionnaire_family_key($row)] = $row;
    $latestEntry = $row;

    $familyKey = resolve_questionnaire_family_key($row);
    $responsesByQuestionnaire[$familyKey][] = $row;
    if (!isset($questionnaireOptions[$familyKey])) {
        $questionnaireOptions[$familyKey] = (string)($row['title'] ?? '');
    }
}
$selectedQuestionnaireFamily = trim((string)($_GET['questionnaire_family'] ?? ''));
if ($selectedQuestionnaireFamily !== '' && !isset($responsesByQuestionnaire[$selectedQuestionnaireFamily])) {
    $selectedQuestionnaireFamily = '';
}
$displayResponses = $selectedQuestionnaireFamily !== ''
    ? ($responsesByQuestionnaire[$selectedQuestionnaireFamily] ?? [])
    : $responses;
$selectedQuestionnaireTitle = $selectedQuestionnaireFamily !== ''
    ? ($questionnaireOptions[$selectedQuestionnaireFamily] ?? '')
    : '';

foreach ($latestScores as $scoreRow) {
    if (isset($scoreRow['score']) && $scoreRow['score'] !== null && (int)$scoreRow['score'] < 100) {
        $currentTrainingFocus[] = $scoreRow;
    }
}

$latestSubmissionRaw = $latestEntry['created_at'] ?? null;
$latestSubmissionDisplay = null;
$latestSubmissionIso = '';
if ($latestSubmissionRaw) {
    $latestSubmissionDate = DateTime::createFromFormat('Y-m-d H:i:s', (string)$latestSubmissionRaw) ?: new DateTime((string)$latestSubmissionRaw);
    if ($latestSubmissionDate instanceof DateTime) {
        $latestSubmissionDisplay = app_format_display_date($latestSubmissionDate->format('Y-m-d'), $locale, $cfg, 'long');
        $latestSubmissionIso = $latestSubmissionDate->format('Y-m-d');
    }
}

$nextAssessmentDisplay = null;
$nextAssessmentIso = '';
if ($latestSubmissionRaw) {
    $nextAssessmentDate = DateTime::createFromFormat('Y-m-d H:i:s', (string)$latestSubmissionRaw) ?: new DateTime((string)$latestSubmissionRaw);
    if ($nextAssessmentDate instanceof DateTime) {
        $nextAssessmentDate->modify('+1 year');
        $nextAssessmentIso = $nextAssessmentDate->format('Y-m-d');
        $nextAssessmentDisplay = app_format_display_date($nextAssessmentIso, $locale, $cfg, 'long');
    }
}

$departmentDisplay = '';
if (function_exists('department_label')) {
    $departmentDisplay = department_label($pdo, (string)($user['department'] ?? ''));
}
if ($departmentDisplay === '') {
    $departmentDisplay = (string)($user['department'] ?? '');
}
$profileRoleLabels = [
    'director_branch_manager' => 'Director / Branch Manager',
    'team_leader_coordinator' => 'Team Leader / Coordinator',
    'officer_level_4' => 'Officer Level 4',
    'officer_level_3' => 'Officer Level 3',
    'officer_level_2' => 'Officer Level 2',
    'officer_level_1' => 'Officer Level 1',
];
$profileRole = trim((string)($user['profile_role'] ?? ''));
$profileRoleOther = trim((string)($user['profile_role_other'] ?? ''));
$userWorkTitle = '';
if ($profileRole === 'other') {
    $userWorkTitle = $profileRoleOther;
} elseif ($profileRole !== '') {
    $userWorkTitle = $profileRoleLabels[$profileRole] ?? $profileRole;
}
$positionDisplay = $userWorkFunctionLabel !== '' ? $userWorkFunctionLabel : (string)($user['work_function'] ?? '');
if ($positionDisplay === '') {
    $positionDisplay = $userWorkTitle;
}
$overviewTitle = $userWorkTitle;

$recommendedCourses = [];
if (!empty($user['work_function'])) {
    $courseStmt = $pdo->prepare('SELECT * FROM course_catalogue WHERE recommended_for=? AND min_score <= ? AND max_score >= ? AND (questionnaire_id = ? OR questionnaire_id IS NULL) AND is_active = 1 ORDER BY questionnaire_id IS NULL ASC, min_score ASC');
    foreach ($latestScores as $scoreRow) {
        if ($scoreRow['score'] === null) {
            continue;
        }
        $score = (int)$scoreRow['score'];
        $courseStmt->execute([$user['work_function'], $score, $score, (int)($scoreRow['questionnaire_id'] ?? 0)]);
        foreach ($courseStmt->fetchAll() as $course) {
            $recommendedCourses[$course['id']] = $course;
        }
    }
}
$recommendedCourses = array_values($recommendedCourses);
$statusLabels = [
    'draft' => t($t, 'status_draft', 'Draft'),
    'submitted' => t($t, 'status_submitted', 'Submitted'),
    'approved' => t($t, 'status_approved', 'Approved'),
    'rejected' => t($t, 'status_rejected', 'Rejected'),
];

$formatCompetencyLevel = static function ($score): string {
    if ($score === null) {
        return '—';
    }
    $level = questionnaire_competency_level((float)$score);
    return $level !== '' ? $level : 'N/A';
};

$flash = $_GET['msg'] ?? '';
$flashMessage = '';
if ($flash === 'submitted') {
    $flashMessage = t($t, 'submission_success', 'Assessment submitted successfully.');
}
$pageHelpKey = 'workspace.my_performance';
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'my_performance','My Performance'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <?php if ($flashMessage): ?><div class="md-alert success"><?=htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <div class="md-card md-elev-2">
    <div class="md-card-title-row">
      <h2 class="md-card-title"><?=t($t,'performance_overview','My Overview')?></h2>
      <a
        class="md-button md-outline md-card-action"
        href="<?=htmlspecialchars($performanceDownloadUrl, ENT_QUOTES, 'UTF-8')?>"
      >
        <?=t($t,'download_performance_pdf','Download PDF')?>
      </a>
    </div>
    <div class="md-overview-card" aria-label="<?=htmlspecialchars(t($t,'performance_overview','My Overview'), ENT_QUOTES, 'UTF-8')?>">
      <dl class="md-overview-details">
        <div>
          <dt><?=t($t,'title','Title')?></dt>
          <dd><?=htmlspecialchars($overviewTitle !== '' ? $overviewTitle : '—', ENT_QUOTES, 'UTF-8')?></dd>
        </div>
        <div>
          <dt><?=t($t,'name_position','Name / Position')?></dt>
          <dd><?=htmlspecialchars(trim((string)($user['full_name'] ?? '')) !== '' ? trim((string)($user['full_name'] ?? '')) : (string)($user['email'] ?? '—'), ENT_QUOTES, 'UTF-8')?><?= $positionDisplay !== '' ? ' / ' . htmlspecialchars($positionDisplay, ENT_QUOTES, 'UTF-8') : '' ?></dd>
        </div>
        <div>
          <dt><?=t($t,'department','Directorate')?></dt>
          <dd><?=htmlspecialchars($departmentDisplay !== '' ? $departmentDisplay : '—', ENT_QUOTES, 'UTF-8')?></dd>
        </div>
        <div>
          <dt><?=t($t,'last_submission_date','Last submission date')?></dt>
          <dd><?php if ($latestSubmissionDisplay): ?><span data-client-date="<?=htmlspecialchars($latestSubmissionIso, ENT_QUOTES, 'UTF-8')?>" data-client-date-mode="date" data-client-date-style="long"><?=htmlspecialchars($latestSubmissionDisplay, ENT_QUOTES, 'UTF-8')?></span><?php else: ?>—<?php endif; ?></dd>
        </div>
        <div>
          <dt><?=t($t,'next_assessment_date','Next assessment date')?></dt>
          <dd><?php if ($nextAssessmentDisplay): ?><span data-client-date="<?=htmlspecialchars($nextAssessmentIso, ENT_QUOTES, 'UTF-8')?>" data-client-date-mode="date" data-client-date-style="long"><?=htmlspecialchars($nextAssessmentDisplay, ENT_QUOTES, 'UTF-8')?></span><?php else: ?>—<?php endif; ?></dd>
        </div>
      </dl>
      <?php if ($latestEntry): ?>
        <p class="md-overview-footnote"><?=t($t,'latest_submission','Latest submission:')?> <?=htmlspecialchars($latestEntry['period_label'])?> · <?=htmlspecialchars($latestEntry['title'])?> (<?= is_null($latestEntry['score']) ? '-' : (int)$latestEntry['score'] ?>%)</p>
      <?php else: ?>
        <p class="md-overview-footnote"><?=t($t,'no_submissions_yet','No submissions recorded yet. Complete your first assessment to see insights.')?></p>
      <?php endif; ?>
    </div>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'submitted_assessments','Submitted Assessments')?></h2>
    <p><?=t($t,'submitted_assessments_hint','Only submitted and approved assessments are included. Scores are shown per questionnaire and are not averaged across different questionnaires.')?></p>
    <?php if (count($questionnaireOptions) > 1): ?>
      <form method="get" class="md-inline-form" action="<?=htmlspecialchars(url_for('my_performance.php'), ENT_QUOTES, 'UTF-8')?>">
        <label for="questionnaire-family-filter"><?=t($t,'filter_by_questionnaire','Filter by questionnaire')?></label>
        <select id="questionnaire-family-filter" name="questionnaire_family">
          <option value=""><?=t($t,'all_questionnaires','All questionnaires')?></option>
          <?php foreach ($questionnaireOptions as $familyKey => $questionnaireTitle): ?>
            <option value="<?=htmlspecialchars($familyKey, ENT_QUOTES, 'UTF-8')?>"<?= $selectedQuestionnaireFamily === $familyKey ? ' selected' : '' ?>><?=htmlspecialchars($questionnaireTitle !== '' ? $questionnaireTitle : $familyKey, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="md-button md-outline"><?=t($t,'apply_filter','Apply filter')?></button>
      </form>
    <?php endif; ?>
    <?php if ($selectedQuestionnaireTitle !== ''): ?>
      <p class="md-muted"><?=htmlspecialchars(sprintf(t($t,'questionnaire_filter_active','Showing submitted assessments for %s only.'), $selectedQuestionnaireTitle), ENT_QUOTES, 'UTF-8')?></p>
    <?php endif; ?>
    <table class="md-table">
      <thead><tr><th><?=t($t,'date','Date')?></th><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'performance_period','Assessment Period')?></th><th><?=t($t,'score','Score (%)')?></th><th><?=t($t,'proficiency_level','Competency level')?></th><th><?=t($t,'status','Status')?></th></tr></thead>
      <tbody>
      <?php foreach ($displayResponses as $r): ?>
        <?php
          $statusKey = $r['status'] ?? 'submitted';
          $statusLabel = $statusLabels[$statusKey] ?? ucfirst($statusKey);
        ?>
        <tr>
          <td><?=htmlspecialchars($r['created_at'])?></td>
          <td><?=htmlspecialchars($r['title'])?></td>
          <td><?=htmlspecialchars($r['period_label'])?></td>
          <td><?= is_null($r['score']) ? '-' : (int)$r['score']?></td>
          <td><?=htmlspecialchars($formatCompetencyLevel($r['score'] ?? null), ENT_QUOTES, 'UTF-8')?></td>
          <td><?=htmlspecialchars($statusLabel)?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$displayResponses): ?>
        <tr><td colspan="6"><?=t($t,'no_submissions_yet','No submissions recorded yet. Complete your first assessment to see insights.')?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'training_focus','Training Focus Areas')?></h2>
    <?php if (!$responses): ?>
      <p><?=t($t,'no_training_focus_data','No training focus data is available yet. Submit assessments to see your current status.')?></p>
    <?php elseif ($currentTrainingFocus): ?>
      <table class="md-table">
        <thead><tr><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'performance_period','Period')?></th><th><?=t($t,'score','Score (%)')?></th></tr></thead>
        <tbody>
        <?php foreach ($currentTrainingFocus as $item): ?>
          <tr>
            <td><?=htmlspecialchars($item['title'])?></td>
            <td><?=htmlspecialchars($item['period_label'])?></td>
            <td><?= (int)$item['score']?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p><?=t($t,'no_training_gaps','No current training gaps found.')?></p>
    <?php endif; ?>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'recommended_courses','Personal Improvement Plan (PIP)')?></h2>
    <?php if ($recommendedCourses): ?>
      <table class="md-table">
        <thead><tr><th><?=t($t,'course','Course')?></th><th><?=t($t,'thematic_area','Thematic Area')?></th><th><?=t($t,'delivery','Delivery')?></th><th><?=t($t,'course_owner','Course Owner')?></th><th><?=t($t,'link','Link')?></th><th><?=t($t,'score_band','Score Band')?></th></tr></thead>
        <tbody>
        <?php foreach ($recommendedCourses as $course): ?>
          <?php
            $deliveryParts = array_values(array_filter([
                trim((string)($course['mode_of_delivery'] ?? '')),
                trim((string)($course['duration'] ?? '')),
                trim((string)($course['ceu'] ?? '')) !== '' ? 'CEU: ' . trim((string)$course['ceu']) : '',
            ], static fn($value) => $value !== ''));
            $deliverySummary = $deliveryParts ? implode(' · ', $deliveryParts) : '—';
          ?>
          <tr>
            <td>
              <strong><?=htmlspecialchars($course['title'])?></strong>
              <?php if (!empty($course['course_objective'])): ?><br><span class="md-muted"><?=htmlspecialchars((string)$course['course_objective'])?></span><?php endif; ?>
            </td>
            <td><?=htmlspecialchars((string)($course['thematic_area'] ?? '—'))?></td>
            <td><?=htmlspecialchars($deliverySummary)?></td>
            <td><?=htmlspecialchars((string)($course['course_owner'] ?? '—'))?></td>
            <td><?php if (!empty($course['moodle_url'])): ?><a href="<?=htmlspecialchars($course['moodle_url'])?>" target="_blank" rel="noopener">Moodle</a><?php else: ?>—<?php endif; ?></td>
            <td><?= (int)$course['min_score'] ?> - <?= (int)$course['max_score'] ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p><?=t($t,'no_courses_available','No targeted courses found for your current scores. Please contact your supervisor for tailored learning paths.')?></p>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>
