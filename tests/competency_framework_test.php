<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/competency_framework.php';
require_once __DIR__ . '/../lib/scoring.php';

$bands = competency_default_level_bands();
if (count($bands) !== 5) {
    fwrite(STDERR, "Expected five default competency bands.\n");
    exit(1);
}

$cases = [
    [49.0, 'Not Proficient'],
    [50.0, 'Basic Proficiency'],
    [65.0, 'Intermediate Proficiency'],
    [80.0, 'Advanced Proficiency'],
    [95.0, 'Expert'],
];

foreach ($cases as $case) {
    [$score, $expected] = $case;
    $actual = questionnaire_competency_level($score);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf("Expected %s for score %.1f but received %s.\n", $expected, $score, $actual));
        exit(1);
    }
}

if (questionnaire_competency_gap(72.0, null) !== 28.0) {
    fwrite(STDERR, "Expected 100-based gap to equal 28.0.\n");
    exit(1);
}

if (questionnaire_competency_gap(72.0, 80.0) !== 8.0) {
    fwrite(STDERR, "Expected benchmark gap to equal 8.0.\n");
    exit(1);
}

if (questionnaire_competency_recommendation(59.0) !== 'Recommend training') {
    fwrite(STDERR, "Expected training recommendation for low scores.\n");
    exit(1);
}

if (questionnaire_competency_recommendation(70.0) !== 'Recommend coaching') {
    fwrite(STDERR, "Expected coaching recommendation for mid scores.\n");
    exit(1);
}

if (questionnaire_competency_recommendation(90.0) !== 'Consider mentorship role') {
    fwrite(STDERR, "Expected mentorship recommendation for high scores.\n");
    exit(1);
}

echo "Competency framework tests passed.\n";
