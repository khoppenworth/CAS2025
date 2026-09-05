<?php

declare(strict_types=1);

require_once __DIR__ . '/performance_sections.php';
require_once __DIR__ . '/competency_framework.php';

function analytics_capacity_completed_statuses(): array
{
    return ['submitted', 'approved', 'approved_late'];
}

function analytics_capacity_valid_year($value): int
{
    $year = (int)$value;
    return ($year >= 2000 && $year <= 2100) ? $year : 0;
}

function analytics_capacity_normalize_filters(array $raw): array
{
    return [
        'year' => analytics_capacity_valid_year($raw['year'] ?? 0),
        'questionnaire_id' => max(0, (int)($raw['questionnaire_id'] ?? 0)),
        'questionnaire_family_key' => trim((string)($raw['questionnaire_family_key'] ?? '')),
        'department' => trim((string)($raw['department'] ?? '')),
        'team' => trim((string)($raw['team'] ?? '')),
        'work_function' => trim((string)($raw['work_function'] ?? '')),
    ];
}

/**
 * Resolve the selected questionnaire to its stable family key so annual trends survive questionnaire version changes.
 */
function analytics_capacity_resolve_questionnaire_family(PDO $pdo, array $filters): array
{
    $qid = (int)($filters['questionnaire_id'] ?? 0);
    if ($qid <= 0) {
        $filters['questionnaire_family_key'] = '';
        return $filters;
    }

    try {
        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(family_key,''), CONCAT('questionnaire-', id)) FROM questionnaire WHERE id = ?");
        $stmt->execute([$qid]);
        $filters['questionnaire_family_key'] = trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        error_log('analytics capacity questionnaire family lookup failed: ' . $e->getMessage());
        $filters['questionnaire_family_key'] = '';
    }

    return $filters;
}

function analytics_capacity_build_where(array $filters, bool $includeYear = true, bool $includeDepartment = true): array
{
    $where = ["qr.status IN ('submitted','approved','approved_late')"];
    $params = [];

    if (($filters['questionnaire_family_key'] ?? '') !== '') {
        $where[] = "COALESCE(NULLIF(q.family_key,''), CONCAT('questionnaire-', q.id)) = ?";
        $params[] = (string)$filters['questionnaire_family_key'];
    } elseif (($filters['questionnaire_id'] ?? 0) > 0) {
        $where[] = 'qr.questionnaire_id = ?';
        $params[] = (int)$filters['questionnaire_id'];
    }

    if ($includeYear && ($filters['year'] ?? 0) > 0) {
        $year = (int)$filters['year'];
        $where[] = 'qr.created_at >= ?';
        $params[] = sprintf('%04d-01-01 00:00:00', $year);
        $where[] = 'qr.created_at < ?';
        $params[] = sprintf('%04d-01-01 00:00:00', $year + 1);
    }

    if ($includeDepartment && ($filters['department'] ?? '') !== '') {
        $where[] = 'u.department = ?';
        $params[] = (string)$filters['department'];
    }
    if (($filters['team'] ?? '') !== '') {
        $where[] = 'u.cadre = ?';
        $params[] = (string)$filters['team'];
    }
    if (($filters['work_function'] ?? '') !== '') {
        $where[] = 'u.work_function = ?';
        $params[] = (string)$filters['work_function'];
    }

    return [$where, $params];
}

