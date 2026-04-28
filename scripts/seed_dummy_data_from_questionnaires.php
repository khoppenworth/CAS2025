#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Seed demo users, questionnaire assignments, and submissions using existing questionnaires.
 *
 * Usage:
 *   php scripts/seed_dummy_data_from_questionnaires.php [--statuses=draft,published] [--start-year=2020] [--end-year=2025]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from CLI." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/department_teams.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "Database connection is not available. Check your .env DB_* settings and try again." . PHP_EOL);
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$flags = [
    'statuses' => ['draft', 'published'],
    'startYear' => 2020,
    'endYear' => 2025,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--statuses=')) {
        $raw = trim((string)substr($arg, strlen('--statuses=')));
        $flags['statuses'] = array_values(array_filter(array_map('trim', explode(',', $raw))));
        continue;
    }
    if (str_starts_with($arg, '--start-year=')) {
        $flags['startYear'] = (int)substr($arg, strlen('--start-year='));
        continue;
    }
    if (str_starts_with($arg, '--end-year=')) {
        $flags['endYear'] = (int)substr($arg, strlen('--end-year='));
        continue;
    }
}

if ($flags['startYear'] < 1900 || $flags['endYear'] > 2200 || $flags['startYear'] > $flags['endYear']) {
    fwrite(STDERR, "Invalid year range. Expected --start-year <= --end-year." . PHP_EOL);
    exit(1);
}
if (!$flags['statuses']) {
    fwrite(STDERR, "Invalid statuses list. Provide at least one status via --statuses." . PHP_EOL);
    exit(1);
}

/**
 * @return array<int, array<string, mixed>>
 */
function load_questionnaires(PDO $pdo, array $statuses): array
{
    $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare(
        "SELECT q.id, q.title, q.status,\n" .
        "COUNT(qi.id) AS item_count,\n" .
        "SUM(CASE WHEN qi.type = 'likert' THEN 1 ELSE 0 END) AS likert_count,\n" .
        "SUM(CASE WHEN qi.type = 'choice' AND qi.requires_correct = 1 THEN 1 ELSE 0 END) AS correct_count\n" .
        "FROM questionnaire q\n" .
        "LEFT JOIN questionnaire_item qi ON qi.questionnaire_id = q.id AND qi.is_active = 1\n" .
        "WHERE q.status IN (" . $placeholders . ")\n" .
        "GROUP BY q.id, q.title, q.status\n" .
        "HAVING item_count > 0\n" .
        "ORDER BY q.id"
    );
    $stmt->execute($statuses);

    return $stmt->fetchAll() ?: [];
}

