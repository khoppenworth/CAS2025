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
 * Resolve competency level bands from runtime configuration when available.
 *
 * Falls back to default level bands if no database connection or overrides exist.
 *
 * @return array<int, array{name:string,min_pct:float,max_pct:float,rank_order:int}>
 */
function competency_level_bands(?PDO $pdo = null, bool $forceRefresh = false): array
{
    static $cache = null;

    if ($cache === null && class_exists('WeakMap')) {
        $cache = new WeakMap();
    }

    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    if (!$pdo instanceof PDO) {
        return competency_default_level_bands();
    }

    if ($cache instanceof WeakMap && !$forceRefresh && isset($cache[$pdo])) {
        return $cache[$pdo];
    }

    try {
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $existsStmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = 'competency_level_band' LIMIT 1");
            $existsStmt->execute();
            if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
                $defaults = competency_default_level_bands();
                if ($cache instanceof WeakMap) {
                    $cache[$pdo] = $defaults;
                }
                return $defaults;
            }
        } else {
            $existsStmt = $pdo->prepare(
                'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $existsStmt->execute(['competency_level_band']);
            if ((int)$existsStmt->fetchColumn() <= 0) {
                $defaults = competency_default_level_bands();
                if ($cache instanceof WeakMap) {
                    $cache[$pdo] = $defaults;
                }
                return $defaults;
            }
        }

        $stmt = $pdo->query(
            'SELECT name, min_pct, max_pct, rank_order FROM competency_level_band ORDER BY rank_order ASC, id ASC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!is_array($rows) || $rows === []) {
            $defaults = competency_default_level_bands();
            if ($cache instanceof WeakMap) {
                $cache[$pdo] = $defaults;
            }
            return $defaults;
        }

        $bands = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $bands[] = [
                'name' => $name,
                'min_pct' => isset($row['min_pct']) ? (float)$row['min_pct'] : 0.0,
                'max_pct' => isset($row['max_pct']) ? (float)$row['max_pct'] : 0.0,
                'rank_order' => isset($row['rank_order']) ? (int)$row['rank_order'] : (count($bands) + 1),
            ];
        }

        if ($bands === []) {
            $defaults = competency_default_level_bands();
            if ($cache instanceof WeakMap) {
                $cache[$pdo] = $defaults;
            }
            return $defaults;
        }

        if ($cache instanceof WeakMap) {
            $cache[$pdo] = $bands;
        }
        return $bands;
    } catch (Throwable $e) {
        error_log('competency_level_bands failed: ' . $e->getMessage());
        return competency_default_level_bands();
    }
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
