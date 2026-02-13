<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('available_work_functions')) {
    require_once __DIR__ . '/../lib/work_functions.php';
}
require_once __DIR__ . '/../lib/analytics_report.php';
require_once __DIR__ . '/../lib/scoring.php';

/**
 * Decode a stored questionnaire response answer payload.
 */
function analytics_decode_answer_json($raw): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $options = defined('JSON_THROW_ON_ERROR') ? JSON_THROW_ON_ERROR : 0;
    try {
        $decoded = json_decode($raw, true, 512, $options);
    } catch (Throwable $e) {
        return [];
    }
    return is_array($decoded) ? $decoded : [];
}

/**
 * Score a single questionnaire item using the stored answers.
 */
function analytics_score_item(array $item, array $answerSet, float $weight): float
{
    if ($weight <= 0) {
        return 0.0;
    }
    $type = (string)($item['type'] ?? 'text');
    if ($type === 'boolean') {
        foreach ($answerSet as $entry) {
            if ((isset($entry['valueBoolean']) && $entry['valueBoolean'])
                || (isset($entry['valueString']) && strtolower((string)$entry['valueString']) === 'true')
            ) {
                return $weight;
            }
        }
        return 0.0;
    }
    if ($type === 'likert') {
        $score = null;
        foreach ($answerSet as $entry) {
            if (isset($entry['valueInteger']) && is_numeric($entry['valueInteger'])) {
                $score = (int)$entry['valueInteger'];
                break;
            }
            if (isset($entry['valueString'])) {
                $candidate = trim((string)$entry['valueString']);
                if (preg_match('/^([1-5])/', $candidate, $matches)) {
                    $score = (int)$matches[1];
                    break;
                }
                if (is_numeric($candidate)) {
                    $value = (int)$candidate;
                    if ($value >= 1 && $value <= 5) {
                        $score = $value;
                        break;
                    }
                }
            }
        }
        if ($score !== null && $score >= 1 && $score <= 5) {
            return $weight * $score / 5.0;
        }
        return 0.0;
    }
    if ($type === 'choice') {
        if (empty($item['allow_multiple'])) {
            $correct = isset($item['correct_value']) ? (string)$item['correct_value'] : '';
            if ($correct === '') {
                return 0.0;
            }
            foreach ($answerSet as $entry) {
                if (isset($entry['valueString']) && (string)$entry['valueString'] === $correct) {
                    return $weight;
                }
            }
            return 0.0;
        }
        foreach ($answerSet as $entry) {
            if (isset($entry['valueString']) && trim((string)$entry['valueString']) !== '') {
                return $weight;
            }
        }
        return 0.0;
    }
    foreach ($answerSet as $entry) {
        if (isset($entry['valueString']) && trim((string)$entry['valueString']) !== '') {
            return $weight;
        }
    }
    return 0.0;
}

/**
 * Fetch weighted questionnaire items for the supplied questionnaire identifiers.
 *
 * @return array<int, array<int, array<string, mixed>>> keyed by questionnaire id.
 */
function analytics_fetch_scoring_items(PDO $pdo, array $questionnaireIds): array
{
    $ids = array_values(array_unique(array_map('intval', $questionnaireIds)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT id, questionnaire_id, linkId, type, allow_multiple, COALESCE(weight_percent,0) AS weight_percent '
        . 'FROM questionnaire_item '
        . 'WHERE questionnaire_id IN (' . $placeholders . ') '
        . 'ORDER BY questionnaire_id, order_index, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $rawItems = [];
    $itemIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $qid = (int)($row['questionnaire_id'] ?? 0);
        if ($qid <= 0) {
            continue;
        }
        $itemId = (int)($row['id'] ?? 0);
        $rawItems[$qid][] = [
            'id' => $itemId,
            'linkId' => (string)($row['linkId'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'allow_multiple' => !empty($row['allow_multiple']),
            'weight_percent' => (float)($row['weight_percent'] ?? 0.0),
        ];
        if ($itemId > 0) {
            $itemIds[] = $itemId;
        }
    }

    $correctByItem = [];
    if ($itemIds) {
        $itemIds = array_values(array_unique($itemIds));
        $optionPlaceholder = implode(',', array_fill(0, count($itemIds), '?'));
        $optStmt = $pdo->prepare(
            "SELECT questionnaire_item_id, value FROM questionnaire_item_option " .
            "WHERE questionnaire_item_id IN ($optionPlaceholder) AND is_correct=1 " .
            "ORDER BY questionnaire_item_id, order_index, id"
        );
        $optStmt->execute($itemIds);
        foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemId = (int)$row['questionnaire_item_id'];
            if (!isset($correctByItem[$itemId])) {
                $correctByItem[$itemId] = (string)($row['value'] ?? '');
            }
        }
    }

    $nonScorableTypes = ['display', 'group', 'section'];
    $itemsByQuestionnaire = [];
    foreach ($rawItems as $qid => $items) {
        $singleChoiceWeights = questionnaire_even_single_choice_weights($items);
        $likertWeights = questionnaire_even_likert_weights($items);
        foreach ($items as $item) {
            $weight = questionnaire_resolve_effective_weight(
                $item,
                $singleChoiceWeights,
                $likertWeights,
                !in_array($item['type'], $nonScorableTypes, true)
            );
            if ($weight <= 0.0) {
                continue;
            }
            $itemsByQuestionnaire[$qid][] = [
                'linkId' => (string)($item['linkId'] ?? ''),
                'type' => (string)($item['type'] ?? ''),
                'allow_multiple' => !empty($item['allow_multiple']),
                'weight' => $weight,
                'correct_value' => $correctByItem[(int)($item['id'] ?? 0)] ?? null,
            ];
        }
    }
    return $itemsByQuestionnaire;
}

/**
 * Fetch decoded answers for a set of response identifiers.
 *
 * @return array<int, array<string, array<int, mixed>>> keyed by response id then linkId.
 */
function analytics_fetch_answers(PDO $pdo, array $responseIds): array
{
    $ids = array_values(array_unique(array_map('intval', $responseIds)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT response_id, linkId, answer FROM questionnaire_response_item '
        . 'WHERE response_id IN (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $answers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rid = (int)($row['response_id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $linkId = (string)($row['linkId'] ?? '');
        if ($linkId === '') {
            continue;
        }
        $answers[$rid][$linkId] = analytics_decode_answer_json($row['answer'] ?? '');
    }
    return $answers;
}

/**
 * Calculate a weighted score for a response.
 */
function analytics_compute_response_score(array $items, array $answers): ?float
{
    $totalWeight = 0.0;
    $achieved = 0.0;
    foreach ($items as $item) {
        $weight = (float)($item['weight'] ?? 0.0);
        if ($weight <= 0) {
            continue;
        }
        $totalWeight += $weight;
        $answerSet = $answers[$item['linkId']] ?? [];
        $achieved += analytics_score_item($item, $answerSet, $weight);
    }
    if ($totalWeight <= 0) {
        return null;
    }
    return round(($achieved / $totalWeight) * 100, 1);
}

/**
 * Compute fallback scores and averages for analytics visualisations.
 *
 * @return array{array<int,float>, array<int,float>, array<string,float>, ?float}
 */
function analytics_resolve_score_fallbacks(PDO $pdo, array $responseRows): array
{
    $responseQuestionnaire = [];
    $responsesNeedingScore = [];
    foreach ($responseRows as $row) {
        $rid = (int)($row['id'] ?? 0);
        $qid = (int)($row['questionnaire_id'] ?? 0);
        if ($rid <= 0 || $qid <= 0) {
            continue;
        }
        $responseQuestionnaire[$rid] = $qid;
        $status = strtolower((string)($row['status'] ?? 'submitted'));
        if ($status !== 'draft' && $row['score'] === null) {
            $responsesNeedingScore[] = $rid;
        }
    }

    $computedScores = [];
    if ($responsesNeedingScore) {
        $questionnaireIds = array_map(static function ($rid) use ($responseQuestionnaire) {
            return $responseQuestionnaire[$rid] ?? 0;
        }, $responsesNeedingScore);
        $itemsByQuestionnaire = analytics_fetch_scoring_items($pdo, $questionnaireIds);
        $answersByResponse = analytics_fetch_answers($pdo, $responsesNeedingScore);
        foreach ($responsesNeedingScore as $rid) {
            $qid = $responseQuestionnaire[$rid] ?? 0;
            if ($qid <= 0 || empty($itemsByQuestionnaire[$qid])) {
                continue;
            }
            $score = analytics_compute_response_score($itemsByQuestionnaire[$qid], $answersByResponse[$rid] ?? []);
            if ($score !== null) {
                $computedScores[$rid] = $score;
            }
        }
    }

    $questionnaireSums = [];
    $workFunctionSums = [];
    $overall = ['sum' => 0.0, 'count' => 0];
    foreach ($responseRows as $row) {
        $rid = (int)($row['id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $status = strtolower((string)($row['status'] ?? 'submitted'));
        if ($status === 'draft') {
            continue;
        }
        $score = $row['score'];
        if ($score === null && isset($computedScores[$rid])) {
            $score = $computedScores[$rid];
        } elseif ($score !== null) {
            $score = (float)$score;
        }
        if ($score === null) {
            continue;
        }
        $qid = (int)($row['questionnaire_id'] ?? 0);
        $wf = (string)($row['work_function'] ?? '');
        if ($qid > 0) {
            $questionnaireSums[$qid]['sum'] = ($questionnaireSums[$qid]['sum'] ?? 0.0) + $score;
            $questionnaireSums[$qid]['count'] = ($questionnaireSums[$qid]['count'] ?? 0) + 1;
        }
        $workFunctionSums[$wf]['sum'] = ($workFunctionSums[$wf]['sum'] ?? 0.0) + $score;
        $workFunctionSums[$wf]['count'] = ($workFunctionSums[$wf]['count'] ?? 0) + 1;
        $overall['sum'] += $score;
        $overall['count'] += 1;
    }

    $questionnaireAverages = [];
    foreach ($questionnaireSums as $qid => $agg) {
        if (($agg['count'] ?? 0) > 0) {
            $questionnaireAverages[$qid] = round($agg['sum'] / $agg['count'], 1);
        }
    }
    $workFunctionAverages = [];
    foreach ($workFunctionSums as $wf => $agg) {
        if (($agg['count'] ?? 0) > 0) {
            $workFunctionAverages[$wf] = round($agg['sum'] / $agg['count'], 1);
        }
    }
    $overallAverage = $overall['count'] > 0 ? round($overall['sum'] / $overall['count'], 1) : null;

    return [$computedScores, $questionnaireAverages, $workFunctionAverages, $overallAverage];
}
auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$reportMessage = '';
$reportError = '';

if (isset($_SESSION['analytics_report_flash']) && is_array($_SESSION['analytics_report_flash'])) {
    $flash = $_SESSION['analytics_report_flash'];
    unset($_SESSION['analytics_report_flash']);
    if ($reportMessage === '' && !empty($flash['message'])) {
        $reportMessage = (string)$flash['message'];
    }
    if ($reportError === '' && !empty($flash['error'])) {
        $reportError = (string)$flash['error'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'send-report') {
        $recipientInput = trim((string)($_POST['report_recipients'] ?? ''));
        $selectedQuestionnaire = (int)($_POST['report_questionnaire_id'] ?? 0);
        $includeDetails = !empty($_POST['report_include_details']);
        $recipients = analytics_report_parse_recipients($recipientInput);
        if (!$recipients) {
            $reportError = t($t, 'analytics_report_recipients_required', 'Please provide at least one valid email address.');
        } else {
            $targetQuestionnaire = $selectedQuestionnaire > 0 ? $selectedQuestionnaire : null;
            try {
                $snapshot = analytics_report_snapshot($pdo, $targetQuestionnaire, $includeDetails);
                $pdfData = analytics_report_render_pdf($snapshot, $cfg);
                /** @var DateTimeImmutable $generatedAt */
                $generatedAt = $snapshot['generated_at'];
                $filename = analytics_report_filename($snapshot['selected_questionnaire_id'], $generatedAt);
                $siteName = trim((string)($cfg['site_name'] ?? 'HR Assessment'));
                $subject = ($siteName !== '' ? $siteName : 'HR Assessment') . ' analytics report - ' . $generatedAt->format('Y-m-d');
                $bodyLines = [
                    'Hello,',
                    '',
                    'Please find the attached analytics report generated on ' . $generatedAt->format('Y-m-d H:i') . '.',
                ];
                if ($includeDetails && !empty($snapshot['selected_questionnaire_title'])) {
                    $bodyLines[] = 'Questionnaire focus: ' . $snapshot['selected_questionnaire_title'];
                }
                $bodyLines[] = '';
                $bodyLines[] = 'Regards,';
                $bodyLines[] = $siteName !== '' ? $siteName : 'HR Assessment';
                $attachments = [[
                    'filename' => $filename,
                    'content' => $pdfData,
                    'content_type' => 'application/pdf',
                ]];
                if (send_notification_email($cfg, $recipients, $subject, implode("\n", $bodyLines), $attachments)) {
                    $reportMessage = t($t, 'analytics_report_sent', 'Analytics report emailed successfully.');
                } else {
                    $reportError = t($t, 'analytics_report_send_failed', 'Unable to send the analytics report email.');
                }
            } catch (Throwable $e) {
                error_log('analytics report send failed: ' . $e->getMessage());
                $reportError = t($t, 'analytics_report_send_failed', 'Unable to send the analytics report email.');
            }
        }
    } elseif ($action === 'create-schedule') {
        $recipientInput = trim((string)($_POST['schedule_recipients'] ?? ''));
        $frequency = strtolower(trim((string)($_POST['schedule_frequency'] ?? 'weekly')));
        if (!in_array($frequency, analytics_report_allowed_frequencies(), true)) {
            $frequency = 'weekly';
        }
        $includeDetails = !empty($_POST['schedule_include_details']);
        $questionnaireSelection = (int)($_POST['schedule_questionnaire_id'] ?? 0);
        $recipients = analytics_report_parse_recipients($recipientInput);
        if (!$recipients) {
            $reportError = t($t, 'analytics_report_recipients_required', 'Please provide at least one valid email address.');
        } else {
            $startInput = trim((string)($_POST['schedule_start_at'] ?? ''));
            if ($startInput === '') {
                $startAt = new DateTimeImmutable('now');
            } else {
                $startAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startInput) ?: null;
            }
            if (!$startAt) {
                $reportError = t($t, 'analytics_schedule_start_invalid', 'Please provide a valid start date and time.');
            } else {
                $targetQuestionnaire = $questionnaireSelection > 0 ? $questionnaireSelection : null;
                $recipientsStored = implode(', ', $recipients);
                $createdBy = $_SESSION['user']['id'] ?? null;
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO analytics_report_schedule (recipients, frequency, next_run_at, last_run_at, created_by, questionnaire_id, include_details, active, created_at, updated_at) '
                        . 'VALUES (?, ?, ?, NULL, ?, ?, ?, 1, NOW(), NOW())'
                    );
                    $stmt->execute([
                        $recipientsStored,
                        $frequency,
                        $startAt->format('Y-m-d H:i:s'),
                        $createdBy,
                        $targetQuestionnaire,
                        $includeDetails ? 1 : 0,
                    ]);
                    $reportMessage = t($t, 'analytics_schedule_created', 'Report schedule created successfully.');
                } catch (PDOException $e) {
                    error_log('analytics schedule create failed: ' . $e->getMessage());
                    $reportError = t($t, 'analytics_schedule_create_failed', 'Unable to save the schedule. Please try again.');
                }
            }
        }
    } elseif ($action === 'toggle-schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            try {
                $rowStmt = $pdo->prepare('SELECT active FROM analytics_report_schedule WHERE id = ?');
                $rowStmt->execute([$scheduleId]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $newStatus = ((int)($row['active'] ?? 0) === 1) ? 0 : 1;
                    $update = $pdo->prepare('UPDATE analytics_report_schedule SET active = ?, updated_at = NOW() WHERE id = ?');
                    $update->execute([$newStatus, $scheduleId]);
                    $reportMessage = $newStatus
                        ? t($t, 'analytics_schedule_enabled', 'Schedule enabled.')
                        : t($t, 'analytics_schedule_paused', 'Schedule paused.');
                } else {
                    $reportError = t($t, 'analytics_schedule_missing', 'Schedule not found.');
                }
            } catch (PDOException $e) {
                error_log('analytics schedule toggle failed: ' . $e->getMessage());
                $reportError = t($t, 'analytics_schedule_update_failed', 'Unable to update the schedule.');
            }
        } else {
            $reportError = t($t, 'analytics_schedule_missing', 'Schedule not found.');
        }
    } elseif ($action === 'delete-schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM analytics_report_schedule WHERE id = ?');
                $stmt->execute([$scheduleId]);
                $reportMessage = t($t, 'analytics_schedule_deleted', 'Schedule removed.');
            } catch (PDOException $e) {
                error_log('analytics schedule delete failed: ' . $e->getMessage());
                $reportError = t($t, 'analytics_schedule_update_failed', 'Unable to update the schedule.');
            }
        } else {
            $reportError = t($t, 'analytics_schedule_missing', 'Schedule not found.');
        }
    }
}

