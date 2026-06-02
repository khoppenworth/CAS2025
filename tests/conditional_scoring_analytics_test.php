<?php
declare(strict_types=1);

require_once __DIR__ . '/../i18n.php';
require_once __DIR__ . '/../lib/performance_sections.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE questionnaire_section (id INTEGER PRIMARY KEY, questionnaire_id INT, title TEXT, order_index INT)');
$pdo->exec('CREATE TABLE questionnaire_item (
    id INTEGER PRIMARY KEY,
    questionnaire_id INT,
    section_id INT,
    linkId TEXT,
    type TEXT,
    allow_multiple INT,
    requires_correct INT,
    condition_source_linkid TEXT,
    condition_operator TEXT,
    condition_value TEXT,
    weight_percent REAL,
    order_index INT
)');
$pdo->exec('CREATE TABLE questionnaire_item_option (id INTEGER PRIMARY KEY, questionnaire_item_id INT, value TEXT, is_correct INT DEFAULT 0, order_index INT)');
$pdo->exec('CREATE TABLE questionnaire_response_item (id INTEGER PRIMARY KEY, response_id INT, linkId TEXT, answer TEXT)');

$pdo->exec("INSERT INTO questionnaire_section (id, questionnaire_id, title, order_index) VALUES (1, 1, 'Mixed conditional scoring', 1)");

$itemStmt = $pdo->prepare(
    'INSERT INTO questionnaire_item (id, questionnaire_id, section_id, linkId, type, allow_multiple, requires_correct, condition_source_linkid, condition_operator, condition_value, weight_percent, order_index) '
    . 'VALUES (?, 1, 1, ?, \'choice\', 0, 1, ?, ?, ?, NULL, ?)'
);
$optionStmt = $pdo->prepare('INSERT INTO questionnaire_item_option (questionnaire_item_id, value, is_correct, order_index) VALUES (?, ?, ?, ?)');
$answerStmt = $pdo->prepare('INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES (1, ?, ?)');

for ($i = 1; $i <= 50; $i++) {
    $linkId = 'q' . $i;
    $itemStmt->execute([$i, $linkId, null, null, null, $i]);
    $optionStmt->execute([$i, 'Correct', 1, 1]);
    $optionStmt->execute([$i, 'Wrong', 0, 2]);
    $selected = $i <= 40 ? 'Correct' : 'Wrong';
    if ($i === 1) {
        $selected = 'Wrong'; // keeps hidden conditional follow-ups invisible in this response
    }
    if ($i === 41) {
        $selected = 'Correct'; // preserve exactly 40 correct and 10 wrong across the 50 visible questions
    }
    $answerStmt->execute([$linkId, json_encode([['valueString' => $selected]])]);
}

for ($i = 51; $i <= 55; $i++) {
    $linkId = 'q' . $i;
    $itemStmt->execute([$i, $linkId, 'q1', 'equals', 'Correct', $i]);
    $optionStmt->execute([$i, 'Correct', 1, 1]);
    $optionStmt->execute([$i, 'Wrong', 0, 2]);
}

$breakdowns = compute_section_breakdowns(
    $pdo,
    [[
        'id' => 1,
        'questionnaire_id' => 1,
        'title' => 'Conditional Assessment',
        'period_label' => 'UAT',
        'score' => 80,
    ]],
    []
);

$score = $breakdowns[1]['sections'][0]['score'] ?? null;
if (abs((float)$score - 80.0) > 0.001) {
    fwrite(STDERR, sprintf("Expected analytics section score to stay at 80.0 for 40/50 visible answers, received %s.\n", var_export($score, true)));
    exit(1);
}

$hiddenItem = [
    'condition_source_linkid' => 'q1',
    'condition_operator' => 'equals',
    'condition_value' => 'Correct',
];
$conditionValues = questionnaire_collect_condition_values_from_answers([
    'q1' => [['valueString' => 'Wrong']],
]);
if (questionnaire_item_matches_condition($hiddenItem, $conditionValues)) {
    fwrite(STDERR, "Expected hidden conditional item to be excluded from analytics scoring.\n");
    exit(1);
}

echo "Conditional scoring analytics tests passed.\n";