function has_table(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function upsert_department_teams_for_demo(PDO $pdo, array $users): void
{
    if (!has_table($pdo, 'department_team_catalog')) {
        return;
    }

    $teamRows = [];
    foreach ($users as $user) {
        $department = trim((string)($user['department'] ?? ''));
        $teamLabel = trim((string)($user['team_label'] ?? ''));
        if ($department === '' || $teamLabel === '') {
            continue;
        }
        $teamSlug = (string)($user['cadre'] ?? '');
        if ($teamSlug === '') {
            continue;
        }
        $teamRows[$teamSlug] = [
            'slug' => $teamSlug,
            'department_slug' => $department,
            'label' => $teamLabel,
        ];
    }

    if (!$teamRows) {
        return;
    }

    $insertTeamStmt = $pdo->prepare(
        'INSERT INTO department_team_catalog (slug, department_slug, label, sort_order, archived_at) ' .
        'VALUES (?, ?, ?, ?, NULL) ' .
        'ON DUPLICATE KEY UPDATE department_slug = VALUES(department_slug), label = VALUES(label), archived_at = NULL'
    );

    $sortOrder = 1;
    foreach ($teamRows as $team) {
        $insertTeamStmt->execute([
            $team['slug'],
            $team['department_slug'],
            $team['label'],
            $sortOrder,
        ]);
        $sortOrder++;
    }
}

/**
 * @return array<int, int>
 */
function ensure_demo_users(PDO $pdo): array
{
    $passwordHash = password_hash('DemoPass#2026', PASSWORD_DEFAULT);
    $users = [
        [
            'username' => 'demo_supervisor',
            'role' => 'supervisor',
            'full_name' => 'Demo Supervisor',
            'email' => 'demo.supervisor@example.com',
            'work_function' => 'leadership_tn',
            'department' => 'leadership_tn',
            'directorate' => 'Leadership',
            'cadre' => 'leadership_tn_team_leads',
            'team_label' => 'Team Leads',
            'business_role' => 'team_lead',
            'job_grade' => 'JG-11',
            'education_level' => 'masters',
        ],
        [
            'username' => 'demo_staff_finance',
            'role' => 'staff',
            'full_name' => 'Demo Finance Staff',
            'email' => 'demo.finance@example.com',
            'work_function' => 'finance',
            'department' => 'finance',
            'directorate' => 'Corporate Services',
            'cadre' => 'finance_budget_and_reporting',
            'team_label' => 'Budget & Reporting',
            'business_role' => 'expert',
            'job_grade' => 'JG-08',
            'education_level' => 'bachelors',
        ],
        [
            'username' => 'demo_staff_hr',
            'role' => 'staff',
            'full_name' => 'Demo HR Staff',
            'email' => 'demo.hr@example.com',
            'work_function' => 'hrm',
            'department' => 'hrm',
            'directorate' => 'People & Culture',
            'cadre' => 'hrm_talent_management',
            'team_label' => 'Talent Management',
            'business_role' => 'manager',
            'job_grade' => 'JG-09',
            'education_level' => 'bachelors',
        ],
        [
            'username' => 'demo_staff_ict',
            'role' => 'staff',
            'full_name' => 'Demo ICT Staff',
            'email' => 'demo.ict@example.com',
            'work_function' => 'ict',
            'department' => 'ict',
            'directorate' => 'Technology',
            'cadre' => 'ict_platform_and_support',
            'team_label' => 'Platform & Support',
            'business_role' => 'expert',
            'job_grade' => 'JG-10',
            'education_level' => 'bachelors',
        ],
        [
            'username' => 'demo_staff_ops',
            'role' => 'staff',
            'full_name' => 'Demo Operations Staff',
            'email' => 'demo.ops@example.com',
            'work_function' => 'general_service',
            'department' => 'general_service',
            'directorate' => 'Operations',
            'cadre' => 'general_service_facilities_and_logistics',
            'team_label' => 'Facilities & Logistics',
            'business_role' => 'expert',
            'job_grade' => 'JG-07',
            'education_level' => 'diploma',
        ],
    ];

    $selectStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $insertStmt = $pdo->prepare(
        'INSERT INTO users (username, password, role, full_name, email, work_function, department, directorate, cadre, business_role, profile_role, job_grade, education_level, account_status, profile_completed, must_reset_password, language) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", 1, 1, "en")'
    );
    $updateStmt = $pdo->prepare(
        'UPDATE users SET full_name = ?, email = ?, work_function = ?, department = ?, directorate = ?, cadre = ?, business_role = ?, profile_role = ?, job_grade = ?, education_level = ?, account_status = "active", profile_completed = 1, must_reset_password = 1 WHERE id = ?'
    );

    ensure_department_catalog($pdo);
    ensure_department_team_catalog($pdo);
    upsert_department_teams_for_demo($pdo, $users);

    $ids = [];
    foreach ($users as $user) {
        $username = (string)$user['username'];
        $role = (string)$user['role'];
        $fullName = (string)$user['full_name'];
        $email = (string)$user['email'];
        $workFunction = (string)$user['work_function'];
        $department = (string)$user['department'];
        $directorate = (string)$user['directorate'];
        $cadre = (string)$user['cadre'];
        $businessRole = (string)$user['business_role'];
        $jobGrade = (string)$user['job_grade'];
        $educationLevel = (string)$user['education_level'];

        $selectStmt->execute([$username]);
        $existing = $selectStmt->fetchColumn();
        if ($existing !== false) {
            $existingId = (int)$existing;
            $updateStmt->execute([
                $fullName,
                $email,
                $workFunction,
                $department,
                $directorate,
                $cadre,
                $businessRole,
                $businessRole,
                $jobGrade,
                $educationLevel,
                $existingId,
            ]);
            $ids[] = $existingId;
            continue;
        }

        $insertStmt->execute([
            $username,
            $passwordHash,
            $role,
            $fullName,
            $email,
            $workFunction,
            $department,
            $directorate,
            $cadre,
            $businessRole,
            $businessRole,
            $jobGrade,
            $educationLevel,
        ]);
        $ids[] = (int)$pdo->lastInsertId();
    }

    return $ids;
}

/**
 * @return array<int, int> map of year to performance_period.id
 */
function ensure_performance_period_range(PDO $pdo, int $startYear, int $endYear): array
{
    $insert = $pdo->prepare(
        'INSERT INTO performance_period (label, period_start, period_end) VALUES (?, ?, ?) ' .
        'ON DUPLICATE KEY UPDATE period_start = VALUES(period_start), period_end = VALUES(period_end)'
    );

    $select = $pdo->prepare('SELECT id FROM performance_period WHERE label = ? LIMIT 1');
    $periodByYear = [];
    for ($year = $startYear; $year <= $endYear; $year++) {
        $label = (string)$year;
        $start = sprintf('%d-01-01', $year);
        $end = sprintf('%d-12-31', $year);
        $insert->execute([$label, $start, $end]);
        $select->execute([$label]);
        $periodByYear[$year] = (int)$select->fetchColumn();
    }

    return $periodByYear;
}

/**
 * @param array<string, mixed> $item
 * @param array<int, array{value: string, is_correct: bool}> $options
 * @return array{payload: array<int, array<string, mixed>>, correct: bool}
 */
function build_answer_payload(array $item, array $options, ?int $correctAnswerChance = null): array
{
    $type = (string)($item['type'] ?? 'text');
    if ($type === 'boolean') {
        return [
            'payload' => [['valueBoolean' => (bool)random_int(0, 1)]],
            'correct' => false,
        ];
    }
    if ($type === 'likert') {
        $score = random_int(3, 5);
        return [
            'payload' => [['valueInteger' => $score]],
            'correct' => false,
        ];
    }
    if ($type === 'choice') {
        if (!$options) {
            return [
                'payload' => [['valueString' => 'N/A']],
                'correct' => false,
            ];
        }
        $allowMultiple = (int)($item['allow_multiple'] ?? 0) === 1;
        $requiresCorrect = (int)($item['requires_correct'] ?? 0) === 1 && !$allowMultiple;
        $correctOptions = array_values(array_filter($options, static fn(array $opt): bool => $opt['is_correct']));
        $incorrectOptions = array_values(array_filter($options, static fn(array $opt): bool => !$opt['is_correct']));
        shuffle($options);
        if ($requiresCorrect && $correctOptions) {
            $targetChance = $correctAnswerChance ?? random_int(45, 90);
            $answerCorrectly = random_int(1, 100) <= $targetChance;
            if ($answerCorrectly || !$incorrectOptions) {
                return [
                    'payload' => [['valueString' => $correctOptions[0]['value']]],
                    'correct' => true,
                ];
            }
            return [
                'payload' => [['valueString' => $incorrectOptions[0]['value']]],
                'correct' => false,
            ];
        }
        if ($allowMultiple) {
            $take = random_int(1, min(2, count($options)));
            $picked = array_slice($options, 0, $take);
            return [
                'payload' => array_map(static fn(array $option): array => ['valueString' => $option['value']], $picked),
                'correct' => false,
            ];
        }
        return [
            'payload' => [['valueString' => $options[0]['value']]],
            'correct' => false,
        ];
    }

    $sentences = [
        'Demonstrated consistent delivery against planned priorities.',
        'Collaborated across teams to improve service quality outcomes.',
        'Documented lessons learned and proposed targeted improvements.',
        'Maintained strong compliance while meeting cycle deliverables.',
    ];
    return [
        'payload' => [['valueString' => $sentences[array_rand($sentences)]]],
        'correct' => false,
    ];
}

function random_timestamp_in_year(int $year, int $startDay = 1, int $endDay = 365): int
{
    $maxDay = (int)date('z', strtotime(sprintf('%d-12-31', $year))) + 1;
    $startDay = max(1, min($startDay, $maxDay));
    $endDay = max($startDay, min($endDay, $maxDay));
    $dayOfYear = random_int($startDay, $endDay);

    $base = new DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));
    $date = $base->modify('+' . ($dayOfYear - 1) . ' days');
    $date = $date->setTime(random_int(8, 16), random_int(0, 59), random_int(0, 59));

    return $date->getTimestamp();
}