$summaryStmt = $pdo->query(
    "SELECT COUNT(*) AS total_responses, "
    . "SUM(status='approved') AS approved_count, "
    . "SUM(status='submitted') AS submitted_count, "
    . "SUM(status='draft') AS draft_count, "
    . "SUM(status='rejected') AS rejected_count, "
    . "AVG(score) AS avg_score, "
    . "MAX(created_at) AS latest_at "
    . "FROM questionnaire_response"
);
$summary = $summaryStmt ? $summaryStmt->fetch(PDO::FETCH_ASSOC) : [];

$totalParticipants = (int)($pdo->query('SELECT COUNT(DISTINCT user_id) FROM questionnaire_response')->fetchColumn() ?: 0);

$questionnaireStmt = $pdo->query(
    "SELECT q.id, q.title, COUNT(*) AS total_responses, "
    . "SUM(qr.status='approved') AS approved_count, "
    . "SUM(qr.status='submitted') AS submitted_count, "
    . "SUM(qr.status='draft') AS draft_count, "
    . "SUM(qr.status='rejected') AS rejected_count, "
    . "AVG(qr.score) AS avg_score "
    . "FROM questionnaire_response qr "
    . "JOIN questionnaire q ON q.id = qr.questionnaire_id "
    . "GROUP BY q.id, q.title "
    . "ORDER BY q.title"
);
$questionnaires = $questionnaireStmt ? $questionnaireStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$responseMetaStmt = $pdo->query(
    'SELECT qr.id, qr.questionnaire_id, qr.user_id, qr.score, qr.status, u.work_function '
    . 'FROM questionnaire_response qr '
    . 'JOIN users u ON u.id = qr.user_id'
);
$responseMetaRows = $responseMetaStmt ? $responseMetaStmt->fetchAll(PDO::FETCH_ASSOC) : [];
[$computedResponseScores, $questionnaireFallbackAverages, $workFunctionFallbackAverages, $overallFallbackAverage]
    = analytics_resolve_score_fallbacks($pdo, $responseMetaRows);
if (($summary['avg_score'] ?? null) === null && $overallFallbackAverage !== null) {
    $summary['avg_score'] = $overallFallbackAverage;
}

$overallKpiTracker = [];
$questionnaireKpiTracker = [];
foreach ($responseMetaRows as $row) {
    $rid = (int)($row['id'] ?? 0);
    $qid = (int)($row['questionnaire_id'] ?? 0);
    $userId = (int)($row['user_id'] ?? 0);
    if ($rid <= 0 || $qid <= 0 || $userId <= 0) {
        continue;
    }
    $status = strtolower((string)($row['status'] ?? ''));
    if ($status === 'draft') {
        continue;
    }
    $score = $row['score'];
    if ($score === null && isset($computedResponseScores[$rid])) {
        $score = $computedResponseScores[$rid];
    } elseif ($score !== null) {
        $score = (float)$score;
    }
    if ($score === null) {
        continue;
    }
    if (!isset($overallKpiTracker[$userId]) || $score > $overallKpiTracker[$userId]) {
        $overallKpiTracker[$userId] = $score;
    }
    if (!isset($questionnaireKpiTracker[$qid][$userId]) || $score > $questionnaireKpiTracker[$qid][$userId]) {
        $questionnaireKpiTracker[$qid][$userId] = $score;
    }
}

$overallKpiStats = [
    'total' => count($overallKpiTracker),
    'hit' => 0,
    'percent' => null,
];
foreach ($overallKpiTracker as $score) {
    if ($score >= 80.0) {
        $overallKpiStats['hit'] += 1;
    }
}
if ($overallKpiStats['total'] > 0) {
    $overallKpiStats['percent'] = round(($overallKpiStats['hit'] / $overallKpiStats['total']) * 100, 1);
}

$questionnaireKpiStats = [];
foreach ($questionnaireKpiTracker as $qid => $userScores) {
    $totalUsers = count($userScores);
    $hitCount = 0;
    foreach ($userScores as $score) {
        if ($score >= 80.0) {
            $hitCount += 1;
        }
    }
    $questionnaireKpiStats[$qid] = [
        'total' => $totalUsers,
        'hit' => $hitCount,
        'percent' => $totalUsers > 0 ? round(($hitCount / $totalUsers) * 100, 1) : null,
    ];
}

