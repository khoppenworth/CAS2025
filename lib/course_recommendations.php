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
                . 'course_objective TEXT NULL, '
                . 'expected_competency TEXT NULL, '
                . 'thematic_area TEXT NULL, '
                . 'mode_of_delivery TEXT NULL, '
                . 'duration TEXT NULL, '
                . 'ceu TEXT NULL, '
                . 'course_owner TEXT NULL, '
                . 'moodle_url TEXT NULL, '
                . 'recommended_for TEXT NOT NULL, '
                . 'questionnaire_id INTEGER NULL, '
                . 'min_score INTEGER NOT NULL DEFAULT 0, '
                . 'max_score INTEGER NOT NULL DEFAULT 100, '
                . 'is_active INTEGER NOT NULL DEFAULT 1, '
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
            try {
                $pdo->exec('ALTER TABLE course_catalogue ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
            } catch (Throwable $e) {
                // Ignore duplicate-column errors.
            }
            ensure_course_catalogue_metadata_columns($pdo, $driver);
            seed_standard_course_catalogue($pdo);
            return;
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS course_catalogue ('
            . 'id INT AUTO_INCREMENT PRIMARY KEY, '
            . 'code VARCHAR(50) NOT NULL, '
            . 'title VARCHAR(255) NOT NULL, '
            . 'course_objective TEXT NULL, '
            . 'expected_competency TEXT NULL, '
            . 'thematic_area VARCHAR(255) NULL, '
            . 'mode_of_delivery VARCHAR(100) NULL, '
            . 'duration VARCHAR(50) NULL, '
            . 'ceu VARCHAR(50) NULL, '
            . 'course_owner VARCHAR(100) NULL, '
            . 'moodle_url VARCHAR(255) NULL, '
            . 'recommended_for VARCHAR(100) NOT NULL, '
            . 'questionnaire_id INT NULL, '
            . 'min_score INT NOT NULL DEFAULT 0, '
            . 'max_score INT NOT NULL DEFAULT 100, '
            . 'is_active TINYINT(1) NOT NULL DEFAULT 1, '
            . 'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, '
            . 'INDEX idx_course_catalogue_match (recommended_for, questionnaire_id, min_score, max_score)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        try {
            $pdo->exec('ALTER TABLE course_catalogue ADD COLUMN questionnaire_id INT NULL AFTER recommended_for');
        } catch (Throwable $e) {
            // Ignore duplicate-column errors.
        }
        try {
            $columnsStmt = $pdo->query('SHOW COLUMNS FROM course_catalogue');
            if ($columnsStmt) {
                while ($column = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
                    if ((string)($column['Field'] ?? '') !== 'recommended_for') {
                        continue;
                    }
                    $type = strtolower((string)($column['Type'] ?? ''));
                    $isShortVarchar = preg_match('/varchar\((\d+)\)/', $type, $matches) === 1 && (int)$matches[1] < 100;
                    if (!str_starts_with($type, 'varchar(') || $isShortVarchar) {
                        $pdo->exec('ALTER TABLE course_catalogue MODIFY COLUMN recommended_for VARCHAR(100) NOT NULL');
                    }
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log('ensure_course_recommendation_schema recommended_for migration failed: ' . $e->getMessage());
        }
        try {
            $pdo->exec('ALTER TABLE course_catalogue MODIFY COLUMN max_score INT NOT NULL DEFAULT 100');
        } catch (Throwable $e) {
            // Ignore engines where the column already has the desired definition.
        }
        try {
            $pdo->exec('CREATE INDEX idx_course_catalogue_match ON course_catalogue (recommended_for, questionnaire_id, min_score, max_score)');
        } catch (Throwable $e) {
            // Ignore duplicate index errors.
        }
        try {
            $pdo->exec('ALTER TABLE course_catalogue ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER max_score');
        } catch (Throwable $e) {
            // Ignore duplicate-column errors.
        }
        ensure_course_catalogue_metadata_columns($pdo, $driver);
        seed_standard_course_catalogue($pdo);

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
 * Add catalogue metadata columns needed for the imported course list.
 */
function ensure_course_catalogue_metadata_columns(PDO $pdo, string $driver): void
{
    $columns = $driver === 'sqlite'
        ? [
            'course_objective' => 'TEXT NULL',
            'expected_competency' => 'TEXT NULL',
            'thematic_area' => 'TEXT NULL',
            'mode_of_delivery' => 'TEXT NULL',
            'duration' => 'TEXT NULL',
            'ceu' => 'TEXT NULL',
            'course_owner' => 'TEXT NULL',
        ]
        : [
            'course_objective' => 'TEXT NULL AFTER title',
            'expected_competency' => 'TEXT NULL AFTER course_objective',
            'thematic_area' => 'VARCHAR(255) NULL AFTER expected_competency',
            'mode_of_delivery' => 'VARCHAR(100) NULL AFTER thematic_area',
            'duration' => 'VARCHAR(50) NULL AFTER mode_of_delivery',
            'ceu' => 'VARCHAR(50) NULL AFTER duration',
            'course_owner' => 'VARCHAR(100) NULL AFTER ceu',
        ];

    foreach ($columns as $column => $definition) {
        try {
            $pdo->exec(sprintf('ALTER TABLE course_catalogue ADD COLUMN %s %s', $column, $definition));
        } catch (Throwable $e) {
            // Ignore duplicate-column errors.
        }
    }
}

/**
 * @return array<int,array<string,string>>
 */
function standard_course_catalogue_entries(): array
{
    $path = __DIR__ . '/../data/course_catalogue.php';
    $entries = is_file($path) ? require $path : [];

    return is_array($entries) ? $entries : [];
}

function normalize_course_catalogue_text(string $value): string
{
    return trim((string)preg_replace('/\s+/u', ' ', $value));
}

function derive_course_catalogue_code(string $title, int $position): string
{
    $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stopWords = ['AND', 'OF', 'THE', 'FOR', 'IN', 'ON', 'TO', 'WITH', 'A', 'AN'];
    $letters = '';
    foreach ($words as $word) {
        if (in_array($word, $stopWords, true)) {
            continue;
        }
        $letters .= substr($word, 0, 1);
    }
    $letters = substr($letters !== '' ? $letters : 'COURSE', 0, 8);

    return sprintf('%s-%03d', $letters, $position);
}

function derive_course_catalogue_recommended_for(string $title, string $thematicArea): string
{
    $combined = strtolower($title . ' ' . $thematicArea);
    if (str_contains($combined, 'financ')) {
        return 'finance';
    }
    if (str_contains($combined, 'leadership') || str_contains($combined, 'governance') || str_contains($combined, 'quality improvement') || str_contains($combined, 'preceptor') || str_contains($combined, 'mentorship')) {
        return 'manager';
    }
    if (str_contains($combined, 'warehouse') || str_contains($combined, 'inventory') || str_contains($combined, 'logistics') || str_contains($combined, 'supply chain') || str_contains($combined, 'scm') || str_contains($combined, 'procurement') || str_contains($combined, 'distribution') || str_contains($combined, 'commodity') || str_contains($combined, 'quantification') || str_contains($combined, 'medical device') || str_contains($combined, 'oxygen') || str_contains($combined, 'pharmaceutical services management') || str_contains($combined, 'digital solution')) {
        return 'wim';
    }

    return 'expert';
}

function seed_standard_course_catalogue(PDO $pdo): void
{
    static $seeded = [];
    $cacheKey = spl_object_id($pdo);
    if (isset($seeded[$cacheKey])) {
        return;
    }
    $seeded[$cacheKey] = true;

    try {
        $pdo->exec(
            "DELETE FROM course_catalogue "
            . "WHERE code IN ('FIN-101', 'ICT-201', 'HRM-110', 'GEN-050', 'LEAD-300', 'SAFE-210') "
            . "AND moodle_url LIKE 'https://moodle.example.com/course/%'"
        );

        $find = $pdo->prepare('SELECT id FROM course_catalogue WHERE title = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO course_catalogue ('
            . 'code, title, course_objective, expected_competency, thematic_area, mode_of_delivery, duration, ceu, course_owner, moodle_url, recommended_for, questionnaire_id, min_score, max_score, is_active'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, 100, 1)'
        );

        foreach (standard_course_catalogue_entries() as $index => $entry) {
            $title = normalize_course_catalogue_text((string)($entry['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $find->execute([$title]);
            if ($find->fetchColumn()) {
                continue;
            }

            $thematicArea = normalize_course_catalogue_text((string)($entry['thematic_area'] ?? ''));
            $insert->execute([
                derive_course_catalogue_code($title, $index + 1),
                $title,
                normalize_course_catalogue_text((string)($entry['course_objective'] ?? '')),
                normalize_course_catalogue_text((string)($entry['expected_competency'] ?? '')),
                $thematicArea,
                normalize_course_catalogue_text((string)($entry['mode_of_delivery'] ?? '')),
                normalize_course_catalogue_text((string)($entry['duration'] ?? '')),
                normalize_course_catalogue_text((string)($entry['ceu'] ?? '')),
                normalize_course_catalogue_text((string)($entry['course_owner'] ?? '')),
                null,
                derive_course_catalogue_recommended_for($title, $thematicArea),
            ]);
        }
    } catch (PDOException $e) {
        error_log('seed_standard_course_catalogue failed: ' . $e->getMessage());
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
                'SELECT id, code, title, course_objective, expected_competency, thematic_area, mode_of_delivery, duration, ceu, course_owner, moodle_url, recommended_for, questionnaire_id, min_score, max_score '
                . 'FROM course_catalogue '
                . 'WHERE recommended_for = ? AND min_score <= ? AND max_score >= ? AND is_active = 1 '
                . 'AND (questionnaire_id = ? OR questionnaire_id IS NULL) '
                . 'ORDER BY questionnaire_id IS NULL ASC, min_score ASC, title ASC'
            );
            $stmt->execute([$workFunction, $score, $score, $questionnaireId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $stmt = $pdo->prepare(
            'SELECT id, code, title, course_objective, expected_competency, thematic_area, mode_of_delivery, duration, ceu, course_owner, moodle_url, recommended_for, questionnaire_id, min_score, max_score '
            . 'FROM course_catalogue '
            . 'WHERE recommended_for = ? AND min_score <= ? AND max_score >= ? AND is_active = 1 '
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
