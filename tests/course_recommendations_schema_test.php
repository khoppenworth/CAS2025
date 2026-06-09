<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/course_recommendations.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ensure_course_recommendation_schema($pdo);


$importedCount = (int)$pdo->query('SELECT COUNT(*) FROM course_catalogue')->fetchColumn();
if ($importedCount !== 32) {
    fwrite(STDERR, "Expected 32 imported standard catalogue courses before test inserts; found {$importedCount}.\n");
    exit(1);
}

$importedCourse = $pdo->query("SELECT code, recommended_for, min_score, max_score, thematic_area, mode_of_delivery, duration, ceu, course_owner FROM course_catalogue WHERE title = 'Anti-Microbial Stewardship'")->fetch(PDO::FETCH_ASSOC);
if (!$importedCourse || $importedCourse['recommended_for'] !== 'expert' || (int)$importedCourse['min_score'] !== 0 || (int)$importedCourse['max_score'] !== 100 || $importedCourse['duration'] !== '10 hours' || $importedCourse['ceu'] !== '10') {
    fwrite(STDERR, "Expected imported Anti-Microbial Stewardship course with derived code, expert mapping, metadata, and 0-100 range.\n");
    exit(1);
}

$pdo->prepare(
    'INSERT INTO course_catalogue (code, title, moodle_url, recommended_for, questionnaire_id, min_score, max_score, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute(['DIR-101', 'Director Leadership Essentials', null, 'director', null, 0, 100, 1]);

$pdo->prepare(
    'INSERT INTO course_catalogue (code, title, moodle_url, recommended_for, questionnaire_id, min_score, max_score, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute(['TEAM-201', 'Team Lead Coaching', null, 'team_lead', 7, 50, 80, 1]);

$directorMatches = find_course_matches($pdo, 'director', 75, null);
if (count($directorMatches) !== 1 || ($directorMatches[0]['code'] ?? '') !== 'DIR-101') {
    fwrite(STDERR, "Expected one global director course match.\n");
    exit(1);
}

$teamLeadMatches = find_course_matches($pdo, 'team_lead', 60, 7);
if (count($teamLeadMatches) !== 1 || ($teamLeadMatches[0]['code'] ?? '') !== 'TEAM-201') {
    fwrite(STDERR, "Expected one questionnaire-specific team lead course match.\n");
    exit(1);
}

$mapped = map_response_to_training_courses($pdo, 123, 'team_lead', 60, 7);
if ($mapped !== 1) {
    fwrite(STDERR, "Expected one mapped training recommendation.\n");
    exit(1);
}

$recommendationCount = (int)$pdo->query('SELECT COUNT(*) FROM training_recommendation WHERE questionnaire_response_id = 123')->fetchColumn();
if ($recommendationCount !== 1) {
    fwrite(STDERR, "Expected one persisted training recommendation.\n");
    exit(1);
}

echo "Course recommendation schema tests passed.\n";