$questionnaires = load_questionnaires($pdo, $flags['statuses']);
if (!$questionnaires) {
    fwrite(
        STDERR,
        sprintf(
            "No questionnaires with statuses [%s] and active items were found. Nothing to seed.",
            implode(', ', $flags['statuses'])
        ) . PHP_EOL
    );
    exit(1);
}

$pdo->beginTransaction();
try {
    $userIds = ensure_demo_users($pdo);
    $staffIds = [];
    $supervisorId = 0;
    foreach ($userIds as $id) {
        $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $roleStmt->execute([$id]);
        $role = (string)$roleStmt->fetchColumn();
        if ($role === 'supervisor') {
            $supervisorId = $id;
        }
        if ($role === 'staff') {
            $staffIds[] = $id;
        }
    }

    $periodByYear = ensure_performance_period_range($pdo, $flags['startYear'], $flags['endYear']);

    $cleanupResponseItems = $pdo->prepare(
        'DELETE FROM questionnaire_response_item WHERE response_id IN (' .
        'SELECT id FROM questionnaire_response WHERE user_id IN (' .
        'SELECT id FROM users WHERE username LIKE "demo_%" OR username LIKE "dummy_%"' .
        ')' .
        ')'
    );
    $cleanupResponses = $pdo->prepare(
        'DELETE FROM questionnaire_response WHERE user_id IN (' .
        'SELECT id FROM users WHERE username LIKE "demo_%" OR username LIKE "dummy_%"' .
        ')'
    );
    $cleanupAssignments = $pdo->prepare(
        'DELETE FROM questionnaire_assignment WHERE staff_id IN (' .
        'SELECT id FROM users WHERE username LIKE "demo_%" OR username LIKE "dummy_%"' .
        ')'
    );
    $cleanupResponseItems->execute();
    $cleanupResponses->execute();
    $cleanupAssignments->execute();

    $itemStmt = $pdo->prepare(
        'SELECT id, linkId, type, allow_multiple, requires_correct FROM questionnaire_item WHERE questionnaire_id = ? AND is_active = 1 ORDER BY order_index, id'
    );
    $optionStmt = $pdo->prepare(
        'SELECT value, is_correct FROM questionnaire_item_option WHERE questionnaire_item_id = ? ORDER BY order_index, id'
    );

    $insertAssignment = $pdo->prepare(
        'INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by, assigned_at) VALUES (?, ?, ?, ?)'
    );
    $insertResponse = $pdo->prepare(
        'INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertResponseItem = $pdo->prepare(
        'INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES (?, ?, ?)'
    );
    $updateScoreStmt = $pdo->prepare('UPDATE questionnaire_response SET score = ? WHERE id = ?');

    $responsesCreated = 0;

    foreach ($questionnaires as $questionnaire) {
        $qid = (int)$questionnaire['id'];
        $itemStmt->execute([$qid]);
        $items = $itemStmt->fetchAll() ?: [];
        if (!$items) {
            continue;
        }

        foreach ($staffIds as $staffId) {
            $year = random_int($flags['startYear'], $flags['endYear']);
            $periodId = (int)($periodByYear[$year] ?? 0);
            if ($periodId <= 0) {
                throw new RuntimeException(sprintf('No performance period found for year %d.', $year));
            }

            $assignedTs = random_timestamp_in_year($year, 1, 320);
            $assignedAt = date('Y-m-d H:i:s', $assignedTs);
            $insertAssignment->execute([$staffId, $qid, $supervisorId > 0 ? $supervisorId : null, $assignedAt]);

            $status = random_int(0, 10) > 2 ? 'submitted' : 'approved';
            $createdTs = min(
                strtotime(sprintf('%d-12-31 23:59:59', $year)),
                $assignedTs + (random_int(3, 45) * 86400)
            );
            $createdAt = date('Y-m-d H:i:s', $createdTs);
            $reviewedAt = $status === 'approved'
                ? date(
                    'Y-m-d H:i:s',
                    min(
                        strtotime(sprintf('%d-12-31 23:59:59', $year)),
                        $createdTs + (random_int(1, 12) * 86400)
                    )
                )
                : null;
            $reviewComment = $status === 'approved' ? 'Reviewed demo submission for seed data.' : null;

            $correctAnswerTotal = 0;
            $correctAnswers = 0;
            $correctAnswerChance = random_int(40, 88);

            $insertResponse->execute([
                $staffId,
                $qid,
                $periodId,
                $status,
                0,
                $status === 'approved' ? ($supervisorId > 0 ? $supervisorId : null) : null,
                $reviewedAt,
                $reviewComment,
                $createdAt,
            ]);
            $responseId = (int)$pdo->lastInsertId();

            foreach ($items as $item) {
                $optionStmt->execute([(int)$item['id']]);
                $options = array_map(
                    static fn(array $row): array => [
                        'value' => (string)$row['value'],
                        'is_correct' => (bool)($row['is_correct'] ?? false),
                    ],
                    $optionStmt->fetchAll() ?: []
                );
                $result = build_answer_payload($item, $options, $correctAnswerChance);
                $payload = $result['payload'];
                $requiresCorrect = (int)($item['requires_correct'] ?? 0) === 1;
                if ($requiresCorrect) {
                    $correctAnswerTotal += 1;
                    if ($result['correct']) {
                        $correctAnswers += 1;
                    }
                }
                $insertResponseItem->execute([
                    $responseId,
                    (string)$item['linkId'],
                    json_encode($payload),
                ]);
            }
            $score = $correctAnswerTotal > 0
                ? (int)round(($correctAnswers / $correctAnswerTotal) * 100)
                : random_int(68, 96);
            $updateScoreStmt->execute([$score, $responseId]);
            $responsesCreated++;
        }
    }

    $pdo->commit();
    fwrite(STDOUT, sprintf('Seed complete. Questionnaires processed: %d, responses created: %d', count($questionnaires), $responsesCreated) . PHP_EOL);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed to seed demo data: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
