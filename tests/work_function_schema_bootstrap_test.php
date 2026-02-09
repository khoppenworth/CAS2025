<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/work_functions.php';

if (!function_exists('ensure_questionnaire_work_function_schema')) {
    fwrite(STDERR, "ensure_questionnaire_work_function_schema() is not defined.\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ensure_questionnaire_work_function_schema($pdo);

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='questionnaire_work_function'")->fetchAll(PDO::FETCH_COLUMN);
if ($tables !== ['questionnaire_work_function']) {
    fwrite(STDERR, "questionnaire_work_function table was not created for sqlite.\n");
    exit(1);
}

// Idempotency: running schema initialization again should not throw and should preserve structure.
ensure_questionnaire_work_function_schema($pdo);

$columns = $pdo->query("PRAGMA table_info('questionnaire_work_function')")->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_map(static fn(array $row): string => (string)$row['name'], $columns);
if ($columnNames !== ['questionnaire_id', 'work_function']) {
    fwrite(STDERR, "Unexpected questionnaire_work_function schema columns.\n");
    exit(1);
}

$pdo->exec("INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (1, 'finance')");
$pdo->exec("INSERT OR IGNORE INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (1, 'finance')");
$count = (int)$pdo->query('SELECT COUNT(*) FROM questionnaire_work_function')->fetchColumn();
if ($count !== 1) {
    fwrite(STDERR, "Expected composite primary key behavior for questionnaire_work_function.\n");
    exit(1);
}

echo "Work function schema bootstrap tests passed.\n";
