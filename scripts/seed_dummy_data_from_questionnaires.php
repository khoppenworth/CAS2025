#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Seed dummy users, questionnaire assignments, and submissions using existing questionnaires.
 *
 * Usage:
 *   php scripts/seed_dummy_data_from_questionnaires.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from CLI." . PHP_EOL);
    exit(1);
}

define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../config.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/**
 * @return array<int, array<string, mixed>>
 */
function load_questionnaires(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT q.id, q.title, q.status,\n" .
        "COUNT(qi.id) AS item_count,\n" .
        "SUM(CASE WHEN qi.type = 'likert' THEN 1 ELSE 0 END) AS likert_count,\n" .
        "SUM(CASE WHEN qi.type = 'choice' AND qi.requires_correct = 1 THEN 1 ELSE 0 END) AS correct_count\n" .
        "FROM questionnaire q\n" .
        "LEFT JOIN questionnaire_item qi ON qi.questionnaire_id = q.id AND qi.is_active = 1\n" .
        "GROUP BY q.id, q.title, q.status\n" .
        "HAVING item_count > 0 AND likert_count = 0 AND correct_count > 0\n" .
        "ORDER BY FIELD(q.status, 'published', 'draft', 'inactive'), q.id"
    );

    return $stmt->fetchAll() ?: [];
}

/**
 * @return array<int, int>
 */
function ensure_dummy_users(PDO $pdo): array
{
    $passwordHash = password_hash('DummyPass#2025', PASSWORD_DEFAULT);
    $users = [
        ['dummy_supervisor', 'supervisor', 'Dummy Supervisor', 'dummy.supervisor@example.com', 'leadership_tn'],
        ['dummy_staff_finance', 'staff', 'Dummy Finance Staff', 'dummy.finance@example.com', 'finance'],
        ['dummy_staff_hr', 'staff', 'Dummy HR Staff', 'dummy.hr@example.com', 'hrm'],
        ['dummy_staff_ict', 'staff', 'Dummy ICT Staff', 'dummy.ict@example.com', 'ict'],
        ['dummy_staff_ops', 'staff', 'Dummy Operations Staff', 'dummy.ops@example.com', 'general_service'],
    ];

    $selectStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $insertStmt = $pdo->prepare(
        'INSERT INTO users (username, password, role, full_name, email, work_function, account_status, profile_completed, must_reset_password, language) ' .
        'VALUES (?, ?, ?, ?, ?, ?, "active", 1, 1, "en")'
    );

    $ids = [];
    foreach ($users as [$username, $role, $fullName, $email, $workFunction]) {
        $selectStmt->execute([$username]);
        $existing = $selectStmt->fetchColumn();
        if ($existing !== false) {
            $ids[] = (int)$existing;
            continue;
        }

        $insertStmt->execute([$username, $passwordHash, $role, $fullName, $email, $workFunction]);
        $ids[] = (int)$pdo->lastInsertId();
    }

    return $ids;
}

function ensure_current_performance_period(PDO $pdo): int
{
    $year = (int)date('Y');
    $month = (int)date('n');
    $half = $month <= 6 ? 'H1' : 'H2';
    $label = sprintf('%d %s', $year, $half);
    $start = $half === 'H1' ? sprintf('%d-01-01', $year) : sprintf('%d-07-01', $year);
    $end = $half === 'H1' ? sprintf('%d-06-30', $year) : sprintf('%d-12-31', $year);

    $insert = $pdo->prepare(
        'INSERT INTO performance_period (label, period_start, period_end) VALUES (?, ?, ?) ' .
        'ON DUPLICATE KEY UPDATE period_start = VALUES(period_start), period_end = VALUES(period_end)'
    );
    $insert->execute([$label, $start, $end]);

    $select = $pdo->prepare('SELECT id FROM performance_period WHERE label = ? LIMIT 1');
    $select->execute([$label]);
    return (int)$select->fetchColumn();
}

/**
 * @param array<string, mixed> $item
 * @param array<int, array{value: string, is_correct: bool}> $options
 * @return array{payload: array<int, array<string, mixed>>, correct: bool}
 */
function build_answer_payload(array $item, array $options): array
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
            $answerCorrectly = random_int(1, 10) > 2;
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

$questionnaires = load_questionnaires($pdo);
if (!$questionnaires) {
    fwrite(STDERR, "No questionnaires with active items were found. Nothing to seed." . PHP_EOL);
    exit(1);
}

$pdo->beginTransaction();
try {
    $userIds = ensure_dummy_users($pdo);
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

    $periodId = ensure_current_performance_period($pdo);

    $cleanupResponseItems = $pdo->prepare(
        'DELETE FROM questionnaire_response_item WHERE response_id IN (' .
        'SELECT id FROM questionnaire_response WHERE user_id IN (SELECT id FROM users WHERE username LIKE "dummy_%")' .
        ')'
    );
    $cleanupResponses = $pdo->prepare(
        'DELETE FROM questionnaire_response WHERE user_id IN (SELECT id FROM users WHERE username LIKE "dummy_%")'
    );
    $cleanupAssignments = $pdo->prepare(
        'DELETE FROM questionnaire_assignment WHERE staff_id IN (SELECT id FROM users WHERE username LIKE "dummy_%")'
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
        'INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())'
    );
    $insertResponse = $pdo->prepare(
        'INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
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
            $insertAssignment->execute([$staffId, $qid, $supervisorId > 0 ? $supervisorId : null]);

            $status = random_int(0, 10) > 2 ? 'submitted' : 'approved';
            $reviewedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;
            $reviewComment = $status === 'approved' ? 'Reviewed dummy submission for seed data.' : null;

            $correctAnswerTotal = 0;
            $correctAnswers = 0;

            $insertResponse->execute([
                $staffId,
                $qid,
                $periodId,
                $status,
                0,
                $status === 'approved' ? ($supervisorId > 0 ? $supervisorId : null) : null,
                $reviewedAt,
                $reviewComment,
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
                $result = build_answer_payload($item, $options);
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
    fwrite(STDERR, 'Failed to seed dummy data: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
