<?php
declare(strict_types=1);

if (defined('APP_DEPARTMENT_TEAMS_LOADED')) {
    return;
}
define('APP_DEPARTMENT_TEAMS_LOADED', true);

/** @return array<string,string> */
function built_in_department_definitions(): array
{
    return [
        'cmd' => 'Change Management & Development',
        'communication' => 'Communications & Partnerships',
        'dfm' => 'Demand Forecasting & Management',
        'driver' => 'Driver Services',
        'ethics' => 'Ethics & Compliance',
        'finance' => 'Finance & Grants',
        'general_service' => 'General Services',
        'hrm' => 'Human Resources Management',
        'ict' => 'Information & Communication Technology',
        'leadership_tn' => 'Leadership & Team Nurturing',
        'legal_service' => 'Legal Services',
        'pme' => 'Planning, Monitoring & Evaluation',
        'quantification' => 'Quantification & Procurement',
        'records_documentation' => 'Records & Documentation',
        'security' => 'Security Operations',
        'security_driver' => 'Security & Driver Management',
        'tmd' => 'Training & Mentorship Development',
        'wim' => 'Warehouse & Inventory Management',
    ];
}

function canonical_department_slug(string $value): string
{
    $normalized = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($value))) ?? '';
    return trim($normalized, '_');
}

function canonical_department_team_slug(string $value): string
{
    return canonical_department_slug($value);
}

function is_placeholder_department_value(string $value): bool
{
    $canonical = canonical_department_slug($value);
    return in_array($canonical, ['none', 'na', 'n_a', 'null', 'unknown', 'not_applicable'], true);
}

function is_placeholder_team_value(string $value): bool
{
    $canonical = canonical_department_team_slug($value);
    return in_array($canonical, ['none', 'na', 'n_a', 'null', 'unknown', 'not_applicable'], true);
}

function unique_slug(string $candidate, array $existing): string
{
    $base = $candidate;
    $suffix = 2;
    while (isset($existing[$candidate])) {
        $candidate = $base . '_' . $suffix;
        $suffix++;
        if ($suffix > 10000) {
            break;
        }
    }
    return $candidate;
}

