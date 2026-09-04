<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/profile_completion.php';
require_once __DIR__ . '/../lib/secure_links.php';
auth_required(['admin']);
refresh_current_user($pdo);
cas_require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$userId = (int)($user['id'] ?? 0);

$downloadRequested = isset($_GET['download']) && $_GET['download'] === '1';
$secureLinkContext = $GLOBALS['secure_link_context'] ?? null;
$isSecureExportDownload = is_array($secureLinkContext)
    && (($secureLinkContext['resource_type'] ?? '') === 'admin_export_csv');

if ($downloadRequested && !$isSecureExportDownload) {
    http_response_code(404);
    exit;
}

/** Return the current database columns for a table, allowing exports to remain compatible during upgrades. */
function cas_export_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string)$row['Field']] = true;
        }
        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

/** Convert stored FHIR-style answer JSON into a lossless human-readable value. */
function cas_export_answer_text($raw): string
{
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return trim((string)$raw);
    }
    $entries = array_is_list($decoded) ? $decoded : [$decoded];
    $values = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (array_key_exists('valueString', $entry)) $value = (string)$entry['valueString'];
        elseif (array_key_exists('valueInteger', $entry)) $value = (string)$entry['valueInteger'];
        elseif (array_key_exists('valueDecimal', $entry)) $value = (string)$entry['valueDecimal'];
        elseif (array_key_exists('valueBoolean', $entry)) $value = $entry['valueBoolean'] ? 'true' : 'false';
        elseif (array_key_exists('valueDate', $entry)) $value = (string)$entry['valueDate'];
        elseif (array_key_exists('valueDateTime', $entry)) $value = (string)$entry['valueDateTime'];
        elseif (array_key_exists('valueTime', $entry)) $value = (string)$entry['valueTime'];
        elseif (isset($entry['valueCoding']) && is_array($entry['valueCoding'])) {
            $value = (string)($entry['valueCoding']['display'] ?? $entry['valueCoding']['code'] ?? '');
        } elseif (isset($entry['valueQuantity']) && is_array($entry['valueQuantity'])) {
            $value = trim((string)($entry['valueQuantity']['value'] ?? '') . ' ' . (string)($entry['valueQuantity']['unit'] ?? ''));
        } else {
            $value = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($value !== '') $values[] = $value;
    }
    return implode(' | ', $values);
}

