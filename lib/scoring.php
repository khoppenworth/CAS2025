<?php
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
