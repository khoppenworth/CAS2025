<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $filters
 * @return array{business_role:string,directorate:string,user_id:int,work_function:string}
 */
function analytics_data_viewer_normalize_filters(array $filters): array
{
    return [
        'business_role' => trim((string)($filters['business_role'] ?? '')),
        'directorate' => trim((string)($filters['directorate'] ?? '')),
        'user_id' => isset($filters['user_id']) ? max(0, (int)$filters['user_id']) : 0,
        'work_function' => trim((string)($filters['work_function'] ?? '')),
    ];
}

/**
 * Supervisors are restricted to their own directorate (or department fallback).
 *
 * @param array<string, mixed> $viewer
 * @param array<string, mixed> $filters
 * @return array{business_role:string,directorate:string,user_id:int,work_function:string}
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
    $isSupervisorDepartmentFallback = trim((string)($viewer['role'] ?? '')) === 'supervisor'
        && trim((string)($viewer['directorate'] ?? '')) === ''
        && trim((string)($viewer['department'] ?? '')) !== '';

    $sql = 'SELECT qr.id AS response_id, qr.questionnaire_id, q.title AS questionnaire_title, '
        . 'u.id AS user_id, u.username, u.full_name, u.department, u.directorate, u.work_function, '
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
        if ($isSupervisorDepartmentFallback) {
            $where[] = 'COALESCE(NULLIF(u.directorate, \'\'), NULLIF(u.department, \'\'), \'Unknown\') = ?';
        } else {
            $where[] = 'COALESCE(NULLIF(u.directorate, \'\'), \'Unknown\') = ?';
        }
        $params[] = $scopeFilters['directorate'];
    }
    if ($scopeFilters['work_function'] !== '') {
        $where[] = 'COALESCE(NULLIF(u.work_function, \'\'), \'Unspecified\') = ?';
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
    if ($dateFrom !== '') {
        $where[] = 'DATE(qr.created_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(qr.created_at) <= ?';
        $params[] = $dateTo;
    }
    if ($where) {
        $sql .= 'WHERE ' . implode(' AND ', $where) . ' ';
    }

    return [[$sql, $scopeFilters], $params];
}
