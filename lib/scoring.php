<?php

require_once __DIR__ . '/competency_framework.php';
/**
 * Helper functions for questionnaire scoring calculations.
 */

/**
 * Build a stable key for identifying questionnaire items when assigning weights.
 */
function questionnaire_item_weight_key(array $item): string
{
    $linkId = '';
    foreach (['linkId', 'linkid'] as $key) {
        if (array_key_exists($key, $item)) {
            $candidate = trim((string)$item[$key]);
            if ($candidate !== '') {
                $linkId = $candidate;
                break;
            }
        }
    }
    if ($linkId !== '') {
        return $linkId;
    }
    if (isset($item['id'])) {
        $id = (int)$item['id'];
        if ($id > 0) {
            return '__id:' . $id;
        }
    }
    if (isset($item['questionnaire_item_id'])) {
        $id = (int)$item['questionnaire_item_id'];
        if ($id > 0) {
            return '__qid:' . $id;
        }
    }
    return '__hash:' . sha1(json_encode($item));
}

/**
 * Determine even weights for all single-choice items in a questionnaire.
 *
 * @param array<int, array<string, mixed>> $items
 * @param float $totalWeight Weight budget to distribute. Defaults to 100.
 *
 * @return array<string, float> Mapping of item key to assigned weight.
 */
function questionnaire_even_single_choice_weights(array $items, float $totalWeight = 100.0): array
{
    $keys = [];
    foreach ($items as $item) {
        $type = strtolower((string)($item['type'] ?? ''));
        $allowMultiple = !empty($item['allow_multiple']);
        if ($type !== 'choice' || $allowMultiple) {
            continue;
        }
        $key = questionnaire_item_weight_key($item);
        if ($key === '') {
            continue;
        }
        $keys[$key] = true;
    }
    if ($keys === []) {
        return [];
    }
    $count = count($keys);
    if ($count <= 0) {
        return [];
    }
    $evenWeight = $totalWeight / $count;
    $weights = [];
    foreach (array_keys($keys) as $key) {
        $weights[$key] = $evenWeight;
    }
    return $weights;
}

/**
 * Resolve the effective weight for a questionnaire item.
 *
 * @param array<string, mixed> $item Item metadata including optional weight fields.
 * @param array<string, float> $singleChoiceWeights Pre-computed even weights for single-choice items.
 * @param array<string, float> $likertWeights Pre-computed even weights for Likert items.
 * @param bool $isScorable Whether the item contributes to scoring.
 */
function questionnaire_resolve_effective_weight(array $item, array $singleChoiceWeights, array $likertWeights, bool $isScorable): float
{
    if (!$isScorable) {
        return 0.0;
    }
    $type = strtolower((string)($item['type'] ?? ''));
    $allowMultiple = !empty($item['allow_multiple']);

    // Respect explicit weights first, even when auto weights for Likert items are present.
    foreach (['weight_percent', 'weight'] as $field) {
        if (!array_key_exists($field, $item)) {
            continue;
        }
        $raw = $item[$field];
        if ($raw === null || $raw === '') {
            continue;
        }
        $candidate = (float)$raw;
        if ($candidate > 0.0) {
            return $candidate;
        }
    }

    $key = questionnaire_item_weight_key($item);
    if ($type === 'choice' && !$allowMultiple && $key !== '' && isset($singleChoiceWeights[$key])) {
        return (float)$singleChoiceWeights[$key];
    }

    if ($singleChoiceWeights === [] && $type === 'likert' && $key !== '' && isset($likertWeights[$key])) {
        return (float)$likertWeights[$key];
    }

    // When auto weights exist, non-primary items without an explicit weight
    // should not silently contribute to scoring.
    if ($singleChoiceWeights !== [] && !($type === 'choice' && !$allowMultiple)) {
        return 0.0;
    }

    if ($singleChoiceWeights === [] && $likertWeights !== [] && $type !== 'likert') {
        return 0.0;
    }

    return 1.0;
}

/**
 * Determine even weights for all Likert items in a questionnaire.
 *
 * @param array<int, array<string, mixed>> $items
 * @param float $totalWeight Weight budget to distribute. Defaults to 100.
 *
 * @return array<string, float> Mapping of item key to assigned weight.
 */
