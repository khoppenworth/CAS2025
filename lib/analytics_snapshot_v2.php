<?php

declare(strict_types=1);

require_once __DIR__ . '/scoring.php';
require_once __DIR__ . '/competency_framework.php';

function analytics_snapshot_v2_table_exists(PDO $pdo, string $table): bool
{
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$table]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(1) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        error_log('analytics_snapshot_v2_table_exists failed: ' . $e->getMessage());
        return false;
    }
}

function analytics_snapshot_v2_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if (strcasecmp((string)($row['name'] ?? ''), $column) === 0) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        error_log('analytics_snapshot_v2_column_exists failed: ' . $e->getMessage());
        return false;
    }
}

function analytics_snapshot_v2_required_benchmark(PDO $pdo): float
{
    if (!analytics_snapshot_v2_table_exists($pdo, 'competency_benchmark_policy')) {
        return 80.0;
    }

    try {
        $stmt = $pdo->query(
            "SELECT required_pct FROM competency_benchmark_policy "
            . "WHERE scope_type = 'organization' AND (scope_id IS NULL OR scope_id = '') "
            . "ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $raw = $stmt->fetchColumn();
            if ($raw !== false) {
                return max(0.0, min(100.0, (float)$raw));
            }
        }
    } catch (Throwable $e) {
        error_log('analytics_snapshot_v2_required_benchmark failed: ' . $e->getMessage());
    }

    return 80.0;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function analytics_snapshot_v2_enrich_rows(array $rows, float $requiredBenchmark): array
{
    $enriched = [];
    foreach ($rows as $row) {
        $score = isset($row['avg_score']) && $row['avg_score'] !== null ? (float)$row['avg_score'] : null;
        $row['competency_level'] = questionnaire_competency_level($score);
        $row['gap_pct_100'] = questionnaire_competency_gap($score, null);
        $row['gap_pct_required'] = questionnaire_competency_gap($score, $requiredBenchmark);
        $row['recommendation'] = questionnaire_competency_recommendation($score);
        $enriched[] = $row;
    }
    return $enriched;
}

/**
 * @return array<int, array<string, mixed>>
 */
function analytics_snapshot_v2_build_critical_gaps(array $departmentRows, array $roleRows): array
{
    $candidates = [];

    foreach ($departmentRows as $row) {
        $score = isset($row['avg_score']) ? (float)$row['avg_score'] : null;
        if ($score === null || $score >= 60.0) {
            continue;
        }
        $candidates[] = [
            'dimension' => 'department',
            'name' => (string)($row['department'] ?? 'Unknown'),
            'score' => $score,
            'gap_level' => $score < 50.0 ? 'High' : 'Moderate',
        ];
    }

    foreach ($roleRows as $row) {
        $score = isset($row['avg_score']) ? (float)$row['avg_score'] : null;
        if ($score === null || $score >= 60.0) {
            continue;
        }
        $candidates[] = [
            'dimension' => 'business_role',
            'name' => (string)($row['business_role'] ?? 'Unspecified'),
            'score' => $score,
            'gap_level' => $score < 50.0 ? 'High' : 'Moderate',
        ];
    }

    usort($candidates, static fn(array $a, array $b): int => ($a['score'] <=> $b['score']));
    $ranked = [];
    $rank = 1;
    foreach ($candidates as $candidate) {
        $candidate['priority_rank'] = $rank++;
        $ranked[] = $candidate;
        if (count($ranked) >= 10) {
            break;
        }
    }
    return $ranked;
}

/**
 * @return array<string, mixed>
 */
function analytics_snapshot_v2_generate(PDO $pdo, ?int $questionnaireId = null, ?int $generatedBy = null, array $filters = []): array
{
    $params = [];
    $whereClauses = ["qr.status IN ('submitted','approved','approved_late')"];
    if ($questionnaireId !== null && $questionnaireId > 0) {
        $whereClauses[] = 'qr.questionnaire_id = ?';
        $params[] = $questionnaireId;
    }
    $normalizedFilters = [
        'business_role' => trim((string)($filters['business_role'] ?? '')),
        'directorate' => trim((string)($filters['directorate'] ?? '')),
        'user_id' => isset($filters['user_id']) ? max(0, (int)$filters['user_id']) : 0,
        'work_function' => trim((string)($filters['work_function'] ?? '')),
    ];
    if ($normalizedFilters['business_role'] !== '') {
        $whereClauses[] = 'COALESCE(NULLIF(u.business_role, \'\'), NULLIF(u.profile_role, \'\'), \'Unspecified\') = ?';
        $params[] = $normalizedFilters['business_role'];
    }
    if ($normalizedFilters['directorate'] !== '') {
        $whereClauses[] = 'COALESCE(NULLIF(u.directorate, \'\'), \'Unknown\') = ?';
        $params[] = $normalizedFilters['directorate'];
    }
    if ($normalizedFilters['user_id'] > 0) {
        $whereClauses[] = 'u.id = ?';
        $params[] = $normalizedFilters['user_id'];
    }
    if ($normalizedFilters['work_function'] !== '') {
        $whereClauses[] = 'COALESCE(NULLIF(u.work_function, \'\'), \'Unspecified\') = ?';
        $params[] = $normalizedFilters['work_function'];
    }
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

    $summaryStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total_responses, COUNT(DISTINCT qr.user_id) AS total_participants, AVG(qr.score) AS average_score '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . $whereSql
    );
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $departmentStmt = $pdo->prepare(
        'SELECT COALESCE(NULLIF(u.department, \'\'), \'Unknown\') AS department, COUNT(*) AS total_responses, AVG(qr.score) AS avg_score '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . $whereSql . ' '
        . 'GROUP BY COALESCE(NULLIF(u.department, \'\'), \'Unknown\') '
        . 'ORDER BY avg_score DESC'
    );
    $departmentStmt->execute($params);
    $departmentRows = $departmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $roleStmt = $pdo->prepare(
        'SELECT COALESCE(NULLIF(u.business_role, \'\'), NULLIF(u.profile_role, \'\'), \'Unspecified\') AS business_role, '
        . 'COUNT(*) AS total_responses, AVG(qr.score) AS avg_score '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . $whereSql . ' '
        . 'GROUP BY COALESCE(NULLIF(u.business_role, \'\'), NULLIF(u.profile_role, \'\'), \'Unspecified\') '
        . 'ORDER BY avg_score DESC'
    );
    $roleStmt->execute($params);
    $roleRows = $roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $requiredBenchmark = analytics_snapshot_v2_required_benchmark($pdo);
    $departmentRows = analytics_snapshot_v2_enrich_rows($departmentRows, $requiredBenchmark);
    $roleRows = analytics_snapshot_v2_enrich_rows($roleRows, $requiredBenchmark);
    $criticalGaps = analytics_snapshot_v2_build_critical_gaps($departmentRows, $roleRows);

    $overallScore = isset($summary['average_score']) && $summary['average_score'] !== null ? (float)$summary['average_score'] : null;
    $summaryPayload = [
        'total_responses' => (int)($summary['total_responses'] ?? 0),
        'total_participants' => (int)($summary['total_participants'] ?? 0),
        'average_score' => $overallScore,
        'competency_level' => questionnaire_competency_level($overallScore),
        'gap_pct_100' => questionnaire_competency_gap($overallScore, null),
        'gap_pct_required' => questionnaire_competency_gap($overallScore, $requiredBenchmark),
        'recommendation' => questionnaire_competency_recommendation($overallScore),
        'required_benchmark' => $requiredBenchmark,
    ];

    $topStrengths = array_slice($departmentRows, 0, 3);
    $topGaps = array_slice(array_reverse($departmentRows), 0, 3);

    $snapshot = [
        'questionnaire_id' => $questionnaireId,
        'filters' => $normalizedFilters,
        'summary' => $summaryPayload,
        'department_analysis' => $departmentRows,
        'role_analysis' => $roleRows,
        'critical_gaps' => $criticalGaps,
        'top_strengths' => $topStrengths,
        'top_gaps' => $topGaps,
        'generated_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
    ];

    if (analytics_snapshot_v2_table_exists($pdo, 'analytics_report_snapshot_v2')) {
        $hasFiltersColumn = analytics_snapshot_v2_column_exists($pdo, 'analytics_report_snapshot_v2', 'filters_json');
        if ($hasFiltersColumn) {
            $insert = $pdo->prepare(
                'INSERT INTO analytics_report_snapshot_v2 (questionnaire_id, generated_by, status, locked, filters_json, summary_json, details_json, generated_at) '
                . 'VALUES (:questionnaire_id, :generated_by, :status, :locked, :filters_json, :summary_json, :details_json, :generated_at)'
            );
            $insert->execute([
                ':questionnaire_id' => $questionnaireId,
                ':generated_by' => $generatedBy,
                ':status' => 'draft',
                ':locked' => 0,
                ':filters_json' => json_encode($normalizedFilters),
                ':summary_json' => json_encode($summaryPayload),
                ':details_json' => json_encode([
                    'department_analysis' => $departmentRows,
                    'role_analysis' => $roleRows,
                    'critical_gaps' => $criticalGaps,
                    'top_strengths' => $topStrengths,
                    'top_gaps' => $topGaps,
                ]),
                ':generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO analytics_report_snapshot_v2 (questionnaire_id, generated_by, status, locked, summary_json, details_json, generated_at) '
                . 'VALUES (:questionnaire_id, :generated_by, :status, :locked, :summary_json, :details_json, :generated_at)'
            );
            $insert->execute([
                ':questionnaire_id' => $questionnaireId,
                ':generated_by' => $generatedBy,
                ':status' => 'draft',
                ':locked' => 0,
                ':summary_json' => json_encode($summaryPayload),
                ':details_json' => json_encode([
                    'department_analysis' => $departmentRows,
                    'role_analysis' => $roleRows,
                    'critical_gaps' => $criticalGaps,
                    'top_strengths' => $topStrengths,
                    'top_gaps' => $topGaps,
                ]),
                ':generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
        }
        $snapshot['snapshot_id'] = (int)$pdo->lastInsertId();
    }

    return $snapshot;
}

function analytics_snapshot_v2_finalize(PDO $pdo, int $snapshotId): bool
{
    if ($snapshotId <= 0 || !analytics_snapshot_v2_table_exists($pdo, 'analytics_report_snapshot_v2')) {
        return false;
    }
    $stmt = $pdo->prepare(
        "UPDATE analytics_report_snapshot_v2 SET locked = 1, status = 'finalized', finalized_at = CURRENT_TIMESTAMP WHERE id = ? AND locked = 0"
    );
    $stmt->execute([$snapshotId]);
    return $stmt->rowCount() > 0;
}
