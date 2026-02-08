<?php
require_once __DIR__.'/../config.php';
if (!function_exists('available_work_functions')) {
    require_once __DIR__ . '/../lib/work_functions.php';
}
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$workFunctionChoices = work_function_choices($pdo);
$availableWorkFunctions = array_keys($workFunctionChoices);
$qbStrings = [
    'scoreWeightLabel' => t($t, 'qb_weight_label', 'Score weight (%)'),
    'scoreWeightHint' => t(
        $t,
        'qb_weight_hint',
        'Only weighted questions contribute to scoring and analytics. Items left at 0 are excluded from scores and charts.'
    ),
    'scoringSummaryTitle' => t($t, 'qb_scoring_summary_heading', 'Scoring summary'),
    'scoringSummaryManualLabel' => t($t, 'qb_scoring_manual_total', 'Manual weight total'),
    'scoringSummaryEffectiveLabel' => t($t, 'qb_scoring_effective_total', 'Effective score total'),
    'scoringSummaryCountLabel' => t($t, 'qb_scoring_count_label', 'Scorable items'),
    'scoringSummaryWeightedLabel' => t($t, 'qb_scoring_weighted_label', 'Items counted'),
    'scoringSummaryActionsLabel' => t($t, 'qb_scoring_actions_label', 'Scoring tools'),
    'normalizeWeights' => t($t, 'qb_scoring_normalize', 'Normalize to 100%'),
    'evenWeights' => t($t, 'qb_scoring_even', 'Split evenly'),
    'clearWeights' => t($t, 'qb_scoring_clear', 'Clear weights'),
    'singleChoiceAutoNote' => t(
        $t,
        'qb_scoring_single_choice_note',
        'Single-choice questions automatically share 100% of the score in analytics.'
    ),
    'nonSingleChoiceIgnoredNote' => t(
        $t,
        'qb_scoring_non_single_choice_note',
        'While a questionnaire contains single-choice questions, other question types are excluded from scoring.'
    ),
    'likertAutoNote' => t(
        $t,
        'qb_scoring_likert_note',
        'Likert questions automatically share 100% of the score in analytics.'
    ),
    'nonLikertIgnoredNote' => t(
        $t,
        'qb_scoring_nonlikert_note',
        'While a questionnaire contains Likert questions, other question types are excluded from scoring.'
    ),
    'missingWeightsWarning' => t(
        $t,
        'qb_scoring_missing_warning',
        'Dashboards will show “Not scored” unless at least one question has weight.'
    ),
    'manualTotalOffWarning' => t(
        $t,
        'qb_scoring_manual_total_warning',
        'Manual weights currently add up to %s%%.'
    ),
    'manualTotalOk' => t($t, 'qb_scoring_manual_total_ok', 'Manual weights currently add up to %s%%.'),
    'noScorableNote' => t(
        $t,
        'qb_scoring_no_scorable',
        'Add single-choice or weighted questions to enable scoring.'
    ),
    'normalizeSuccess' => t($t, 'qb_scoring_normalize_success', 'Weights normalized to total 100%.'),
    'normalizeNoop' => t($t, 'qb_scoring_normalize_noop', 'Add weights to questions before normalizing.'),
    'evenSuccess' => t($t, 'qb_scoring_even_success', 'Split weights evenly across scorable questions.'),
    'evenNoop' => t($t, 'qb_scoring_even_noop', 'Add scorable questions before splitting weights.'),
    'clearSuccess' => t($t, 'qb_scoring_clear_success', 'Cleared all question weights.'),
    'clearNoop' => t($t, 'qb_scoring_clear_noop', 'No weights to clear.'),
];

const LIKERT_DEFAULT_OPTIONS = [
    '1 - Strongly Disagree',
    '2 - Disagree',
    '3 - Neutral',
    '4 - Agree',
    '5 - Strongly Agree',
];

const QB_IMPORT_MAX_QUESTIONNAIRE_TITLE = 255;
const QB_IMPORT_MAX_SECTION_TITLE = 255;
const QB_IMPORT_MAX_ITEM_TEXT = 500;
const QB_IMPORT_MAX_LINK_ID = 64;
const QB_IMPORT_MAX_OPTION_VALUE = 500;
const QB_IMPORT_MAX_DESCRIPTION = 65535;

function qb_import_extract_value($value): string
{
    if (is_array($value)) {
        if (isset($value['@attributes']['value'])) {
            $value = $value['@attributes']['value'];
        } elseif (isset($value['value'])) {
            $value = $value['value'];
        } elseif (!empty($value)) {
            $value = reset($value);
        } else {
            $value = '';
        }
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        return (string)$value;
    }
    return '';
}

function qb_import_truncate(string $value, int $maxLength): string
{
    if ($maxLength <= 0) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return $value;
    }
    if (strlen($value) > $maxLength) {
        return substr($value, 0, $maxLength);
    }
    return $value;
}

function qb_import_normalize_string($value, int $maxLength, string $fallback = ''): string
{
    $normalized = trim(qb_import_extract_value($value));
    if ($normalized === '') {
        $normalized = trim($fallback);
    }
    if ($normalized === '') {
        return '';
    }
    return qb_import_truncate($normalized, $maxLength);
}

function qb_import_normalize_nullable_string($value, int $maxLength): ?string
{
    $normalized = trim(qb_import_extract_value($value));
    if ($normalized === '') {
        return null;
    }
    return qb_import_truncate($normalized, $maxLength);
}

function qb_questionnaire_to_fhir_resource(array $questionnaire): array
{
    $resource = [
        'resourceType' => 'Questionnaire',
        'status' => in_array(strtolower((string)($questionnaire['status'] ?? 'draft')), ['published', 'active'], true)
            ? 'active'
            : 'draft',
        'title' => $questionnaire['title'] ?? 'Questionnaire',
    ];
    if (!empty($questionnaire['description'])) {
        $resource['description'] = $questionnaire['description'];
    }

    $items = [];
    $sections = $questionnaire['sections'] ?? [];
    foreach ($sections as $section) {
        $items[] = [
            'linkId' => (string)($section['id'] ?? $section['title'] ?? uniqid('section', true)),
            'text' => $section['title'] ?: t(load_lang(ensure_locale()), 'section', 'Section'),
            'type' => 'group',
            'item' => qb_questionnaire_items_to_fhir_items($section['items'] ?? []),
        ];
    }
    $rootItems = qb_questionnaire_items_to_fhir_items($questionnaire['items'] ?? []);
    $resource['item'] = array_values(array_merge($items, $rootItems));

    if (!empty($questionnaire['id'])) {
        $resource['identifier'] = [
            [
                'system' => 'urn:hrassess:questionnaire',
                'value' => (string)$questionnaire['id'],
            ],
        ];
    }

    return $resource;
}