$questionnaireIds = array_map(static fn($row) => (int)$row['id'], $questionnaires);
$selectedQuestionnaireId = (int)($_GET['questionnaire_id'] ?? 0);
if ($questionnaires) {
    if (!$selectedQuestionnaireId || !in_array($selectedQuestionnaireId, $questionnaireIds, true)) {
        $selectedQuestionnaireId = (int)$questionnaires[0]['id'];
    }
} else {
    $selectedQuestionnaireId = 0;
}

if (isset($summary['avg_score']) && $summary['avg_score'] !== null) {
    $summary['avg_score'] = (float)$summary['avg_score'];
}

foreach ($questionnaires as &$questionnaireRow) {
    $qid = (int)($questionnaireRow['id'] ?? 0);
    if ($questionnaireRow['avg_score'] !== null) {
        $questionnaireRow['avg_score'] = (float)$questionnaireRow['avg_score'];
    } elseif (isset($questionnaireFallbackAverages[$qid])) {
        $questionnaireRow['avg_score'] = $questionnaireFallbackAverages[$qid];
    }
    $questionnaireRow['kpi_total_users'] = $questionnaireKpiStats[$qid]['total'] ?? 0;
    $questionnaireRow['kpi_hit_users'] = $questionnaireKpiStats[$qid]['hit'] ?? 0;
    $questionnaireRow['kpi_percent'] = $questionnaireKpiStats[$qid]['percent'] ?? null;
}
unset($questionnaireRow);

$kpiBreakdownRows = $questionnaires;
usort($kpiBreakdownRows, static function (array $a, array $b): int {
    $aPercent = $a['kpi_percent'] ?? -1;
    $bPercent = $b['kpi_percent'] ?? -1;
    if ($aPercent === $bPercent) {
        return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    }
    return ($bPercent <=> $aPercent);
});

$selectedResponses = [];
$selectedUserBreakdown = [];
$sectionColumns = [];
$sectionScoresByResponse = [];
$sectionAggregates = [];

$downloadUrlFor = static function (array $params = []): string {
    $path = 'admin/analytics_download.php';
    if ($params) {
        $path .= '?' . http_build_query($params);
    }
    return url_for($path);
};

$lookerStudioQuery = <<<'SQL'
SELECT
  qr.id AS response_id,
  qr.created_at AS response_created_at,
  qr.status AS response_status,
  qr.score AS response_score,
  qr.reviewed_at,
  qr.review_comment,
  pp.label AS performance_period,
  q.id AS questionnaire_id,
  q.title AS questionnaire_title,
  u.id AS user_id,
  u.username,
  u.full_name,
  u.email,
  u.department,
  u.cadre,
  u.work_function,
  u.gender,
  u.account_status,
  u.created_at AS user_created_at,
  reviewer.full_name AS reviewer_name,
  reviewer.email AS reviewer_email,
  qi.id AS item_id,
  qi.linkId AS item_link_id,
  qi.text AS item_text,
  qi.type AS item_type,
  qs.title AS section_title,
  qri.answer AS item_answer,
  tr.recommended_courses,
  tr.recommendation_reasons
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN questionnaire q ON q.id = qr.questionnaire_id
LEFT JOIN users reviewer ON reviewer.id = qr.reviewed_by
LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id
LEFT JOIN questionnaire_response_item qri ON qri.response_id = qr.id
LEFT JOIN questionnaire_item qi ON qi.linkId = qri.linkId AND qi.questionnaire_id = qr.questionnaire_id
LEFT JOIN questionnaire_section qs ON qs.id = qi.section_id
LEFT JOIN (
  SELECT
    tr.questionnaire_response_id,
    GROUP_CONCAT(cc.title ORDER BY cc.title SEPARATOR '; ') AS recommended_courses,
    GROUP_CONCAT(tr.recommendation_reason ORDER BY cc.title SEPARATOR '; ') AS recommendation_reasons
  FROM training_recommendation tr
  JOIN course_catalogue cc ON cc.id = tr.course_id
  GROUP BY tr.questionnaire_response_id
) tr ON tr.questionnaire_response_id = qr.id;
SQL;

$defaultReportDownloads = [
    [
        'title' => t($t, 'analytics_download_summary', 'Overall summary report'),
        'description' => t($t, 'analytics_download_summary_hint', 'Includes total responses, averages, and questionnaire performance.'),
        'url' => $downloadUrlFor([]),
    ],
    [
        'title' => t($t, 'analytics_download_summary_details', 'Summary with top contributors'),
        'description' => t($t, 'analytics_download_summary_details_hint', 'Adds the leading contributors for the busiest questionnaire.'),
        'url' => $downloadUrlFor(['include_details' => 1]),
    ],
];

foreach ($questionnaires as $qRow) {
    $qid = (int)($qRow['id'] ?? 0);
    if ($qid <= 0) {
        continue;
    }
    $rawTitle = trim((string)($qRow['title'] ?? ''));
    $displayTitle = $rawTitle !== '' ? $rawTitle : t($t, 'questionnaire', 'Questionnaire');
    $displayTitleForFormat = str_replace('%', '%%', $displayTitle);
    $defaultReportDownloads[] = [
        'title' => sprintf(
            t($t, 'analytics_download_questionnaire_title', 'Questionnaire report: %s'),
            $displayTitleForFormat
        ),
        'description' => t(
            $t,
            'analytics_download_questionnaire_hint',
            'Detailed breakdown for this questionnaire, including top contributors.'
        ),
        'url' => $downloadUrlFor([
            'questionnaire_id' => $qid,
            'include_details' => 1,
        ]),
    ];
}
if ($selectedQuestionnaireId) {
    $responseStmt = $pdo->prepare(
        'SELECT qr.id, qr.status, qr.score, qr.created_at, qr.review_comment, '
        . 'u.id AS user_id, u.username, u.full_name, u.department, u.cadre, u.work_function, pp.label AS period_label '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . 'LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id '
        . 'WHERE qr.questionnaire_id = ? '
        . 'ORDER BY qr.created_at DESC'
    );
    $responseStmt->execute([$selectedQuestionnaireId]);
    $selectedResponses = $responseStmt->fetchAll(PDO::FETCH_ASSOC);

    $userStmt = $pdo->prepare(
        'SELECT u.id AS user_id, u.username, u.full_name, u.department, u.cadre, u.work_function, '
        . 'COUNT(*) AS total_responses, '
        . 'SUM(qr.status="approved") AS approved_count, '
        . 'AVG(qr.score) AS avg_score '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . 'WHERE qr.questionnaire_id = ? '
        . 'GROUP BY u.id, u.username, u.full_name, u.department, u.cadre, u.work_function '
        . 'ORDER BY avg_score DESC'
    );
    $userStmt->execute([$selectedQuestionnaireId]);
    $selectedUserBreakdown = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($selectedResponses) {
        $selectedUserScoreSums = [];
        foreach ($selectedResponses as &$responseRow) {
            $rid = (int)($responseRow['id'] ?? 0);
            if ($responseRow['score'] === null && isset($computedResponseScores[$rid])) {
                $responseRow['score'] = $computedResponseScores[$rid];
            } elseif ($responseRow['score'] !== null) {
                $responseRow['score'] = (float)$responseRow['score'];
            }
            $status = strtolower((string)($responseRow['status'] ?? ''));
            if ($status === 'draft') {
                continue;
            }
            $userId = (int)($responseRow['user_id'] ?? 0);
            if ($userId <= 0 || $responseRow['score'] === null) {
                continue;
            }
            $selectedUserScoreSums[$userId]['sum'] = ($selectedUserScoreSums[$userId]['sum'] ?? 0.0) + (float)$responseRow['score'];
            $selectedUserScoreSums[$userId]['count'] = ($selectedUserScoreSums[$userId]['count'] ?? 0) + 1;
        }
        unset($responseRow);

        if ($selectedUserBreakdown) {
            foreach ($selectedUserBreakdown as &$userRow) {
                $uid = (int)($userRow['user_id'] ?? 0);
                if ($userRow['avg_score'] !== null) {
                    $userRow['avg_score'] = (float)$userRow['avg_score'];
                    continue;
                }
                if (isset($selectedUserScoreSums[$uid]) && $selectedUserScoreSums[$uid]['count'] > 0) {
                    $userRow['avg_score'] = round(
                        $selectedUserScoreSums[$uid]['sum'] / $selectedUserScoreSums[$uid]['count'],
                        1
                    );
                }
            }
            unset($userRow);
        }
    }

    if ($selectedResponses) {
        $sectionStmt = $pdo->prepare(
            'SELECT id, title FROM questionnaire_section WHERE questionnaire_id=? ORDER BY order_index, id'
        );
        $sectionStmt->execute([$selectedQuestionnaireId]);
        $sectionLabels = [];
        $sectionOrder = [];
        foreach ($sectionStmt->fetchAll(PDO::FETCH_ASSOC) as $sectionRow) {
            $sid = (int)$sectionRow['id'];
            $sectionOrder[] = $sid;
            $sectionLabels[$sid] = trim((string)($sectionRow['title'] ?? ''));
        }

        $itemStmt = $pdo->prepare(
            'SELECT section_id, linkId, type, allow_multiple, COALESCE(weight_percent,0) AS weight '
            . 'FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index, id'
        );
        $itemStmt->execute([$selectedQuestionnaireId]);
        $rawItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $unassignedKey = 'unassigned';
        $generalLabel = t($t, 'unassigned_section_label', 'General');
        $sectionFallback = t($t, 'section_placeholder', 'Section');

        $baseSectionWeights = [];
        $scoringItems = [];
        foreach ($rawItems as $itemRow) {
            $sectionKey = $itemRow['section_id'] !== null ? (int)$itemRow['section_id'] : $unassignedKey;
            $weight = (float)($itemRow['weight'] ?? 0.0);
            if ($weight <= 0) {
                continue;
            }
            $baseSectionWeights[$sectionKey] = ($baseSectionWeights[$sectionKey] ?? 0.0) + $weight;
            $scoringItems[] = [
                'section_key' => $sectionKey,
                'linkId' => (string)($itemRow['linkId'] ?? ''),
                'type' => (string)($itemRow['type'] ?? 'text'),
                'allow_multiple' => !empty($itemRow['allow_multiple']),
                'weight' => $weight,
            ];
        }

        $sectionColumns = [];
        foreach ($sectionOrder as $sid) {
            if (($baseSectionWeights[$sid] ?? 0) <= 0) {
                continue;
            }
            $label = $sectionLabels[$sid] ?? '';
            $label = trim($label) !== '' ? $label : $sectionFallback;
            $sectionColumns[] = [
                'key' => $sid,
                'label' => $label,
            ];
        }
        if (($baseSectionWeights[$unassignedKey] ?? 0) > 0) {
            $sectionColumns[] = [
                'key' => $unassignedKey,
                'label' => $generalLabel,
            ];
        }

        if ($scoringItems && $sectionColumns) {
            $responseIds = array_map(static fn($row) => (int)$row['id'], $selectedResponses);
            $answersByResponse = [];
            if ($responseIds) {
                $placeholders = implode(',', array_fill(0, count($responseIds), '?'));
                $answerStmt = $pdo->prepare(
                    "SELECT response_id, linkId, answer FROM questionnaire_response_item WHERE response_id IN ($placeholders)"
                );
                $answerStmt->execute($responseIds);
                foreach ($answerStmt->fetchAll(PDO::FETCH_ASSOC) as $answerRow) {
                    $rid = (int)$answerRow['response_id'];
                    $answersByResponse[$rid][$answerRow['linkId']] = analytics_decode_answer_json($answerRow['answer'] ?? '');
                }
            }

            $scoreCalculator = static function (array $item, array $answerSet, float $weight): float {
                if ($weight <= 0) {
                    return 0.0;
                }
                $type = (string)($item['type'] ?? 'text');
                if ($type === 'boolean') {
                    foreach ($answerSet as $entry) {
                        if ((isset($entry['valueBoolean']) && $entry['valueBoolean'])
                            || (isset($entry['valueString']) && strtolower((string)$entry['valueString']) === 'true')) {
                            return $weight;
                        }
                    }
                    return 0.0;
                }
                if ($type === 'likert') {
                    $score = null;
                    foreach ($answerSet as $entry) {
                        if (isset($entry['valueInteger']) && is_numeric($entry['valueInteger'])) {
                            $score = (int)$entry['valueInteger'];
                            break;
                        }
                        if (isset($entry['valueString'])) {
                            $candidate = trim((string)$entry['valueString']);
                            if (preg_match('/^([1-5])/', $candidate, $matches)) {
                                $score = (int)$matches[1];
                                break;
                            }
                            if (is_numeric($candidate)) {
                                $value = (int)$candidate;
                                if ($value >= 1 && $value <= 5) {
                                    $score = $value;
                                    break;
                                }
                            }
                        }
                    }
                    if ($score !== null && $score >= 1 && $score <= 5) {
                        return $weight * $score / 5.0;
                    }
                    return 0.0;
                }
                if ($type === 'choice') {
                    foreach ($answerSet as $entry) {
                        if (isset($entry['valueString']) && trim((string)$entry['valueString']) !== '') {
                            return $weight;
                        }
                    }
                    return 0.0;
                }
                foreach ($answerSet as $entry) {
                    if (isset($entry['valueString']) && trim((string)$entry['valueString']) !== '') {
                        return $weight;
                    }
                }
                return 0.0;
            };

            $aggregateStats = [];
            foreach ($selectedResponses as $row) {
                $responseId = (int)$row['id'];
                $answers = $answersByResponse[$responseId] ?? [];
                $sectionStats = [];
                foreach ($sectionColumns as $col) {
                    $sectionStats[$col['key']] = [
                        'weight' => 0.0,
                        'achieved' => 0.0,
                    ];
                }

                foreach ($scoringItems as $item) {
                    $sectionKey = $item['section_key'];
                    if (!isset($sectionStats[$sectionKey])) {
                        continue;
                    }
                    $sectionStats[$sectionKey]['weight'] += $item['weight'];
                    $answerSet = $answers[$item['linkId']] ?? [];
                    $sectionStats[$sectionKey]['achieved'] += $scoreCalculator($item, $answerSet, $item['weight']);
                }

                $sectionScores = [];
                foreach ($sectionColumns as $col) {
                    $stat = $sectionStats[$col['key']];
                    if ($stat['weight'] > 0) {
                        $scorePct = round(($stat['achieved'] / $stat['weight']) * 100, 1);
                        $sectionScores[$col['key']] = $scorePct;
                        $aggregateStats[$col['key']]['achieved'] = ($aggregateStats[$col['key']]['achieved'] ?? 0.0) + $stat['achieved'];
                        $aggregateStats[$col['key']]['weight'] = ($aggregateStats[$col['key']]['weight'] ?? 0.0) + $stat['weight'];
                    } else {
                        $sectionScores[$col['key']] = null;
                    }
                }

                $sectionScoresByResponse[] = [
                    'id' => $responseId,
                    'username' => $row['username'] ?? '',
                    'full_name' => $row['full_name'] ?? '',
                    'period' => $row['period_label'] ?? '',
                    'status' => $row['status'] ?? '',
                    'overall' => is_numeric($row['score']) ? round((float)$row['score'], 1) : null,
                    'sections' => $sectionScores,
                ];
            }

            foreach ($sectionColumns as $col) {
                $stat = $aggregateStats[$col['key']] ?? ['achieved' => 0.0, 'weight' => 0.0];
                $avg = $stat['weight'] > 0 ? round(($stat['achieved'] / $stat['weight']) * 100, 1) : null;
                $sectionAggregates[] = [
                    'label' => $col['label'],
                    'score' => $avg,
                ];
            }
        }
    }
}