function ensure_department_catalog(PDO $pdo): void
{
    static $initialized = [];
    $cacheKey = spl_object_id($pdo);
    if (isset($initialized[$cacheKey])) {
        return;
    }

    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS department_catalog ('
                . 'slug TEXT NOT NULL PRIMARY KEY, '
                . 'label TEXT NOT NULL, '
                . 'sort_order INTEGER NOT NULL DEFAULT 0, '
                . 'archived_at TEXT NULL, '
                . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_department_catalog_sort ON department_catalog (archived_at, sort_order, label)');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS department_catalog ('
                . 'slug VARCHAR(120) NOT NULL PRIMARY KEY, '
                . 'label VARCHAR(255) NOT NULL, '
                . 'sort_order INT NOT NULL DEFAULT 0, '
                . 'archived_at DATETIME NULL, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            try {
                $pdo->exec('CREATE INDEX idx_department_catalog_sort ON department_catalog (archived_at, sort_order, label)');
            } catch (Throwable $e) {
                // ignore duplicate index errors
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_department_catalog schema failed: ' . $e->getMessage());
        return;
    }

    try {
        $existing = [];
        $stmt = $pdo->query('SELECT slug, label FROM department_catalog');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $slug = trim((string)($row['slug'] ?? ''));
                $label = trim((string)($row['label'] ?? ''));
                if ($slug !== '') {
                    $existing[$slug] = $label;
                }
            }
        }

        $insert = $pdo->prepare('INSERT INTO department_catalog (slug, label, sort_order, archived_at) VALUES (?, ?, ?, NULL)');
        $update = $pdo->prepare('UPDATE department_catalog SET label = ?, sort_order = ?, archived_at = NULL WHERE slug = ?');

        $sort = 1;
        foreach (built_in_department_definitions() as $slug => $label) {
            if (isset($existing[$slug])) {
                $update->execute([$label, $sort, $slug]);
            } else {
                $insert->execute([$slug, $label, $sort]);
                $existing[$slug] = $label;
            }
            $sort++;
        }

        // Backfill any custom/legacy department labels in users table.
        $seen = array_fill_keys(array_keys($existing), true);
        $labelToSlug = [];
        foreach ($existing as $existingSlug => $existingLabel) {
            $normalizedLabel = strtolower(trim((string)$existingLabel));
            if ($normalizedLabel !== '' && !isset($labelToSlug[$normalizedLabel])) {
                $labelToSlug[$normalizedLabel] = $existingSlug;
            }
        }

        $userStmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND TRIM(department) <> ''");
        if ($userStmt) {
            while ($value = $userStmt->fetchColumn()) {
                $label = trim((string)$value);
                if ($label === '' || is_placeholder_department_value($label)) {
                    continue;
                }

                $slug = canonical_department_slug($label);
                if ($slug === '') {
                    continue;
                }

                if (isset($existing[$slug])) {
                    $labelToSlug[$normalizedLabel] = $slug;
                    continue;
                }

                $slug = unique_slug($slug, $seen);
                if (!isset($existing[$slug])) {
                    $insert->execute([$slug, $label, $sort]);
                    $existing[$slug] = $label;
                    $seen[$slug] = true;
                    $labelToSlug[$normalizedLabel] = $slug;
                    $sort++;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('ensure_department_catalog seed failed: ' . $e->getMessage());
    }

    $initialized[$cacheKey] = true;
}

function ensure_department_team_catalog(PDO $pdo): void
{
    static $initialized = [];
    $cacheKey = spl_object_id($pdo);
    if (isset($initialized[$cacheKey])) {
        return;
    }

    ensure_department_catalog($pdo);
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS department_team_catalog ('
                . 'slug TEXT NOT NULL PRIMARY KEY, '
                . 'department_slug TEXT NOT NULL, '
                . 'label TEXT NOT NULL, '
                . 'sort_order INTEGER NOT NULL DEFAULT 0, '
                . 'archived_at TEXT NULL, '
                . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_department_team_catalog_sort ON department_team_catalog (department_slug, archived_at, sort_order, label)');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS department_team_catalog ('
                . 'slug VARCHAR(120) NOT NULL PRIMARY KEY, '
                . 'department_slug VARCHAR(120) NOT NULL, '
                . 'label VARCHAR(255) NOT NULL, '
                . 'sort_order INT NOT NULL DEFAULT 0, '
                . 'archived_at DATETIME NULL, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            try {
                $cols = $pdo->query('SHOW COLUMNS FROM department_team_catalog');
                $hasDepartment = false;
                if ($cols) {
                    while ($c = $cols->fetch(PDO::FETCH_ASSOC)) {
                        if (($c['Field'] ?? '') === 'department_slug') {
                            $hasDepartment = true;
                            break;
                        }
                    }
                }
                if (!$hasDepartment) {
                    $pdo->exec('ALTER TABLE department_team_catalog ADD COLUMN department_slug VARCHAR(120) NULL AFTER slug');
                }
                $pdo->exec('CREATE INDEX idx_department_team_catalog_sort ON department_team_catalog (department_slug, archived_at, sort_order, label)');
            } catch (Throwable $e) {
                // ignore duplicate index errors
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_department_team_catalog schema failed: ' . $e->getMessage());
        return;
    }

    try {
        $teamRows = [];
        $teamStmt = $pdo->query('SELECT slug, department_slug, label FROM department_team_catalog');
        if ($teamStmt) {
            while ($row = $teamStmt->fetch(PDO::FETCH_ASSOC)) {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $teamRows[$slug] = [
                    'department_slug' => trim((string)($row['department_slug'] ?? '')),
                    'label' => trim((string)($row['label'] ?? '')),
                ];
            }
        }

        $insert = $pdo->prepare('INSERT INTO department_team_catalog (slug, department_slug, label, sort_order, archived_at) VALUES (?, ?, ?, ?, NULL)');
        $seenSlugs = array_fill_keys(array_keys($teamRows), true);
        $seenLabelsByDepartment = [];
        foreach ($teamRows as $existingRow) {
            $existingDepartment = trim((string)($existingRow['department_slug'] ?? ''));
            $existingLabel = trim((string)($existingRow['label'] ?? ''));
            if ($existingDepartment === '' || $existingLabel === '') {
                continue;
            }
            $seenLabelsByDepartment[$existingDepartment][strtolower($existingLabel)] = true;
        }
        $sort = count($teamRows) + 1;

        $sourceStmt = $pdo->query("SELECT DISTINCT department, cadre FROM users WHERE cadre IS NOT NULL AND TRIM(cadre) <> '' ORDER BY department, cadre");
        if ($sourceStmt) {
            while ($row = $sourceStmt->fetch(PDO::FETCH_ASSOC)) {
                $teamLabel = trim((string)($row['cadre'] ?? ''));
                if ($teamLabel === '' || is_placeholder_team_value($teamLabel)) {
                    continue;
                }
                $dep = resolve_department_slug($pdo, (string)($row['department'] ?? ''));
                if ($dep === '') {
                    $dep = 'general_service';
                }

                // If a row already exists by exact label+department, skip.
                if (isset($seenLabelsByDepartment[$dep][strtolower($teamLabel)])) {
                    continue;
                }

                $candidate = canonical_department_team_slug($teamLabel);
                if ($candidate === '') {
                    continue;
                }
                if (isset($seenSlugs[$candidate])) {
                    $candidate = canonical_department_slug($dep . '__' . $teamLabel);
                }
                $slug = unique_slug($candidate, $seenSlugs);
                $insert->execute([$slug, $dep, $teamLabel, $sort]);
                $seenSlugs[$slug] = true;
                $teamRows[$slug] = ['department_slug' => $dep, 'label' => $teamLabel];
                $seenLabelsByDepartment[$dep][strtolower($teamLabel)] = true;
                $sort++;
            }
        }
    } catch (Throwable $e) {
        error_log('ensure_department_team_catalog seed failed: ' . $e->getMessage());
    }

    $initialized[$cacheKey] = true;
}

function ensure_questionnaire_department_schema(PDO $pdo): void
{
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS questionnaire_department ('
                . 'questionnaire_id INTEGER NOT NULL, '
                . 'department_slug TEXT NOT NULL, '
                . 'PRIMARY KEY (questionnaire_id, department_slug)'
                . ')');
            return;
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS questionnaire_department ('
            . 'questionnaire_id INT NOT NULL, '
            . 'department_slug VARCHAR(120) NOT NULL, '
            . 'PRIMARY KEY (questionnaire_id, department_slug)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (PDOException $e) {
        error_log('ensure_questionnaire_department_schema failed: ' . $e->getMessage());
    }
}

/** @return array<string,array{label:string,sort_order:int,archived_at:?string}> */
function department_catalog(PDO $pdo): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    ensure_department_catalog($pdo);
    $rows = [];
    try {
        $stmt = $pdo->query('SELECT slug, label, sort_order, archived_at FROM department_catalog ORDER BY archived_at IS NOT NULL, sort_order, label');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $rows[$slug] = [
                    'label' => trim((string)($row['label'] ?? '')),
                    'sort_order' => (int)($row['sort_order'] ?? 0),
                    'archived_at' => $row['archived_at'] ?? null,
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('department_catalog query failed: ' . $e->getMessage());
    }

    $cache[$cacheKey] = $rows;
    return $rows;
}

/** @return array<string,string> */
function department_options(PDO $pdo): array
{
    $options = [];
    foreach (department_catalog($pdo) as $slug => $record) {
        if (($record['archived_at'] ?? null) === null && $record['label'] !== '') {
            $options[$slug] = $record['label'];
        }
    }
    return $options;
}

function resolve_department_slug(PDO $pdo, string $value): string
{
    $value = trim($value);
    if ($value === '' || is_placeholder_department_value($value)) {
        return '';
    }

    $catalog = department_catalog($pdo);
    if (isset($catalog[$value]) && ($catalog[$value]['archived_at'] ?? null) === null) {
        return $value;
    }

    $canonical = canonical_department_slug($value);
    if ($canonical !== '' && isset($catalog[$canonical]) && ($catalog[$canonical]['archived_at'] ?? null) === null) {
        return $canonical;
    }

    foreach ($catalog as $slug => $record) {
        if (($record['archived_at'] ?? null) !== null) {
            continue;
        }
        if (strcasecmp($value, (string)$record['label']) === 0) {
            return $slug;
        }
    }

    return '';
}

function department_label(PDO $pdo, string $slug): string
{
    $catalog = department_catalog($pdo);
    return $catalog[$slug]['label'] ?? $slug;
}

/** @return array<string,array{department_slug:string,label:string,sort_order:int,archived_at:?string}> */
function department_team_catalog(PDO $pdo): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    ensure_department_team_catalog($pdo);
    $rows = [];
    try {
        $stmt = $pdo->query('SELECT slug, department_slug, label, sort_order, archived_at FROM department_team_catalog ORDER BY archived_at IS NOT NULL, department_slug, sort_order, label');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $rows[$slug] = [
                    'department_slug' => trim((string)($row['department_slug'] ?? '')),
                    'label' => trim((string)($row['label'] ?? '')),
                    'sort_order' => (int)($row['sort_order'] ?? 0),
                    'archived_at' => $row['archived_at'] ?? null,
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('department_team_catalog query failed: ' . $e->getMessage());
    }

    $cache[$cacheKey] = $rows;
    return $rows;
}

/** @return array<string,string> */
function department_team_options(PDO $pdo, ?string $departmentSlug = null): array
{
    $options = [];
    foreach (department_team_catalog($pdo) as $slug => $record) {
        if (($record['archived_at'] ?? null) !== null) {
            continue;
        }
        if ($departmentSlug !== null && $departmentSlug !== '' && $record['department_slug'] !== $departmentSlug) {
            continue;
        }
        if ($record['label'] !== '') {
            $options[$slug] = $record['label'];
        }
    }
    uasort($options, static fn($a, $b) => strcasecmp((string)$a, (string)$b));
    return $options;
}

function resolve_team_slug(PDO $pdo, string $value, ?string $departmentSlug = null): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $catalog = department_team_catalog($pdo);

    if (isset($catalog[$value])) {
        $record = $catalog[$value];
        if (($record['archived_at'] ?? null) === null && ($departmentSlug === null || $departmentSlug === '' || $record['department_slug'] === $departmentSlug)) {
            return $value;
        }
    }

    $canonical = canonical_department_team_slug($value);
    if (isset($catalog[$canonical])) {
        $record = $catalog[$canonical];
        if (($record['archived_at'] ?? null) === null && ($departmentSlug === null || $departmentSlug === '' || $record['department_slug'] === $departmentSlug)) {
            return $canonical;
        }
    }

    foreach ($catalog as $slug => $record) {
        if (($record['archived_at'] ?? null) !== null) {
            continue;
        }
        if ($departmentSlug !== null && $departmentSlug !== '' && $record['department_slug'] !== $departmentSlug) {
            continue;
        }
        if (strcasecmp((string)$record['label'], $value) === 0) {
            return $slug;
        }
    }

    return '';
}

function team_label(PDO $pdo, string $teamSlug): string
{
    $catalog = department_team_catalog($pdo);
    if (isset($catalog[$teamSlug]) && ($catalog[$teamSlug]['label'] ?? '') !== '') {
        return (string)$catalog[$teamSlug]['label'];
    }

    foreach ($catalog as $record) {
        if (strcasecmp((string)($record['label'] ?? ''), $teamSlug) === 0) {
            return (string)($record['label'] ?? $teamSlug);
        }
    }

    return $teamSlug;
}


/** @return array<string,array{label:string,sort_order:int,archived_at:?string}> */
function department_catalogue(PDO $pdo): array
{
    return department_catalog($pdo);
}
