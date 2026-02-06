<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/work_functions.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE questionnaire (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, status TEXT)');
$pdo->exec('CREATE TABLE questionnaire_work_function (questionnaire_id INT NOT NULL, work_function TEXT NOT NULL)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, work_function TEXT)');

$pdo->exec("INSERT INTO questionnaire (id, title, status) VALUES (1, 'Annual Review', 'published'), (2, 'Specialist', 'published')");
$pdo->exec("INSERT INTO users (work_function) VALUES ('finance'), ('hrm'), ('wim')");

$initialAssignments = [
    'finance' => [1, 2],
    'hrm' => [1],
];

save_work_function_assignments($pdo, $initialAssignments);

$rows = $pdo
    ->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function ORDER BY work_function, questionnaire_id')
    ->fetchAll(PDO::FETCH_ASSOC);

$expected = [
    ['questionnaire_id' => 1, 'work_function' => 'finance'],
    ['questionnaire_id' => 2, 'work_function' => 'finance'],
    ['questionnaire_id' => 1, 'work_function' => 'hrm'],
];

if ($rows !== $expected) {
    fwrite(STDERR, "Initial assignment save did not match expectations.\n");
    exit(1);
}

$normalized = normalize_work_function_assignments(
    [
        'finance' => [1, '2', 'ignore-me'],
        'hrm' => ['2'],
        'unknown' => [1],
        'wim' => ['999'],
    ],
    ['finance', 'hrm', 'wim'],
    [1, 2]
);

if ($normalized !== [
    'finance' => [1, 2],
    'hrm' => [2],
    'wim' => [],
]) {
    fwrite(STDERR, "Normalization failed to filter assignments properly.\n");
    exit(1);
}

save_work_function_assignments($pdo, $normalized);

$rows = $pdo
    ->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function ORDER BY work_function, questionnaire_id')
    ->fetchAll(PDO::FETCH_ASSOC);

$expected = [
    ['questionnaire_id' => 1, 'work_function' => 'finance'],
    ['questionnaire_id' => 2, 'work_function' => 'finance'],
    ['questionnaire_id' => 2, 'work_function' => 'hrm'],
];

if ($rows !== $expected) {
    fwrite(STDERR, "Second assignment save did not match expectations.\n");
    exit(1);
}

save_work_function_assignments($pdo, ['hrm' => [2], 'wim' => [1]]);

$rows = $pdo
    ->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function ORDER BY work_function, questionnaire_id')
    ->fetchAll(PDO::FETCH_ASSOC);

$expected = [
    ['questionnaire_id' => 2, 'work_function' => 'hrm'],
    ['questionnaire_id' => 1, 'work_function' => 'wim'],
];

if ($rows !== $expected) {
    fwrite(STDERR, "Final assignment save did not match expectations.\n");
    exit(1);
}

$custom = create_work_function($pdo, 'Custom Squad');
if (!isset($custom['slug']) || $custom['slug'] === '') {
    fwrite(STDERR, "Failed to create custom work function.\n");
    exit(1);
}

update_work_function_label($pdo, $custom['slug'], 'Custom Response Squad');
$definitions = work_function_definitions($pdo, true);
if (($definitions[$custom['slug']] ?? '') !== 'Custom Response Squad') {
    fwrite(STDERR, "Renaming work function did not update the catalog.\n");
    exit(1);
}

$pdo->exec("UPDATE users SET work_function='" . $custom['slug'] . "' WHERE id=1");
save_work_function_assignments($pdo, ['hrm' => [2], $custom['slug'] => [1]]);

$rows = $pdo
    ->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function ORDER BY work_function, questionnaire_id')
    ->fetchAll(PDO::FETCH_ASSOC);

$expected = [
    ['questionnaire_id' => 1, 'work_function' => $custom['slug']],
    ['questionnaire_id' => 2, 'work_function' => 'hrm'],
];

if ($rows !== $expected) {
    fwrite(STDERR, "Assignments including custom work function did not match expectations.\n");
    exit(1);
}

archive_work_function($pdo, $custom['slug']);

$rows = $pdo
    ->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function ORDER BY work_function, questionnaire_id')
    ->fetchAll(PDO::FETCH_ASSOC);

if ($rows !== [
    ['questionnaire_id' => 2, 'work_function' => 'hrm'],
]) {
    fwrite(STDERR, "Archiving the work function should remove related assignments.\n");
    exit(1);
}

$userValue = $pdo->query('SELECT work_function FROM users WHERE id=1')->fetchColumn();
if ($userValue !== null && $userValue !== '') {
    fwrite(STDERR, "Archiving the work function should clear user assignments.\n");
    exit(1);
}

if (work_function_label($pdo, 'finance') !== 'Finance & Grants') {
    fwrite(STDERR, "Failed to resolve finance label.\n");
    exit(1);
}

if (work_function_label($pdo, 'Finance') !== 'Finance & Grants') {
    fwrite(STDERR, "Canonical lookup for Finance failed.\n");
    exit(1);
}

if (work_function_label($pdo, 'custom squad') !== 'Custom Squad') {
    fwrite(STDERR, "Fallback label formatting failed.\n");
    exit(1);
}

if (work_function_label($pdo, '') !== '') {
    fwrite(STDERR, "Empty work function should return an empty label.\n");
    exit(1);
}


$normalizedCustom = normalize_work_function_assignments(
    [
        'Rapid Response Team' => [1],
    ],
    ['rapid_response_team'],
    [1, 2]
);

if ($normalizedCustom !== [
    'rapid_response_team' => [1],
]) {
    fwrite(STDERR, "Normalization should accept catalog slugs generated from labels.\n");
    exit(1);
}

echo "Work function assignment tests passed.\n";