function questionnaire_even_likert_weights(array $items, float $totalWeight = 100.0): array
{
    $keys = [];
    foreach ($items as $item) {
        $type = strtolower((string)($item['type'] ?? ''));
        if ($type !== 'likert') {
            continue;
        }
        $key = questionnaire_item_weight_key($item);
        if ($key === '') {
            continue;
        }
        $keys[$key] = true;
    }
    if ($keys === []) {
        return [];
    }
    $count = count($keys);
    if ($count <= 0) {
        return [];
    }
    $evenWeight = $totalWeight / $count;
    $weights = [];
    foreach (array_keys($keys) as $key) {
        $weights[$key] = $evenWeight;
    }
    return $weights;
}

/**
 * Determine if an item can be scored using the correct-answer method.
 *
 * @param array<string, mixed> $item
 */
function questionnaire_item_uses_correct_answer(array $item): bool
{
    $type = strtolower((string)($item['type'] ?? ''));
    if ($type !== 'choice') {
        return false;
    }
    if (!empty($item['allow_multiple'])) {
        return false;
    }
    return !empty($item['requires_correct']);
}

/**
 * Decide whether a section should be included in scoring.
 *
 * @param array<string, mixed> $section
 */
function questionnaire_section_included_in_scoring(array $section): bool
{
    if (array_key_exists('include_in_scoring', $section)) {
        return !empty($section['include_in_scoring']);
    }
    return true;
}

/**
 * Evaluate whether a question was answered correctly.
 *
 * @param array<int, mixed> $answerSet
 */
function questionnaire_answer_is_correct(array $answerSet, string $correctValue): bool
{
    if ($correctValue === '') {
        return false;
    }
    foreach ($answerSet as $entry) {
        if (is_array($entry) && isset($entry['valueString']) && (string)$entry['valueString'] === $correctValue) {
            return true;
        }
    }
    return false;
}


/**
 * Normalize a questionnaire condition linkId or HTML field name.
 */
function questionnaire_normalize_condition_link_id(string $value): string
{
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }
    if (str_starts_with(strtolower($normalized), 'item_')) {
        $normalized = substr($normalized, 5);
    }
    if (str_ends_with($normalized, '[]')) {
        $normalized = substr($normalized, 0, -2);
    }
    return strtolower(trim($normalized));
}

/**
 * Extract condition-comparable values from stored QuestionnaireResponse answers.
 *
 * @param array<string, array<int, mixed>> $answersByLinkId
 * @return array<string, array<int, string>>
 */
function questionnaire_collect_condition_values_from_answers(array $answersByLinkId): array
{
    $valuesByLinkId = [];
    foreach ($answersByLinkId as $linkId => $answerEntries) {
        if (!is_string($linkId)) {
            continue;
        }
        $normalizedLinkId = questionnaire_normalize_condition_link_id($linkId);
        if ($normalizedLinkId === '' || !is_array($answerEntries)) {
            continue;
        }
        $values = [];
        foreach ($answerEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (['valueString', 'valueInteger', 'valueBoolean'] as $key) {
                if (!array_key_exists($key, $entry)) {
                    continue;
                }
                $raw = $entry[$key];
                if ($key === 'valueBoolean') {
                    $text = !empty($raw) ? 'true' : 'false';
                } elseif (is_scalar($raw) || $raw === null) {
                    $text = trim((string)$raw);
                } else {
                    $text = '';
                }
                if ($text !== '') {
                    $values[] = $text;
                }
            }
            if (isset($entry['valueCoding']) && is_array($entry['valueCoding'])) {
                foreach (['code', 'display'] as $codingKey) {
                    if (isset($entry['valueCoding'][$codingKey]) && is_scalar($entry['valueCoding'][$codingKey])) {
                        $text = trim((string)$entry['valueCoding'][$codingKey]);
                        if ($text !== '') {
                            $values[] = $text;
                        }
                    }
                }
            }
        }
        if ($values) {
            $valuesByLinkId[$normalizedLinkId] = $values;
        }
    }
    return $valuesByLinkId;
}

/**
 * Determine whether a questionnaire item is visible for a submitted answer set.
 *
 * @param array<string, mixed> $item
 * @param array<string, array<int, string>> $valuesByLinkId
 */
