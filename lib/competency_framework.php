<?php

declare(strict_types=1);

/**
 * Default competency level bands used when no database override exists.
 *
 * @return array<int, array{name:string,min_pct:float,max_pct:float,rank_order:int}>
 */
function competency_default_level_bands(): array
{
    return [
        ['name' => 'Not Proficient', 'min_pct' => 0.0, 'max_pct' => 49.99, 'rank_order' => 1],
        ['name' => 'Basic Proficiency', 'min_pct' => 50.0, 'max_pct' => 64.99, 'rank_order' => 2],
        ['name' => 'Intermediate Proficiency', 'min_pct' => 65.0, 'max_pct' => 79.99, 'rank_order' => 3],
        ['name' => 'Advanced Proficiency', 'min_pct' => 80.0, 'max_pct' => 89.99, 'rank_order' => 4],
        ['name' => 'Expert', 'min_pct' => 90.0, 'max_pct' => 100.0, 'rank_order' => 5],
    ];
}

/**
 * Evaluate a competency level label for a percentage score.
 *
 * @param array<int, array{name:string,min_pct:float,max_pct:float}> $bands
 */
function competency_level_from_bands(?float $score, array $bands): string
{
    if ($score === null) {
        return '';
    }

    $normalizedScore = max(0.0, min(100.0, (float)$score));
    foreach ($bands as $band) {
        $min = isset($band['min_pct']) ? (float)$band['min_pct'] : 0.0;
        $max = isset($band['max_pct']) ? (float)$band['max_pct'] : 0.0;
        if ($normalizedScore >= $min && $normalizedScore <= $max) {
            return (string)($band['name'] ?? '');
        }
    }

    return '';
}

/**
 * Compute competency gap.
 *
 * When benchmark is null, uses 100 - score.
 * When benchmark is provided, uses benchmark - score.
 */
function competency_gap(?float $score, ?float $benchmark = null): ?float
{
    if ($score === null) {
        return null;
    }

    $normalizedScore = max(0.0, min(100.0, (float)$score));
    if ($benchmark === null) {
        return round(100.0 - $normalizedScore, 1);
    }

    $normalizedBenchmark = max(0.0, min(100.0, (float)$benchmark));
    return round($normalizedBenchmark - $normalizedScore, 1);
}

/**
 * Rule-based recommendation text from the reporting template.
 */
function competency_recommendation(?float $score): string
{
    if ($score === null) {
        return '';
    }
    if ($score < 60.0) {
        return 'Recommend training';
    }
    if ($score <= 75.0) {
        return 'Recommend coaching';
    }
    if ($score > 85.0) {
        return 'Consider mentorship role';
    }
    return 'Maintain current development plan';
}
