<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/simple_pdf.php';
require_once __DIR__ . '/lib/course_recommendations.php';
require_once __DIR__ . '/lib/analytics_report.php';
require_once __DIR__ . '/lib/performance_sections.php';

auth_required(['staff', 'supervisor', 'admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
ensure_course_recommendation_schema($pdo);
$userId = (int) ($user['id'] ?? 0);

if ($userId <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("SELECT qr.*, q.title, pp.label AS period_label, pp.period_start
    FROM questionnaire_response qr
    JOIN questionnaire q ON q.id = qr.questionnaire_id
    JOIN performance_period pp ON pp.id = qr.performance_period_id
    WHERE qr.user_id = ?
    ORDER BY qr.created_at ASC");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$latestEntry = null;
$latestScores = [];
foreach ($rows as $row) {
    $latestScores[$row['questionnaire_id']] = $row;
    if ($latestEntry === null || strtotime((string) ($row['created_at'] ?? '')) > strtotime((string) ($latestEntry['created_at'] ?? ''))) {
        $latestEntry = $row;
    }
}

$nextAssessmentDisplay = '';
$nextAssessmentRaw = (string) ($user['next_assessment_date'] ?? '');
if ($nextAssessmentRaw !== '') {
    $timestamp = strtotime($nextAssessmentRaw);
    if ($timestamp) {
        $nextAssessmentDisplay = date('F j, Y', $timestamp);
    } else {
        $nextAssessmentDisplay = $nextAssessmentRaw;
    }
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
            $recommendedCourses[$course['id']] = $course;
        }
    }
}
$recommendedCourses = array_slice(array_values($recommendedCourses), 0, 6);

$sectionBreakdowns = compute_section_breakdowns($pdo, array_values($latestScores), $t);

$generatedAt = new DateTimeImmutable('now');
$siteName = (string) ($cfg['site_name'] ?? 'My Performance');

$pdf = new SimplePdfDocument();
$logoSpec = analytics_report_header_logo_spec($pdf, $cfg);
$pdf->setHeader($siteName, t($t, 'my_performance_pdf_subtitle', 'Personal performance summary'), $logoSpec);
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
    $userDetails[] = t($t, 'employee_department', 'Department') . ': ' . $departmentLabel;
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
$pdf->addHeading(t($t, 'my_performance', 'My Performance'));
$pdf->addParagraph(sprintf(
    '%s %s',
    t($t, 'my_performance_pdf_intro', 'Generated on'),
    $generatedAt->format('Y-m-d H:i')
));

$summaryRows = [
    [t($t, 'total_responses', 'Responses submitted'), (string) $submittedCount],
    [t($t, 'approved', 'Approved'), (string) $approvedCount],
    [t($t, 'status_draft', 'Draft'), (string) $draftCount],
    [t($t, 'status_rejected', 'Rejected'), (string) $rejectedCount],
];
if ($averageScore !== null) {
    $summaryRows[] = [t($t, 'average_score', 'Average score (%)'), number_format($averageScore, 1)];
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

$pdf->addSubheading(t($t, 'performance_overview', 'Performance Overview'));
$pdf->addTable([
    t($t, 'metric', 'Metric'),
    t($t, 'value', 'Value'),
], $summaryRows, [70, 40]);

if ($sectionBreakdowns) {
    $pdf->addSubheading(t($t, 'section_breakdown', 'Section score radar'));
    $pdf->addParagraph(t($t, 'section_breakdown_hint', 'Each radar shows how your latest submission performed across questionnaire sections.'));
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
        $chartImage = analytics_report_generate_radar_chart($radar['sections'] ?? [], $palette, [
            'max_value' => 100,
            'value_suffix' => '%',
        ]);
        if ($chartImage) {
            $pdf->addImageBlock($chartImage['data'], $chartImage['width'], $chartImage['height'], 420.0);
        } else {
            $rows = [];
            foreach ($radar['sections'] ?? [] as $section) {
                $label = (string)($section['label'] ?? '');
                $score = isset($section['score']) ? number_format((float)$section['score'], 1) . '%' : '—';
                $rows[] = [$label, $score];
            }
            if ($rows) {
                $pdf->addTable([
                    t($t, 'section_placeholder', 'Section'),
                    t($t, 'score', 'Score (%)'),
                ], $rows, [70, 30]);
            }
        }
    }
}

$pdf->addSubheading(t($t, 'recent_responses', 'Recent responses'));
if ($rows) {
    $responseRows = [];
    $limit = 20;
    foreach (array_slice($rows, -$limit) as $row) {
        $responseRows[] = [
            resolve_performance_year($row),
            (string) ($row['title'] ?? ''),
            (string) ($row['period_label'] ?? ''),
            isset($row['score']) && $row['score'] !== null ? number_format((float) $row['score'], 0) . '%' : '—',
            t($t, 'status_' . ($row['status'] ?? 'submitted'), ucfirst((string) ($row['status'] ?? 'submitted'))),
        ];
    }
    $pdf->addTable([
        t($t, 'year', 'Year'),
        t($t, 'questionnaire', 'Questionnaire'),
        t($t, 'performance_period', 'Performance Period'),
        t($t, 'score', 'Score (%)'),
        t($t, 'status', 'Status'),
    ], $responseRows, [20, 60, 60, 28, 32]);
} else {
    $pdf->addParagraph(t($t, 'no_submissions_yet', 'No submissions recorded yet. Complete your first assessment to see insights.'));
}

if ($recommendedCourses) {
    $pdf->addSubheading(t($t, 'recommended_courses', 'Recommended Courses'));
    $courseRows = [];
    foreach ($recommendedCourses as $course) {
        $courseRows[] = [
            (string) ($course['title'] ?? ''),
            (string) ($course['moodle_url'] ?? ''),
            sprintf('%d – %d%%', (int) ($course['min_score'] ?? 0), (int) ($course['max_score'] ?? 100)),
        ];
    }
    $pdf->addTable([
        t($t, 'course', 'Course'),
        t($t, 'link', 'Link'),
        t($t, 'score_band', 'Score Band'),
    ], $courseRows, [70, 60, 40]);
}

$pdf->addParagraph(t($t, 'my_performance_pdf_footer', 'For the most up-to-date analytics and section breakdowns, sign in to the portal.'));
$pdf->addSignatureFields([
    [
        t($t, 'staff_name', 'Staff name'),
        t($t, 'staff_signature', 'Staff signature'),
    ],
    [
        t($t, 'supervisor_name', 'Supervisor name'),
        t($t, 'supervisor_signature', 'Supervisor signature'),
    ],
]);

$filename = sprintf(
    'my-performance-%s-%s.pdf',
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