$workFunctionOptions = work_function_choices($pdo);
$departmentOptions = department_options($pdo);
$teamCatalog = department_team_catalog($pdo);
$workFunctionStmt = $pdo->query(
    "SELECT u.work_function, COUNT(*) AS total_responses, "
    . "SUM(qr.status='approved') AS approved_count, "
    . "AVG(qr.score) AS avg_score "
    . "FROM questionnaire_response qr "
    . "JOIN users u ON u.id = qr.user_id "
    . "GROUP BY u.work_function "
    . "ORDER BY total_responses DESC"
);
$workFunctionSummary = $workFunctionStmt ? $workFunctionStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$departmentSummaryStmt = $pdo->query(
    "SELECT u.department, COUNT(*) AS total_responses, "
    . "SUM(qr.status='approved') AS approved_count, "
    . "AVG(qr.score) AS avg_score "
    . "FROM questionnaire_response qr "
    . "JOIN users u ON u.id = qr.user_id "
    . "GROUP BY u.department "
    . "ORDER BY total_responses DESC"
);
$departmentSummary = $departmentSummaryStmt ? $departmentSummaryStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$teamSummaryStmt = $pdo->query(
    "SELECT u.cadre, COUNT(*) AS total_responses, "
    . "SUM(qr.status='approved') AS approved_count, "
    . "AVG(qr.score) AS avg_score "
    . "FROM questionnaire_response qr "
    . "JOIN users u ON u.id = qr.user_id "
    . "GROUP BY u.cadre "
    . "ORDER BY total_responses DESC"
);
$teamSummary = $teamSummaryStmt ? $teamSummaryStmt->fetchAll(PDO::FETCH_ASSOC) : [];
foreach ($workFunctionSummary as &$wfRow) {
    $wfKey = (string)($wfRow['work_function'] ?? '');
    if ($wfRow['avg_score'] !== null) {
        $wfRow['avg_score'] = (float)$wfRow['avg_score'];
    } elseif (isset($workFunctionFallbackAverages[$wfKey])) {
        $wfRow['avg_score'] = $workFunctionFallbackAverages[$wfKey];
    }
}
unset($wfRow);

$questionnaireChartData = [];
foreach ($questionnaires as $row) {
    $questionnaireChartData[] = [
        'label' => (string)($row['title'] ?? ('Questionnaire ' . (int)$row['id'])),
        'score' => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
        'responses' => (int)($row['total_responses'] ?? 0),
    ];
}
$questionnaireChartData = array_values(array_filter($questionnaireChartData, static fn($row) => $row['score'] !== null));
usort($questionnaireChartData, static function ($a, $b) {
    $aScore = $a['score'] ?? 101;
    $bScore = $b['score'] ?? 101;
    if ($aScore === $bScore) {
        return strcmp($a['label'], $b['label']);
    }
    return $aScore <=> $bScore;
});
if (count($questionnaireChartData) > 12) {
    $questionnaireChartData = array_slice($questionnaireChartData, 0, 12);
}

$workFunctionChartData = [];
foreach ($workFunctionSummary as $row) {
    $wfKey = $row['work_function'] ?? '';
    $label = $workFunctionOptions[$wfKey] ?? ($wfKey !== '' ? (string)$wfKey : t($t, 'unknown', 'Unknown'));
    $workFunctionChartData[] = [
        'label' => (string)$label,
        'score' => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
        'responses' => (int)($row['total_responses'] ?? 0),
    ];
}
$workFunctionChartData = array_values(array_filter($workFunctionChartData, static fn($row) => $row['score'] !== null));
usort($workFunctionChartData, static function ($a, $b) {
    $aScore = $a['score'] ?? 101;
    $bScore = $b['score'] ?? 101;
    if ($aScore === $bScore) {
        return strcmp($a['label'], $b['label']);
    }
    return $aScore <=> $bScore;
});
if (count($workFunctionChartData) > 12) {
    $workFunctionChartData = array_slice($workFunctionChartData, 0, 12);
}

try {
    $scheduleStmt = $pdo->query(
        'SELECT s.*, q.title AS questionnaire_title, u.full_name AS creator_name '
        . 'FROM analytics_report_schedule s '
        . 'LEFT JOIN questionnaire q ON q.id = s.questionnaire_id '
        . 'LEFT JOIN users u ON u.id = s.created_by '
        . 'ORDER BY s.next_run_at ASC'
    );
    $reportSchedules = $scheduleStmt ? $scheduleStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    error_log('analytics schedule fetch failed: ' . $e->getMessage());
    $reportSchedules = [];
}

$chartJsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
if (defined('JSON_THROW_ON_ERROR')) {
    $chartJsonFlags |= JSON_THROW_ON_ERROR;
}
$hasAnalyticsCharts = !empty($questionnaireChartData) || !empty($workFunctionChartData);

$statusLabels = [
    'draft' => t($t, 'status_draft', 'Draft'),
    'submitted' => t($t, 'status_submitted', 'Submitted'),
    'approved' => t($t, 'status_approved', 'Approved'),
    'rejected' => t($t, 'status_rejected', 'Rejected'),
];

$formatScore = static function ($score, int $precision = 1): string {
    if ($score === null) {
        return '—';
    }
    return number_format((float)$score, $precision);
};

