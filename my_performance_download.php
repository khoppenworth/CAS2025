<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/simple_pdf.php';
require_once __DIR__ . '/lib/course_recommendations.php';
require_once __DIR__ . '/lib/analytics_report.php';
require_once __DIR__ . '/lib/performance_sections.php';
require_once __DIR__ . '/lib/scoring.php';

auth_required(['staff', 'supervisor', 'admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$secureLinkContext = $GLOBALS['secure_link_context'] ?? null;
$isSecureLinkRequest = is_array($secureLinkContext)
    && (($secureLinkContext['resource_type'] ?? '') === 'performance_pdf');
if (!$isSecureLinkRequest) {
    http_response_code(404);
    exit;
}

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
ensure_course_recommendation_schema($pdo);
$userId = (int) ($user['id'] ?? 0);

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
    error_log('my_performance_download performance_period schema lookup failed: ' . $periodSchemaError->getMessage());
}

if ($userId <= 0) {
    http_response_code(404);
    exit;
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

$periodStartSelect = $hasPeriodStartColumn ? 'pp.period_start' : 'NULL AS period_start';
$stmt = $pdo->prepare("SELECT qr.*, q.title, COALESCE(q.family_key, CONCAT('questionnaire-', q.id)) AS questionnaire_family_key, COALESCE(pp.label, '') AS period_label, {$periodStartSelect}
    FROM questionnaire_response qr
    JOIN questionnaire q ON q.id = qr.questionnaire_id
    LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id
    WHERE qr.user_id = ?
    ORDER BY qr.created_at ASC");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$latestEntry = null;
$latestScores = [];
foreach ($rows as $row) {
    $latestScores[resolve_questionnaire_family_key($row)] = $row;
    if ($latestEntry === null || strtotime((string) ($row['created_at'] ?? '')) > strtotime((string) ($latestEntry['created_at'] ?? ''))) {
        $latestEntry = $row;
    }
}

$nextAssessmentDisplay = '';
$nextAssessmentRaw = (string) ($user['next_assessment_date'] ?? '');
if ($nextAssessmentRaw !== '') {
    $nextAssessmentDisplay = app_format_display_date($nextAssessmentRaw, $locale, $cfg, 'long');
}

$departmentLabel = '';
if (!empty($user['work_function'])) {
    $departmentLabel = work_function_label($pdo, (string) $user['work_function']);
}

$submittedCount = 0;
$approvedCount = 0;
$draftCount = 0;
$rejectedCount = 0;
$scoredValues = [];
foreach ($rows as $row) {
    $status = (string) ($row['status'] ?? 'submitted');
    if ($status === 'draft') {
        $draftCount++;
    } elseif ($status === 'rejected') {
        $rejectedCount++;
    } else {
        $submittedCount++;
        if ($status === 'approved') {
            $approvedCount++;
        }
    }
    if (isset($row['score']) && $row['score'] !== null) {
        $scoredValues[] = (float) $row['score'];
    }
}

$averageScore = $scoredValues ? array_sum($scoredValues) / count($scoredValues) : null;

$recommendedCourses = [];
if (!empty($user['work_function'])) {
    $courseStmt = $pdo->prepare('SELECT * FROM course_catalogue WHERE recommended_for=? AND min_score <= ? AND max_score >= ? AND (questionnaire_id = ? OR questionnaire_id IS NULL) ORDER BY questionnaire_id IS NULL ASC, min_score ASC');
    foreach ($latestScores as $scoreRow) {
        if ($scoreRow['score'] === null) {
            continue;
        }
        $score = (int) $scoreRow['score'];
        $courseStmt->execute([$user['work_function'], $score, $score, (int)($scoreRow['questionnaire_id'] ?? 0)]);
        foreach ($courseStmt->fetchAll(PDO::FETCH_ASSOC) as $course) {
            $courseId = (int)($course['id'] ?? 0);
            $course['_matched_score'] = $score;
            $course['_matched_questionnaire'] = (string)($scoreRow['title'] ?? '');
            $course['_priority'] = personal_report_priority_label($score);
            $course['_reason'] = personal_report_course_reason($scoreRow, $course, $t);
            if ($courseId <= 0 || !isset($recommendedCourses[$courseId]) || $score < (int)($recommendedCourses[$courseId]['_matched_score'] ?? 101)) {
                $recommendedCourses[$courseId] = $course;
            }
        }
    }
}
$recommendedCourses = array_slice(array_values($recommendedCourses), 0, 8);

$sectionBreakdowns = compute_section_breakdowns($pdo, array_values($latestScores), $t);
$competencyAreaRows = personal_report_competency_area_rows($sectionBreakdowns);
$strengthRows = personal_report_rank_competency_rows($competencyAreaRows, true, 3);
$developmentRows = personal_report_rank_competency_rows($competencyAreaRows, false, 3);
$trendPoints = personal_report_trend_points($rows);
$latestScoreValue = isset($latestEntry['score']) && $latestEntry['score'] !== null ? (float)$latestEntry['score'] : null;
$suggestedCompletionDate = personal_report_suggested_completion_date($nextAssessmentRaw, $locale, $cfg);

$generatedAt = new DateTimeImmutable('now');
$reportTitle = t($t, 'personal_summary_report', 'Personal Summary Report');

$pdf = new SimplePdfDocument();
$logoSpec = analytics_report_header_logo_spec($pdf, $cfg);
$pdf->setHeader(
    $reportTitle,
    t($t, 'my_performance_pdf_subtitle', 'Personal performance summary'),
    $logoSpec,
    analytics_report_header_style($cfg)
);
$userDetails = [];
$nameLine = trim((string) ($user['full_name'] ?? ''));
if ($nameLine === '') {
    $nameLine = (string) ($user['username'] ?? '');
}
$userDetails[] = t($t, 'employee_name', 'Name') . ': ' . $nameLine;
if (!empty($user['username'])) {
    $userDetails[] = t($t, 'employee_username', 'Username') . ': ' . $user['username'];
}
if ($departmentLabel !== '') {
    $userDetails[] = t($t, 'employee_department', 'Directorate') . ': ' . $departmentLabel;
}
$roleKey = trim((string) ($user['role'] ?? ''));
if ($roleKey !== '') {
    $userDetails[] = t($t, 'employee_role', 'Role') . ': ' . ucfirst($roleKey);
}
$emailValue = trim((string) ($user['email'] ?? ''));
if ($emailValue !== '') {
    $userDetails[] = t($t, 'employee_email', 'Email') . ': ' . $emailValue;
}
if ($nextAssessmentDisplay !== '') {
    $userDetails[] = t($t, 'next_assessment', 'Next Assessment Date') . ': ' . $nextAssessmentDisplay;
}
$pdf->addRightAlignedText($userDetails, 10.0);
$pdf->addHeading($reportTitle);
$pdf->addParagraph(sprintf(
    '%s %s',
    t($t, 'my_performance_pdf_intro', 'Generated on'),
    app_format_display_datetime($generatedAt, $locale, $cfg, 'medium', 'short', true)
));
$pdf->addParagraph(t($t, 'personal_report_confidentiality', 'Confidential: This report is intended for personal development planning and authorized supervisor, HR, or learning-and-development review only.'), 9.5);

$pdf->ensureSpaceForBlock(150.0);
$pdf->addSubheading(t($t, 'report_contents', 'Report Contents'));
$pdf->addBulletList([
    t($t, 'contents_executive_summary', 'Executive summary'),
    t($t, 'contents_score_interpretation', 'Score interpretation'),
    t($t, 'contents_latest_assessment', 'Latest assessment detail'),
    t($t, 'contents_competency_results', 'Competency area results and gap analysis'),
    t($t, 'contents_performance_trend', 'Performance trend'),
    t($t, 'contents_training_plan', 'Recommended training and personal improvement plan'),
    t($t, 'contents_history_notes', 'Recent responses, methodology, notes, and sign-off'),
], 10.0);

$highestArea = $strengthRows[0] ?? null;
$lowestArea = $developmentRows[0] ?? null;
$executiveRows = [
    [t($t, 'latest_score', 'Latest score'), $latestScoreValue !== null ? number_format($latestScoreValue, 1) . '%' : t($t, 'score_pending', 'Pending')],
    [t($t, 'average_score', 'Average score (%)'), $averageScore !== null ? number_format((float)$averageScore, 1) . '%' : '—'],
    [t($t, 'proficiency_level', 'Competency level'), $latestScoreValue !== null ? questionnaire_competency_level($latestScoreValue) : ($averageScore !== null ? questionnaire_competency_level((float)$averageScore) : '—')],
    [t($t, 'strongest_competency_area', 'Strongest competency area'), $highestArea ? sprintf('%s (%s)', $highestArea['label'], personal_report_format_percent($highestArea['score'])) : '—'],
    [t($t, 'priority_development_area', 'Priority development area'), $lowestArea ? sprintf('%s (%s)', $lowestArea['label'], personal_report_format_percent($lowestArea['score'])) : '—'],
    [t($t, 'recommended_training_courses', 'Recommended Training Courses'), (string)count($recommendedCourses)],
];
if ($nextAssessmentDisplay !== '') {
    $executiveRows[] = [t($t, 'next_assessment', 'Next Assessment Date'), $nextAssessmentDisplay];
}
$pdf->ensureSpaceForBlock(170.0);
$pdf->addSubheading(t($t, 'executive_summary', 'Executive Summary'));
$pdf->addTable([
    t($t, 'summary_item', 'Summary item'),
    t($t, 'value', 'Value'),
], $executiveRows, [55, 85], 9.5);

$scoreInterpretationRows = personal_report_competency_scale_rows($t);
$pdf->ensureSpaceForBlock(180.0);
$pdf->addSubheading(t($t, 'score_interpretation', 'Score Interpretation'));
$pdf->addParagraph(t($t, 'score_interpretation_hint', 'Scores are percentage based. Higher scores indicate stronger demonstrated competency against the questionnaire criteria.'), 10.0);
$pdf->addTable([
    t($t, 'score_range', 'Score range'),
    t($t, 'proficiency_level', 'Competency level'),
    t($t, 'meaning', 'Meaning'),
], $scoreInterpretationRows, [24, 34, 92], 8.5);

$summaryRows = [
    [t($t, 'total_responses', 'Responses submitted'), (string) $submittedCount],
    [t($t, 'approved', 'Approved'), (string) $approvedCount],
    [t($t, 'status_draft', 'Draft'), (string) $draftCount],
    [t($t, 'status_rejected', 'Rejected'), (string) $rejectedCount],
];
if ($averageScore !== null) {
    $summaryRows[] = [t($t, 'average_score', 'Average score (%)'), number_format($averageScore, 1)];
    $summaryRows[] = [
        t($t, 'proficiency_level', 'Competency level'),
        questionnaire_competency_level((float) $averageScore),
    ];
}
if ($nextAssessmentDisplay !== '') {
    $summaryRows[] = [t($t, 'next_assessment', 'Next Assessment Date'), $nextAssessmentDisplay];
}
if ($latestEntry !== null) {
    $latestScore = isset($latestEntry['score']) && $latestEntry['score'] !== null
        ? number_format((float) $latestEntry['score'], 0) . '%'
        : t($t, 'score_pending', 'Pending');
    $summaryRows[] = [
        t($t, 'latest_submission', 'Latest submission'),
        sprintf(
            '%s · %s',
            (string) ($latestEntry['period_label'] ?? ''),
            $latestScore
        ),
    ];
}

$pdf->ensureSpaceForBlock(160.0);
$pdf->addSubheading(t($t, 'performance_overview', 'Performance Overview'));
$pdf->addTable([
    t($t, 'metric', 'Metric'),
    t($t, 'value', 'Value'),
], $summaryRows, [70, 40]);

$pdf->ensureSpaceForBlock(150.0);
$pdf->addSubheading(t($t, 'latest_assessment_detail', 'Latest Assessment Detail'));
if ($latestEntry !== null) {
    $latestDetailScore = $latestScoreValue !== null ? number_format($latestScoreValue, 1) . '%' : t($t, 'score_pending', 'Pending');
    $latestDetailRows = [
        [t($t, 'questionnaire', 'Questionnaire'), (string)($latestEntry['title'] ?? '')],
        [t($t, 'performance_period', 'Assessment Period'), (string)($latestEntry['period_label'] ?? '')],
        [t($t, 'year', 'Year'), resolve_performance_year($latestEntry)],
        [t($t, 'status', 'Status'), t($t, 'status_' . ($latestEntry['status'] ?? 'submitted'), ucfirst((string)($latestEntry['status'] ?? 'submitted')))],
        [t($t, 'score', 'Score (%)'), $latestDetailScore],
        [t($t, 'proficiency_level', 'Competency level'), $latestScoreValue !== null ? questionnaire_competency_level($latestScoreValue) : '—'],
    ];
    if ($averageScore !== null && $latestScoreValue !== null) {
        $latestDetailRows[] = [
            t($t, 'comparison_to_average', 'Comparison to average'),
            sprintf('%+.1f percentage points', $latestScoreValue - (float)$averageScore),
        ];
    }
    $pdf->addTable([
        t($t, 'detail', 'Detail'),
        t($t, 'value', 'Value'),
    ], $latestDetailRows, [50, 90], 9.5);
} else {
    $pdf->addParagraph(t($t, 'no_submissions_yet', 'No submissions recorded yet. Complete your first assessment to see insights.'));
}

if ($sectionBreakdowns) {
    $pdf->ensureSpaceForBlock(490.0);
    $pdf->addSubheading(t($t, 'section_breakdown', 'Section score radar'));
    $pdf->addParagraph(t($t, 'section_breakdown_hint', 'Each radar uses short CA labels in the graph and lists the full Competency Area text in the legend below.'));
    $palette = analytics_report_palette_colors($cfg);
    foreach ($sectionBreakdowns as $radar) {
        $titleLine = (string)($radar['title'] ?? '');
        $period = trim((string)($radar['period'] ?? ''));
        if ($period !== '') {
            $titleLine = $titleLine !== '' ? $titleLine . ' · ' . $period : $period;
        }
        if ($titleLine !== '') {
            $pdf->addParagraph($titleLine, 12.0);
        }
        $radarLegendRows = [];
        $radarChartSections = [];
        foreach (array_values($radar['sections'] ?? []) as $index => $section) {
            $shortLabel = 'CA' . ($index + 1);
            $fullLabel = trim((string)($section['label'] ?? ''));
            $score = isset($section['score']) && is_numeric($section['score']) ? (float)$section['score'] : null;
            $radarLegendRows[] = [
                $shortLabel,
                $fullLabel !== '' ? $fullLabel : t($t, 'competency_area', 'Competency Area'),
                $score !== null ? number_format($score, 1) . '%' : '—',
            ];
            $radarChartSections[] = [
                'label' => $shortLabel,
                'score' => $score,
            ];
        }

        $chartImage = analytics_report_generate_radar_chart($radarChartSections, $palette, [
            'max_value' => 100,
            'value_suffix' => '%',
        ]);
        if ($chartImage) {
            $pdf->addImageBlock($chartImage['data'], $chartImage['width'], $chartImage['height'], 420.0);
        }

        if ($radarLegendRows) {
            $pdf->addParagraph(t($t, 'competency_area_legend', 'Legend: Competency Areas'), 10.5);
            $pdf->addTable([
                t($t, 'short_form', 'Short form'),
                t($t, 'competency_area', 'Competency Area'),
                t($t, 'score', 'Score (%)'),
            ], $radarLegendRows, [24, 116, 30], 9.0);
        }
    }
}

if ($competencyAreaRows) {
    $pdf->ensureSpaceForBlock(190.0);
    $pdf->addSubheading(t($t, 'competency_gap_analysis', 'Competency Gap Analysis'));
    $gapRows = [];
    foreach ($developmentRows ?: array_slice($competencyAreaRows, 0, 6) as $areaRow) {
        $gapRows[] = [
            (string)$areaRow['local_code'],
            (string)$areaRow['questionnaire'],
            (string)$areaRow['label'],
            personal_report_format_percent($areaRow['score']),
            personal_report_format_percent($areaRow['gap']),
            personal_report_priority_label($areaRow['score']),
            questionnaire_competency_recommendation($areaRow['score']),
        ];
    }
    $pdf->addTable([
        t($t, 'short_form', 'Short form'),
        t($t, 'questionnaire', 'Questionnaire'),
        t($t, 'competency_area', 'Competency Area'),
        t($t, 'score', 'Score (%)'),
        t($t, 'gap_to_target', 'Gap to 100%'),
        t($t, 'priority', 'Priority'),
        t($t, 'recommended_action', 'Recommended action'),
    ], $gapRows, [16, 38, 60, 20, 22, 22, 42], 7.5);

    $pdf->ensureSpaceForBlock(170.0);
    $pdf->addSubheading(t($t, 'strengths_and_development_areas', 'Strengths and Development Areas'));
    $strengthText = personal_report_competency_summary_text($strengthRows, $t, true);
    $developmentText = personal_report_competency_summary_text($developmentRows, $t, false);
    $pdf->addTable([
        t($t, 'strengths', 'Strengths'),
        t($t, 'development_areas', 'Development areas'),
    ], [[$strengthText, $developmentText]], [1, 1], 9.0);
}

$pdf->ensureSpaceForBlock(330.0);
$pdf->addSubheading(t($t, 'performance_trend', 'Performance Trend'));
if ($trendPoints) {
    $palette = analytics_report_palette_colors($cfg);
    $trendImage = analytics_report_generate_line_chart($trendPoints, $palette, [
        'max_value' => 100,
        'value_suffix' => '%',
        'decimal_places' => 0,
    ]);
    if ($trendImage) {
        $pdf->addImageBlock($trendImage['data'], $trendImage['width'], $trendImage['height'], 430.0);
    }
    $trendSummaryRows = personal_report_trend_summary_rows($trendPoints, $t);
    if ($trendSummaryRows) {
        $pdf->addTable([
            t($t, 'metric', 'Metric'),
            t($t, 'value', 'Value'),
        ], $trendSummaryRows, [55, 85], 9.0);
    }
} else {
    $pdf->addParagraph(t($t, 'no_trend_data', 'Trend analysis will appear once at least one scored response is available.'));
}

$pdf->ensureSpaceForBlock(160.0);
$pdf->addSubheading(t($t, 'recent_responses', 'Recent responses'));
if ($rows) {
    $responseRows = [];
    $limit = 20;
    foreach (array_slice($rows, -$limit) as $row) {
        $scoreValue = isset($row['score']) && $row['score'] !== null ? (float) $row['score'] : null;
        $responseRows[] = [
            resolve_performance_year($row),
            (string) ($row['title'] ?? ''),
            (string) ($row['period_label'] ?? ''),
            $scoreValue !== null ? number_format($scoreValue, 0) . '%' : '—',
            $scoreValue !== null ? questionnaire_competency_level($scoreValue) : '—',
            t($t, 'status_' . ($row['status'] ?? 'submitted'), ucfirst((string) ($row['status'] ?? 'submitted'))),
        ];
    }
    $pdf->addTable([
        t($t, 'year', 'Year'),
        t($t, 'questionnaire', 'Questionnaire'),
        t($t, 'performance_period', 'Assessment Period'),
        t($t, 'score', 'Score (%)'),
        t($t, 'proficiency_level', 'Competency level'),
        t($t, 'status', 'Status'),
    ], $responseRows, [18, 52, 52, 20, 28, 30]);
} else {
    $pdf->addParagraph(t($t, 'no_submissions_yet', 'No submissions recorded yet. Complete your first assessment to see insights.'));
}

$pdf->ensureSpaceForBlock(210.0);
$pdf->addSubheading(t($t, 'recommended_training_courses', 'Recommended Training Courses'));
if ($recommendedCourses) {
    $courseRows = [];
    foreach ($recommendedCourses as $course) {
        $courseRows[] = [
            trim((string)($course['code'] ?? '')) !== '' ? (string)$course['code'] : '—',
            (string)($course['title'] ?? ''),
            (string)($course['_priority'] ?? personal_report_priority_label(isset($course['_matched_score']) ? (float)$course['_matched_score'] : null)),
            (string)($course['_reason'] ?? ''),
            sprintf('%d – %d%%', (int)($course['min_score'] ?? 0), (int)($course['max_score'] ?? 100)),
            (string)($course['moodle_url'] ?? ''),
        ];
    }
    $pdf->addTable([
        t($t, 'course_code', 'Code'),
        t($t, 'course', 'Course'),
        t($t, 'priority', 'Priority'),
        t($t, 'recommendation_reason', 'Reason'),
        t($t, 'score_band', 'Score Band'),
        t($t, 'link', 'Link'),
    ], $courseRows, [18, 50, 22, 58, 24, 38], 7.8);
} else {
    $pdf->addParagraph(t($t, 'no_courses_available', 'No targeted courses found for your current scores. Please contact your supervisor for tailored learning paths.'));
}

$pdf->ensureSpaceForBlock(190.0);
$pdf->addSubheading(t($t, 'personal_improvement_plan', 'Personal Improvement Plan'));
$improvementRows = personal_report_improvement_plan_rows($developmentRows, $recommendedCourses, $suggestedCompletionDate, $t);
$pdf->addTable([
    t($t, 'development_area', 'Development area'),
    t($t, 'recommended_action', 'Recommended action'),
    t($t, 'course', 'Course'),
    t($t, 'target_date', 'Target date'),
    t($t, 'status', 'Status'),
], $improvementRows, [52, 60, 44, 28, 26], 8.0);

$pdf->ensureSpaceForBlock(180.0);
$pdf->addSubheading(t($t, 'methodology_and_notes', 'Methodology and Notes'));
$pdf->addBulletList(personal_report_methodology_notes($sectionBreakdowns, $rows, $recommendedCourses, $t), 9.0);

$pdf->ensureSpaceForBlock(120.0);
$pdf->addSubheading(t($t, 'review_sign_off', 'Review Sign-Off'));
$pdf->addParagraph(t($t, 'review_sign_off_hint', 'Use this section if the report is discussed as part of a formal development or supervisor review conversation.'), 9.5);
$pdf->addSignatureFields([
    [t($t, 'employee_signature', 'Employee signature'), t($t, 'supervisor_signature', 'Supervisor signature')],
    [t($t, 'hr_reviewer_signature', 'HR / L&D reviewer signature'), t($t, 'review_date', 'Review date')],
]);

$pdf->addParagraph(t($t, 'my_performance_pdf_footer', 'For the most up-to-date analytics and section breakdowns, sign in to the portal.'));

$filename = sprintf(
    'personal-summary-report-%s-%s.pdf',
    preg_replace('/[^a-z0-9_-]/i', '', (string) ($user['username'] ?? 'user')),
    $generatedAt->format('Ymd_His')
);

$pdfData = $pdf->output();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfData));
header('Cache-Control: private, max-age=0');
echo $pdfData;
exit;

function personal_report_format_percent(?float $value): string
{
    if ($value === null) {
        return '—';
    }

    return number_format(max(0.0, min(100.0, (float)$value)), 1) . '%';
}

function personal_report_priority_label(?float $score): string
{
    if ($score === null) {
        return 'Pending';
    }
    if ($score < 60.0) {
        return 'High';
    }
    if ($score < 75.0) {
        return 'Medium';
    }

    return 'Maintain';
}

function personal_report_course_reason(array $scoreRow, array $course, array $translations): string
{
    $score = isset($scoreRow['score']) && $scoreRow['score'] !== null ? (float)$scoreRow['score'] : null;
    $questionnaire = trim((string)($scoreRow['title'] ?? ''));
    $band = sprintf('%d–%d%%', (int)($course['min_score'] ?? 0), (int)($course['max_score'] ?? 100));
    if ($score === null) {
        return t($translations, 'course_reason_pending_score', 'Mapped to your role and pending score review.');
    }
    if ($questionnaire !== '') {
        return sprintf(
            'Matched %s score of %s against course band %s.',
            $questionnaire,
            personal_report_format_percent($score),
            $band
        );
    }

    return sprintf('Matched score of %s against course band %s.', personal_report_format_percent($score), $band);
}

function personal_report_suggested_completion_date(string $nextAssessmentRaw, string $locale, array $cfg): string
{
    $nextAssessmentRaw = trim($nextAssessmentRaw);
    if ($nextAssessmentRaw === '') {
        return 'Discuss with supervisor';
    }

    try {
        $target = (new DateTimeImmutable($nextAssessmentRaw))->modify('-30 days');
        return app_format_display_date($target->format('Y-m-d'), $locale, $cfg, 'long');
    } catch (Throwable $e) {
        return 'Discuss with supervisor';
    }
}

function personal_report_competency_area_rows(array $sectionBreakdowns): array
{
    $rows = [];
    foreach ($sectionBreakdowns as $radar) {
        $questionnaire = trim((string)($radar['title'] ?? ''));
        foreach (array_values($radar['sections'] ?? []) as $index => $section) {
            $score = isset($section['score']) && is_numeric($section['score']) ? (float)$section['score'] : null;
            $label = trim((string)($section['label'] ?? ''));
            if ($label === '') {
                $label = 'Competency Area';
            }
            $rows[] = [
                'code' => 'CA' . (count($rows) + 1),
                'local_code' => 'CA' . ($index + 1),
                'questionnaire' => $questionnaire,
                'label' => $label,
                'score' => $score,
                'gap' => questionnaire_competency_gap($score),
            ];
        }
    }

    return $rows;
}

function personal_report_rank_competency_rows(array $rows, bool $descending, int $limit): array
{
    $scored = array_values(array_filter($rows, static fn(array $row): bool => isset($row['score']) && $row['score'] !== null));
    usort($scored, static function (array $a, array $b) use ($descending): int {
        $left = (float)($a['score'] ?? 0.0);
        $right = (float)($b['score'] ?? 0.0);
        return $descending ? ($right <=> $left) : ($left <=> $right);
    });

    return array_slice($scored, 0, max(1, $limit));
}

function personal_report_competency_summary_text(array $rows, array $translations, bool $strengths): string
{
    if (!$rows) {
        return $strengths
            ? t($translations, 'no_strengths_available', 'Strengths will appear once section scores are available.')
            : t($translations, 'no_development_areas_available', 'Development areas will appear once section scores are available.');
    }

    $parts = [];
    foreach ($rows as $row) {
        $parts[] = sprintf('%s: %s', (string)$row['label'], personal_report_format_percent($row['score']));
    }

    return implode('; ', $parts);
}

function personal_report_competency_scale_rows(array $translations): array
{
    $rows = [];
    foreach (competency_default_level_bands() as $band) {
        $name = (string)($band['name'] ?? '');
        $details = questionnaire_competency_details((float)($band['min_pct'] ?? 0.0));
        $rows[] = [
            sprintf('%d–%d%%', (int)round((float)$band['min_pct']), (int)round((float)$band['max_pct'])),
            $name,
            $details['interpretation'] !== '' ? $details['interpretation'] : t($translations, 'competency_level_description', 'Competency level description'),
        ];
    }

    return $rows;
}

function personal_report_trend_points(array $rows): array
{
    $points = [];
    foreach ($rows as $index => $row) {
        if (!isset($row['score']) || $row['score'] === null || !is_numeric($row['score'])) {
            continue;
        }
        $label = resolve_performance_year($row);
        if ($label === '') {
            $label = 'Response ' . ($index + 1);
        }
        $period = trim((string)($row['period_label'] ?? ''));
        if ($period !== '' && $period !== $label) {
            $label .= ' ' . $period;
        }
        $points[] = [
            'label' => $label,
            'value' => (float)$row['score'],
            'count' => 1,
        ];
    }

    return $points;
}

function personal_report_trend_summary_rows(array $trendPoints, array $translations): array
{
    $count = count($trendPoints);
    if ($count === 0) {
        return [];
    }

    $first = (float)($trendPoints[0]['value'] ?? 0.0);
    $latest = (float)($trendPoints[$count - 1]['value'] ?? 0.0);
    $rows = [
        [t($translations, 'first_scored_response', 'First scored response'), personal_report_format_percent($first)],
        [t($translations, 'latest_score', 'Latest score'), personal_report_format_percent($latest)],
    ];
    if ($count > 1) {
        $previous = (float)($trendPoints[$count - 2]['value'] ?? 0.0);
        $rows[] = [t($translations, 'change_since_previous', 'Change since previous'), sprintf('%+.1f percentage points', $latest - $previous)];
        $rows[] = [t($translations, 'overall_change', 'Overall change'), sprintf('%+.1f percentage points', $latest - $first)];
    }

    return $rows;
}

function personal_report_improvement_plan_rows(array $developmentRows, array $recommendedCourses, string $targetDate, array $translations): array
{
    $rows = [];
    $developmentRows = $developmentRows ?: [[
        'label' => t($translations, 'general_development', 'General development'),
        'score' => null,
    ]];
    $courseTitles = array_values(array_filter(array_map(static fn(array $course): string => trim((string)($course['title'] ?? '')), $recommendedCourses)));

    foreach (array_slice($developmentRows, 0, 3) as $index => $area) {
        $score = $area['score'] ?? null;
        $action = $score !== null ? questionnaire_competency_recommendation((float)$score) : t($translations, 'discuss_development_action', 'Discuss targeted development action with supervisor');
        $rows[] = [
            (string)($area['label'] ?? t($translations, 'development_area', 'Development area')),
            $action,
            $courseTitles[$index] ?? ($courseTitles[0] ?? t($translations, 'supervisor_assigned_learning', 'Supervisor-assigned learning')),
            $targetDate,
            t($translations, 'not_started', 'Not started'),
        ];
    }

    return $rows;
}

function personal_report_methodology_notes(array $sectionBreakdowns, array $rows, array $recommendedCourses, array $translations): array
{
    $notes = [
        t($translations, 'methodology_scores_percentage', 'Scores are reported as percentages from the scored questionnaire items available for each response.'),
        t($translations, 'methodology_section_scores', 'Section and competency area scores use eligible scorable items; display-only, group, and non-scored items may be excluded.'),
        t($translations, 'methodology_gap', 'Gap values compare the recorded score with a 100% target unless a separate benchmark is configured.'),
        t($translations, 'methodology_training_matches', 'Training recommendations are matched from active course catalogue rules for your work function, questionnaire, and score band.'),
    ];

    if (!$sectionBreakdowns) {
        $notes[] = t($translations, 'note_no_section_breakdown', 'No section-level breakdown is available for the latest scored questionnaires.');
    }
    if (!$rows) {
        $notes[] = t($translations, 'note_no_responses', 'No responses are available yet, so this report is limited to profile information.');
    }
    if (!$recommendedCourses) {
        $notes[] = t($translations, 'note_no_course_mapping', 'No active course mapping currently matches the latest score bands.');
    }

    return $notes;
}

function resolve_performance_year(array $row): string
{
    if (!empty($row['period_start'])) {
        $periodTime = strtotime((string) $row['period_start']);
        if ($periodTime) {
            return date('Y', $periodTime);
        }
    }
    if (!empty($row['period_label'])) {
        $candidate = (string) $row['period_label'];
        if (preg_match('/(20\d{2}|19\d{2})/u', $candidate, $matches)) {
            return $matches[1];
        }
        return $candidate;
    }
    if (!empty($row['created_at'])) {
        $createdTime = strtotime((string) $row['created_at']);
        if ($createdTime) {
            return date('Y', $createdTime);
        }
        return (string) $row['created_at'];
    }
    return '';
}
