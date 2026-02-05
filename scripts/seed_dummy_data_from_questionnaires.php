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
        "SELECT q.id, q.title, q.status, COUNT(qi.id) AS item_count\n" .
        "FROM questionnaire q\n" .
        "LEFT JOIN questionnaire_item qi ON qi.questionnaire_id = q.id AND qi.is_active = 1\n" .
        "GROUP BY q.id, q.title, q.status\n" .
        "HAVING item_count > 0\n" .
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
 * @param list<string> $options
 * @return array<int, array<string, mixed>>
 */
function build_answer_payload(array $item, array $options): array
{
    $type = (string)($item['type'] ?? 'text');
    if ($type === 'boolean') {
        return [['valueBoolean' => (bool)random_int(0, 1)]];
    }
    if ($type === 'likert') {
        $score = random_int(3, 5);
        return [['valueInteger' => $score]];
    }
    if ($type === 'choice') {
        if (!$options) {
            return [['valueString' => 'N/A']];
        }
        shuffle($options);
        $allowMultiple = (int)($item['allow_multiple'] ?? 0) === 1;
        if ($allowMultiple) {
            $take = random_int(1, min(2, count($options)));
            $picked = array_slice($options, 0, $take);
            return array_map(static fn(string $value): array => ['valueString' => $value], $picked);
        }
        return [['valueString' => $options[0]]];
    }

    $sentences = [
        'Demonstrated consistent delivery against planned priorities.',
        'Collaborated across teams to improve service quality outcomes.',
        'Documented lessons learned and proposed targeted improvements.',
        'Maintained strong compliance while meeting cycle deliverables.',
    ];
    return [['valueString' => $sentences[array_rand($sentences)]]];
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

    $cleanupResponses = $pdo->prepare(
        'DELETE FROM questionnaire_response WHERE user_id IN (SELECT id FROM users WHERE username LIKE "dummy_%")'
    );
    $cleanupAssignments = $pdo->prepare(
        'DELETE FROM questionnaire_assignment WHERE staff_id IN (SELECT id FROM users WHERE username LIKE "dummy_%")'
    );
    $cleanupResponses->execute();
    $cleanupAssignments->execute();

    $itemStmt = $pdo->prepare(
        'SELECT id, linkId, type, allow_multiple FROM questionnaire_item WHERE questionnaire_id = ? AND is_active = 1 ORDER BY order_index, id'
    );
    $optionStmt = $pdo->prepare(
        'SELECT value FROM questionnaire_item_option WHERE questionnaire_item_id = ? ORDER BY order_index, id'
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
            $score = random_int(68, 96);
            $reviewedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;
            $reviewComment = $status === 'approved' ? 'Reviewed dummy submission for seed data.' : null;

            $insertResponse->execute([
                $staffId,
                $qid,
                $periodId,
                $status,
                $score,
                $status === 'approved' ? ($supervisorId > 0 ? $supervisorId : null) : null,
                $reviewedAt,
                $reviewComment,
            ]);
            $responseId = (int)$pdo->lastInsertId();

            foreach ($items as $item) {
                $optionStmt->execute([(int)$item['id']]);
                $options = array_map(static fn(array $row): string => (string)$row['value'], $optionStmt->fetchAll() ?: []);
                $payload = build_answer_payload($item, $options);
                $insertResponseItem->execute([
                    $responseId,
                    (string)$item['linkId'],
                    json_encode($payload),
                ]);
            }
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