$selectedAggregate = [
    'total' => count($selectedResponses),
    'approved' => 0,
    'submitted' => 0,
    'draft' => 0,
    'rejected' => 0,
    'scored_count' => 0,
    'score_sum' => 0.0,
];
foreach ($selectedResponses as $row) {
    $statusKey = $row['status'] ?? '';
    if (isset($selectedAggregate[$statusKey])) {
        $selectedAggregate[$statusKey] += 1;
    }
    if (isset($row['score']) && $row['score'] !== null) {
        $selectedAggregate['score_sum'] += (float)$row['score'];
        $selectedAggregate['scored_count'] += 1;
    }
}
$selectedAverage = $selectedAggregate['scored_count'] > 0
    ? $selectedAggregate['score_sum'] / $selectedAggregate['scored_count']
    : null;
$pageHelpKey = 'team.analytics';
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t, 'analytics', 'Analytics'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
    .md-kpi-card {
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      border-radius: 12px;
      background: var(--app-surface, #fff);
    }
    .md-kpi-hero {
      background: linear-gradient(135deg, #003c8f, #1976d2);
      color: #fff;
      border-radius: 12px;
      padding: 1.75rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 2rem;
      flex-wrap: wrap;
    }
    .md-kpi-hero__copy {
      flex: 1 1 260px;
      max-width: 520px;
    }
    .md-kpi-hero__eyebrow {
      margin: 0 0 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-size: 0.75rem;
      opacity: 0.85;
    }
    .md-kpi-hero__title {
      margin: 0;
      font-size: 1.4rem;
    }
    .md-kpi-hero__meta {
      margin: 0.75rem 0 0;
      font-size: 0.95rem;
      opacity: 0.95;
    }
    .md-kpi-hero__ring {
      --kpi-angle: 0deg;
      width: 132px;
      height: 132px;
      border-radius: 50%;
      background: conic-gradient(rgba(255, 255, 255, 0.9) var(--kpi-angle), rgba(255, 255, 255, 0.2) 0deg);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: inset 0 0 0 6px rgba(255, 255, 255, 0.35);
      flex: 0 0 auto;
    }
    .md-kpi-hero__ring span {
      width: calc(100% - 28px);
      height: calc(100% - 28px);
      border-radius: 50%;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.6rem;
      font-weight: 700;
      color: #1c54b2;
    }
    .md-kpi-hero__ring--empty {
      background: rgba(255, 255, 255, 0.25);
      box-shadow: inset 0 0 0 6px rgba(255, 255, 255, 0.35);
    }
    .md-kpi-hero__ring--empty span {
      background: transparent;
      color: rgba(255, 255, 255, 0.9);
    }
    .md-kpi-breakdown {
      margin-top: 1.5rem;
    }
    .md-kpi-breakdown h3 {
      margin: 0 0 0.75rem;
      font-size: 1.05rem;
    }
    .md-kpi-breakdown__list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }
    .md-kpi-breakdown__item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      background: var(--app-surface-alt, #f5f7fa);
    }
    .md-kpi-breakdown__item div {
      flex: 1 1 auto;
    }
    .md-kpi-breakdown__name {
      display: block;
      font-weight: 600;
    }
    .md-kpi-breakdown__meta {
      display: block;
      font-size: 0.9rem;
      color: var(--app-text-secondary, #555);
      margin-top: 0.25rem;
    }
    .md-kpi-breakdown__value {
      font-weight: 700;
      font-size: 1.2rem;
      color: var(--app-primary, #0050c8);
      white-space: nowrap;
    }
    .md-summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
      margin: 1rem 0;
    }
    .md-summary-card {
      padding: 1rem;
      border-radius: 6px;
      background: var(--app-surface-alt, #f5f7fa);
    }
    .md-summary-card strong {
      display: block;
      font-size: 1.25rem;
      margin-bottom: 0.35rem;
    }
    .md-report-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      margin-bottom: 1rem;
    }
    .md-download-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      margin: 0.5rem 0 1rem;
    }
    .md-download-card {
      padding: 1rem;
      border: 1px solid rgba(0, 0, 0, 0.08);
      border-radius: 6px;
      background: var(--app-surface, #fff);
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      min-height: 100%;
    }
    .md-download-card h3 {
      margin: 0;
      font-size: 1.05rem;
    }
    .md-download-card p {
      margin: 0;
      color: var(--app-text-secondary, #555);
      flex: 1 1 auto;
    }
    .md-download-card .md-button {
      align-self: flex-start;
    }
    .md-analytics-guide {
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
      margin-top: 1rem;
    }
    .md-guide-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }
    .md-query-card {
      border-radius: 12px;
      border: 1px solid var(--app-border, #e2e8f0);
      background: var(--app-surface-alt, #f8fafc);
      padding: 1.25rem;
    }
    .md-query-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
    }
    .md-query-header h3 {
      margin: 0;
      font-size: 1.05rem;
    }
    .md-query-pre {
      margin: 1rem 0 0;
      max-height: 320px;
      overflow: auto;
      padding: 1rem;
      background: #0f172a;
      color: #e2e8f0;
      border-radius: 8px;
      font-size: 0.85rem;
      line-height: 1.4;
      white-space: pre;
    }
    .md-report-grid textarea {
      min-height: 80px;
    }
    .md-schedule-actions {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
      align-items: center;
    }
    .md-table--interactive tr.is-selected {
      background: rgba(0, 132, 255, 0.08);
    }
    .md-table--interactive tr.is-selected td {
      font-weight: 600;
    }
    .md-table--interactive a.md-row-link {
      display: inline-block;
      color: inherit;
      text-decoration: none;
    }
    .md-table--interactive a.md-row-link:hover,
    .md-table--interactive a.md-row-link:focus {
      text-decoration: underline;
    }
    .md-analytics-meta {
      margin: 0.75rem 0 0;
      color: var(--app-text-secondary, #555);
    }
    .md-analytics-meta--hint {
      margin-top: 0.35rem;
      font-size: 0.9rem;
    }
    .md-table-responsive {
      overflow-x: auto;
    }
    .md-sectional-table th,
    .md-sectional-table td {
      white-space: nowrap;
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <?php if ($reportMessage): ?>
    <div class="md-alert success"><?=htmlspecialchars($reportMessage, ENT_QUOTES, 'UTF-8')?></div>
  <?php endif; ?>
  <?php if ($reportError): ?>
    <div class="md-alert error"><?=htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8')?></div>
  <?php endif; ?>
  <div class="md-card md-kpi-card md-elev-2">
    <div class="md-kpi-hero">
      <div class="md-kpi-hero__copy">
        <p class="md-kpi-hero__eyebrow"><?=t($t, 'epss_kpi_label', 'EPSS KPI')?></p>
        <h3 class="md-kpi-hero__title"><?=t($t, 'epss_kpi_title', 'Percentage of staff reaching 80% or more')?></h3>
        <p class="md-kpi-hero__meta">
          <?php if ($overallKpiStats['total'] > 0): ?>
            <?=sprintf(
                t($t, 'epss_kpi_meta', '%1$d of %2$d staff reached 80%% or more.'),
                $overallKpiStats['hit'],
                $overallKpiStats['total']
            )?>
          <?php else: ?>
            <?=t($t, 'epss_kpi_meta_empty', 'No completed questionnaire scores yet.')?>
          <?php endif; ?>
        </p>
      </div>
      <?php if ($overallKpiStats['percent'] !== null): ?>
        <?php
          $overallKpiValue = number_format((float)$overallKpiStats['percent'], 1);
          $overallKpiAngle = max(0.0, min(100.0, (float)$overallKpiStats['percent'])) * 3.6;
        ?>
        <div class="md-kpi-hero__ring" style="--kpi-angle: <?=$overallKpiAngle?>deg;">
          <span><?=$overallKpiValue?>%</span>
        </div>
      <?php else: ?>
        <div class="md-kpi-hero__ring md-kpi-hero__ring--empty">
          <span>—</span>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($kpiBreakdownRows): ?>
      <div class="md-kpi-breakdown">
        <h3><?=t($t, 'epss_kpi_breakdown_title', 'KPI by questionnaire')?></h3>
        <ul class="md-kpi-breakdown__list">
          <?php foreach ($kpiBreakdownRows as $row): ?>
            <?php
              $percent = $row['kpi_percent'] ?? null;
              $totalUsers = (int)($row['kpi_total_users'] ?? 0);
              $hitUsers = (int)($row['kpi_hit_users'] ?? 0);
              $title = trim((string)($row['title'] ?? ''));
              $displayTitle = $title !== '' ? $title : t($t, 'questionnaire', 'Questionnaire');
            ?>
            <li class="md-kpi-breakdown__item">
              <div>
                <span class="md-kpi-breakdown__name"><?=htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8')?></span>
                <span class="md-kpi-breakdown__meta">
                  <?php if ($totalUsers > 0): ?>
                    <?=sprintf(
                        t($t, 'epss_kpi_breakdown_meta', '%1$d of %2$d staff'),
                        $hitUsers,
                        $totalUsers
                    )?>
                  <?php else: ?>
                    <?=t($t, 'epss_kpi_breakdown_empty', 'No scores yet')?>
                  <?php endif; ?>
                </span>
              </div>
              <span class="md-kpi-breakdown__value">
                <?=$percent !== null ? number_format((float)$percent, 1) . '%' : '—'?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_overview', 'Analytics overview')?></h2>
    <div class="md-summary-grid">
      <div class="md-summary-card">
        <strong><?= (int)($summary['total_responses'] ?? 0) ?></strong>
        <span><?=t($t, 'total_responses', 'Total responses recorded')?></span>
      </div>
      <div class="md-summary-card">
        <strong><?= $formatScore($summary['avg_score'] ?? null, 1) ?></strong>
        <span><?=t($t, 'average_score_all', 'Average score across all questionnaires')?></span>
      </div>
      <div class="md-summary-card">
        <strong><?= (int)($summary['approved_count'] ?? 0) ?></strong>
        <span><?=t($t, 'approved_responses', 'Approved responses')?></span>
      </div>
      <div class="md-summary-card">
        <strong><?= $totalParticipants ?></strong>
        <span><?=t($t, 'unique_participants', 'Unique participants')?></span>
      </div>
    </div>
    <?php if (!empty($summary['latest_at'])): ?>
      <p class="md-analytics-meta"><?=t($t, 'latest_submission', 'Latest submission:')?> <?=htmlspecialchars($summary['latest_at'], ENT_QUOTES, 'UTF-8')?></p>
    <?php endif; ?>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_download_reports', 'Download default reports')?></h2>
    <p><?=t($t, 'analytics_download_reports_hint', 'Quickly download PDF snapshots for offline sharing.')?></p>
    <div class="md-download-grid">
      <?php foreach ($defaultReportDownloads as $download): ?>
        <div class="md-download-card">
          <h3><?=htmlspecialchars($download['title'], ENT_QUOTES, 'UTF-8')?></h3>
          <p><?=htmlspecialchars($download['description'], ENT_QUOTES, 'UTF-8')?></p>
          <a class="md-button md-primary md-elev-1" href="<?=htmlspecialchars($download['url'], ENT_QUOTES, 'UTF-8')?>">
            <?=t($t, 'analytics_download_button', 'Download PDF')?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_looker_resources', 'Analytics guide & Looker Studio query')?></h2>
    <p><?=t($t, 'analytics_looker_resources_hint', 'Share the PDF guide and copy the SQL query into Google Looker Studio to build custom dashboards.')?></p>
    <div class="md-analytics-guide">
      <div class="md-guide-actions">
        <a class="md-button md-primary md-elev-1" href="<?=htmlspecialchars(asset_url('assets/analytics-guide.pdf'), ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener noreferrer">
          <?=t($t, 'analytics_download_guide', 'Download analytics guide (PDF)')?>
        </a>
      </div>
      <div class="md-query-card">
        <div class="md-query-header">
          <h3><?=t($t, 'analytics_looker_query_title', 'Google Looker Studio query')?></h3>
          <button class="md-button md-outline" type="button" data-copy-target="looker-query" data-copy-default="<?=htmlspecialchars(t($t, 'copy_query', 'Copy query'), ENT_QUOTES, 'UTF-8')?>" data-copy-success="<?=htmlspecialchars(t($t, 'copy_query_success', 'Copied!'), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'copy_query', 'Copy query')?></button>
        </div>
        <pre class="md-query-pre" id="looker-query"><code><?=htmlspecialchars($lookerStudioQuery, ENT_QUOTES, 'UTF-8')?></code></pre>
      </div>
    </div>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_email_report', 'Email analytics report')?></h2>
    <form method="post" class="md-form-grid md-report-grid" action="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="send-report">
      <label class="md-field">
        <span><?=t($t, 'recipients', 'Recipients')?></span>
        <textarea name="report_recipients" placeholder="name@example.com, other@example.com" required></textarea>
      </label>
      <label class="md-field">
        <span><?=t($t, 'questionnaire', 'Questionnaire')?></span>
        <select name="report_questionnaire_id">
          <option value="0"><?=t($t, 'all_questionnaires', 'All questionnaires')?></option>
          <?php foreach ($questionnaires as $row): ?>
            <option value="<?=$row['id']?>"><?=htmlspecialchars($row['title'] ?? t($t, 'questionnaire', 'Questionnaire'), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-checkbox">
        <input type="checkbox" name="report_include_details" value="1">
        <span><?=t($t, 'include_detailed_breakdown', 'Include detailed questionnaire breakdown')?></span>
      </label>
      <div class="md-inline-actions">
        <button class="md-button md-primary" type="submit"><?=t($t, 'send_report_now', 'Send report')?></button>
      </div>
    </form>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_schedules', 'Scheduled analytics reports')?></h2>
    <form method="post" class="md-form-grid md-report-grid" action="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="create-schedule">
      <label class="md-field">
        <span><?=t($t, 'recipients', 'Recipients')?></span>
        <textarea name="schedule_recipients" placeholder="name@example.com, other@example.com" required></textarea>
      </label>
      <label class="md-field">
        <span><?=t($t, 'frequency', 'Frequency')?></span>
        <select name="schedule_frequency">
          <?php foreach (analytics_report_allowed_frequencies() as $freq): ?>
            <option value="<?=$freq?>"><?=htmlspecialchars(t($t, 'frequency_' . $freq, analytics_report_frequency_label($freq)), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t, 'start_time', 'First delivery (local time)')?></span>
        <input type="datetime-local" name="schedule_start_at">
      </label>
      <label class="md-field">
        <span><?=t($t, 'questionnaire', 'Questionnaire')?></span>
        <select name="schedule_questionnaire_id">
          <option value="0"><?=t($t, 'all_questionnaires', 'All questionnaires')?></option>
          <?php foreach ($questionnaires as $row): ?>
            <option value="<?=$row['id']?>"><?=htmlspecialchars($row['title'] ?? t($t, 'questionnaire', 'Questionnaire'), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-checkbox">
        <input type="checkbox" name="schedule_include_details" value="1">
        <span><?=t($t, 'include_detailed_breakdown', 'Include detailed questionnaire breakdown')?></span>
      </label>
      <div class="md-inline-actions">
        <button class="md-button md-primary" type="submit"><?=t($t, 'create_schedule', 'Create schedule')?></button>
      </div>
    </form>

    <?php if ($reportSchedules): ?>
      <div class="md-table-responsive">
        <table class="md-table">
          <thead>
            <tr>
              <th><?=t($t, 'recipients', 'Recipients')?></th>
              <th><?=t($t, 'frequency', 'Frequency')?></th>
              <th><?=t($t, 'questionnaire', 'Questionnaire')?></th>
              <th><?=t($t, 'include_detailed_breakdown', 'Detailed breakdown?')?></th>
              <th><?=t($t, 'next_run', 'Next send')?></th>
              <th><?=t($t, 'last_run', 'Last sent')?></th>
              <th><?=t($t, 'status', 'Status')?></th>
              <th><?=t($t, 'action', 'Action')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reportSchedules as $schedule): ?>
              <?php
                $isActive = (int)($schedule['active'] ?? 0) === 1;
                $frequencyKey = (string)($schedule['frequency'] ?? '');
                $frequencyLabel = t($t, 'frequency_' . $frequencyKey, analytics_report_frequency_label($frequencyKey));
                $questionnaireLabel = $schedule['questionnaire_id'] ? ($schedule['questionnaire_title'] ?? t($t, 'questionnaire', 'Questionnaire')) : t($t, 'all_questionnaires', 'All questionnaires');
              ?>
              <tr>
                <td><?=htmlspecialchars($schedule['recipients'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($frequencyLabel, ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($questionnaireLabel, ENT_QUOTES, 'UTF-8')?></td>
                <td><?=!empty($schedule['include_details']) ? t($t, 'yes', 'Yes') : t($t, 'no', 'No')?></td>
                <td><?=htmlspecialchars($schedule['next_run_at'] ?? '-', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($schedule['last_run_at'] ?? '-', ENT_QUOTES, 'UTF-8')?></td>
                <td><?= $isActive ? t($t, 'status_active', 'Active') : t($t, 'status_disabled', 'Disabled') ?></td>
                <td>
                  <div class="md-schedule-actions">
                    <form method="post" action="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>" class="md-inline-form">
                      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                      <input type="hidden" name="action" value="toggle-schedule">
                      <input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                      <button class="md-button" type="submit"><?= $isActive ? t($t, 'pause', 'Pause') : t($t, 'resume', 'Resume') ?></button>
                    </form>
                    <form method="post" action="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>" class="md-inline-form" onsubmit="return confirm('<?=htmlspecialchars(t($t, 'confirm_delete_schedule', 'Remove this schedule?'), ENT_QUOTES, 'UTF-8')?>');">
                      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                      <input type="hidden" name="action" value="delete-schedule">
                      <input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                      <button class="md-button md-danger" type="submit"><?=t($t, 'delete', 'Delete')?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="md-upgrade-meta"><?=t($t, 'no_schedules_configured', 'No report schedules have been configured yet.')?></p>
    <?php endif; ?>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'questionnaire_performance', 'Questionnaire performance')?></h2>
    <?php if ($questionnaires): ?>
      <p class="md-upgrade-meta"><?=t($t, 'questionnaire_drilldown_hint', 'Select a questionnaire to drill into individual responses.')?></p>
      <?php if ($questionnaireChartData): ?>
        <div
          class="md-chart-container"
          data-chart-target="questionnaire-performance-heatmap"
          data-has-data="true"
          data-empty-message="<?= htmlspecialchars(t($t, 'questionnaire_heatmap_empty', 'Questionnaire performance data will appear here once submissions include scores.'), ENT_QUOTES, 'UTF-8') ?>"
        >
          <canvas id="questionnaire-performance-heatmap" role="img" aria-label="<?=htmlspecialchars(t($t, 'questionnaire_heatmap_alt', 'Horizontal bar chart highlighting questionnaire averages with heatmap colours.'), ENT_QUOTES, 'UTF-8')?>"></canvas>
        </div>
        <p class="md-analytics-meta md-analytics-meta--hint"><?=t($t, 'performance_heatmap_hint', 'Heatmap colours shift from red to green so low scores stand out for follow-up.')?></p>
      <?php endif; ?>
      <table class="md-table md-table--interactive">
        <thead>
          <tr>
            <th><?=t($t, 'questionnaire', 'Questionnaire')?></th>
            <th><?=t($t, 'count', 'Responses')?></th>
            <th><?=t($t, 'approved', 'Approved')?></th>
            <th><?=t($t, 'status_submitted', 'Submitted')?></th>
            <th><?=t($t, 'status_draft', 'Draft')?></th>
            <th><?=t($t, 'status_rejected', 'Rejected')?></th>
            <th><?=t($t, 'average_score', 'Average score (%)')?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($questionnaires as $row): ?>
            <?php $isSelected = ((int)$row['id'] === $selectedQuestionnaireId); ?>
            <tr class="<?= $isSelected ? 'is-selected' : '' ?>">
              <td>
                <a class="md-row-link" href="<?=htmlspecialchars(url_for('admin/analytics.php') . '?questionnaire_id=' . (int)$row['id'], ENT_QUOTES, 'UTF-8')?>">
                  <?=htmlspecialchars($row['title'] ?? '', ENT_QUOTES, 'UTF-8')?>
                </a>
              </td>
              <td><?= (int)$row['total_responses'] ?></td>
              <td><?= (int)$row['approved_count'] ?></td>
              <td><?= (int)$row['submitted_count'] ?></td>
              <td><?= (int)$row['draft_count'] ?></td>
              <td><?= (int)$row['rejected_count'] ?></td>
              <td><?= $formatScore($row['avg_score'] ?? null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="md-upgrade-meta"><?=t($t, 'no_questionnaire_stats', 'No questionnaire responses are available yet.')?></p>
    <?php endif; ?>
  </div>

  <?php if ($selectedQuestionnaireId): ?>
    <div class="md-card md-elev-2">
      <?php
        $selectedQuestionnaire = null;
        foreach ($questionnaires as $candidate) {
            if ((int)$candidate['id'] === $selectedQuestionnaireId) {
                $selectedQuestionnaire = $candidate;
                break;
            }
        }
      ?>
      <h2 class="md-card-title">
        <?=t($t, 'responses_for_questionnaire', 'Responses for questionnaire')?> ·
        <?=htmlspecialchars($selectedQuestionnaire['title'] ?? '', ENT_QUOTES, 'UTF-8')?>
      </h2>
      <?php if ($selectedAggregate['total'] > 0): ?>
        <p class="md-upgrade-meta">
          <?=t($t, 'selected_summary', 'Average score: ')?>
          <?=$formatScore($selectedAverage)?> ·
          <?=t($t, 'approved_responses', 'Approved responses')?>: <?=$selectedAggregate['approved']?> ·
          <?=t($t, 'status_submitted', 'Submitted')?>: <?=$selectedAggregate['submitted']?> ·
          <?=t($t, 'status_draft', 'Draft')?>: <?=$selectedAggregate['draft']?> ·
          <?=t($t, 'status_rejected', 'Rejected')?>: <?=$selectedAggregate['rejected']?>
        </p>
        <table class="md-table">
          <thead>
            <tr>
              <th><?=t($t, 'user', 'User')?></th>
              <th><?=t($t, 'performance_period', 'Performance Period')?></th>
              <th><?=t($t, 'status', 'Status')?></th>
              <th><?=t($t, 'score', 'Score (%)')?></th>
              <th><?=t($t, 'date', 'Submitted on')?></th>
              <th><?=t($t, 'review_comment', 'Review comment')?></th>
              <th><?=t($t, 'view', 'View')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($selectedResponses as $row): ?>
              <?php $statusKey = $row['status'] ?? ''; ?>
              <tr>
                <td>
                  <?=htmlspecialchars($row['username'] ?? '', ENT_QUOTES, 'UTF-8')?>
                  <?php if (!empty($row['full_name'])): ?>
                    <br><span class="md-muted"><?=htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8')?></span>
                  <?php endif; ?>
                </td>
                <td><?=htmlspecialchars($row['period_label'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($statusLabels[$statusKey] ?? ucfirst((string)$statusKey), ENT_QUOTES, 'UTF-8')?></td>
                <td><?= isset($row['score']) && $row['score'] !== null ? (int)$row['score'] : '—' ?></td>
                <td><?=htmlspecialchars($row['created_at'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($row['review_comment'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><a class="md-button" href="<?=htmlspecialchars(url_for('admin/view_submission.php?id=' . (int)$row['id']), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'open', 'Open')?></a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t, 'no_responses_for_selection', 'There are no responses for this questionnaire yet.')?></p>
      <?php endif; ?>
    </div>

    <?php if ($sectionColumns && $sectionScoresByResponse): ?>
      <div class="md-card md-elev-2">
        <h2 class="md-card-title"><?=t($t, 'sectional_scores_by_user', 'Sectional performance by participant')?></h2>
        <p class="md-upgrade-meta"><?=t($t, 'sectional_scores_by_user_hint', 'Scores reflect the weighted result for each questionnaire section per submission.')?></p>
        <div class="md-table-responsive">
          <table class="md-table md-sectional-table">
            <thead>
              <tr>
                <th><?=t($t, 'user', 'User')?></th>
                <th><?=t($t, 'performance_period', 'Performance Period')?></th>
                <th><?=t($t, 'status', 'Status')?></th>
                <th><?=t($t, 'score', 'Score (%)')?></th>
                <?php foreach ($sectionColumns as $col): ?>
                  <th><?=htmlspecialchars($col['label'], ENT_QUOTES, 'UTF-8')?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sectionScoresByResponse as $row): ?>
                <?php $statusKey = $row['status'] ?? ''; ?>
                <tr>
                  <td>
                    <?=htmlspecialchars($row['username'] ?? '', ENT_QUOTES, 'UTF-8')?>
                    <?php if (!empty($row['full_name'])): ?>
                      <br><span class="md-muted"><?=htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8')?></span>
                    <?php endif; ?>
                  </td>
                  <td><?=htmlspecialchars($row['period'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                  <td><?=htmlspecialchars($statusLabels[$statusKey] ?? ucfirst((string)$statusKey), ENT_QUOTES, 'UTF-8')?></td>
                  <td><?= $row['overall'] !== null ? (int)$row['overall'] : '—' ?></td>
                  <?php foreach ($sectionColumns as $col): ?>
                    <?php $value = $row['sections'][$col['key']] ?? null; ?>
                    <td><?= $value !== null ? htmlspecialchars(number_format((float)$value, 1), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($sectionAggregates): ?>
      <div class="md-card md-elev-2">
        <h2 class="md-card-title"><?=t($t, 'sectional_scores_aggregated', 'Section averages for questionnaire')?></h2>
        <p class="md-upgrade-meta"><?=t($t, 'sectional_scores_aggregated_hint', 'Average weighted score per section across all submissions for this questionnaire.')?></p>
        <table class="md-table">
          <thead>
            <tr>
              <th><?=t($t, 'section_label', 'Section')?></th>
              <th><?=t($t, 'average_score', 'Average score (%)')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sectionAggregates as $aggregate): ?>
              <tr>
                <td><?=htmlspecialchars($aggregate['label'], ENT_QUOTES, 'UTF-8')?></td>
                <td><?= $aggregate['score'] !== null ? htmlspecialchars(number_format((float)$aggregate['score'], 1), ENT_QUOTES, 'UTF-8') : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="md-card md-elev-2">
      <h2 class="md-card-title"><?=t($t, 'user_breakdown', 'Participant breakdown')?></h2>
      <?php if ($selectedUserBreakdown): ?>
        <table class="md-table">
          <thead>
            <tr>
              <th><?=t($t, 'user', 'User')?></th>
              <th><?=t($t, 'department', 'Department')?></th>
              <th><?=t($t, 'cadre', 'Team in the Department')?></th>
              <th><?=t($t, 'work_function', 'Work Role')?></th>
              <th><?=t($t, 'count', 'Responses')?></th>
              <th><?=t($t, 'approved', 'Approved')?></th>
              <th><?=t($t, 'average_score', 'Average score (%)')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($selectedUserBreakdown as $row): ?>
              <?php $workFunctionKey = $row['work_function'] ?? ''; ?>
              <tr>
                <td>
                  <?=htmlspecialchars($row['username'] ?? '', ENT_QUOTES, 'UTF-8')?>
                  <?php if (!empty($row['full_name'])): ?>
                    <br><span class="md-muted"><?=htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8')?></span>
                  <?php endif; ?>
                </td>
                <td><?=htmlspecialchars($departmentOptions[$row['department'] ?? ''] ?? ($row['department'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars(team_label($pdo, (string)($row['cadre'] ?? '')), ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($workFunctionOptions[$workFunctionKey] ?? $workFunctionKey ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><?= (int)$row['total_responses'] ?></td>
                <td><?= (int)$row['approved_count'] ?></td>
                <td><?= $formatScore($row['avg_score'] ?? null) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t, 'no_user_breakdown', 'No participant data available for this questionnaire.')?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'department_performance', 'Department Performance')?></h2>
    <?php if ($departmentSummary): ?>
      <table class="md-table"><thead><tr><th><?=t($t,'department','Department')?></th><th><?=t($t,'count','Responses')?></th><th><?=t($t,'approved','Approved')?></th><th><?=t($t,'average_score','Average score (%)')?></th></tr></thead><tbody>
      <?php foreach ($departmentSummary as $row): ?>
        <tr><td><?=htmlspecialchars($departmentOptions[$row['department'] ?? ''] ?? ($row['department'] ?: t($t,'unknown','Unknown')), ENT_QUOTES, 'UTF-8')?></td><td><?= (int)$row['total_responses'] ?></td><td><?= (int)$row['approved_count'] ?></td><td><?= $formatScore($row['avg_score'] ?? null) ?></td></tr>
      <?php endforeach; ?></tbody></table>
    <?php else: ?><p class="md-upgrade-meta">—</p><?php endif; ?>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'team_performance', 'Team Performance')?></h2>
    <?php if ($teamSummary): ?>
      <table class="md-table"><thead><tr><th><?=t($t,'cadre','Team in the Department')?></th><th><?=t($t,'count','Responses')?></th><th><?=t($t,'approved','Approved')?></th><th><?=t($t,'average_score','Average score (%)')?></th></tr></thead><tbody>
      <?php foreach ($teamSummary as $row): ?>
        <tr><td><?=htmlspecialchars(team_label($pdo, (string)($row['cadre'] ?? '')), ENT_QUOTES, 'UTF-8')?></td><td><?= (int)$row['total_responses'] ?></td><td><?= (int)$row['approved_count'] ?></td><td><?= $formatScore($row['avg_score'] ?? null) ?></td></tr>
      <?php endforeach; ?></tbody></table>
    <?php else: ?><p class="md-upgrade-meta">—</p><?php endif; ?>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'work_function_performance', 'Work Role Performance')?></h2>
    <?php if ($workFunctionSummary): ?>
      <?php if ($workFunctionChartData): ?>
        <div
          class="md-chart-container"
          data-chart-target="work-function-heatmap"
          data-has-data="true"
          data-empty-message="<?= htmlspecialchars(t($t, 'work_function_heatmap_empty', 'Performance by work function will display after a few submissions are recorded.'), ENT_QUOTES, 'UTF-8') ?>"
        >
          <canvas id="work-function-heatmap" role="img" aria-label="<?=htmlspecialchars(t($t, 'work_function_heatmap_alt', 'Horizontal bar chart comparing work function averages using heatmap colours.'), ENT_QUOTES, 'UTF-8')?>"></canvas>
        </div>
      <?php endif; ?>
      <table class="md-table">
        <thead>
          <tr>
            <th><?=t($t, 'work_function', 'Work Role')?></th>
            <th><?=t($t, 'count', 'Responses')?></th>
            <th><?=t($t, 'approved', 'Approved')?></th>
            <th><?=t($t, 'average_score', 'Average score (%)')?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($workFunctionSummary as $row): ?>
            <?php $wfKey = $row['work_function'] ?? ''; ?>
            <tr>
              <td><?=htmlspecialchars($workFunctionOptions[$wfKey] ?? ($wfKey !== '' ? $wfKey : t($t, 'unknown', 'Unknown')), ENT_QUOTES, 'UTF-8')?></td>
              <td><?= (int)$row['total_responses'] ?></td>
              <td><?= (int)$row['approved_count'] ?></td>
              <td><?= $formatScore($row['avg_score'] ?? null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="md-upgrade-meta"><?=t($t, 'work_function_empty', 'Assign questionnaires to teams to see benchmarks populate here.')?></p>
    <?php endif; ?>
  </div>
</section>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  document.addEventListener('DOMContentLoaded', () => {
    const copyButtons = document.querySelectorAll('[data-copy-target]');
    if (!copyButtons.length) {
      return;
    }

    const fallbackCopy = (text) => {
      const helper = document.createElement('textarea');
      helper.value = text;
      helper.setAttribute('readonly', '');
      helper.style.position = 'absolute';
      helper.style.left = '-9999px';
      document.body.appendChild(helper);
      helper.select();
      document.execCommand('copy');
      document.body.removeChild(helper);
    };

    copyButtons.forEach((button) => {
      button.addEventListener('click', async () => {
        const targetId = button.getAttribute('data-copy-target');
        if (!targetId) {
          return;
        }
        const target = document.getElementById(targetId);
        if (!target) {
          return;
        }
        const text = target.textContent || '';
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
          } else {
            fallbackCopy(text);
          }
          const successLabel = button.getAttribute('data-copy-success');
          const defaultLabel = button.getAttribute('data-copy-default');
          if (successLabel && defaultLabel) {
            button.textContent = successLabel;
            window.setTimeout(() => {
              button.textContent = defaultLabel;
            }, 2000);
          }
        } catch (error) {
          fallbackCopy(text);
        }
      });
    });
  });
</script>
<?php if ($hasAnalyticsCharts): ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  (function () {
    const rootStyles = getComputedStyle(document.documentElement);
    const questionnaireHeatmap = <?=json_encode([
      'labels' => array_column($questionnaireChartData, 'label'),
      'scores' => array_map(static fn($row) => $row['score'], $questionnaireChartData),
      'counts' => array_map(static fn($row) => $row['responses'], $questionnaireChartData),
    ], $chartJsonFlags)?>;
    const workFunctionHeatmap = <?=json_encode([
      'labels' => array_column($workFunctionChartData, 'label'),
      'scores' => array_map(static fn($row) => $row['score'], $workFunctionChartData),
      'counts' => array_map(static fn($row) => $row['responses'], $workFunctionChartData),
    ], $chartJsonFlags)?>;
    const labels = {
      averageScore: <?=json_encode(t($t, 'average_score', 'Average score (%)'), $chartJsonFlags)?>,
      responses: <?=json_encode(t($t, 'count', 'Responses'), $chartJsonFlags)?>,
    };

    const cssVar = (name, fallback) => {
      const value = rootStyles.getPropertyValue(name);
      if (value && value.trim()) {
        return value.trim();
      }
      if (fallback) {
        const fallbackValue = rootStyles.getPropertyValue(fallback);
        if (fallbackValue && fallbackValue.trim()) {
          return fallbackValue.trim();
        }
      }
      return '';
    };

    const parseMajorVersion = (chartLib) => {
      if (!chartLib || !chartLib.version) {
        return 0;
      }
      const versionStr = String(chartLib.version);
      const majorPart = versionStr.split('.')[0];
      const parsed = Number.parseInt(majorPart, 10);
      return Number.isNaN(parsed) ? 0 : parsed;
    };

    const createChartInstance = (chartLib, context, config) => {
      if (!chartLib || !context) {
        return null;
      }
      if (typeof chartLib === 'function') {
        return new chartLib(context, config);
      }
      if (chartLib.Chart && typeof chartLib.Chart === 'function') {
        return new chartLib.Chart(context, config);
      }
      if (chartLib.default && typeof chartLib.default === 'function') {
        return new chartLib.default(context, config);
      }
      return null;
    };

    const prepareChartLibrary = (chartLib) => {
      if (!chartLib) {
        return null;
      }
      if (chartLib.register && Array.isArray(chartLib.registerables) && chartLib.registerables.length) {
        try {
          chartLib.register(...chartLib.registerables);
        } catch (err) {
          // Ignore duplicate registration errors.
        }
      }
      return chartLib;
    };

    const heatStops = [
      { stop: 0, color: [211, 47, 47] },
      { stop: 0.5, color: [249, 168, 37] },
      { stop: 1, color: [46, 125, 50] },
    ];

    const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
    const mix = (start, end, ratio) => Math.round(start + (end - start) * ratio);

    function heatColor(score, alpha = 0.85) {
      if (typeof score !== 'number' || Number.isNaN(score)) {
        score = 0;
      }
      const normalized = clamp(score / 100, 0, 1);
      let left = heatStops[0];
      let right = heatStops[heatStops.length - 1];
      for (let i = 0; i < heatStops.length - 1; i += 1) {
        const current = heatStops[i];
        const next = heatStops[i + 1];
        if (normalized >= current.stop && normalized <= next.stop) {
          left = current;
          right = next;
          break;
        }
      }
      const range = right.stop - left.stop || 1;
      const ratio = clamp((normalized - left.stop) / range, 0, 1);
      const r = mix(left.color[0], right.color[0], ratio);
      const g = mix(left.color[1], right.color[1], ratio);
      const b = mix(left.color[2], right.color[2], ratio);
      return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function renderHeatmap(chartLib, targetId, dataset, renderOptions = {}) {
      const canvas = document.getElementById(targetId);
      const container = canvas
        ? canvas.closest('.md-chart-container')
        : document.querySelector(`.md-chart-container[data-chart-target="${targetId}"]`);
      const hasDataset = dataset && Array.isArray(dataset.labels) && dataset.labels.length;
      if (!hasDataset) {
        if (container) {
          container.setAttribute('data-has-data', 'false');
        }
        return;
      }
      if (!canvas) {
        if (container) {
          container.setAttribute('data-has-data', 'false');
        }
        return;
      }
      const context = canvas.getContext('2d');
      if (!context) {
        if (container) {
          container.setAttribute('data-has-data', 'false');
        }
        return;
      }
      if (container) {
        container.setAttribute('data-has-data', 'true');
      }
      const normalizeScore = (value) => {
        if (typeof value === 'number') {
          return Number.isFinite(value) ? value : null;
        }
        if (typeof value === 'string') {
          const parsed = Number.parseFloat(value);
          return Number.isFinite(parsed) ? parsed : null;
        }
        return null;
      };
      const normalizedScores = Array.isArray(dataset.scores)
        ? dataset.scores.map((value) => normalizeScore(value))
        : [];
      const chartScores = normalizedScores.map((score) => (score === null ? null : score));
      const counts = Array.isArray(dataset.counts)
        ? dataset.counts.map((value) => {
            if (typeof value === 'number') {
              return Number.isFinite(value) ? value : null;
            }
            if (typeof value === 'string' && value.trim() !== '') {
              const parsed = Number.parseInt(value, 10);
              return Number.isFinite(parsed) ? parsed : null;
            }
            return null;
          })
        : [];
      const neutralFill = 'rgba(148, 163, 184, 0.25)';
      const neutralStroke = 'rgba(148, 163, 184, 0.55)';
      const colors = normalizedScores.map((score) => (typeof score === 'number' ? heatColor(score, 0.8) : neutralFill));
      const borderColors = normalizedScores.map((score) => (typeof score === 'number' ? heatColor(score, 1) : neutralStroke));
      const orientation = renderOptions.indexAxis === 'x' ? 'x' : 'y';
      const major = parseMajorVersion(chartLib);
      const isModern = major >= 3;

      const formatTooltip = (label, index) => {
        const score = normalizedScores[index];
        const valueText = Number.isFinite(score) ? `${score.toFixed(1)}%` : '—';
        const count = counts[index];
        const countText = Number.isFinite(count) ? ` · ${count} ${labels.responses}` : '';
        return `${label}: ${valueText}${countText}`;
      };

      const datasetConfig = {
        data: chartScores,
        backgroundColor: colors,
        borderColor: borderColors,
        borderWidth: 1.5,
        barPercentage: 0.75,
      };

      const gridColor = cssVar('--app-border', '--brand-border') || 'rgba(17, 56, 94, 0.08)';
      let chartConfig;
      if (isModern) {
        datasetConfig.borderRadius = 6;
        const chartOptions = {
          responsive: true,
          maintainAspectRatio: false,
          indexAxis: orientation,
          scales: {
            x: {
              beginAtZero: true,
              max: 100,
              ticks: {
                callback: (value) => `${value}%`,
              },
              grid: { color: gridColor },
            },
            y: {
              ticks: { autoSkip: false },
              grid: { display: false },
            },
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (context) => formatTooltip(context.label || '', context.dataIndex),
              },
            },
          },
        };

        if (orientation === 'y') {
          chartOptions.scales.x.title = { display: true, text: labels.averageScore };
        } else {
          chartOptions.scales.y = {
            beginAtZero: true,
            max: 100,
            ticks: {
              callback: (value) => `${value}%`,
            },
            grid: { color: gridColor },
            title: { display: true, text: labels.averageScore },
          };
          chartOptions.scales.x = {
            ticks: { autoSkip: false },
            grid: { display: false },
          };
        }

        chartConfig = {
          type: 'bar',
          data: {
            labels: dataset.labels,
            datasets: [datasetConfig],
          },
          options: chartOptions,
        };
      } else {
        const tooltipLegacy = (tooltipItem, data) => {
          const itemLabel = tooltipItem.label || (data.labels && data.labels[tooltipItem.index]) || '';
          return formatTooltip(itemLabel, tooltipItem.index);
        };

        const valueScale = {
          ticks: {
            beginAtZero: true,
            max: 100,
            callback: (value) => `${value}%`,
          },
          gridLines: { color: gridColor },
          scaleLabel: { display: true, labelString: labels.averageScore },
        };

        const categoryScale = {
          ticks: { autoSkip: false },
          gridLines: { display: false },
        };

        const scales = orientation === 'y'
          ? { xAxes: [valueScale], yAxes: [categoryScale] }
          : { xAxes: [categoryScale], yAxes: [valueScale] };

        chartConfig = {
          type: orientation === 'y' ? 'horizontalBar' : 'bar',
          data: {
            labels: dataset.labels,
            datasets: [datasetConfig],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: false },
            tooltips: {
              callbacks: {
                label: tooltipLegacy,
              },
            },
            scales,
          },
        };
      }

      const containerHeight = container ? container.clientHeight : 0;
      const containerWidth = container ? container.clientWidth : 0;

      canvas.width = canvas.clientWidth || containerWidth || canvas.width;
      canvas.height = canvas.clientHeight || containerHeight || canvas.height || 320;
      createChartInstance(chartLib, context, chartConfig);
    }

    document.addEventListener('DOMContentLoaded', () => {
      const chartLib = prepareChartLibrary(window.Chart || null);
      if (!chartLib) {
        return;
      }
      if (questionnaireHeatmap.labels && questionnaireHeatmap.labels.length) {
        renderHeatmap(chartLib, 'questionnaire-performance-heatmap', questionnaireHeatmap, { indexAxis: 'y' });
      }
      if (workFunctionHeatmap.labels && workFunctionHeatmap.labels.length) {
        renderHeatmap(chartLib, 'work-function-heatmap', workFunctionHeatmap, { indexAxis: 'y' });
      }
    });
  })();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
