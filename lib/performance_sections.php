<?php

declare(strict_types=1);

require_once __DIR__ . '/scoring.php';

function performance_sections_supports_include_in_scoring(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM questionnaire_section');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (strcasecmp((string)($row['Field'] ?? ''), 'include_in_scoring') === 0) {
                $cached = true;
                return true;
            }
        }
    } catch (Throwable $e) {
        error_log('performance_sections include_in_scoring lookup failed: ' . $e->getMessage());
    }

    $cached = false;
    return false;
}

function compute_section_breakdowns(PDO $pdo, array $responses, array $translations): array
{
    if (!$responses) {
        return [];
    }

    $questionnaireIds = [];
    $responseMeta = [];
    foreach ($responses as $response) {
        if (!is_array($response)) {
            continue;
        }
        $responseId = isset($response['id']) ? (int)$response['id'] : 0;
        $questionnaireId = isset($response['questionnaire_id']) ? (int)$response['questionnaire_id'] : 0;
        if ($responseId <= 0 || $questionnaireId <= 0) {
            continue;
        }
        $questionnaireIds[$questionnaireId] = true;
        $responseMeta[$responseId] = [
            'questionnaire_id' => $questionnaireId,
            'title' => (string)($response['title'] ?? ''),
            'period' => $response['period_label'] ?? null,
            'score' => $response['score'] ?? null,
        ];
    }

    if (!$responseMeta) {
        return [];
    }

    $qidList = array_keys($questionnaireIds);
    $placeholder = implode(',', array_fill(0, count($qidList), '?'));

    $sectionsByQuestionnaire = [];
    if ($placeholder !== '') {
        $sectionsStmt = $pdo->prepare(
            "SELECT id, questionnaire_id, title, order_index FROM questionnaire_section " .
            "WHERE questionnaire_id IN ($placeholder) ORDER BY questionnaire_id, order_index, id"
        );
        $sectionsStmt->execute($qidList);
        foreach ($sectionsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = (int)$row['questionnaire_id'];
            $sectionsByQuestionnaire[$qid][] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => $row['title'] ?? '',
            ];
        }

        $supportsIncludeInScoring = performance_sections_supports_include_in_scoring($pdo);
        $includeSelect = $supportsIncludeInScoring
            ? 'COALESCE(qs.include_in_scoring,1) AS include_in_scoring'
            : '1 AS include_in_scoring';

        try {
            $itemsStmt = $pdo->prepare(
                "SELECT id, questionnaire_id, section_id, linkId, type, allow_multiple, requires_correct, " .
                "COALESCE(weight_percent,0) AS weight_percent, {$includeSelect} FROM questionnaire_item qi " .
                "LEFT JOIN questionnaire_section qs ON qs.id = qi.section_id WHERE qi.questionnaire_id IN ($placeholder) ORDER BY qi.questionnaire_id, qi.order_index, qi.id"
            );
            $itemsStmt->execute($qidList);
        } catch (PDOException $e) {
            $itemsStmt = $pdo->prepare(
                "SELECT qi.id, qi.questionnaire_id, qi.section_id, qi.linkId, qi.type, qi.allow_multiple, " .
                "COALESCE(qi.weight_percent,0) AS weight_percent, {$includeSelect} FROM questionnaire_item qi " .
                "LEFT JOIN questionnaire_section qs ON qs.id = qi.section_id WHERE qi.questionnaire_id IN ($placeholder) ORDER BY qi.questionnaire_id, qi.order_index, qi.id"
            );
            $itemsStmt->execute($qidList);
        } catch (PDOException $e) {
            try {
                $itemsStmt = $pdo->prepare(
                    "SELECT qi.id, qi.questionnaire_id, qi.section_id, qi.linkId, qi.type, qi.allow_multiple, " .
                    "COALESCE(qi.weight_percent,0) AS weight_percent, COALESCE(qs.include_in_scoring,1) AS include_in_scoring, " .
                    "0 AS requires_correct FROM questionnaire_item qi " .
                    "LEFT JOIN questionnaire_section qs ON qs.id = qi.section_id WHERE qi.questionnaire_id IN ($placeholder) ORDER BY qi.questionnaire_id, qi.order_index, qi.id"
                );
                $itemsStmt->execute($qidList);
            } catch (PDOException $inner) {
                $itemsStmt = $pdo->prepare(
                    "SELECT qi.id, qi.questionnaire_id, qi.section_id, qi.linkId, qi.type, qi.allow_multiple, " .
                    "COALESCE(qi.weight_percent,0) AS weight_percent, 1 AS include_in_scoring, 0 AS requires_correct FROM questionnaire_item qi " .
                    "WHERE qi.questionnaire_id IN ($placeholder) ORDER BY qi.questionnaire_id, qi.order_index, qi.id"
                );
                $itemsStmt->execute($qidList);
            }
        }
    } else {
        $itemsStmt = $pdo->prepare('SELECT 1 WHERE 0');
        $itemsStmt->execute();
    }

    $itemsByQuestionnaire = [];
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qid = (int)$row['questionnaire_id'];
        $sectionId = $row['section_id'] !== null ? (int)$row['section_id'] : null;
        $itemsByQuestionnaire[$qid][] = [
            'id' => (int)($row['id'] ?? 0),
            'section_id' => $sectionId,
            'linkId' => (string)$row['linkId'],
            'type' => (string)$row['type'],
            'allow_multiple' => (bool)$row['allow_multiple'],
            'requires_correct' => (bool)($row['requires_correct'] ?? false),
            'weight_percent' => (float)$row['weight_percent'],
            'include_in_scoring' => !empty($row['include_in_scoring']),
        ];
    }

    $nonScorableTypes = ['display', 'group', 'section'];
    foreach ($itemsByQuestionnaire as $qid => &$itemsForQuestionnaire) {
        $singleChoiceWeights = questionnaire_even_single_choice_weights($itemsForQuestionnaire);
        $likertWeights = questionnaire_even_likert_weights($itemsForQuestionnaire);
        foreach ($itemsForQuestionnaire as &$item) {
            $item['weight'] = questionnaire_resolve_effective_weight(
                $item,
                $singleChoiceWeights,
                $likertWeights,
                !in_array($item['type'], $nonScorableTypes, true)
            );
        }
        unset($item);
    }
    unset($itemsForQuestionnaire);

    $responseIds = array_keys($responseMeta);
    $answersByResponse = [];
    $correctByItem = [];
    if ($responseIds) {
        $answerPlaceholder = implode(',', array_fill(0, count($responseIds), '?'));
        $answerStmt = $pdo->prepare(
            "SELECT response_id, linkId, answer FROM questionnaire_response_item " .
            "WHERE response_id IN ($answerPlaceholder)"
        );
        $answerStmt->execute($responseIds);
        foreach ($answerStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int)$row['response_id'];
            $decoded = json_decode($row['answer'] ?? '[]', true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $answersByResponse[$rid][$row['linkId']] = $decoded;
        }
    }

    $itemIds = [];
    foreach ($itemsByQuestionnaire as $qid => $itemsForQuestionnaire) {
        foreach ($itemsForQuestionnaire as $item) {
            if (!empty($item['id'])) {
                $itemIds[] = (int)$item['id'];
            }
        }
    }
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

    $sectionBreakdowns = [];
    $generalLabel = t($translations, 'unassigned_section_label', 'General');
    $sectionFallback = t($translations, 'section_placeholder', 'Section');
    $questionnaireFallback = t($translations, 'questionnaire_placeholder', 'Questionnaire');

    foreach ($responseMeta as $responseId => $meta) {
        $qid = $meta['questionnaire_id'];
        $items = $itemsByQuestionnaire[$qid] ?? [];
        if (!$items) {
            continue;
        }
        $sectionStats = [];
        $orderedSections = [];
        foreach ($sectionsByQuestionnaire[$qid] ?? [] as $section) {
            $sid = $section['id'];
            $sectionStats[$sid] = [
                'label' => (string)$section['title'],
                'total' => 0,
                'correct' => 0,
            ];
            $orderedSections[] = $sid;
        }
        $unassignedKey = 'unassigned';
        $sectionStats[$unassignedKey] = [
            'label' => $generalLabel,
            'total' => 0,
            'correct' => 0,
        ];

        $answers = $answersByResponse[$responseId] ?? [];
        foreach ($items as $item) {
            $sectionKey = $item['section_id'] ?? $unassignedKey;
            if (!isset($sectionStats[$sectionKey])) {
                $sectionStats[$sectionKey] = [
                    'label' => $sectionFallback,
                    'total' => 0,
                    'correct' => 0,
                ];
                if ($sectionKey !== $unassignedKey) {
                    $orderedSections[] = $sectionKey;
                }
            }
            if (!questionnaire_section_included_in_scoring($item) || !questionnaire_item_uses_correct_answer($item)) {
                continue;
            }
            $correctValue = (string)($correctByItem[(int)($item['id'] ?? 0)] ?? '');
            if ($correctValue === '') {
                continue;
            }
            $sectionStats[$sectionKey]['total'] += 1;
            $answerSet = $answers[$item['linkId']] ?? [];
            if (questionnaire_answer_is_correct($answerSet, $correctValue)) {
                $sectionStats[$sectionKey]['correct'] += 1;
            }
        }

        $sections = [];
        foreach ($orderedSections as $sid) {
            $stat = $sectionStats[$sid] ?? null;
            if (!$stat || $stat['total'] <= 0) {
                continue;
            }
            $label = trim((string)$stat['label']);
            if ($label === '') {
                $label = $sectionFallback;
            }
            $sections[] = [
                'label' => $label,
                'score' => round(($stat['correct'] / $stat['total']) * 100, 1),
            ];
        }

        if ($sectionStats[$unassignedKey]['total'] > 0) {
            $sections[] = [
                'label' => $sectionStats[$unassignedKey]['label'],
                'score' => round(($sectionStats[$unassignedKey]['correct'] / $sectionStats[$unassignedKey]['total']) * 100, 1),
            ];
        }

        if (!$sections) {
            $overallScore = $meta['score'] ?? null;
            if ($overallScore !== null && is_numeric($overallScore)) {
                $fallbackScore = round((float)$overallScore, 1);
                foreach ($orderedSections as $sid) {
                    if ($sid === $unassignedKey || !isset($sectionStats[$sid])) {
                        continue;
                    }
                    $label = trim((string)($sectionStats[$sid]['label'] ?? ''));
                    if ($label === '') {
                        $label = $sectionFallback;
                    }
                    $sections[] = [
                        'label' => $label,
                        'score' => $fallbackScore,
                    ];
                }

                if (!$sections) {
                    $sections[] = [
                        'label' => $sectionStats[$unassignedKey]['label'],
                        'score' => $fallbackScore,
                    ];
                }
            }
        }

        if ($sections) {
            $title = trim((string)$meta['title']);
            if ($title === '') {
                $title = $questionnaireFallback;
            }
            $sectionBreakdowns[$qid] = [
                'title' => $title,
                'period' => $meta['period'] ? (string)$meta['period'] : null,
                'sections' => $sections,
            ];
        }
    }

    return $sectionBreakdowns;
}
