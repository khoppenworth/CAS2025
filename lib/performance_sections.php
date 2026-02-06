<?php

declare(strict_types=1);

require_once __DIR__ . '/scoring.php';

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

        try {
            $itemsStmt = $pdo->prepare(
                "SELECT id, questionnaire_id, section_id, linkId, type, allow_multiple, requires_correct, " .
                "COALESCE(weight_percent,0) AS weight_percent FROM questionnaire_item " .
                "WHERE questionnaire_id IN ($placeholder) ORDER BY questionnaire_id, order_index, id"
            );
            $itemsStmt->execute($qidList);
        } catch (PDOException $e) {
            $itemsStmt = $pdo->prepare(
                "SELECT id, questionnaire_id, section_id, linkId, type, allow_multiple, " .
                "COALESCE(weight_percent,0) AS weight_percent FROM questionnaire_item " .
                "WHERE questionnaire_id IN ($placeholder) ORDER BY questionnaire_id, order_index, id"
            );
            $itemsStmt->execute($qidList);
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

    $scoreCalculator = static function (array $item, array $answerSet, float $weight, array $correctByItem) use (&$translations): float {
        $type = (string)($item['type'] ?? 'text');
        if ($weight <= 0) {
            return 0.0;
        }
        if ($type === 'boolean') {
            foreach ($answerSet as $entry) {
                if ((isset($entry['valueBoolean']) && $entry['valueBoolean']) ||
                    (isset($entry['valueString']) && strtolower((string)$entry['valueString']) === 'true')) {
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
                $requiresCorrect = !empty($item['requires_correct']);
                if (!$requiresCorrect) {
                    foreach ($answerSet as $entry) {
                        if (isset($entry['valueString']) && trim((string)$entry['valueString']) !== '') {
                            return $weight;
                        }
                    }
                    return 0.0;
                }
                $correct = $correctByItem[(int)($item['id'] ?? 0)] ?? null;
                if ($correct === null || $correct === '') {
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
    };

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
                'weight' => 0.0,
                'achieved' => 0.0,
            ];
            $orderedSections[] = $sid;
        }
        $unassignedKey = 'unassigned';
        $sectionStats[$unassignedKey] = [
            'label' => $generalLabel,
            'weight' => 0.0,
            'achieved' => 0.0,
        ];

        $answers = $answersByResponse[$responseId] ?? [];
        foreach ($items as $item) {
            $sectionKey = $item['section_id'] ?? $unassignedKey;
            if (!isset($sectionStats[$sectionKey])) {
                $sectionStats[$sectionKey] = [
                    'label' => $sectionFallback,
                    'weight' => 0.0,
                    'achieved' => 0.0,
                ];
                if ($sectionKey !== $unassignedKey) {
                    $orderedSections[] = $sectionKey;
                }
            }
            $weight = (float)$item['weight'];
            if ($weight <= 0) {
                continue;
            }
            $sectionStats[$sectionKey]['weight'] += $weight;
            $answerSet = $answers[$item['linkId']] ?? [];
            $sectionStats[$sectionKey]['achieved'] += $scoreCalculator($item, $answerSet, $weight, $correctByItem);
        }

        $sections = [];
        foreach ($orderedSections as $sid) {
            $stat = $sectionStats[$sid] ?? null;
            if (!$stat || $stat['weight'] <= 0) {
                continue;
            }
            $label = trim((string)$stat['label']);
            if ($label === '') {
                $label = $sectionFallback;
            }
            $sections[] = [
                'label' => $label,
                'score' => round(($stat['achieved'] / $stat['weight']) * 100, 1),
            ];
        }

        if ($sectionStats[$unassignedKey]['weight'] > 0) {
            $sections[] = [
                'label' => $sectionStats[$unassignedKey]['label'],
                'score' => round(($sectionStats[$unassignedKey]['achieved'] / $sectionStats[$unassignedKey]['weight']) * 100, 1),
            ];
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
