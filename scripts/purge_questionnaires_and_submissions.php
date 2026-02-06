#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Delete all questionnaires and every submission linked to them.
 *
 * Usage:
 *   php scripts/purge_questionnaires_and_submissions.php [--yes]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be run from CLI." . PHP_EOL);
    exit(1);
}

define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../config.php';

$confirmed = in_array('--yes', $argv, true);
if (!$confirmed) {
    fwrite(STDOUT, "This will permanently delete all questionnaires and related submissions." . PHP_EOL);
    fwrite(STDOUT, "Re-run with --yes to confirm." . PHP_EOL);
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$counts = [
    'questionnaire' => (int)($pdo->query('SELECT COUNT(*) FROM questionnaire')->fetchColumn() ?: 0),
    'questionnaire_response' => (int)($pdo->query('SELECT COUNT(*) FROM questionnaire_response')->fetchColumn() ?: 0),
    'questionnaire_response_item' => (int)($pdo->query('SELECT COUNT(*) FROM questionnaire_response_item')->fetchColumn() ?: 0),
    'training_recommendation' => (int)($pdo->query('SELECT COUNT(*) FROM training_recommendation')->fetchColumn() ?: 0),
    'questionnaire_assignment' => (int)($pdo->query('SELECT COUNT(*) FROM questionnaire_assignment')->fetchColumn() ?: 0),
];

$pdo->beginTransaction();
try {
    // Remove dependent records first so reporting and assignment tables are fully reset.
    $pdo->exec('DELETE FROM training_recommendation');
    $pdo->exec('DELETE FROM questionnaire_response_item');
    $pdo->exec('DELETE FROM questionnaire_response');
    $pdo->exec('DELETE FROM questionnaire_assignment');
    $pdo->exec('DELETE FROM questionnaire_work_function');
    $pdo->exec('DELETE FROM questionnaire_item_option');
    $pdo->exec('DELETE FROM questionnaire_item');
    $pdo->exec('DELETE FROM questionnaire_section');
    $pdo->exec('DELETE FROM questionnaire');

    // Reset schedules that were scoped to removed questionnaires.
    $pdo->exec('UPDATE analytics_report_schedule SET questionnaire_id = NULL WHERE questionnaire_id IS NOT NULL');

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed to purge questionnaires/submissions: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Purge complete.' . PHP_EOL);
foreach ($counts as $table => $count) {
    fwrite(STDOUT, sprintf(' - %s rows before purge: %d', $table, $count) . PHP_EOL);
}
