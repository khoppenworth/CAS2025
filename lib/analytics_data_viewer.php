<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $filters
 * @return array{business_role:string,department:string,directorate:string,team:string,user_id:int,work_function:string}
 */
function analytics_data_viewer_normalize_filters(array $filters): array
{
    return [
        'business_role' => trim((string)($filters['business_role'] ?? '')),
        'department' => trim((string)($filters['department'] ?? '')),
        'directorate' => trim((string)($filters['directorate'] ?? '')),
        'team' => trim((string)($filters['team'] ?? '')),
        'user_id' => isset($filters['user_id']) ? max(0, (int)$filters['user_id']) : 0,
        'work_function' => trim((string)($filters['work_function'] ?? '')),
    ];
}

/**
 * Supervisors are restricted to their own directorate (or department fallback).
 *
 * @param array<string, mixed> $viewer
 * @param array<string, mixed> $filters
 * @return array{business_role:string,department:string,directorate:string,team:string,user_id:int,work_function:string}
 */
function analytics_data_viewer_apply_scope(array $viewer, array $filters): array
{
    $normalized = analytics_data_viewer_normalize_filters($filters);
    $role = trim((string)($viewer['role'] ?? ''));
    if ($role !== 'supervisor') {
        return $normalized;
    }

    $viewerDirectorate = trim((string)($viewer['directorate'] ?? ''));
    if ($viewerDirectorate === '') {
        $viewerDirectorate = trim((string)($viewer['department'] ?? ''));
    }
    if ($viewerDirectorate !== '') {
        $normalized['directorate'] = $viewerDirectorate;
    }

    return $normalized;
}

function analytics_data_viewer_valid_date_string(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

function analytics_data_viewer_next_day_start(string $date): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt instanceof DateTimeImmutable) {
        return $date . ' 23:59:59';
    }

    return $dt->modify('+1 day')->format('Y-m-d 00:00:00');
}

function analytics_data_viewer_csv_safe_cell(string $value): string
{
    if ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

/**
 * @param array<string, mixed> $viewer
 * @param array<string, mixed> $filters
 * @return array{array<int, array<string, mixed>>, array<int, mixed>}
 */
function analytics_data_viewer_query(PDO $pdo, array $viewer, array $filters, int $questionnaireId = 0, string $statusFilter = '', string $dateFrom = '', string $dateTo = ''): array
{
    $scopeFilters = analytics_data_viewer_apply_scope($viewer, $filters);
    $directorateExpr = 'COALESCE(NULLIF(u.directorate, \'\'), NULLIF(u.department, \'\'), \'Unknown\')';
    $workFunctionExpr = 'COALESCE(NULLIF(u.work_function, \'\'), NULLIF(u.department, \'\'), \'Unspecified\')';

    $sql = 'SELECT qr.id AS response_id, qr.questionnaire_id, q.title AS questionnaire_title, '
        . 'u.id AS user_id, u.username, u.full_name, u.department, u.cadre AS team, '
        . $directorateExpr . ' AS directorate, '
        . $workFunctionExpr . ' AS work_function, '
        . 'COALESCE(NULLIF(u.business_role, \'\'), NULLIF(u.profile_role, \'\'), \'Unspecified\') AS business_role, '
        . 'qr.status, qr.score, qr.created_at, qr.reviewed_at '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . 'LEFT JOIN questionnaire q ON q.id = qr.questionnaire_id ';

    $where = [];
    $params = [];
    if ($questionnaireId > 0) {
        $where[] = 'qr.questionnaire_id = ?';
        $params[] = $questionnaireId;
    }
    if ($scopeFilters['business_role'] !== '') {
        $where[] = 'COALESCE(NULLIF(u.business_role, \'\'), NULLIF(u.profile_role, \'\'), \'Unspecified\') = ?';
        $params[] = $scopeFilters['business_role'];
    }
    if ($scopeFilters['directorate'] !== '') {
        $where[] = $directorateExpr . ' = ?';
        $params[] = $scopeFilters['directorate'];
    }
    if ($scopeFilters['department'] !== '') {
        $where[] = 'u.department = ?';
        $params[] = $scopeFilters['department'];
    }
    if ($scopeFilters['team'] !== '') {
        $where[] = 'u.cadre = ?';
        $params[] = $scopeFilters['team'];
    }
    if ($scopeFilters['work_function'] !== '') {
        $where[] = $workFunctionExpr . ' = ?';
        $params[] = $scopeFilters['work_function'];
    }
    if ($scopeFilters['user_id'] > 0) {
        $where[] = 'u.id = ?';
        $params[] = $scopeFilters['user_id'];
    }
    if ($statusFilter !== '') {
        $where[] = 'qr.status = ?';
        $params[] = $statusFilter;
    }
    $dateFrom = analytics_data_viewer_valid_date_string($dateFrom);
    $dateTo = analytics_data_viewer_valid_date_string($dateTo);
    if ($dateFrom !== '') {
        $where[] = 'qr.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = 'qr.created_at < ?';
        $params[] = analytics_data_viewer_next_day_start($dateTo);
    }
    if ($where) {
        $sql .= 'WHERE ' . implode(' AND ', $where) . ' ';
    }

    return [[$sql, $scopeFilters], $params];
}