function analytics_capacity_fetch_response_rows(PDO $pdo, array $filters, bool $includeYear = true, bool $includeDepartment = true): array
{
    $filters = analytics_capacity_resolve_questionnaire_family($pdo, $filters);
    [$where, $params] = analytics_capacity_build_where($filters, $includeYear, $includeDepartment);

    $sql = "SELECT qr.id, qr.user_id, qr.questionnaire_id, qr.performance_period_id, qr.status, qr.score, qr.created_at, "
        . "q.title, COALESCE(NULLIF(q.family_key,''), CONCAT('questionnaire-', q.id)) AS questionnaire_family_key, "
        . "COALESCE(pp.label,'') AS period_label, u.username, u.full_name, u.department, u.cadre, u.work_function "
        . "FROM questionnaire_response qr "
        . "JOIN questionnaire q ON q.id = qr.questionnaire_id "
        . "JOIN users u ON u.id = qr.user_id "
        . "LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id "
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'ORDER BY qr.created_at ASC, qr.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * One canonical response per employee, questionnaire family and assessment year.
 */
function analytics_capacity_latest_per_employee(array $rows): array
{
    $latest = [];
    foreach ($rows as $row) {
        $uid = (int)($row['user_id'] ?? 0);
        $family = trim((string)($row['questionnaire_family_key'] ?? ''));
        $createdAt = (string)($row['created_at'] ?? '');
        if ($uid <= 0 || $family === '' || $createdAt === '') {
            continue;
        }
        $year = (int)substr($createdAt, 0, 4);
        $key = $uid . '|' . $family . '|' . $year;
        $latest[$key] = $row;
    }
    return array_values($latest);
}

function analytics_capacity_average(array $rows, string $scoreKey = 'score'): ?float
{
    $sum = 0.0;
    $count = 0;
    foreach ($rows as $row) {
        if (!array_key_exists($scoreKey, $row) || $row[$scoreKey] === null || !is_numeric($row[$scoreKey])) {
            continue;
        }
        $sum += max(0.0, min(100.0, (float)$row[$scoreKey]));
        $count++;
    }
    return $count > 0 ? round($sum / $count, 1) : null;
}

function analytics_capacity_attainment(array $rows, float $target = 80.0): array
{
    $total = 0;
    $hit = 0;
    foreach ($rows as $row) {
        if (!isset($row['score']) || $row['score'] === null || !is_numeric($row['score'])) {
            continue;
        }
        $score = (float)$row['score'];
        $total++;
        if ($score >= $target) {
            $hit++;
        }
    }

    return [
        'total' => $total,
        'hit' => $hit,
        'percent' => $total > 0 ? round(($hit / $total) * 100, 1) : null,
    ];
}

function analytics_capacity_annual_trend(PDO $pdo, array $filters, int $firstYear = 2026, int $lastYear = 2028): array
{
    $rows = analytics_capacity_latest_per_employee(
        analytics_capacity_fetch_response_rows($pdo, $filters, false, true)
    );

    $byYear = [];
    foreach ($rows as $row) {
        $year = (int)substr((string)($row['created_at'] ?? ''), 0, 4);
        if ($year >= $firstYear && $year <= $lastYear) {
            $byYear[$year][] = $row;
        }
    }

    $trend = [];
    for ($year = $firstYear; $year <= $lastYear; $year++) {
        $yearRows = $byYear[$year] ?? [];
        $attainment = analytics_capacity_attainment($yearRows);
        $trend[] = [
            'year' => $year,
            'average_score' => analytics_capacity_average($yearRows),
            'attainment_percent' => $attainment['percent'],
            'staff_assessed' => $attainment['total'],
        ];
    }
    return $trend;
}

/**
 * Capacity area codes such as CA1 are local to a questionnaire/framework context.
 * Never use the visible section label alone as a cross-questionnaire identifier.
 */
function analytics_capacity_identity(string $questionnaireFamilyKey, string $label): string
{
    $family = strtolower(trim(preg_replace('/\s+/', ' ', $questionnaireFamilyKey) ?? $questionnaireFamilyKey));
    $area = strtolower(trim(preg_replace('/\s+/', ' ', $label) ?? $label));
    return hash('sha256', $family . "\x1f" . $area);
}

function analytics_capacity_section_rows(PDO $pdo, array $canonicalRows, array $translations): array
{
    if (!$canonicalRows) {
        return [];
    }

    $breakdowns = compute_section_breakdowns($pdo, $canonicalRows, $translations, true);
    $responsesById = [];
    foreach ($canonicalRows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $responsesById[$id] = $row;
        }
    }

    $aggregates = [];
    foreach ($breakdowns as $responseId => $breakdown) {
        $response = $responsesById[(int)$responseId] ?? null;
        if (!$response) {
            continue;
        }

        $familyKey = trim((string)($response['questionnaire_family_key'] ?? ''));
        $questionnaireTitle = trim((string)($response['title'] ?? ''));
        $questionnaireId = (int)($response['questionnaire_id'] ?? 0);

        foreach (($breakdown['sections'] ?? []) as $section) {
            $label = trim((string)($section['label'] ?? ''));
            $score = $section['score'] ?? null;
            if ($label === '' || $familyKey === '' || $score === null || !is_numeric($score)) {
                continue;
            }

            $capacityKey = analytics_capacity_identity($familyKey, $label);
            if (!isset($aggregates[$capacityKey])) {
                $aggregates[$capacityKey] = [
                    'capacity_key' => $capacityKey,
                    'label' => $label,
                    'questionnaire_family_key' => $familyKey,
                    'questionnaire_title' => $questionnaireTitle,
                    'questionnaire_id' => $questionnaireId,
                    'scores' => [],
                    'responses' => [],
                ];
            }

            $aggregates[$capacityKey]['scores'][] = (float)$score;
            $aggregates[$capacityKey]['responses'][] = [
                'response_id' => (int)$responseId,
                'user_id' => (int)($response['user_id'] ?? 0),
                'username' => (string)($response['username'] ?? ''),
                'full_name' => (string)($response['full_name'] ?? ''),
                'department' => (string)($response['department'] ?? ''),
                'team' => (string)($response['cadre'] ?? ''),
                'work_function' => (string)($response['work_function'] ?? ''),
                'questionnaire_id' => $questionnaireId,
                'questionnaire_title' => $questionnaireTitle,
                'questionnaire_family_key' => $familyKey,
                'score' => round((float)$score, 1),
            ];
        }
    }

    $rows = [];
    foreach ($aggregates as $aggregate) {
        $scores = $aggregate['scores'];
        $count = count($scores);
        if ($count === 0) {
            continue;
        }

        $average = round(array_sum($scores) / $count, 1);
        $below = count(array_filter($scores, static fn($score): bool => (float)$score < 80.0));

        $rows[] = [
            'capacity_key' => $aggregate['capacity_key'],
            'label' => $aggregate['label'],
            'questionnaire_family_key' => $aggregate['questionnaire_family_key'],
            'questionnaire_title' => $aggregate['questionnaire_title'],
            'questionnaire_id' => $aggregate['questionnaire_id'],
            'average_score' => $average,
            'benchmark' => 80.0,
            'gap' => round($average - 80.0, 1),
            'level' => questionnaire_competency_level($average),
            'staff_assessed' => $count,
            'staff_below_target' => $below,
            'below_percent' => round(($below / $count) * 100, 1),
            'priority_score' => round(max(0.0, 80.0 - $average) * $below, 1),
            'responses' => $aggregate['responses'],
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $priority = ($b['priority_score'] <=> $a['priority_score']);
        if ($priority !== 0) {
            return $priority;
        }
        $questionnaire = strnatcasecmp((string)$a['questionnaire_title'], (string)$b['questionnaire_title']);
        return $questionnaire !== 0 ? $questionnaire : strnatcasecmp((string)$a['label'], (string)$b['label']);
    });

    return $rows;
}

