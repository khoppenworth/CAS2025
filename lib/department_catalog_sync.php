<?php
declare(strict_types=1);

if (defined('APP_DEPARTMENT_CATALOG_SYNC_LOADED')) {
    return;
}
define('APP_DEPARTMENT_CATALOG_SYNC_LOADED', true);
const DEPARTMENT_CATALOG_SYNC_MAX_LABEL_LENGTH = 255;
const DEPARTMENT_CATALOG_SYNC_MAX_SLUG_LENGTH = 120;
const DEPARTMENT_CATALOG_SYNC_MAX_UPLOAD_BYTES = 2097152;

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
    try {
        return json_encode(department_catalog_export_payload($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Unable to encode department/team catalog export JSON.', 0, $e);
    }
}

/** @return array<string,mixed> */
function parse_department_catalog_import_json(string $json): array
{
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('The import file must be valid JSON: ' . $e->getMessage(), 0, $e);
    }
    if (!is_array($data)) {
        throw new InvalidArgumentException('The import file must be a JSON object.');
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
    if ($departmentsRaw === [] && $teamsRaw === []) {
        $errors[] = 'The import file does not contain any departments or teams.';
    }

    $departments = [];
    $departmentLabels = [];
    foreach ($departmentsRaw as $index => $row) {
        if (!is_array($row)) {
            $errors[] = 'Department row ' . ((int)$index + 1) . ' must be an object.';
            continue;
        }
        $label = trim((string)($row['label'] ?? $row['name'] ?? $row['department'] ?? ''));
        $slugSource = trim((string)($row['slug'] ?? ''));
        $slug = normalize_catalog_sync_slug($slugSource, $label);
        if ($slug === '') {
            $errors[] = 'Department row ' . ((int)$index + 1) . ' needs either a slug or a label/name that can be converted to a slug.';
            continue;
        }
        if ($label === '') {
            $errors[] = "Department {$slug} has an empty label.";
        }
        if (strlen($label) > DEPARTMENT_CATALOG_SYNC_MAX_LABEL_LENGTH) {
            $errors[] = "Department {$slug} label is longer than " . DEPARTMENT_CATALOG_SYNC_MAX_LABEL_LENGTH . ' characters.';
        }
        if (isset($departments[$slug])) {
            $errors[] = "Department slug {$slug} appears more than once after normalization.";
            continue;
        }
        $departments[$slug] = [
            'slug' => $slug,
            'label' => $label,
            'sort_order' => normalize_catalog_sync_sort_order($row['sort_order'] ?? 0, $errors, "Department {$slug}"),
            'archived_at' => validate_catalog_sync_archived_at($row['archived_at'] ?? null, $errors, "Department {$slug}"),
        ];
        if ($label !== '') {
            $departmentLabels[strtolower($label)] = $slug;
        }
        if ($slugSource !== '') {
            $departmentLabels[strtolower($slugSource)] = $slug;
        }
    }

    $teams = [];
    foreach ($teamsRaw as $index => $row) {
        if (!is_array($row)) {
            $errors[] = 'Team row ' . ((int)$index + 1) . ' must be an object.';
            continue;
        }
        $label = trim((string)($row['label'] ?? $row['name'] ?? $row['team'] ?? ''));
        $slugSource = trim((string)($row['slug'] ?? ''));
        $slug = normalize_catalog_sync_slug($slugSource, $label);
        if ($slug === '') {
            $errors[] = 'Team row ' . ((int)$index + 1) . ' needs either a slug or a label/name that can be converted to a slug.';
            continue;
        }

        $departmentSlugSource = trim((string)($row['department_slug'] ?? ''));
        $departmentSlug = normalize_catalog_sync_slug($departmentSlugSource, '');
        if ($departmentSlug === '' || !isset($departments[$departmentSlug])) {
            $departmentName = trim((string)($row['department_label'] ?? $row['department'] ?? $row['directorate'] ?? ''));
            $departmentKey = strtolower($departmentName);
            if ($departmentKey !== '' && isset($departmentLabels[$departmentKey])) {
                $departmentSlug = $departmentLabels[$departmentKey];
            }
        }

        if ($departmentSlug === '' || !isset($departments[$departmentSlug])) {
            $errors[] = "Team {$slug} references a missing department. Provide department_slug or a matching department label from this same import file.";
        }
        if ($label === '') {
            $errors[] = "Team {$slug} has an empty label.";
        }
        if (strlen($label) > DEPARTMENT_CATALOG_SYNC_MAX_LABEL_LENGTH) {
            $errors[] = "Team {$slug} label is longer than " . DEPARTMENT_CATALOG_SYNC_MAX_LABEL_LENGTH . ' characters.';
        }
        if (isset($teams[$slug])) {
            $errors[] = "Team slug {$slug} appears more than once after normalization.";
            continue;
        }
        $teams[$slug] = [
            'slug' => $slug,
            'department_slug' => $departmentSlug,
            'label' => $label,
            'sort_order' => normalize_catalog_sync_sort_order($row['sort_order'] ?? 0, $errors, "Team {$slug}"),
            'archived_at' => validate_catalog_sync_archived_at($row['archived_at'] ?? null, $errors, "Team {$slug}"),
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
            $changes['departments']['update'][] = ['slug' => $slug, 'from' => array_merge(['slug' => $slug], $currentDepartments[$slug]), 'to' => $incoming];
        } else {
            $changes['departments']['unchanged'][] = $incoming;
        }
    }
    if ($archiveMissing) {
        foreach ($currentDepartments as $slug => $record) {
            if (!isset($departments[$slug]) && normalize_catalog_sync_archived_at($record['archived_at'] ?? null) === null) {
                $changes['departments']['archive_missing'][] = ['slug' => $slug, 'from' => array_merge(['slug' => $slug], $record)];
            }
        }
    }

    foreach ($teams as $slug => $incoming) {
        if (!isset($currentTeams[$slug])) {
            $changes['teams']['create'][] = $incoming;
            continue;
        }
        if (catalog_sync_team_differs($currentTeams[$slug], $incoming)) {
            $changes['teams']['update'][] = ['slug' => $slug, 'from' => array_merge(['slug' => $slug], $currentTeams[$slug]), 'to' => $incoming];
        } else {
            $changes['teams']['unchanged'][] = $incoming;
        }
    }
    if ($archiveMissing) {
        foreach ($currentTeams as $slug => $record) {
            if (!isset($teams[$slug]) && normalize_catalog_sync_archived_at($record['archived_at'] ?? null) === null) {
                $changes['teams']['archive_missing'][] = ['slug' => $slug, 'from' => array_merge(['slug' => $slug], $record)];
            }
        }
    }

    return $changes;
}

/** @return array{departments:array<string,int>,teams:array<string,int>} */
function apply_department_catalog_import(PDO $pdo, array $departments, array $teams, bool $archiveMissing): array
{
    $result = apply_department_catalog_import_decisions($pdo, $departments, $teams, $archiveMissing, []);

    return [
        'departments' => [
            'created' => $result['departments']['created'],
            'updated' => $result['departments']['updated'],
            'archived' => $result['departments']['archived'],
        ],
        'teams' => [
            'created' => $result['teams']['created'],
            'updated' => $result['teams']['updated'],
            'archived' => $result['teams']['archived'],
        ],
    ];
}

/** @return array{departments:array<string,int>,teams:array<string,int>} */
function apply_department_catalog_import_decisions(PDO $pdo, array $departments, array $teams, bool $archiveMissing, array $decisions): array
{
    $preview = preview_department_catalog_import($pdo, $departments, $teams, $archiveMissing);
    $selectedDepartments = [];
    $selectedTeams = [];
    $departmentArchiveSlugs = [];
    $teamArchiveSlugs = [];
    $result = [
        'departments' => ['created' => 0, 'updated' => 0, 'archived' => 0, 'kept' => 0],
        'teams' => ['created' => 0, 'updated' => 0, 'archived' => 0, 'kept' => 0],
    ];

    foreach ($preview['departments']['create'] as $row) {
        $slug = (string)($row['slug'] ?? '');
        if (catalog_sync_decision($decisions, 'departments', 'create', $slug, 'create') === 'create') {
            $selectedDepartments[$slug] = $row;
            $result['departments']['created']++;
        } else {
            $result['departments']['kept']++;
        }
    }
    foreach ($preview['departments']['update'] as $change) {
        $slug = (string)($change['slug'] ?? '');
        if (catalog_sync_decision($decisions, 'departments', 'update', $slug, 'overwrite') === 'overwrite') {
            $selectedDepartments[$slug] = $change['to'];
            $result['departments']['updated']++;
        } else {
            $result['departments']['kept']++;
        }
    }
    if ($archiveMissing) {
        foreach ($preview['departments']['archive_missing'] as $row) {
            $slug = (string)($row['slug'] ?? '');
            if (catalog_sync_decision($decisions, 'departments', 'archive_missing', $slug, 'archive') === 'archive') {
                $departmentArchiveSlugs[] = $slug;
                $result['departments']['archived']++;
            } else {
                $result['departments']['kept']++;
            }
        }
    }

    foreach ($preview['teams']['create'] as $row) {
        $slug = (string)($row['slug'] ?? '');
        if (catalog_sync_decision($decisions, 'teams', 'create', $slug, 'create') === 'create') {
            $selectedTeams[$slug] = $row;
            $result['teams']['created']++;
        } else {
            $result['teams']['kept']++;
        }
    }
    foreach ($preview['teams']['update'] as $change) {
        $slug = (string)($change['slug'] ?? '');
        if (catalog_sync_decision($decisions, 'teams', 'update', $slug, 'overwrite') === 'overwrite') {
            $selectedTeams[$slug] = $change['to'];
            $result['teams']['updated']++;
        } else {
            $result['teams']['kept']++;
        }
    }
    if ($archiveMissing) {
        foreach ($preview['teams']['archive_missing'] as $row) {
            $slug = (string)($row['slug'] ?? '');
            if (catalog_sync_decision($decisions, 'teams', 'archive_missing', $slug, 'archive') === 'archive') {
                $teamArchiveSlugs[] = $slug;
                $result['teams']['archived']++;
            } else {
                $result['teams']['kept']++;
            }
        }
    }

    catalog_sync_execute_changes($pdo, $selectedDepartments, $selectedTeams, $departmentArchiveSlugs, $teamArchiveSlugs);

    return $result;
}

function catalog_sync_decision(array $decisions, string $group, string $action, string $slug, string $default): string
{
    $value = (string)($decisions[$group][$action][$slug] ?? $default);
    $allowed = [
        'create' => ['create', 'ignore'],
        'update' => ['overwrite', 'keep'],
        'archive_missing' => ['archive', 'keep'],
    ];
    return in_array($value, $allowed[$action] ?? [], true) ? $value : $default;
}

function catalog_sync_execute_changes(PDO $pdo, array $departments, array $teams, array $departmentArchiveSlugs, array $teamArchiveSlugs): void
{
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
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($departments as $row) {
            $departmentUpsert->execute([$row['slug'], $row['label'], (int)$row['sort_order'], $row['archived_at']]);
        }
        foreach ($teams as $row) {
            $teamUpsert->execute([$row['slug'], $row['department_slug'], $row['label'], (int)$row['sort_order'], $row['archived_at']]);
        }
        if ($departmentArchiveSlugs !== []) {
            $archiveDepartment = $pdo->prepare('UPDATE department_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug = ? AND archived_at IS NULL');
            foreach ($departmentArchiveSlugs as $slug) {
                $archiveDepartment->execute([$slug]);
            }
        }
        if ($teamArchiveSlugs !== []) {
            $archiveTeam = $pdo->prepare('UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug = ? AND archived_at IS NULL');
            foreach ($teamArchiveSlugs as $slug) {
                $archiveTeam->execute([$slug]);
            }
        }
        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function normalize_catalog_sync_slug(string $source, string $fallback): string
{
    $candidate = trim($source);
    if ($candidate !== '' && preg_match('/^[a-z0-9_]+$/', $candidate) && strlen($candidate) <= DEPARTMENT_CATALOG_SYNC_MAX_SLUG_LENGTH) {
        return $candidate;
    }

    return canonical_department_slug($candidate !== '' ? $candidate : $fallback);
}

function normalize_catalog_sync_sort_order($value, array &$errors, string $context): int
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (!is_numeric($value)) {
        $errors[] = $context . ' sort_order must be numeric.';
        return 0;
    }
    $sortOrder = (int)$value;
    if ($sortOrder < 0) {
        $errors[] = $context . ' sort_order cannot be negative.';
        return 0;
    }

    return min($sortOrder, 2147483647);
}

function validate_catalog_sync_archived_at($value, array &$errors, string $context): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_scalar($value)) {
        $errors[] = $context . ' archived_at must be a date/time string or null.';
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        $errors[] = $context . ' archived_at must be a valid date/time.';
        return null;
    }

    return $date->format('Y-m-d H:i:s');
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
