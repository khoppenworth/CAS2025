<?php
declare(strict_types=1);

if (defined('APP_DEPARTMENT_CATALOG_SYNC_LOADED')) {
    return;
}
define('APP_DEPARTMENT_CATALOG_SYNC_LOADED', true);

/** @return array{version:int,exported_at:string,departments:array<int,array<string,mixed>>,teams:array<int,array<string,mixed>>} */
function department_catalog_export_payload(PDO $pdo): array
{
    $departments = [];
    foreach (department_catalog($pdo) as $slug => $record) {
        $departments[] = [
            'slug' => $slug,
            'label' => (string)($record['label'] ?? ''),
            'sort_order' => (int)($record['sort_order'] ?? 0),
            'archived_at' => normalize_catalog_sync_archived_at($record['archived_at'] ?? null),
        ];
    }

    $teams = [];
    foreach (department_team_catalog($pdo) as $slug => $record) {
        $teams[] = [
            'slug' => $slug,
            'department_slug' => (string)($record['department_slug'] ?? ''),
            'label' => (string)($record['label'] ?? ''),
            'sort_order' => (int)($record['sort_order'] ?? 0),
            'archived_at' => normalize_catalog_sync_archived_at($record['archived_at'] ?? null),
        ];
    }

    return [
        'version' => 1,
        'exported_at' => gmdate('c'),
        'departments' => $departments,
        'teams' => $teams,
    ];
}

