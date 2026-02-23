<?php

declare(strict_types=1);

if (defined('APP_COURSE_RECOMMENDATIONS_LOADED')) {
    return;
}
define('APP_COURSE_RECOMMENDATIONS_LOADED', true);

function ensure_course_recommendation_schema(PDO $pdo): void
{
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS course_catalogue ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'code TEXT NOT NULL, '
                . 'title TEXT NOT NULL, '
                . 'moodle_url TEXT NULL, '
                . 'recommended_for TEXT NOT NULL, '
                . 'questionnaire_id INTEGER NULL, '
                . 'min_score INTEGER NOT NULL DEFAULT 0, '
                . 'max_score INTEGER NOT NULL DEFAULT 100, '
                . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')');
            $pdo->exec('CREATE TABLE IF NOT EXISTS training_recommendation ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'questionnaire_response_id INTEGER NOT NULL, '
                . 'course_id INTEGER NOT NULL, '
                . 'recommendation_reason TEXT NULL, '
                . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')');
            try {
                $pdo->exec('ALTER TABLE course_catalogue ADD COLUMN questionnaire_id INTEGER NULL');
            } catch (Throwable $e) {
                // Ignore duplicate-column errors.
            }
            return;
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS course_catalogue ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'code VARCHAR(50) NOT NULL, '
            . 'title VARCHAR(255) NOT NULL, '
            . 'moodle_url VARCHAR(255) NULL, '
            . 'recommended_for VARCHAR(100) NOT NULL, '
            . 'questionnaire_id INT NULL, '
            . 'min_score INT NOT NULL DEFAULT 0, '
            . 'max_score INT NOT NULL DEFAULT 100, '
            . 'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, '
            . 'INDEX idx_course_catalogue_match (recommended_for, questionnaire_id, min_score, max_score)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        try {
            $pdo->exec('ALTER TABLE course_catalogue ADD COLUMN questionnaire_id INT NULL AFTER recommended_for');
        } catch (Throwable $e) {
            // Ignore duplicate-column errors.
        }
        try {
            $pdo->exec('CREATE INDEX idx_course_catalogue_match ON course_catalogue (recommended_for, questionnaire_id, min_score, max_score)');
        } catch (Throwable $e) {
            // Ignore duplicate index errors.
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS training_recommendation ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'questionnaire_response_id INT NOT NULL, '
            . 'course_id INT NOT NULL, '
            . 'recommendation_reason VARCHAR(255) NULL, '
            . 'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, '
            . 'INDEX idx_training_recommendation_response (questionnaire_response_id), '
            . 'INDEX idx_training_recommendation_course (course_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (PDOException $e) {
        error_log('ensure_course_recommendation_schema failed: ' . $e->getMessage());
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function find_course_matches(PDO $pdo, string $workFunction, int $score, ?int $questionnaireId = null): array
{
    try {
        if ($questionnaireId !== null && $questionnaireId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, code, title, moodle_url, recommended_for, questionnaire_id, min_score, max_score '
                . 'FROM course_catalogue '
                . 'WHERE recommended_for = ? AND min_score <= ? AND max_score >= ? '
                . 'AND (questionnaire_id = ? OR questionnaire_id IS NULL) '
                . 'ORDER BY questionnaire_id IS NULL ASC, min_score ASC, title ASC'
            );
            $stmt->execute([$workFunction, $score, $score, $questionnaireId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $stmt = $pdo->prepare(
            'SELECT id, code, title, moodle_url, recommended_for, questionnaire_id, min_score, max_score '
            . 'FROM course_catalogue '
            . 'WHERE recommended_for = ? AND min_score <= ? AND max_score >= ? '
            . 'AND questionnaire_id IS NULL '
            . 'ORDER BY min_score ASC, title ASC'
        );
        $stmt->execute([$workFunction, $score, $score]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('find_course_matches failed: ' . $e->getMessage());
        return [];
    }
}


function clear_response_training_recommendations(PDO $pdo, int $responseId): void
{
    if ($responseId <= 0) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM training_recommendation WHERE questionnaire_response_id = ?')->execute([$responseId]);
    } catch (PDOException $e) {
        error_log('clear_response_training_recommendations failed: ' . $e->getMessage());
    }
}

function map_response_to_training_courses(PDO $pdo, int $responseId, string $workFunction, int $score, ?int $questionnaireId = null): int
{
    $workFunction = trim($workFunction);
    if ($responseId <= 0 || $workFunction === '') {
        return 0;
    }

    $courses = find_course_matches($pdo, $workFunction, $score, $questionnaireId);
    clear_response_training_recommendations($pdo, $responseId);
    if ($courses === []) {
        return 0;
    }

    try {
        $insert = $pdo->prepare(
            'INSERT INTO training_recommendation (questionnaire_response_id, course_id, recommendation_reason) VALUES (?, ?, ?)'
        );
        $count = 0;
        foreach ($courses as $course) {
            $scope = ((int)($course['questionnaire_id'] ?? 0) > 0) ? 'questionnaire-specific' : 'global';
            $reason = sprintf(
                'Matched %s %s score band %d-%d%%',
                $workFunction,
                $scope,
                (int)$course['min_score'],
                (int)$course['max_score']
            );
            $insert->execute([$responseId, (int)$course['id'], $reason]);
            $count++;
        }

        return $count;
    } catch (PDOException $e) {
        error_log('map_response_to_training_courses failed: ' . $e->getMessage());
        return 0;
    }
}