/**
 * Return directorate-scoped heatmap bands instead of a false CA1-by-directorate matrix.
 * Each directorate retains the capacity areas defined by its questionnaire context.
 */
function analytics_capacity_department_heatmap(PDO $pdo, array $filters, array $translations): array
{
    $heatmapFilters = $filters;
    $heatmapFilters['department'] = '';

    $rows = analytics_capacity_latest_per_employee(
        analytics_capacity_fetch_response_rows($pdo, $heatmapFilters, true, false)
    );

    $byDepartment = [];
    foreach ($rows as $row) {
        $department = trim((string)($row['department'] ?? ''));
        $department = $department !== '' ? $department : 'Unknown';
        $byDepartment[$department][] = $row;
    }
    ksort($byDepartment, SORT_NATURAL | SORT_FLAG_CASE);

    $groups = [];
    foreach ($byDepartment as $department => $departmentRows) {
        $capacities = analytics_capacity_section_rows($pdo, $departmentRows, $translations);
        usort($capacities, static function (array $a, array $b): int {
            $questionnaire = strnatcasecmp((string)$a['questionnaire_title'], (string)$b['questionnaire_title']);
            return $questionnaire !== 0 ? $questionnaire : strnatcasecmp((string)$a['label'], (string)$b['label']);
        });

        $groups[] = [
            'department' => $department,
            'capacities' => array_map(static fn(array $row): array => [
                'capacity_key' => $row['capacity_key'],
                'label' => $row['label'],
                'questionnaire_title' => $row['questionnaire_title'],
                'questionnaire_family_key' => $row['questionnaire_family_key'],
                'average_score' => $row['average_score'],
                'staff_assessed' => $row['staff_assessed'],
            ], $capacities),
        ];
    }

    return [
        'groups' => $groups,
        'department_count' => count($groups),
    ];
}

function analytics_capacity_course_matches(PDO $pdo, string $capacityLabel, array $filters, ?float $score): array
{
    $capacityLabel = trim($capacityLabel);
    if ($capacityLabel === '') {
        return [];
    }

    $score = $score ?? 0.0;
    $needle = '%' . $capacityLabel . '%';
    $sql = "SELECT id, code, title, course_objective, expected_competency, thematic_area, mode_of_delivery, duration, moodle_url "
        . "FROM course_catalogue WHERE is_active = 1 "
        . "AND min_score <= ? AND max_score >= ? "
        . "AND (questionnaire_id = ? OR questionnaire_id IS NULL) "
        . "AND (thematic_area LIKE ? OR expected_competency LIKE ? OR title LIKE ?) ";
    $params = [$score, $score, (int)($filters['questionnaire_id'] ?? 0), $needle, $needle, $needle];

    if (($filters['work_function'] ?? '') !== '') {
        $sql .= "AND (recommended_for = ? OR recommended_for = '' OR recommended_for IS NULL) ";
        $params[] = (string)$filters['work_function'];
    }

    $sql .= 'ORDER BY questionnaire_id IS NULL ASC, min_score ASC, title ASC LIMIT 6';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('analytics capacity course matching failed: ' . $e->getMessage());
        return [];
    }
}
