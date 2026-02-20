<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/course_recommendations.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Missing tables should not throw from helper functions.
$matches = find_course_matches($pdo, 'finance', 80, 1);
if ($matches !== []) {
    fwrite(STDERR, "Expected empty matches when catalogue table is missing.\n");
    exit(1);
}

clear_response_training_recommendations($pdo, 123);

$mapped = map_response_to_training_courses($pdo, 123, 'finance', 80, 1);
if ($mapped !== 0) {
    fwrite(STDERR, "Expected zero mapped courses when recommendation tables are missing.\n");
    exit(1);
}

echo "Course recommendation resilience tests passed.\n";
