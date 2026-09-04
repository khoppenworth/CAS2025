<?php
$path = __DIR__ . '/../admin/export.php';
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "Unable to read admin/export.php\n");
    exit(1);
}
$required = [
    'questionnaire_response_item',
    'questionnaire_item qi',
    'questionnaire_item_option',
    'question_text',
    'answer_text',
    'answer_json',
    'correct_expected_answers',
    'answer_is_correct',
    'recommended_courses',
    'recommendation_reasons',
    'department',
    'directorate',
    'work_function',
    'score_percent',
    'reached_80_percent',
    'performance_period',
    'review_comment',
];
foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing granular export contract token: {$needle}\n");
        exit(1);
    }
}
if (strpos($source, "fwrite($out, \"\\xEF\\xBB\\xBF\")") === false) {
    fwrite(STDERR, "Detailed CSV should include a UTF-8 BOM for Excel compatibility.\n");
    exit(1);
}
echo "Granular export contract passed.\n";