if ($downloadRequested) {
    $userColumns = cas_export_table_columns($pdo, 'users');
    $itemColumns = cas_export_table_columns($pdo, 'questionnaire_item');
    $sectionColumns = cas_export_table_columns($pdo, 'questionnaire_section');

    $userFields = ['department','directorate','cadre','work_function','profile_role','profile_role_other','business_role','job_grade','education_level','highest_degree_subject','work_experience_profile','total_work_experience_band','epss_work_experience_band','gender','date_of_birth','phone'];
    $availableUserFields = array_values(array_filter($userFields, static fn($field) => isset($userColumns[$field])));

    $headers = array_merge([
        'response_id','user_id','username','full_name','email','role'
    ], $availableUserFields, [
        'account_status','questionnaire_id','questionnaire_family_key','questionnaire_title','questionnaire_status',
        'response_status','score_percent','reached_80_percent','performance_period','period_start','period_end',
        'response_created_at','reviewed_at','reviewer_username','reviewer_full_name','reviewer_email','review_comment',
        'question_id','question_link_id','question_order','section_id','section_title','section_order','section_include_in_scoring',
        'question_text','question_type','question_weight_percent','allow_multiple','is_required','requires_correct','question_is_active',
        'answer_text','answer_json','available_options','correct_expected_answers','answer_is_correct',
        'recommended_courses','recommendation_reasons'
    ]);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cas_detailed_raw_responses_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);

    $userSelect = '';
    foreach ($availableUserFields as $field) {
        $userSelect .= ', u.`' . $field . '` AS `u_' . $field . '`';
    }
    $itemSelect = static function (string $field, string $fallback) use ($itemColumns): string {
        return isset($itemColumns[$field]) ? 'qi.`' . $field . '`' : $fallback;
    };
    $sectionSelect = static function (string $field, string $fallback) use ($sectionColumns): string {
        return isset($sectionColumns[$field]) ? 'qs.`' . $field . '`' : $fallback;
    };

    $sql = "SELECT qr.id AS response_id, u.id AS user_id, u.username, u.full_name, u.email, u.role{$userSelect}, u.account_status,
        q.id AS questionnaire_id, COALESCE(q.family_key, CONCAT('questionnaire-', q.id)) AS questionnaire_family_key,
        q.title AS questionnaire_title, q.status AS questionnaire_status, qr.status AS response_status, qr.score,
        pp.label AS performance_period, pp.period_start, pp.period_end, qr.created_at AS response_created_at,
        qr.reviewed_at, reviewer.username AS reviewer_username, reviewer.full_name AS reviewer_full_name,
        reviewer.email AS reviewer_email, qr.review_comment,
        qi.id AS question_id, qi.linkId AS question_link_id, qi.order_index AS question_order,
        qi.section_id, qs.title AS section_title, " . $sectionSelect('order_index', 'NULL') . " AS section_order,
        " . $sectionSelect('include_in_scoring', '1') . " AS section_include_in_scoring,
        qi.text AS question_text, qi.type AS question_type, " . $itemSelect('weight_percent', '0') . " AS question_weight_percent,
        " . $itemSelect('allow_multiple', '0') . " AS allow_multiple, " . $itemSelect('is_required', '0') . " AS is_required,
        " . $itemSelect('requires_correct', '0') . " AS requires_correct, " . $itemSelect('is_active', '1') . " AS question_is_active,
        qri.answer AS answer_json,
        (SELECT GROUP_CONCAT(qio.value ORDER BY qio.order_index, qio.id SEPARATOR ' | ') FROM questionnaire_item_option qio WHERE qio.questionnaire_item_id = qi.id) AS available_options,
        (SELECT GROUP_CONCAT(qio.value ORDER BY qio.order_index, qio.id SEPARATOR ' | ') FROM questionnaire_item_option qio WHERE qio.questionnaire_item_id = qi.id AND qio.is_correct = 1) AS correct_expected_answers,
        tr.recommended_courses, tr.recommendation_reasons
      FROM questionnaire_response qr
      JOIN users u ON u.id = qr.user_id
      LEFT JOIN questionnaire q ON q.id = qr.questionnaire_id
      LEFT JOIN users reviewer ON reviewer.id = qr.reviewed_by
      LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id
      LEFT JOIN questionnaire_response_item qri ON qri.response_id = qr.id
      LEFT JOIN questionnaire_item qi ON qi.questionnaire_id = qr.questionnaire_id AND qi.linkId = qri.linkId
      LEFT JOIN questionnaire_section qs ON qs.id = qi.section_id
      LEFT JOIN (
        SELECT tr.questionnaire_response_id,
          GROUP_CONCAT(cc.title ORDER BY cc.title SEPARATOR ' | ') AS recommended_courses,
          GROUP_CONCAT(COALESCE(tr.recommendation_reason, '') ORDER BY cc.title SEPARATOR ' | ') AS recommendation_reasons
        FROM training_recommendation tr
        JOIN course_catalogue cc ON cc.id = tr.course_id
        GROUP BY tr.questionnaire_response_id
      ) tr ON tr.questionnaire_response_id = qr.id
      ORDER BY qr.id DESC, qs.order_index ASC, qi.order_index ASC, qi.id ASC";

    foreach ($pdo->query($sql) as $row) {
        $answerText = cas_export_answer_text($row['answer_json'] ?? '');
        $correctText = trim((string)($row['correct_expected_answers'] ?? ''));
        $answerIsCorrect = '';
        if ($correctText !== '') {
            $selected = array_values(array_filter(array_map('trim', explode(' | ', $answerText)), 'strlen'));
            $correct = array_values(array_filter(array_map('trim', explode(' | ', $correctText)), 'strlen'));
            sort($selected); sort($correct);
            $answerIsCorrect = $selected === $correct ? '1' : '0';
        }
        $values = [
            $row['response_id'],$row['user_id'],$row['username'],$row['full_name'],$row['email'],$row['role']
        ];
        foreach ($availableUserFields as $field) $values[] = $row['u_' . $field] ?? '';
        $values = array_merge($values, [
            $row['account_status'],$row['questionnaire_id'],$row['questionnaire_family_key'],$row['questionnaire_title'],$row['questionnaire_status'],
            $row['response_status'],$row['score'],($row['score'] !== null && (float)$row['score'] >= 80 ? '1' : '0'),$row['performance_period'],$row['period_start'],$row['period_end'],
            $row['response_created_at'],$row['reviewed_at'],$row['reviewer_username'],$row['reviewer_full_name'],$row['reviewer_email'],$row['review_comment'],
            $row['question_id'],$row['question_link_id'],$row['question_order'],$row['section_id'],$row['section_title'],$row['section_order'],$row['section_include_in_scoring'],
            $row['question_text'],$row['question_type'],$row['question_weight_percent'],$row['allow_multiple'],$row['is_required'],$row['requires_correct'],$row['question_is_active'],
            $answerText,$row['answer_json'],$row['available_options'],$row['correct_expected_answers'],$answerIsCorrect,
            $row['recommended_courses'],$row['recommendation_reasons']
        ]);
        fputcsv($out, $values);
    }
    fclose($out);
    exit;
}