function questionnaire_item_matches_condition(array $item, array $valuesByLinkId): bool
{
    $source = questionnaire_normalize_condition_link_id((string)($item['condition_source_linkid'] ?? ''));
    if ($source === '') {
        return true;
    }

    $operator = strtolower(trim((string)($item['condition_operator'] ?? 'equals')));
    if ($operator === '') {
        $operator = 'equals';
    }

    $expected = trim((string)($item['condition_value'] ?? ''));
    if ($expected === '') {
        return true;
    }
    $expectedLower = function_exists('mb_strtolower') ? mb_strtolower($expected, 'UTF-8') : strtolower($expected);

    if (!array_key_exists($source, $valuesByLinkId)) {
        return false;
    }

    $candidateValues = [];
    foreach (($valuesByLinkId[$source] ?? []) as $value) {
        $candidateValues[] = trim((string)$value);
    }

    if ($operator === 'contains') {
        if ($expectedLower === '') {
            return false;
        }
        foreach ($candidateValues as $candidate) {
            $candidateLower = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
            if ($candidateLower !== '' && str_contains($candidateLower, $expectedLower)) {
                return true;
            }
        }
        return false;
    }

    $normalizedCandidates = [];
    foreach ($candidateValues as $candidate) {
        $normalizedCandidates[] = function_exists('mb_strtolower')
            ? mb_strtolower((string)$candidate, 'UTF-8')
            : strtolower((string)$candidate);
    }
    $equals = in_array($expectedLower, $normalizedCandidates, true);
    if ($operator === 'not_equals') {
        return !$equals;
    }
    return $equals;
}


/**
 * Extract string-like answer values from a stored QuestionnaireResponse answer set.
 *
 * @param array<int, mixed> $answerSet
 * @return array<int, string>
 */
function questionnaire_answer_string_values(array $answerSet): array
{
    $values = [];
    foreach ($answerSet as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (array_key_exists('valueString', $entry) && is_scalar($entry['valueString'])) {
            $text = trim((string)$entry['valueString']);
            if ($text !== '') {
                $values[] = $text;
            }
        }
        if (isset($entry['valueCoding']) && is_array($entry['valueCoding'])) {
            foreach (['code', 'display'] as $codingKey) {
                if (isset($entry['valueCoding'][$codingKey]) && is_scalar($entry['valueCoding'][$codingKey])) {
                    $text = trim((string)$entry['valueCoding'][$codingKey]);
                    if ($text !== '') {
                        $values[] = $text;
                    }
                }
            }
        }
    }
    return array_values(array_unique($values));
}

/**
 * Extract the first numeric answer value from a stored answer set.
 *
 * @param array<int, mixed> $answerSet
 */
function questionnaire_answer_integer_value(array $answerSet): ?int
{
    foreach ($answerSet as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (isset($entry['valueInteger']) && is_numeric($entry['valueInteger'])) {
            return (int)$entry['valueInteger'];
        }
        if (isset($entry['valueString']) && is_scalar($entry['valueString'])) {
            $text = trim((string)$entry['valueString']);
            if (preg_match('/^([1-5])/', $text, $matches)) {
                return (int)$matches[1];
            }
            if (is_numeric($text)) {
                $candidate = (int)$text;
                if ($candidate >= 1 && $candidate <= 5) {
                    return $candidate;
                }
            }
        }
    }
    return null;
}

/**
 * Calculate achieved and possible weighted points for one questionnaire item.
 *
 * @param array<string, mixed> $item
 * @param array<int, mixed> $answerSet
 * @param array<int, string> $validOptions
 * @return array{possible:float, achieved:float, answered:bool}
 */
