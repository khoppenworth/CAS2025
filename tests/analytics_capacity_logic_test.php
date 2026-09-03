<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/analytics_capacity.php';

$rows = [
    ['id' => 1, 'user_id' => 10, 'questionnaire_family_key' => 'supply', 'created_at' => '2026-01-10 09:00:00', 'score' => 55],
    ['id' => 2, 'user_id' => 10, 'questionnaire_family_key' => 'supply', 'created_at' => '2026-05-10 09:00:00', 'score' => 82],
    ['id' => 3, 'user_id' => 11, 'questionnaire_family_key' => 'supply', 'created_at' => '2026-03-10 09:00:00', 'score' => 76],
    ['id' => 4, 'user_id' => 12, 'questionnaire_family_key' => 'supply', 'created_at' => '2026-04-10 09:00:00', 'score' => 62],
    ['id' => 5, 'user_id' => 10, 'questionnaire_family_key' => 'supply', 'created_at' => '2027-02-10 09:00:00', 'score' => 84],
];

$latest = analytics_capacity_latest_per_employee($rows);
if (count($latest) !== 4) {
    fwrite(STDERR, "Expected four canonical user/family/year rows.\n");
    exit(1);
}

$year2026 = array_values(array_filter($latest, static fn(array $row): bool => str_starts_with((string)$row['created_at'], '2026-')));
$ids2026 = array_column($year2026, 'id');
sort($ids2026);
if ($ids2026 !== [2, 3, 4]) {
    fwrite(STDERR, 'Latest completed response selection failed for 2026: ' . json_encode($ids2026) . "\n");
    exit(1);
}

$average2026 = analytics_capacity_average($year2026);
if ($average2026 === null || abs($average2026 - 73.3) > 0.001) {
    fwrite(STDERR, "Expected 2026 canonical average of 73.3.\n");
    exit(1);
}

$attainment2026 = analytics_capacity_attainment($year2026);
if ($attainment2026['total'] !== 3 || $attainment2026['hit'] !== 1 || abs((float)$attainment2026['percent'] - 33.3) > 0.001) {
    fwrite(STDERR, "Expected 2026 attainment of 1/3 (33.3%).\n");
    exit(1);
}

$filters = analytics_capacity_normalize_filters([
    'year' => '2028',
    'questionnaire_id' => '12',
    'department' => ' Supply Chain ',
    'team' => 'warehouse',
    'work_function' => 'logistics',
]);
if ($filters !== [
    'year' => 2028,
    'questionnaire_id' => 12,
    'questionnaire_family_key' => '',
    'department' => 'Supply Chain',
    'team' => 'warehouse',
    'work_function' => 'logistics',
]) {
    fwrite(STDERR, "Filter normalization failed.\n");
    exit(1);
}

$familyFilters = $filters;
$familyFilters['questionnaire_family_key'] = 'annual-supply';
[$where, $params] = analytics_capacity_build_where($familyFilters, true, true);
$whereText = implode(' ', $where);
if (!str_contains($whereText, 'q.family_key') || in_array(12, $params, true)) {
    fwrite(STDERR, "Questionnaire family filtering should supersede exact questionnaire ID filtering.\n");
    exit(1);
}
if (!in_array('annual-supply', $params, true)) {
    fwrite(STDERR, "Questionnaire family key was not bound into analytics filters.\n");
    exit(1);
}

echo "Analytics capacity logic tests passed.\n";