function qb_is_list_array(array $value): bool
{
    if ($value === []) {
        return true;
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function qb_xml_append_value(DOMDocument $doc, DOMElement $parent, string $key, $value): void
{
    if ($value === null) {
        return;
    }
    if (is_array($value)) {
        if (qb_is_list_array($value)) {
            foreach ($value as $item) {
                qb_xml_append_value($doc, $parent, $key, $item);
            }
            return;
        }

        $node = $doc->createElement($key);
        foreach ($value as $childKey => $childValue) {
            qb_xml_append_value($doc, $node, (string)$childKey, $childValue);
        }
        $parent->appendChild($node);
        return;
    }

    $node = $doc->createElement($key);
    $textValue = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    $node->appendChild($doc->createTextNode($textValue));
    $parent->appendChild($node);
}

function qb_questionnaire_to_fhir_xml(array $resource): string
{
    $resourceType = isset($resource['resourceType']) ? (string)$resource['resourceType'] : 'Questionnaire';
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $root = $doc->createElement($resourceType);
    $doc->appendChild($root);

    foreach ($resource as $key => $value) {
        if ($key === 'resourceType') {
            continue;
        }
        qb_xml_append_value($doc, $root, (string)$key, $value);
    }

    return $doc->saveXML() ?: '';
}

function qb_questionnaire_items_to_fhir_items(array $items): array
{
    $fhirItems = [];
    foreach ($items as $item) {
        $type = strtolower((string)($item['type'] ?? ''));
        $fhirType = 'string';
        if ($type === 'textarea') {
            $fhirType = 'text';
        } elseif ($type === 'boolean') {
            $fhirType = 'boolean';
        } elseif ($type === 'choice' || $type === 'likert') {
            $fhirType = 'choice';
        }

        $fhirItem = [
            'linkId' => (string)($item['linkId'] ?? uniqid('item', true)),
            'text' => $item['text'] ?? '',
            'type' => $fhirType,
        ];
        if (!empty($item['is_required'])) {
            $fhirItem['required'] = true;
        }
        if ($fhirType === 'choice' && !empty($item['options'])) {
            $fhirItem['answerOption'] = array_map(static function ($option) {
                return ['valueString' => (string)($option['value'] ?? '')];
            }, $item['options']);
        }
        $fhirItems[] = $fhirItem;
    }
    return $fhirItems;
}

function send_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function resolve_csrf_token(?array $payload = null): string {
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (isset($_GET['csrf'])) {
        return (string)$_GET['csrf'];
    }
    if (isset($_POST['csrf'])) {
        return (string)$_POST['csrf'];
    }
    if ($payload && isset($payload['csrf'])) {
        return (string)$payload['csrf'];
    }
    return '';
}

function ensure_csrf(?array $payload = null): void {
    $token = resolve_csrf_token($payload);
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        send_json([
            'status' => 'error',
            'message' => 'Invalid CSRF token',
        ], 400);
    }
}

$action = $_GET['action'] ?? '';

function qb_fetch_questionnaires(PDO $pdo): array
{
    $qsRows = $pdo->query('SELECT * FROM questionnaire ORDER BY id DESC')->fetchAll();
    $sectionsRows = $pdo->query('SELECT * FROM questionnaire_section ORDER BY questionnaire_id, order_index, id')->fetchAll();
    $itemsRows = $pdo->query('SELECT * FROM questionnaire_item ORDER BY questionnaire_id, order_index, id')->fetchAll();
    $optionsRows = $pdo->query('SELECT * FROM questionnaire_item_option ORDER BY questionnaire_item_id, order_index, id')->fetchAll();
    try {
        $wfRows = $pdo->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function')->fetchAll();
    } catch (PDOException $e) {
        error_log('questionnaire_manage work function fetch failed: ' . $e->getMessage());
        $wfRows = [];
    }

    $questionnaireResponseCounts = [];
    try {
        $responseCountStmt = $pdo->query('SELECT questionnaire_id, COUNT(*) AS response_count FROM questionnaire_response GROUP BY questionnaire_id');
        if ($responseCountStmt) {
            foreach ($responseCountStmt->fetchAll() as $row) {
                $qid = isset($row['questionnaire_id']) ? (int)$row['questionnaire_id'] : 0;
                if ($qid > 0) {
                    $questionnaireResponseCounts[$qid] = (int)$row['response_count'];
                }
            }
        }
    } catch (PDOException $e) {
        error_log('questionnaire_manage response count fetch failed: ' . $e->getMessage());
    }

    $itemResponseCounts = [];
    try {
        $itemResponseStmt = $pdo->query('SELECT qr.questionnaire_id, qri.linkId, COUNT(*) AS response_count FROM questionnaire_response_item qri JOIN questionnaire_response qr ON qr.id = qri.response_id GROUP BY qr.questionnaire_id, qri.linkId');
        if ($itemResponseStmt) {
            foreach ($itemResponseStmt->fetchAll() as $row) {
                $qid = isset($row['questionnaire_id']) ? (int)$row['questionnaire_id'] : 0;
                $linkId = isset($row['linkId']) ? (string)$row['linkId'] : '';
                if ($qid > 0 && $linkId !== '') {
                    $itemResponseCounts[$qid][$linkId] = (int)$row['response_count'];
                }
            }
        }
    } catch (PDOException $e) {
        error_log('questionnaire_manage item response count fetch failed: ' . $e->getMessage());
    }

    $sectionsByQuestionnaire = [];
    foreach ($sectionsRows as $section) {
        $qid = (int)$section['questionnaire_id'];
        $sectionsByQuestionnaire[$qid][] = [
            'id' => (int)$section['id'],
            'questionnaire_id' => $qid,
            'title' => $section['title'],
            'description' => $section['description'],
            'order_index' => (int)$section['order_index'],
            'is_active' => (bool)($section['is_active'] ?? true),
        ];
    }

    $optionsByItem = [];
    foreach ($optionsRows as $option) {
        $itemId = (int)$option['questionnaire_item_id'];
        $optionsByItem[$itemId][] = [
            'id' => (int)$option['id'],
            'questionnaire_item_id' => $itemId,
            'value' => $option['value'],
            'is_correct' => !empty($option['is_correct']),
            'order_index' => (int)$option['order_index'],
        ];
    }

    $itemsByQuestionnaire = [];
    $itemsBySection = [];
    foreach ($itemsRows as $item) {
        $qid = (int)$item['questionnaire_id'];
        $sid = $item['section_id'] !== null ? (int)$item['section_id'] : null;
        $formatted = [
            'id' => (int)$item['id'],
            'questionnaire_id' => $qid,
            'section_id' => $sid,
            'linkId' => $item['linkId'],
            'text' => $item['text'],
            'type' => $item['type'],
            'order_index' => (int)$item['order_index'],
            'weight_percent' => (int)$item['weight_percent'],
            'allow_multiple' => (bool)$item['allow_multiple'],
            'is_required' => (bool)($item['is_required'] ?? false),
            'requires_correct' => (bool)($item['requires_correct'] ?? false),
            'options' => $optionsByItem[(int)$item['id']] ?? [],
            'is_active' => (bool)($item['is_active'] ?? true),
            'has_responses' => !empty($itemResponseCounts[$qid][$item['linkId']] ?? null),
        ];
        if ($sid) {
            $itemsBySection[$sid][] = $formatted;
        } else {
            $itemsByQuestionnaire[$qid][] = $formatted;
        }
    }

    $workFunctionsByQuestionnaire = [];
    foreach ($wfRows as $wf) {
        $qid = (int)$wf['questionnaire_id'];
        $label = isset($wf['work_function']) ? (string)$wf['work_function'] : '';
        if ($label === '') {
            continue;
        }
        $workFunctionsByQuestionnaire[$qid][] = $label;
    }

    $questionnaires = [];
    foreach ($qsRows as $row) {
        $qid = (int)$row['id'];
        $sections = [];
        foreach ($sectionsByQuestionnaire[$qid] ?? [] as $section) {
            $sectionId = $section['id'];
            $sectionItems = $itemsBySection[$sectionId] ?? [];
            $sectionHasResponses = false;
            foreach ($sectionItems as $sectionItem) {
                if (!empty($sectionItem['has_responses'])) {
                    $sectionHasResponses = true;
                    break;
                }
            }
            $sections[] = $section + [
                'items' => $sectionItems,
                'has_responses' => $sectionHasResponses,
            ];
        }
        $questionnaires[] = [
            'id' => $qid,
            'title' => $row['title'],
            'description' => $row['description'],
            'status' => strtolower((string)($row['status'] ?? 'draft')),
            'created_at' => $row['created_at'],
            'sections' => $sections,
            'items' => $itemsByQuestionnaire[$qid] ?? [],
            'work_functions' => array_values(array_unique($workFunctionsByQuestionnaire[$qid] ?? [])),
            'has_responses' => !empty($questionnaireResponseCounts[$qid] ?? null),
            'response_count' => (int)($questionnaireResponseCounts[$qid] ?? 0),
        ];
    }

    return $questionnaires;
}

if ($action === 'fetch') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        send_json(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
    ensure_csrf();

    $questionnaires = qb_fetch_questionnaires($pdo);
    send_json([
        'status' => 'ok',
        'csrf' => csrf_token(),
        'questionnaires' => $questionnaires,
    ]);
}

if ($action === 'export') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        send_json(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
    ensure_csrf();
    $qid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($qid <= 0) {
        send_json(['status' => 'error', 'message' => 'Missing questionnaire id'], 400);
    }

    $questionnaires = qb_fetch_questionnaires($pdo);
    $match = null;
    foreach ($questionnaires as $questionnaire) {
        if ((int)($questionnaire['id'] ?? 0) === $qid) {
            $match = $questionnaire;
            break;
        }
    }
    if (!$match) {
        send_json(['status' => 'error', 'message' => 'Questionnaire not found'], 404);
    }

    $resource = qb_questionnaire_to_fhir_resource($match);
    $xml = qb_questionnaire_to_fhir_xml($resource);
    $filename = 'questionnaire-' . $qid . '.xml';
    header('Content-Type: application/fhir+xml');
    header('Content-Disposition: attachment; filename=' . $filename);
    echo $xml;
    exit;
}

if ($action === 'upgrade') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    ensure_csrf($payload);
    $targetId = isset($payload['questionnaire_id']) ? (int)$payload['questionnaire_id'] : 0;
    if ($targetId <= 0) {
        send_json(['status' => 'error', 'message' => 'Invalid questionnaire id'], 400);
    }

    $pdo->beginTransaction();
    try {
        $itemStmt = $pdo->prepare('SELECT * FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index, id');
        $optionStmt = $pdo->prepare('SELECT * FROM questionnaire_item_option WHERE questionnaire_item_id=? ORDER BY order_index, id');
        $updateItemStmt = $pdo->prepare('UPDATE questionnaire_item SET weight_percent=? WHERE id=?');
        $insertOptionStmt = $pdo->prepare('INSERT INTO questionnaire_item_option (questionnaire_item_id, value, is_correct, order_index) VALUES (?, ?, ?, ?)');

        $itemStmt->execute([$targetId]);
        $items = $itemStmt->fetchAll();

        $likertItems = [];
        $otherItems = [];
        foreach ($items as $row) {
            $type = strtolower((string)($row['type'] ?? ''));
            if ($type === 'likert') {
                $likertItems[] = $row;
            } else {
                $otherItems[] = $row;
            }
        }

        $updates = 0;
        $optionInserts = 0;
        if ($likertItems) {
            $count = count($likertItems);
            $base = (int)floor(100 / $count);
            $remainder = 100 - ($base * $count);
            foreach ($likertItems as $index => $row) {
                $targetWeight = $base + ($remainder > 0 ? 1 : 0);
                if ($remainder > 0) {
                    $remainder -= 1;
                }
                $currentWeight = (int)($row['weight_percent'] ?? 0);
                if ($currentWeight !== $targetWeight) {
                    $updateItemStmt->execute([$targetWeight, (int)$row['id']]);
                    $updates += 1;
                }

                $optionStmt->execute([(int)$row['id']]);
                $existingOptions = $optionStmt->fetchAll();
                if (!$existingOptions) {
                    $order = 1;
                    foreach (LIKERT_DEFAULT_OPTIONS as $label) {
                        $insertOptionStmt->execute([(int)$row['id'], $label, 0, $order]);
                        $order += 1;
                        $optionInserts += 1;
                    }
                }
            }
            foreach ($otherItems as $row) {
                if ((int)($row['weight_percent'] ?? 0) !== 0) {
                    $updateItemStmt->execute([0, (int)$row['id']]);
                    $updates += 1;
                }
            }
        }

        $pdo->commit();
        $message = 'Questionnaire updated';
        if ($updates === 0 && $optionInserts === 0) {
            $message = 'Questionnaire already up to date';
        }
        send_json([
            'status' => 'ok',
            'message' => $message,
            'updated_items' => $updates,
            'added_options' => $optionInserts,
            'csrf' => csrf_token(),
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('questionnaire_manage upgrade failed: ' . $e->getMessage());
        send_json(['status' => 'error', 'message' => 'Unable to update questionnaire'], 500);
    }
}

if ($action === 'save' || $action === 'publish') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        send_json(['status' => 'error', 'message' => 'Invalid payload'], 400);
    }
    ensure_csrf($payload);

    $structures = $payload['questionnaires'] ?? [];
    if (!is_array($structures)) {
        send_json(['status' => 'error', 'message' => 'Invalid questionnaire data'], 400);
    }

    $existingQs = $pdo->query('SELECT * FROM questionnaire ORDER BY id')->fetchAll();
    $questionnaireMap = [];
    foreach ($existingQs as $row) {
        $questionnaireMap[(int)$row['id']] = $row;
    }

    $sectionsRows = $pdo->query('SELECT * FROM questionnaire_section ORDER BY questionnaire_id, id')->fetchAll();
    $sectionsMap = [];
    foreach ($sectionsRows as $row) {
        $qid = (int)$row['questionnaire_id'];
        $sectionsMap[$qid][(int)$row['id']] = $row;
    }

    $itemsRows = $pdo->query('SELECT * FROM questionnaire_item ORDER BY questionnaire_id, id')->fetchAll();
    $optionsRows = $pdo->query('SELECT * FROM questionnaire_item_option ORDER BY questionnaire_item_id, id')->fetchAll();
    $itemsMap = [];
    foreach ($itemsRows as $row) {
        $qid = (int)$row['questionnaire_id'];
        $itemsMap[$qid][(int)$row['id']] = $row;
    }

    $optionsMap = [];
    foreach ($optionsRows as $row) {
        $itemId = (int)$row['questionnaire_item_id'];
        $optionsMap[$itemId][(int)$row['id']] = $row;
    }

    $questionnaireSeen = [];
    $idMap = [
        'questionnaires' => [],
        'sections' => [],
        'items' => [],
        'options' => [],
    ];

    $questionnaireResponseCounts = [];
    try {
        $responseCountStmt = $pdo->query('SELECT questionnaire_id, COUNT(*) AS response_count FROM questionnaire_response GROUP BY questionnaire_id');
        if ($responseCountStmt) {
            foreach ($responseCountStmt->fetchAll() as $row) {
                $qid = isset($row['questionnaire_id']) ? (int)$row['questionnaire_id'] : 0;
                if ($qid > 0) {
                    $questionnaireResponseCounts[$qid] = (int)$row['response_count'];
                }
            }
        }
    } catch (PDOException $e) {
        error_log('questionnaire_manage response count fetch failed: ' . $e->getMessage());
    }

    $itemResponsePresence = [];
    try {
        $itemResponseStmt = $pdo->query('SELECT qr.questionnaire_id, qri.linkId FROM questionnaire_response_item qri JOIN questionnaire_response qr ON qr.id = qri.response_id GROUP BY qr.questionnaire_id, qri.linkId');
        if ($itemResponseStmt) {
            foreach ($itemResponseStmt->fetchAll() as $row) {
                $qid = isset($row['questionnaire_id']) ? (int)$row['questionnaire_id'] : 0;
                $linkId = isset($row['linkId']) ? (string)$row['linkId'] : '';
                if ($qid > 0 && $linkId !== '') {
                    $itemResponsePresence[$qid][$linkId] = true;
                }
            }
        }
    } catch (PDOException $e) {
        error_log('questionnaire_manage item response presence fetch failed: ' . $e->getMessage());
    }

    $pdo->beginTransaction();
    try {
        $insertQuestionnaireStmt = $pdo->prepare('INSERT INTO questionnaire (title, description, status) VALUES (?, ?, ?)');
        $updateQuestionnaireStmt = $pdo->prepare('UPDATE questionnaire SET title=?, description=?, status=? WHERE id=?');

        $insertSectionStmt = $pdo->prepare('INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index, is_active) VALUES (?, ?, ?, ?, ?)');
        $updateSectionStmt = $pdo->prepare('UPDATE questionnaire_section SET title=?, description=?, order_index=?, is_active=? WHERE id=?');

        $insertItemStmt = $pdo->prepare('INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple, is_required, requires_correct, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $updateItemStmt = $pdo->prepare('UPDATE questionnaire_item SET section_id=?, linkId=?, text=?, type=?, order_index=?, weight_percent=?, allow_multiple=?, is_required=?, requires_correct=?, is_active=? WHERE id=?');
        $insertOptionStmt = $pdo->prepare('INSERT INTO questionnaire_item_option (questionnaire_item_id, value, is_correct, order_index) VALUES (?, ?, ?, ?)');
        $updateOptionStmt = $pdo->prepare('UPDATE questionnaire_item_option SET value=?, is_correct=?, order_index=? WHERE id=?');
        $insertWorkFunctionStmt = $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');
        $deleteWorkFunctionStmt = $pdo->prepare('DELETE FROM questionnaire_work_function WHERE questionnaire_id=?');

        $saveOptions = function (int $itemId, $optionsInput, bool $isSingleChoice, bool $requiresCorrect) use (&$optionsMap, $insertOptionStmt, $updateOptionStmt, &$idMap, $pdo) {
            $existing = $optionsMap[$itemId] ?? [];
            if (!is_array($optionsInput)) {
                $optionsInput = [];
            }
            $seen = [];
            $correctAssigned = false;
            $hasExplicitCorrect = false;
            if ($isSingleChoice && $requiresCorrect) {
                foreach ($optionsInput as $optionData) {
                    if (is_array($optionData) && !empty($optionData['is_correct'])) {
                        $hasExplicitCorrect = true;
                        break;
                    }
                }
            }
            $order = 1;
            foreach ($optionsInput as $optionData) {
                if (!is_array($optionData)) {
                    continue;
                }
                $value = trim((string)($optionData['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $isCorrect = !empty($optionData['is_correct']);
                if ($isSingleChoice && $requiresCorrect) {
                    if (!$hasExplicitCorrect && !$correctAssigned) {
                        $isCorrect = true;
                    }
                    if ($isCorrect && $correctAssigned) {
                        $isCorrect = false;
                    }
                    if ($isCorrect) {
                        $correctAssigned = true;
                    }
                } elseif ($isSingleChoice && !$requiresCorrect) {
                    $isCorrect = false;
                }
                $optionClientId = $optionData['clientId'] ?? null;
                $optionId = isset($optionData['id']) ? (int)$optionData['id'] : null;
                if ($optionId && isset($existing[$optionId])) {
                    $updateOptionStmt->execute([$value, $isCorrect ? 1 : 0, $order, $optionId]);
                } else {
                    $insertOptionStmt->execute([$itemId, $value, $isCorrect ? 1 : 0, $order]);
                    $optionId = (int)$pdo->lastInsertId();
                    if ($optionClientId) {
                        $idMap['options'][$optionClientId] = $optionId;
                    }
                }
                $seen[] = $optionId;
                $order++;
            }
            $toDelete = array_diff(array_keys($existing), $seen);
            if ($toDelete) {
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                $stmt = $pdo->prepare("DELETE FROM questionnaire_item_option WHERE id IN ($placeholders)");
                $stmt->execute(array_values($toDelete));
            }
            $optionsMap[$itemId] = [];
            foreach ($seen as $optionId) {
                $optionsMap[$itemId][$optionId] = ['id' => $optionId];
            }
        };

        foreach ($structures as $qData) {
            if (!is_array($qData)) {
                continue;
            }
            $clientId = $qData['clientId'] ?? null;
            $qid = isset($qData['id']) ? (int)$qData['id'] : null;
            $title = trim((string)($qData['title'] ?? ''));
            $description = $qData['description'] ?? null;
            $status = strtolower(trim((string)($qData['status'] ?? '')));
            if (!in_array($status, ['draft', 'published', 'inactive'], true)) {
                $status = 'draft';
            }
            if ($action === 'publish') {
                $status = 'published';
            }

            if ($qid && isset($questionnaireMap[$qid])) {
                $updateQuestionnaireStmt->execute([$title, $description, $status, $qid]);
                $questionnaireMap[$qid]['status'] = $status;
            } else {
                $insertQuestionnaireStmt->execute([$title, $description, $status]);
                $qid = (int)$pdo->lastInsertId();
                if ($clientId) {
                    $idMap['questionnaires'][$clientId] = $qid;
                }
                $questionnaireMap[$qid] = [
                    'id' => $qid,
                    'title' => $title,
                    'description' => $description,
                    'status' => $status,
                ];
            }
            $questionnaireSeen[] = $qid;

            $sectionSeen = [];
            $itemSeen = [];

            $existingSections = $sectionsMap[$qid] ?? [];
            $existingItems = $itemsMap[$qid] ?? [];

            $sectionsInput = $qData['sections'] ?? [];
            if (!is_array($sectionsInput)) {
                $sectionsInput = [];
            }

            $orderIndex = 1;
            foreach ($sectionsInput as $sectionData) {
                if (!is_array($sectionData)) {
                    continue;
                }
                $sectionClientId = $sectionData['clientId'] ?? null;
                $sectionId = isset($sectionData['id']) ? (int)$sectionData['id'] : null;
                $sectionTitle = trim((string)($sectionData['title'] ?? ''));
                $sectionDescription = $sectionData['description'] ?? null;
                $sectionActive = array_key_exists('is_active', $sectionData) ? !empty($sectionData['is_active']) : true;

                if ($sectionId && isset($existingSections[$sectionId])) {
                    $updateSectionStmt->execute([$sectionTitle, $sectionDescription, $orderIndex, $sectionActive ? 1 : 0, $sectionId]);
                    $existingSections[$sectionId]['is_active'] = $sectionActive ? 1 : 0;
                } else {
                    $insertSectionStmt->execute([$qid, $sectionTitle, $sectionDescription, $orderIndex, $sectionActive ? 1 : 0]);
                    $sectionId = (int)$pdo->lastInsertId();
                    if ($sectionClientId) {
                        $idMap['sections'][$sectionClientId] = $sectionId;
                    }
                    $existingSections[$sectionId] = [
                        'id' => $sectionId,
                        'is_active' => $sectionActive ? 1 : 0,
                    ];
                }
                $sectionSeen[] = $sectionId;

                $itemsInput = $sectionData['items'] ?? [];
                if (!is_array($itemsInput)) {
                    $itemsInput = [];
                }
                $itemOrder = 1;
                foreach ($itemsInput as $itemData) {
                    if (!is_array($itemData)) {
                        continue;
                    }
                    $itemClientId = $itemData['clientId'] ?? null;
                    $itemId = isset($itemData['id']) ? (int)$itemData['id'] : null;
                    $linkId = trim((string)($itemData['linkId'] ?? ''));
                    $text = trim((string)($itemData['text'] ?? ''));
                    $type = $itemData['type'] ?? 'text';
                    if (!in_array($type, ['likert', 'text', 'textarea', 'boolean', 'choice'], true)) {
                        $type = 'choice';
                    }
                    $weight = isset($itemData['weight_percent']) ? (int)$itemData['weight_percent'] : 0;
                    $allowMultiple = !empty($itemData['allow_multiple']);
                    $isRequired = !empty($itemData['is_required']);
                    $requiresCorrect = !empty($itemData['requires_correct']);
                    if ($type !== 'choice') {
                        $allowMultiple = false;
                    }
                    if ($type !== 'choice' || $allowMultiple) {
                        $requiresCorrect = false;
                    }
                    $itemActive = array_key_exists('is_active', $itemData) ? !empty($itemData['is_active']) : true;

                    if ($itemId && isset($existingItems[$itemId])) {
                        $updateItemStmt->execute([$sectionId, $linkId, $text, $type, $itemOrder, $weight, $allowMultiple ? 1 : 0, $isRequired ? 1 : 0, $requiresCorrect ? 1 : 0, $itemActive ? 1 : 0, $itemId]);
                        $existingItems[$itemId]['section_id'] = $sectionId;
                        $existingItems[$itemId]['linkId'] = $linkId;
                        $existingItems[$itemId]['is_active'] = $itemActive ? 1 : 0;
                    } else {
                        $insertItemStmt->execute([$qid, $sectionId, $linkId, $text, $type, $itemOrder, $weight, $allowMultiple ? 1 : 0, $isRequired ? 1 : 0, $requiresCorrect ? 1 : 0, $itemActive ? 1 : 0]);
                        $itemId = (int)$pdo->lastInsertId();
                        if ($itemClientId) {
                            $idMap['items'][$itemClientId] = $itemId;
                        }
                        $existingItems[$itemId] = [
                            'id' => $itemId,
                            'section_id' => $sectionId,
                            'linkId' => $linkId,
                            'is_active' => $itemActive ? 1 : 0,
                        ];
                    }
                    $optionsInput = $itemData['options'] ?? [];
                    if (!in_array($type, ['choice', 'likert'], true)) {
                        $optionsInput = [];
                    }
                    $isSingleChoice = ($type === 'choice') && empty($allowMultiple);
                    $saveOptions($itemId, $optionsInput, $isSingleChoice, $requiresCorrect);
                    $itemSeen[] = $itemId;
                    $itemOrder++;
                }
                $orderIndex++;
            }

            $rootItemsInput = $qData['items'] ?? [];
            if (!is_array($rootItemsInput)) {
                $rootItemsInput = [];
            }
            $rootOrder = 1;
            foreach ($rootItemsInput as $itemData) {
                if (!is_array($itemData)) {
                    continue;
                }
                $itemClientId = $itemData['clientId'] ?? null;
                $itemId = isset($itemData['id']) ? (int)$itemData['id'] : null;
                $linkId = trim((string)($itemData['linkId'] ?? ''));
                $text = trim((string)($itemData['text'] ?? ''));
                $type = $itemData['type'] ?? 'text';
                if (!in_array($type, ['likert', 'text', 'textarea', 'boolean', 'choice'], true)) {
                    $type = 'choice';
                }
                $weight = isset($itemData['weight_percent']) ? (int)$itemData['weight_percent'] : 0;
                $allowMultiple = !empty($itemData['allow_multiple']);
                $isRequired = !empty($itemData['is_required']);
                $requiresCorrect = !empty($itemData['requires_correct']);
                if ($type !== 'choice') {
                    $allowMultiple = false;
                }
                if ($type !== 'choice' || $allowMultiple) {
                    $requiresCorrect = false;
                }
                $itemActive = array_key_exists('is_active', $itemData) ? !empty($itemData['is_active']) : true;

                if ($itemId && isset($existingItems[$itemId])) {
                    $updateItemStmt->execute([null, $linkId, $text, $type, $rootOrder, $weight, $allowMultiple ? 1 : 0, $isRequired ? 1 : 0, $requiresCorrect ? 1 : 0, $itemActive ? 1 : 0, $itemId]);
                    $existingItems[$itemId]['section_id'] = null;
                    $existingItems[$itemId]['linkId'] = $linkId;
                    $existingItems[$itemId]['is_active'] = $itemActive ? 1 : 0;
                } else {
                    $insertItemStmt->execute([$qid, null, $linkId, $text, $type, $rootOrder, $weight, $allowMultiple ? 1 : 0, $isRequired ? 1 : 0, $requiresCorrect ? 1 : 0, $itemActive ? 1 : 0]);
                    $itemId = (int)$pdo->lastInsertId();
                    if ($itemClientId) {
                        $idMap['items'][$itemClientId] = $itemId;
                    }
                    $existingItems[$itemId] = [
                        'id' => $itemId,
                        'section_id' => null,
                        'linkId' => $linkId,
                        'is_active' => $itemActive ? 1 : 0,
                    ];
                }
                $optionsInput = $itemData['options'] ?? [];
                if (!in_array($type, ['choice', 'likert'], true)) {
                    $optionsInput = [];
                }
                $isSingleChoice = ($type === 'choice') && empty($allowMultiple);
                $saveOptions($itemId, $optionsInput, $isSingleChoice, $requiresCorrect);
                $itemSeen[] = $itemId;
                $rootOrder++;
            }

            $itemsToDelete = array_diff(array_keys($existingItems), $itemSeen);
            if ($itemsToDelete) {
                $itemsToDeactivate = [];
                $itemsToRemove = [];
                foreach ($itemsToDelete as $itemId) {
                    $row = $existingItems[$itemId] ?? [];
                    $linkId = isset($row['linkId']) ? (string)$row['linkId'] : '';
                    $hasResponses = $linkId !== '' && !empty($itemResponsePresence[$qid][$linkId] ?? null);
                    if ($hasResponses) {
                        $itemsToDeactivate[] = $itemId;
                    } else {
                        $itemsToRemove[] = $itemId;
                    }
                }
                if ($itemsToDeactivate) {
                    $placeholders = implode(',', array_fill(0, count($itemsToDeactivate), '?'));
                    $stmt = $pdo->prepare("UPDATE questionnaire_item SET is_active=0 WHERE id IN ($placeholders)");
                    $stmt->execute(array_values($itemsToDeactivate));
                }
                if ($itemsToRemove) {
                    $placeholders = implode(',', array_fill(0, count($itemsToRemove), '?'));
                    $stmt = $pdo->prepare("DELETE FROM questionnaire_item WHERE id IN ($placeholders)");
                    $stmt->execute(array_values($itemsToRemove));
                }
            }

            if (array_key_exists('work_functions', $qData)) {
                $workFunctionsInput = $qData['work_functions'];
                if (!is_array($workFunctionsInput)) {
                    $workFunctionsInput = [];
                }
                $allowedFunctions = [];
                foreach ($workFunctionsInput as $wf) {
                    if (!is_string($wf)) {
                        continue;
                    }
                    $wfKey = trim($wf);
                    if ($wfKey === '' || !isset($workFunctionChoices[$wfKey])) {
                        continue;
                    }
                    $allowedFunctions[$wfKey] = $wfKey;
                }
                $deleteWorkFunctionStmt->execute([$qid]);
                foreach (array_keys($allowedFunctions) as $wfKey) {
                    $insertWorkFunctionStmt->execute([$qid, $wfKey]);
                }
            }

        $sectionsToDelete = array_diff(array_keys($existingSections), $sectionSeen);
        if ($sectionsToDelete) {
            $sectionsToDeactivate = [];
            $sectionsToRemove = [];
            foreach ($sectionsToDelete as $sectionId) {
                $hasResponses = false;
                foreach ($itemsMap[$qid] ?? [] as $existingItemRow) {
                    if ((int)($existingItemRow['section_id'] ?? 0) === $sectionId) {
                        $linkCandidate = isset($existingItemRow['linkId']) ? (string)$existingItemRow['linkId'] : '';
                        if ($linkCandidate !== '' && !empty($itemResponsePresence[$qid][$linkCandidate] ?? null)) {
                            $hasResponses = true;
                            break;
                        }
                    }
                }
                if ($hasResponses) {
                    $sectionsToDeactivate[] = $sectionId;
                } else {
                    $sectionsToRemove[] = $sectionId;
                }
            }
            if ($sectionsToDeactivate) {
                $placeholders = implode(',', array_fill(0, count($sectionsToDeactivate), '?'));
                $stmt = $pdo->prepare("UPDATE questionnaire_section SET is_active=0 WHERE id IN ($placeholders)");
                $stmt->execute(array_values($sectionsToDeactivate));
                $stmt = $pdo->prepare("UPDATE questionnaire_item SET is_active=0 WHERE section_id IN ($placeholders)");
                $stmt->execute(array_values($sectionsToDeactivate));
            }
            if ($sectionsToRemove) {
                $placeholders = implode(',', array_fill(0, count($sectionsToRemove), '?'));
                $stmt = $pdo->prepare("DELETE FROM questionnaire_section WHERE id IN ($placeholders)");
                $stmt->execute(array_values($sectionsToRemove));
            }
        }
    }

    $deleteQuestionnaires = array_diff(array_keys($questionnaireMap), $questionnaireSeen);
    if ($deleteQuestionnaires) {
        $questionnairesToDeactivate = [];
        $questionnairesToRemove = [];
        foreach ($deleteQuestionnaires as $deleteQid) {
            if (!empty($questionnaireResponseCounts[$deleteQid] ?? null)) {
                $questionnairesToDeactivate[] = $deleteQid;
            } else {
                $questionnairesToRemove[] = $deleteQid;
            }
        }
        if ($questionnairesToDeactivate) {
            $placeholders = implode(',', array_fill(0, count($questionnairesToDeactivate), '?'));
            $stmt = $pdo->prepare("UPDATE questionnaire SET status='inactive' WHERE id IN ($placeholders)");
            $stmt->execute(array_values($questionnairesToDeactivate));
            $stmt = $pdo->prepare("UPDATE questionnaire_section SET is_active=0 WHERE questionnaire_id IN ($placeholders)");
            $stmt->execute(array_values($questionnairesToDeactivate));
            $stmt = $pdo->prepare("UPDATE questionnaire_item SET is_active=0 WHERE questionnaire_id IN ($placeholders)");
            $stmt->execute(array_values($questionnairesToDeactivate));
        }
        if ($questionnairesToRemove) {
            $placeholders = implode(',', array_fill(0, count($questionnairesToRemove), '?'));
            $stmt = $pdo->prepare("DELETE FROM questionnaire WHERE id IN ($placeholders)");
            $stmt->execute(array_values($questionnairesToRemove));
        }
    }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        send_json([
            'status' => 'error',
            'message' => 'Failed to persist questionnaire data',
            'detail' => $e->getMessage(),
        ], 500);
    }

    send_json([
        'status' => 'ok',
        'message' => $action === 'publish' ? 'Questionnaires published' : 'Questionnaires saved',
        'idMap' => $idMap,
        'csrf' => csrf_token(),
    ]);
}

$msg = $_SESSION['questionnaire_import_flash'] ?? '';
$importPopup = $_SESSION['questionnaire_import_popup'] ?? null;
$recentImportId = null;
if (isset($_SESSION['questionnaire_import_focus'])) {
    $candidate = (int)$_SESSION['questionnaire_import_focus'];
    if ($candidate > 0) {
        $recentImportId = $candidate;
    }
}
unset($_SESSION['questionnaire_import_flash'], $_SESSION['questionnaire_import_focus'], $_SESSION['questionnaire_import_popup']);
if (isset($_POST['import'])) {
    csrf_check();
    $recentImportId = null;
    $parseErrors = [];
    $importDetails = [];
    $importStatus = 'error';
    $importTitle = t($t, 'import_log_title', 'Import log');
    $importFilename = $_FILES['file']['name'] ?? '';
    if ($importFilename) {
        $importDetails[] = 'File: ' . $importFilename;
    }
    if (!empty($_FILES['file']['tmp_name'])) {
        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $raw = ltrim((string)$raw, "\xEF\xBB\xBF");
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml !== false) {
                $rootName = $xml->getName();
                $json = json_encode($xml);
                $data = json_decode($json, true);
                if (is_array($data) && $rootName && !isset($data['resourceType'])) {
                    $data['resourceType'] = $rootName;
                }
            } else {
                $xmlErrors = libxml_get_errors();
                if ($xmlErrors) {
                    $messages = array_map(static function ($err) {
                        return trim($err->message) . ' on line ' . $err->line;
                    }, $xmlErrors);
                    error_log('Questionnaire import XML parse errors: ' . implode(' | ', $messages));
                    $parseErrors = array_slice($messages, 0, 3);
                    $importDetails[] = 'XML parse errors: ' . implode(' | ', $parseErrors) . '.';
                } else {
                    error_log('Questionnaire import XML parse failed with no libxml errors.');
                    $parseErrors = ['XML parsing failed with no additional error details.'];
                    $importDetails[] = $parseErrors[0];
                }
                libxml_clear_errors();
            }
            libxml_use_internal_errors(false);
        }
        if ($data) {
            $qs = [];
            $bundleResourceTypes = [];
            if (($data['resourceType'] ?? '') === 'Bundle') {
                foreach ($data['entry'] ?? [] as $entry) {
                    $entryResourceType = $entry['resource']['resourceType'] ?? null;
                    if ($entryResourceType) {
                        $bundleResourceTypes[] = $entryResourceType;
                    }
                    if ($entryResourceType === 'Questionnaire') {
                        $qs[] = $entry['resource'];
                    }
                }
            } elseif (($data['resourceType'] ?? '') === 'Questionnaire') {
                $qs[] = $data;
            }

            if ($qs) {
                $startedTransaction = false;
                $importedQuestionnaires = 0;
                $importedSections = 0;
                $importedItems = 0;
                $importedOptions = 0;
                try {
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                        $startedTransaction = true;
                    }

                    $insertQuestionnaireStmt = $pdo->prepare('INSERT INTO questionnaire (title, description, status) VALUES (?, ?, ?)');
                    $insertWorkFunctionStmt = $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');

                    foreach ($qs as $resource) {
                        $title = qb_import_normalize_string($resource['title'] ?? null, QB_IMPORT_MAX_QUESTIONNAIRE_TITLE, 'FHIR Questionnaire');
                        if ($title === '') {
                            $title = 'FHIR Questionnaire';
                        }
                        $description = qb_import_normalize_nullable_string($resource['description'] ?? null, QB_IMPORT_MAX_DESCRIPTION);
                        $rawStatus = strtolower((string)($resource['status'] ?? ''));
                        switch ($rawStatus) {
                            case 'draft':
                                $status = 'draft';
                                break;
                            case 'retired':
                            case 'inactive':
                                $status = 'inactive';
                                break;
                            case 'active':
                                $status = 'published';
                                break;
                            default:
                                $status = 'published';
                                break;
                        }
                        $insertQuestionnaireStmt->execute([$title, $description, $status]);
                        $qid = (int)$pdo->lastInsertId();
                        $recentImportId = $qid;
                        $importedQuestionnaires++;

                        foreach ($availableWorkFunctions as $wf) {
                            $insertWorkFunctionStmt->execute([$qid, $wf]);
                        }

                        $insertSectionStmt = $pdo->prepare('INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index) VALUES (?, ?, ?, ?)');
                        $insertItemStmt = $pdo->prepare('INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple, is_required) VALUES (?,?,?,?,?,?,?,?,?)');
                        $insertOptionStmt = $pdo->prepare('INSERT INTO questionnaire_item_option (questionnaire_item_id, value, is_correct, order_index) VALUES (?,?,?,?)');

                        $sectionOrder = 1;
                        $itemOrder = 1;

                        $toList = static function ($value) {
                            if (!is_array($value)) {
                                return [];
                            }
                            $expected = 0;
                            foreach (array_keys($value) as $key) {
                                if ((string)$key !== (string)$expected) {
                                    return [$value];
                                }
                                $expected++;
                            }
                            return $value;
                        };

                        $mapType = static function ($type) {
                            $type = strtolower((string)$type);
                            switch ($type) {
                                case 'boolean':
                                    return 'boolean';
                                case 'likert':
                                case 'scale':
                                    return 'likert';
                                case 'choice':
                                    return 'choice';
                                case 'text':
                                    return 'text';
                                case 'textarea':
                                    return 'textarea';
                                default:
                                    return 'text';
                            }
                        };

                        $isTruthy = static function ($value): bool {
                            if (is_array($value)) {
                                if (isset($value['@attributes']['value'])) {
                                    $value = $value['@attributes']['value'];
                                } elseif (isset($value['value'])) {
                                    $value = $value['value'];
                                } else {
                                    $value = reset($value);
                                }
                            }
                            if (is_string($value)) {
                                $value = trim($value);
                            }
                            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        };

                        $processItems = function ($items, $sectionId = null) use (&$processItems, &$sectionOrder, &$itemOrder, $insertSectionStmt, $insertItemStmt, $insertOptionStmt, $qid, $toList, $mapType, $pdo, $isTruthy, &$importedSections, &$importedItems, &$importedOptions) {
                            $items = $toList($items);
                            foreach ($items as $it) {
                                if (!is_array($it)) {
                                    continue;
                                }
                                $children = $it['item'] ?? [];
                                $childList = $toList($children);
                                $type = strtolower($it['type'] ?? '');
                                $hasChildren = !empty($childList);

                                if ($hasChildren || $type === 'group') {
                                    $sectionTitle = qb_import_normalize_string($it['text'] ?? null, QB_IMPORT_MAX_SECTION_TITLE, $it['linkId'] ?? ('Section ' . $sectionOrder));
                                    if ($sectionTitle === '') {
                                        $sectionTitle = 'Section ' . $sectionOrder;
                                    }
                                    $sectionDescription = qb_import_normalize_nullable_string($it['description'] ?? null, QB_IMPORT_MAX_DESCRIPTION);
                                    $insertSectionStmt->execute([$qid, $sectionTitle, $sectionDescription, $sectionOrder]);
                                    $newSectionId = (int)$pdo->lastInsertId();
                                    $sectionOrder++;
                                    $importedSections++;
                                    if ($hasChildren) {
                                        $processItems($childList, $newSectionId);
                                    }
                                    continue;
                                }

                                if ($type === 'display') {
                                    // Display items are headers or text blocks; skip them.
                                    continue;
                                }

                                $linkId = qb_import_normalize_string($it['linkId'] ?? null, QB_IMPORT_MAX_LINK_ID, 'i' . $itemOrder);
                                if ($linkId === '') {
                                    $linkId = 'i' . $itemOrder;
                                }
                                $text = qb_import_normalize_string($it['text'] ?? null, QB_IMPORT_MAX_ITEM_TEXT, $linkId);
                                if ($text === '') {
                                    $text = $linkId;
                                }
                                $allowMultiple = isset($it['repeats']) ? $isTruthy($it['repeats']) : false;
                                $dbType = $mapType($type);
                                $itemOrderIndex = $itemOrder;
                                $insertItemStmt->execute([
                                    $qid,
                                    $sectionId,
                                    $linkId,
                                    $text,
                                    $dbType,
                                    $itemOrderIndex,
                                    0,
                                    $dbType === 'choice' && $allowMultiple ? 1 : 0,
                                    $isTruthy($it['required'] ?? false) ? 1 : 0,
                                ]);
                                $itemId = (int)$pdo->lastInsertId();
                                $importedItems++;
                                if ($dbType === 'choice' || $dbType === 'likert') {
                                    $options = $toList($it['answerOption'] ?? []);
                                    $optionOrder = 1;
                                    foreach ($options as $option) {
                                        if (!is_array($option)) {
                                            continue;
                                        }
                                        $value = null;
                                        if (isset($option['valueString'])) {
                                            $value = $option['valueString'];
                                        } elseif (isset($option['valueCoding']['display'])) {
                                            $value = $option['valueCoding']['display'];
                                        } elseif (isset($option['valueCoding']['code'])) {
                                            $value = $option['valueCoding']['code'];
                                        }
                                        $normalizedValue = qb_import_normalize_string($value, QB_IMPORT_MAX_OPTION_VALUE);
                                        if ($normalizedValue === '') {
                                            continue;
                                        }
                                        $insertOptionStmt->execute([$itemId, $normalizedValue, 0, $optionOrder]);
                                        $optionOrder++;
                                        $importedOptions++;
                                    }
                                    if ($dbType === 'likert' && $optionOrder === 1) {
                                        foreach (LIKERT_DEFAULT_OPTIONS as $label) {
                                            $insertOptionStmt->execute([$itemId, qb_import_truncate($label, QB_IMPORT_MAX_OPTION_VALUE), 0, $optionOrder]);
                                            $optionOrder++;
                                            $importedOptions++;
                                        }
                                    }
                                }
                                $itemOrder++;
                            }
                        };

                        $processItems($resource['item'] ?? []);
                    }

                    if ($startedTransaction) {
                        $pdo->commit();
                    }
                    $summary = sprintf(
                        'FHIR import complete. Imported %d questionnaire(s), %d section(s), %d item(s), %d option(s).',
                        $importedQuestionnaires,
                        $importedSections,
                        $importedItems,
                        $importedOptions
                    );
                    if ($importedItems === 0) {
                        $summary .= ' No items were imported. Verify that your Questionnaire resources include item definitions.';
                        $importDetails[] = 'Warning: no items were imported.';
                    }
                    $msg = t($t, 'fhir_import_complete', $summary);
                    $importStatus = 'success';
                    $importDetails[] = 'Questionnaires imported: ' . $importedQuestionnaires;
                    $importDetails[] = 'Sections imported: ' . $importedSections;
                    $importDetails[] = 'Items imported: ' . $importedItems;
                    $importDetails[] = 'Options imported: ' . $importedOptions;
                } catch (Throwable $e) {
                    if ($startedTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('FHIR questionnaire import failed: ' . $e->getMessage());
                    $msg = t($t, 'fhir_import_failed', 'FHIR import failed. Please check your file and try again.');
                    $importDetails[] = 'Database error: ' . $e->getMessage();
                }
            } else {
                $resourceType = $data['resourceType'] ?? 'unknown';
                $detail = 'No questionnaires found in the import file.';
                if ($resourceType === 'Bundle') {
                    $uniqueTypes = array_values(array_unique($bundleResourceTypes));
                    if ($uniqueTypes) {
                        $detail .= ' Bundle contained: ' . implode(', ', $uniqueTypes) . '.';
                    } else {
                        $detail .= ' Bundle entries were empty or missing resource types.';
                    }
                    $detail .= ' Ensure the Bundle includes Questionnaire resources.';
                } elseif ($resourceType !== 'unknown') {
                    $detail .= ' Detected resourceType "' . $resourceType . '". Expected Questionnaire or Bundle.';
                } else {
                    $detail .= ' Expected a FHIR Questionnaire or Bundle payload.';
                }
                $msg = t($t, 'no_questionnaires_found', $detail);
                $importDetails[] = $detail;
            }
        } else {
            $detail = 'Invalid file. Upload a FHIR Questionnaire XML or JSON file.';
            if ($parseErrors) {
                $detail .= ' XML parse errors: ' . implode(' | ', $parseErrors) . '.';
            }
            $msg = t($t, 'invalid_file', $detail);
            $importDetails[] = $detail;
        }
    } else {
        $msg = t($t, 'no_file_uploaded', 'No file uploaded');
        $importDetails[] = 'No file uploaded.';
    }
    $_SESSION['questionnaire_import_flash'] = $msg;
    $_SESSION['questionnaire_import_popup'] = [
        'title' => $importTitle,
        'status' => $importStatus,
        'message' => $msg,
        'details' => $importDetails,
    ];
    if ($recentImportId) {
        $_SESSION['questionnaire_import_focus'] = $recentImportId;
    }
    session_write_close();
    header('Location: ' . url_for('admin/questionnaire_manage.php'));
    exit;
}

$bootstrapQuestionnaires = qb_fetch_questionnaires($pdo);
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
<meta charset="utf-8">
<title><?=htmlspecialchars(t($t,'manage_questionnaires','Manage Questionnaires'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<meta name="csrf-token" content="<?=htmlspecialchars(csrf_token(), ENT_QUOTES)?>">
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/questionnaire-builder.css')?>">
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">window.QB_STRINGS = <?=json_encode($qbStrings, JSON_THROW_ON_ERROR)?>;</script>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">window.QB_BOOTSTRAP = <?=json_encode($bootstrapQuestionnaires, JSON_THROW_ON_ERROR)?>;</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" defer></script>
<script type="module" src="<?=asset_url('assets/js/questionnaire-builder.js')?>" defer></script>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>" style="<?=htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <header class="md-page-header qb-page-header">
    <div class="md-page-header__content">
      <h1 class="md-page-title" id="qb-page-title" tabindex="-1"><?=t($t,'manage_questionnaires','Manage Questionnaires')?></h1>
      <p class="md-page-subtitle"><?=t($t,'qb_builder_intro','Build and organize questionnaires for upcoming assessments.')?></p>
      <p class="md-hint">
        <a href="<?=htmlspecialchars(url_for('admin/questionnaire_builder_v2.php'), ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(t($t, 'qb_builder_v2_link', 'Try the new builder preview'), ENT_QUOTES, 'UTF-8')?></a>
      </p>
    </div>
  </header>
  <?php if ($msg): ?>
    <div class="md-alert"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div>
  <?php endif; ?>
  <?php if ($importPopup): ?>
    <div class="md-upgrade-popup md-import-popup" role="alertdialog" aria-live="assertive" aria-label="<?=htmlspecialchars($importPopup['title'] ?? t($t, 'import_log_title', 'Import log'), ENT_QUOTES, 'UTF-8')?>">
      <div class="md-upgrade-popup__backdrop"></div>
      <div class="md-upgrade-popup__dialog">
        <div class="md-upgrade-popup__header">
          <span class="md-upgrade-popup__title"><?=htmlspecialchars($importPopup['title'] ?? t($t, 'import_log_title', 'Import log'), ENT_QUOTES, 'UTF-8')?></span>
          <button type="button" class="md-upgrade-popup__close" data-import-popup-close aria-label="<?=htmlspecialchars(t($t, 'close', 'Close'), ENT_QUOTES, 'UTF-8')?>">×</button>
        </div>
        <p class="md-upgrade-popup__message"><?=htmlspecialchars((string)($importPopup['message'] ?? ''), ENT_QUOTES, 'UTF-8')?></p>
        <?php if (!empty($importPopup['details']) && is_array($importPopup['details'])): ?>
          <ul class="md-import-popup__list">
            <?php foreach ($importPopup['details'] as $detail): ?>
              <li><?=htmlspecialchars((string)$detail, ENT_QUOTES, 'UTF-8')?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
      document.addEventListener('click', (event) => {
        if (event.target.closest('[data-import-popup-close]') || event.target.classList.contains('md-upgrade-popup__backdrop')) {
          const popup = event.target.closest('.md-import-popup');
          if (popup) {
            popup.remove();
          }
        }
      });
    </script>
  <?php endif; ?>
  <div class="qb-start-grid">
    <div class="md-card md-elev-2 qb-start-card">
      <div class="qb-start-card-header">
        <p class="md-overline"><?=t($t,'qb_start_create_label','Start new')?></p>
        <h2 class="md-card-title"><?=t($t,'qb_start_create_title','Create a questionnaire')?></h2>
        <p class="md-hint"><?=t($t,'qb_start_create_hint','Begin from scratch with a fresh questionnaire layout.')?></p>
      </div>
      <div class="qb-start-actions">
        <button class="md-button md-primary md-elev-2" id="qb-add-questionnaire"><?=t($t,'add_questionnaire','Add Questionnaire')?></button>
      </div>
    </div>
    <div class="md-card md-elev-2 qb-start-card">
      <div class="qb-start-card-header">
        <p class="md-overline"><?=t($t,'qb_start_edit_label','Edit')?></p>
        <h2 class="md-card-title"><?=t($t,'qb_start_edit_title','Open an existing questionnaire')?></h2>
        <p class="md-hint"><?=t($t,'qb_start_edit_hint','Choose a questionnaire to load it into the builder for updates.')?></p>
      </div>
      <label class="qb-select-label" for="qb-selector"><?=t($t,'choose_questionnaire','Questionnaire')?></label>
      <div class="qb-select-wrap">
        <select id="qb-selector" class="qb-select qb-select-input"></select>
      </div>
      <div class="qb-start-actions">
        <button class="md-button md-elev-2" id="qb-open-selected"><?=t($t,'edit_selected','Edit selected')?></button>
        <button class="md-button md-outline md-elev-1" id="qb-export-questionnaire"><?=t($t,'export_fhir','Export questionnaire')?></button>
      </div>
    </div>
    <div class="md-card md-elev-2 qb-start-card qb-import-start">
      <div class="qb-start-card-header">
        <p class="md-overline"><?=t($t,'qb_start_import_label','Import')?></p>
        <h2 class="md-card-title"><?=t($t,'qb_start_import_title','Import or align a questionnaire')?></h2>
        <p class="md-hint"><?=t($t,'qb_start_import_hint','Upload a questionnaire XML file or download our template to mirror other survey tools.')?></p>
      </div>
      <form method="post" enctype="multipart/form-data" class="qb-import-form" action="<?=htmlspecialchars(url_for('admin/questionnaire_manage.php'), ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <div class="qb-import-inline">
          <label class="md-field md-field--compact"><span><?=t($t,'file','File')?></span><input type="file" name="file" required></label>
          <button class="md-button md-elev-2" name="import"><?=t($t,'import','Import')?></button>
        </div>
        <div class="qb-start-actions">
          <a class="md-button md-outline md-elev-1" href="<?=htmlspecialchars(url_for('scripts/download_questionnaire_template.php'), ENT_QUOTES, 'UTF-8')?>" download>
            <?=t($t,'download_xml_template','Download XML template')?>
          </a>
          <a class="md-button md-outline md-elev-1" href="<?=htmlspecialchars(asset_url('docs/questionnaire-import-guide.md'), ENT_QUOTES, 'UTF-8')?>" download>
            <?=t($t,'download_import_guide','Download Import Guide')?>
          </a>
        </div>
      </form>
    </div>
  </div>
  <div class="qb-manager-layout">
    <aside class="qb-manager-sidebar" aria-label="<?=htmlspecialchars(t($t,'questionnaire_side_menu','Questionnaire side menu'), ENT_QUOTES, 'UTF-8')?>">
      <div class="md-card md-elev-2 qb-sidebar-card">
        <h3 class="md-card-title"><?=t($t,'questionnaire_navigation','Questionnaire Navigation')?></h3>
        <p class="qb-section-nav-help"><?=t($t,'qb_navigation_hint','Use the section list to jump around large questionnaires without losing your place.')?></p>
        <nav id="qb-section-nav" class="qb-section-nav" aria-label="<?=htmlspecialchars(t($t,'section_navigation','Section navigation'), ENT_QUOTES, 'UTF-8')?>" data-empty-label="<?=htmlspecialchars(t($t,'select_questionnaire_to_view_sections','Select a questionnaire to view its sections'), ENT_QUOTES, 'UTF-8')?>" data-root-label="<?=htmlspecialchars(t($t,'items_without_section','Items without a section'), ENT_QUOTES, 'UTF-8')?>" data-untitled-label="<?=htmlspecialchars(t($t,'untitled_questionnaire','Untitled questionnaire'), ENT_QUOTES, 'UTF-8')?>">
          <p class="qb-section-nav-empty"><?=t($t,'select_questionnaire_to_view_sections','Select a questionnaire to view its sections')?></p>
        </nav>
      </div>
      <div class="md-card md-elev-2 qb-sidebar-card qb-scoring-card">
        <div class="qb-scoring-note">
          <strong><?=t($t, 'qb_scoring_hint_title', 'Scoring & analytics')?></strong>
          <p><?=t(
              $t,
              'qb_scoring_hint_text',
              'Assign weights so priority questions total about 100%. Items left at 0 are informational only and do not affect scores or analytics.'
          )?></p>
        </div>
      </div>
    </aside>
    <div class="qb-manager-main">
      <button type="button" class="md-button md-secondary md-elev-2 qb-scroll-top" id="qb-scroll-top" aria-label="<?=t($t,'qb_scroll_to_top','Back to top')?>" aria-hidden="true" tabindex="-1">
        <span class="qb-scroll-top-icon" aria-hidden="true">⇧</span>
        <span class="qb-scroll-top-label"><?=t($t,'qb_scroll_to_top','Back to top')?></span>
      </button>
      <div class="md-card md-elev-2 qb-builder-card">
        <div class="qb-toolbar">
          <div class="qb-toolbar-actions">
            <button class="md-button md-outline md-elev-1" id="qb-export-questionnaire"><?=t($t,'export_fhir','Export questionnaire')?></button>
            <button class="md-button md-secondary md-elev-2" id="qb-publish" disabled><?=t($t,'publish','Publish')?></button>
          </div>
        </div>
        <div id="qb-message" class="qb-message" role="status" aria-live="polite"></div>
        <div id="qb-list" class="qb-list" aria-live="polite"></div>
      </div>
    </div>
  </div>
  <button type="button" class="md-button md-outline md-floating-save-draft qb-floating-save" id="qb-save-floating" disabled>
    <?=t($t,'save','Save Changes')?>
  </button>
</section>
<?php if ($recentImportId): ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">window.QB_INITIAL_ACTIVE_ID = <?=json_encode($recentImportId, JSON_THROW_ON_ERROR)?>;</script>
<?php endif; ?>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