function questionnaire_score_answer(array $item, array $answerSet, array $validOptions = [], string $correctValue = ''): array
{
    $weight = isset($item['weight']) && is_numeric($item['weight'])
        ? (float)$item['weight']
        : (isset($item['computed_weight']) && is_numeric($item['computed_weight']) ? (float)$item['computed_weight'] : 0.0);
    if ($weight <= 0.0 || !questionnaire_section_included_in_scoring($item)) {
        return ['possible' => 0.0, 'achieved' => 0.0, 'answered' => false];
    }

    $type = strtolower((string)($item['type'] ?? ''));
    $allowMultiple = !empty($item['allow_multiple']);
    $strings = questionnaire_answer_string_values($answerSet);
    $answered = $strings !== [];
    $achieved = 0.0;

    if ($type === 'boolean') {
        $answered = false;
        foreach ($answerSet as $entry) {
            if (!is_array($entry) || !array_key_exists('valueBoolean', $entry)) {
                continue;
            }
            $answered = true;
            if (!empty($entry['valueBoolean'])) {
                $achieved = $weight;
                break;
            }
        }
    } elseif ($type === 'likert') {
        $scoreValue = questionnaire_answer_integer_value($answerSet);
        if ($scoreValue !== null) {
            $answered = true;
            $scaleMax = 5;
            if ($validOptions !== []) {
                $scaleMax = max(1, count($validOptions));
            }
            $achieved = $weight * (max(0, min($scoreValue, $scaleMax)) / $scaleMax);
        } elseif ($strings !== [] && $validOptions !== []) {
            $selected = $strings[0];
            $optionIndex = array_search($selected, $validOptions, true);
            if ($optionIndex !== false) {
                $answered = true;
                $scaleMax = max(1, count($validOptions));
                $achieved = $weight * (($optionIndex + 1) / $scaleMax);
            }
        }
    } elseif ($type === 'choice') {
        $answered = $strings !== [];
        if ($answered && $allowMultiple) {
            $achieved = $weight;
        } elseif ($answered) {
            $selected = (string)($strings[0] ?? '');
            if (questionnaire_item_uses_correct_answer($item)) {
                if ($correctValue !== '' && $selected === $correctValue) {
                    $achieved = $weight;
                }
            } else {
                $achieved = $weight;
            }
        }
    } else {
        $answered = $strings !== [];
        if ($answered) {
            $achieved = $weight;
        }
    }

    return [
        'possible' => $weight,
        'achieved' => max(0.0, min($weight, $achieved)),
        'answered' => $answered,
    ];
}

/**
 * Calculate an overall weighted percentage for a response.
 *
 * @param array<int, array<string, mixed>> $items
 * @param array<string, array<int, mixed>> $answersByLinkId
 * @param array<int, array{values?:array<int,string>,correct?:string}> $optionMap
 */
function questionnaire_calculate_response_score(array $items, array $answersByLinkId, array $optionMap = []): ?float
{
    $conditionValues = questionnaire_collect_condition_values_from_answers($answersByLinkId);
    $possible = 0.0;
    $achieved = 0.0;
    foreach ($items as $item) {
        if (!questionnaire_item_matches_condition($item, $conditionValues)) {
            continue;
        }
        $itemId = (int)($item['id'] ?? 0);
        $answerSet = $answersByLinkId[(string)($item['linkId'] ?? '')] ?? [];
        $score = questionnaire_score_answer(
            $item,
            is_array($answerSet) ? $answerSet : [],
            $optionMap[$itemId]['values'] ?? [],
            (string)($optionMap[$itemId]['correct'] ?? '')
        );
        $possible += $score['possible'];
        $achieved += $score['achieved'];
    }
    if ($possible <= 0.0) {
        return null;
    }
    return max(0.0, min(100.0, ($achieved / $possible) * 100.0));
}

/**
 * Resolve a competency level label from a score percentage.
 */
function questionnaire_competency_level(?float $score): string
{
    return competency_level_from_bands($score, competency_default_level_bands());
}

/**
 * Resolve competency level and interpretation details for a score percentage.
 *
 * @return array{level:string, interpretation:string}
 */
function questionnaire_competency_details(?float $score): array
{
    $level = questionnaire_competency_level($score);
    return match ($level) {
        'Strategic' => [
            'level' => 'Strategic',
            'interpretation' => 'Consistently demonstrates strategic capability and can guide others.',
        ],
        'Advanced' => [
            'level' => 'Advanced',
            'interpretation' => 'Performs independently and consistently exceeds most role expectations.',
        ],
        'Essential' => [
            'level' => 'Essential',
            'interpretation' => 'Shows reliable capability with periodic coaching in complex tasks.',
        ],
        'Introductory' => [
            'level' => 'Introductory',
            'interpretation' => 'Has foundational capability and benefits from targeted support.',
        ],
        'Below Basics' => [
            'level' => 'Below Basics',
            'interpretation' => 'Requires substantial development support and structured learning.',
        ],
        default => [
            'level' => '',
            'interpretation' => '',
        ],
    };
}

function questionnaire_competency_gap(?float $score, ?float $benchmark = null): ?float
{
    return competency_gap($score, $benchmark);
}

function questionnaire_competency_recommendation(?float $score): string
{
    return competency_recommendation($score);
}