$csvDownloadUrl = url_for('admin/export.php?download=1');
if ($userId > 0) {
    try {
        $csvDownloadUrl = secure_links_build_url($pdo, 'admin_export_csv', ['user_id' => $userId, 'format' => 'csv'], $userId, 600, true);
    } catch (Throwable $secureLinkError) {
        error_log('admin/export.php secure link generation failed: ' . $secureLinkError->getMessage());
    }
}
$totalResponses = 0; $totalAnswers = 0; $latestSubmission = null;
try {
    $totalResponses = (int)$pdo->query('SELECT COUNT(*) FROM questionnaire_response')->fetchColumn();
    $totalAnswers = (int)$pdo->query('SELECT COUNT(*) FROM questionnaire_response_item')->fetchColumn();
    $latestSubmission = $pdo->query('SELECT MAX(created_at) FROM questionnaire_response')->fetchColumn();
} catch (PDOException $e) { error_log('export.php stats failed: ' . $e->getMessage()); }
$latestSubmissionDisplay = $latestSubmission ? app_format_display_datetime($latestSubmission, $locale, $cfg) : '—';
$latestSubmissionIso = $latestSubmission ? app_format_machine_datetime($latestSubmission) : '';
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head><meta charset="utf-8"><title><?=htmlspecialchars(t($t, 'export_data', 'Export Assessment Data'), ENT_QUOTES, 'UTF-8')?></title><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><link rel="manifest" href="<?=asset_url('manifest.php')?>"><link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>"><link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>"></head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'export_assessments_title', 'Detailed raw assessment export')?></h2>
    <p><?=t($t, 'export_assessments_intro', 'Download question-level raw data: one row per staff submission and answered question, with profile, questionnaire, question, answer, scoring and training recommendation details.')?></p>
    <ul class="md-stat-list">
      <li class="md-stat-item"><span class="md-stat-label"><?=t($t, 'responses_available', 'Submissions available')?>: </span><span class="md-stat-value"><?=$totalResponses?></span></li>
      <li class="md-stat-item"><span class="md-stat-label"><?=t($t, 'answers_available', 'Question answers available')?>: </span><span class="md-stat-value"><?=$totalAnswers?></span></li>
      <li class="md-stat-item"><span class="md-stat-label"><?=t($t, 'latest_submission', 'Latest submission')?>: </span><span class="md-stat-value" data-client-date="<?=htmlspecialchars($latestSubmissionIso, ENT_QUOTES, 'UTF-8')?>" data-client-date-mode="datetime"><?=htmlspecialchars($latestSubmissionDisplay, ENT_QUOTES, 'UTF-8')?></span></li>
    </ul>
    <div class="md-form-actions md-form-actions--center md-form-actions--stack"><a class="md-button md-primary md-elev-2" href="<?=htmlspecialchars($csvDownloadUrl, ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'download_csv', 'Download Detailed Raw CSV')?></a></div>
    <p class="md-upgrade-meta"><?=t($t, 'export_notice', 'The CSV is intentionally granular and includes both human-readable answers and the original answer JSON so downstream analysis can reconstruct CAS results without losing response detail.')?></p>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'export_columns', 'What is included')?></h2>
    <p>Each row identifies the staff member, organizational/profile attributes, questionnaire and period, overall score and 80% threshold, review details, section and full question text, question settings and weight, actual answer, original raw answer JSON, available and correct answers, question correctness, and training recommendations.</p>
    <p><strong>Raw-data principle:</strong> the export contains source-level values rather than only dashboard aggregates, making it suitable for Excel, Power BI, Looker Studio, QA, audit and trend analysis.</p>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>