function department_catalog_export_json(PDO $pdo): string
{
    return (string)json_encode(department_catalog_export_payload($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** @return array<string,mixed> */
function parse_department_catalog_import_json(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('The import file must be valid JSON.');
    }
    return $data;
}

/** @return array{valid:bool,errors:array<int,string>,departments:array<string,array<string,mixed>>,teams:array<string,array<string,mixed>>,stats:array<string,int>} */
function validate_department_catalog_import_payload(array $payload): array
{
    $errors = [];
    $departmentsRaw = $payload['departments'] ?? null;
    $teamsRaw = $payload['teams'] ?? null;
    if (!is_array($departmentsRaw)) {
        $errors[] = 'The import file must include a departments array.';
        $departmentsRaw = [];
    }
    if (!is_array($teamsRaw)) {
        $errors[] = 'The import file must include a teams array.';
        $teamsRaw = [];
    }

    $departments = [];
    foreach ($departmentsRaw as $index => $row) {
        if (!is_array($row)) {
            $errors[] = 'Department row ' . ((int)$index + 1) . ' must be an object.';
            continue;
        }
        $slug = trim((string)($row['slug'] ?? ''));
        $label = trim((string)($row['label'] ?? ''));
        if ($slug === '') {
            $errors[] = 'Department row ' . ((int)$index + 1) . ' has an empty slug.';
            continue;
        }
        if (!preg_match('/^[a-z0-9_]{1,120}$/', $slug)) {
            $errors[] = "Department slug {$slug} must use lowercase letters, numbers, and underscores only.";
        }
        if ($label === '') {
            $errors[] = "Department {$slug} has an empty label.";
        }
        if (isset($departments[$slug])) {
            $errors[] = "Department slug {$slug} appears more than once.";
            continue;
        }
        $departments[$slug] = [
            'slug' => $slug,
            'label' => $label,
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'archived_at' => normalize_catalog_sync_archived_at($row['archived_at'] ?? null),
        ];
    }

    $teams = [];
    foreach ($teamsRaw as $index => $row) {
        if (!is_array($row)) {
            $errors[] = 'Team row ' . ((int)$index + 1) . ' must be an object.';
            continue;
        }
        $slug = trim((string)($row['slug'] ?? ''));
        $departmentSlug = trim((string)($row['department_slug'] ?? ''));
        $label = trim((string)($row['label'] ?? ''));
        if ($slug === '') {
            $errors[] = 'Team row ' . ((int)$index + 1) . ' has an empty slug.';
            continue;
        }
        if (!preg_match('/^[a-z0-9_]{1,120}$/', $slug)) {
            $errors[] = "Team slug {$slug} must use lowercase letters, numbers, and underscores only.";
        }
        if ($departmentSlug === '' || !isset($departments[$departmentSlug])) {
            $errors[] = "Team {$slug} references a missing department_slug.";
        }
        if ($label === '') {
            $errors[] = "Team {$slug} has an empty label.";
        }
        if (isset($teams[$slug])) {
            $errors[] = "Team slug {$slug} appears more than once.";
            continue;
        }
        $teams[$slug] = [
            'slug' => $slug,
            'department_slug' => $departmentSlug,
            'label' => $label,
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'archived_at' => normalize_catalog_sync_archived_at($row['archived_at'] ?? null),
        ];
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'departments' => $departments,
        'teams' => $teams,
        'stats' => ['departments' => count($departments), 'teams' => count($teams)],
    ];
}

/** @return array{departments:array<string,array<int,array<string,mixed>>>,teams:array<string,array<int,array<string,mixed>>>} */
function preview_department_catalog_import(PDO $pdo, array $departments, array $teams, bool $archiveMissing): array
{
    $currentDepartments = department_catalog($pdo);
    $currentTeams = department_team_catalog($pdo);
    $changes = [
        'departments' => ['create' => [], 'update' => [], 'archive_missing' => [], 'unchanged' => []],
        'teams' => ['create' => [], 'update' => [], 'archive_missing' => [], 'unchanged' => []],
    ];

    foreach ($departments as $slug => $incoming) {
        if (!isset($currentDepartments[$slug])) {
            $changes['departments']['create'][] = $incoming;
            continue;
        }
        if (catalog_sync_department_differs($currentDepartments[$slug], $incoming)) {
            $changes['departments']['update'][] = ['slug' => $slug, 'from' => $currentDepartments[$slug], 'to' => $incoming];
        } else {
            $changes['departments']['unchanged'][] = $incoming;
        }
    }
    if ($archiveMissing) {
        foreach ($currentDepartments as $slug => $record) {
            if (!isset($departments[$slug]) && normalize_catalog_sync_archived_at($record['archived_at'] ?? null) === null) {
                $changes['departments']['archive_missing'][] = ['slug' => $slug, 'label' => (string)($record['label'] ?? '')];
            }
        }
    }

    foreach ($teams as $slug => $incoming) {
        if (!isset($currentTeams[$slug])) {
            $changes['teams']['create'][] = $incoming;
            continue;
        }
        if (catalog_sync_team_differs($currentTeams[$slug], $incoming)) {
            $changes['teams']['update'][] = ['slug' => $slug, 'from' => $currentTeams[$slug], 'to' => $incoming];
        } else {
            $changes['teams']['unchanged'][] = $incoming;
        }
    }
    if ($archiveMissing) {
        foreach ($currentTeams as $slug => $record) {
            if (!isset($teams[$slug]) && normalize_catalog_sync_archived_at($record['archived_at'] ?? null) === null) {
                $changes['teams']['archive_missing'][] = ['slug' => $slug, 'label' => (string)($record['label'] ?? ''), 'department_slug' => (string)($record['department_slug'] ?? '')];
            }
        }
    }

    return $changes;
}

/** @return array{departments:array<string,int>,teams:array<string,int>} */
function apply_department_catalog_import(PDO $pdo, array $departments, array $teams, bool $archiveMissing): array
{
    $preview = preview_department_catalog_import($pdo, $departments, $teams, $archiveMissing);
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $departmentSql = 'INSERT INTO department_catalog (slug, label, sort_order, archived_at) VALUES (?, ?, ?, ?) '
            . 'ON CONFLICT(slug) DO UPDATE SET label = excluded.label, sort_order = excluded.sort_order, archived_at = excluded.archived_at';
        $teamSql = 'INSERT INTO department_team_catalog (slug, department_slug, label, sort_order, archived_at) VALUES (?, ?, ?, ?, ?) '
            . 'ON CONFLICT(slug) DO UPDATE SET department_slug = excluded.department_slug, label = excluded.label, sort_order = excluded.sort_order, archived_at = excluded.archived_at';
    } else {
        $departmentSql = 'INSERT INTO department_catalog (slug, label, sort_order, archived_at) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order), archived_at = VALUES(archived_at)';
        $teamSql = 'INSERT INTO department_team_catalog (slug, department_slug, label, sort_order, archived_at) VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE department_slug = VALUES(department_slug), label = VALUES(label), sort_order = VALUES(sort_order), archived_at = VALUES(archived_at)';
    }
    $departmentUpsert = $pdo->prepare($departmentSql);
    $teamUpsert = $pdo->prepare($teamSql);

    $pdo->beginTransaction();
    foreach ($departments as $row) {
        $departmentUpsert->execute([$row['slug'], $row['label'], (int)$row['sort_order'], $row['archived_at']]);
    }
    foreach ($teams as $row) {
        $teamUpsert->execute([$row['slug'], $row['department_slug'], $row['label'], (int)$row['sort_order'], $row['archived_at']]);
    }
    if ($archiveMissing) {
        $archiveDepartment = $pdo->prepare('UPDATE department_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug = ? AND archived_at IS NULL');
        foreach ($preview['departments']['archive_missing'] as $row) {
            $archiveDepartment->execute([$row['slug']]);
        }
        $archiveTeam = $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug = ? AND archived_at IS NULL');
        foreach ($preview['teams']['archive_missing'] as $row) {
            $archiveTeam->execute([$row['slug']]);
        }
    }
    $pdo->commit();

    return [
        'departments' => [
            'created' => count($preview['departments']['create']),
            'updated' => count($preview['departments']['update']),
            'archived' => count($preview['departments']['archive_missing']),
        ],
        'teams' => [
            'created' => count($preview['teams']['create']),
            'updated' => count($preview['teams']['update']),
            'archived' => count($preview['teams']['archive_missing']),
        ],
    ];
}

function normalize_catalog_sync_archived_at($value): ?string
{
    $value = trim((string)($value ?? ''));
    return $value === '' ? null : $value;
}

function catalog_sync_department_differs(array $current, array $incoming): bool
{
    return trim((string)($current['label'] ?? '')) !== (string)$incoming['label']
        || (int)($current['sort_order'] ?? 0) !== (int)$incoming['sort_order']
        || normalize_catalog_sync_archived_at($current['archived_at'] ?? null) !== normalize_catalog_sync_archived_at($incoming['archived_at'] ?? null);
}

function catalog_sync_team_differs(array $current, array $incoming): bool
{
    return trim((string)($current['department_slug'] ?? '')) !== (string)$incoming['department_slug']
        || trim((string)($current['label'] ?? '')) !== (string)$incoming['label']
        || (int)($current['sort_order'] ?? 0) !== (int)$incoming['sort_order']
        || normalize_catalog_sync_archived_at($current['archived_at'] ?? null) !== normalize_catalog_sync_archived_at($incoming['archived_at'] ?? null);
}
