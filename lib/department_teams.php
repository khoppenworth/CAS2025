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

function ensure_department_catalog(PDO $pdo): void
{
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
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_department_catalog schema failed: ' . $e->getMessage());
        return;
    }

    try {
        $countStmt = $pdo->query('SELECT COUNT(*) FROM department_catalog');
        $count = $countStmt ? (int)$countStmt->fetchColumn() : 0;
        if ($count > 0) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO department_catalog (slug, label, sort_order) VALUES (?, ?, ?)');
        $sort = 1;
        foreach (built_in_department_definitions() as $slug => $label) {
            $insert->execute([$slug, $label, $sort]);
            $sort++;
        }
    } catch (Throwable $e) {
        error_log('ensure_department_catalog seed failed: ' . $e->getMessage());
    }
}

function ensure_department_team_catalog(PDO $pdo): void
{
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
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_department_team_catalog schema failed: ' . $e->getMessage());
        return;
    }

    try {
        $countStmt = $pdo->query('SELECT COUNT(*) FROM department_team_catalog');
        $count = $countStmt ? (int)$countStmt->fetchColumn() : 0;
        if ($count > 0) {
            return;
        }

        $sourceStmt = $pdo->query("SELECT DISTINCT department, cadre FROM users WHERE cadre IS NOT NULL AND TRIM(cadre) <> '' ORDER BY department, cadre");
        if (!$sourceStmt) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO department_team_catalog (slug, department_slug, label, sort_order) VALUES (?, ?, ?, ?)');
        $seen = [];
        $sort = 1;
        while ($row = $sourceStmt->fetch(PDO::FETCH_ASSOC)) {
            $team = trim((string)($row['cadre'] ?? ''));
            if ($team === '') {
                continue;
            }
            $dep = canonical_department_slug((string)($row['department'] ?? ''));
            if ($dep === '') {
                $dep = 'general_service';
            }
            $slug = canonical_department_team_slug($team);
            if ($slug === '') {
                continue;
            }
            while (isset($seen[$slug])) {
                $slug .= '_2';
            }
            $seen[$slug] = true;
            $insert->execute([$slug, $dep, $team, $sort]);
            $sort++;
        }
    } catch (Throwable $e) {
        error_log('ensure_department_team_catalog seed failed: ' . $e->getMessage());
    }
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

function department_label(PDO $pdo, string $slug): string
{
    $catalog = department_catalog($pdo);
    return $catalog[$slug]['label'] ?? $slug;
}

/** @return array<string,array{department_slug:string,label:string,sort_order:int,archived_at:?string}> */
function department_team_catalog(PDO $pdo): array
{
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

function team_label(PDO $pdo, string $teamSlug): string
{
    $catalog = department_team_catalog($pdo);
    return $catalog[$teamSlug]['label'] ?? $teamSlug;
}
