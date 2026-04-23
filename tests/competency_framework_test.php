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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE competency_level_band ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
    . 'name TEXT NOT NULL, '
    . 'min_pct REAL NOT NULL, '
    . 'max_pct REAL NOT NULL, '
    . 'rank_order INTEGER NOT NULL)'
);
$pdo->exec("INSERT INTO competency_level_band (name, min_pct, max_pct, rank_order) VALUES ('Starter', 0.0, 74.99, 1)");
$pdo->exec("INSERT INTO competency_level_band (name, min_pct, max_pct, rank_order) VALUES ('Skilled', 75.0, 100.0, 2)");
$GLOBALS['pdo'] = $pdo;
competency_level_bands($pdo, true);

if (questionnaire_competency_level(70.0) !== 'Starter') {
    fwrite(STDERR, "Expected DB-configured level band Starter for score 70.0.\n");
    exit(1);
}

if (questionnaire_competency_level(90.0) !== 'Skilled') {
    fwrite(STDERR, "Expected DB-configured level band Skilled for score 90.0.\n");
    exit(1);
}

unset($GLOBALS['pdo']);
$pdo = null;

$pdoA = new PDO('sqlite::memory:');
$pdoA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoA->exec(
    'CREATE TABLE competency_level_band ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
    . 'name TEXT NOT NULL, '
    . 'min_pct REAL NOT NULL, '
    . 'max_pct REAL NOT NULL, '
    . 'rank_order INTEGER NOT NULL)'
);
$pdoA->exec("INSERT INTO competency_level_band (name, min_pct, max_pct, rank_order) VALUES ('Alpha', 0.0, 100.0, 1)");
if (competency_level_from_bands(60.0, competency_level_bands($pdoA, true)) !== 'Alpha') {
    fwrite(STDERR, "Expected Alpha level from first PDO connection.\n");
    exit(1);
}
unset($pdoA);

$pdoB = new PDO('sqlite::memory:');
$pdoB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoB->exec(
    'CREATE TABLE competency_level_band ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
    . 'name TEXT NOT NULL, '
    . 'min_pct REAL NOT NULL, '
    . 'max_pct REAL NOT NULL, '
    . 'rank_order INTEGER NOT NULL)'
);
$pdoB->exec("INSERT INTO competency_level_band (name, min_pct, max_pct, rank_order) VALUES ('Beta', 0.0, 100.0, 1)");
if (competency_level_from_bands(60.0, competency_level_bands($pdoB, true)) !== 'Beta') {
    fwrite(STDERR, "Expected Beta level from second PDO connection after destroying first.\n");
    exit(1);
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
