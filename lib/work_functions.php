<?php
declare(strict_types=1);

if (defined('APP_WORK_FUNCTIONS_LOADED')) {
    return;
}
define('APP_WORK_FUNCTIONS_LOADED', true);

/**
 * Retrieve the built-in (code-level) work function definition list.
 *
 * @return array<string,string>
 */
function built_in_work_function_definitions(): array
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

function ensure_work_function_catalog(PDO $pdo): void
{
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    try {
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS work_function_catalog ('
                . 'slug TEXT NOT NULL PRIMARY KEY, '
                . 'label TEXT NOT NULL, '
                . 'sort_order INTEGER NOT NULL DEFAULT 0, '
                . 'archived_at TEXT NULL, '
                . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_work_function_catalog_sort ON work_function_catalog (archived_at, sort_order, label)');
        } else {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS work_function_catalog ('
                . 'slug VARCHAR(100) NOT NULL PRIMARY KEY, '
                . 'label VARCHAR(255) NOT NULL, '
                . 'sort_order INT NOT NULL DEFAULT 0, '
                . 'archived_at DATETIME NULL, '
                . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            try {
                $pdo->exec('CREATE INDEX idx_work_function_catalog_sort ON work_function_catalog (archived_at, sort_order, label)');
            } catch (Throwable $e) {
                // Ignore duplicate index errors.
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_work_function_catalog schema failed: ' . $e->getMessage());
        return;
    }

    $count = 0;
    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM work_function_catalog');
        if ($stmt) {
            $count = (int) $stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('ensure_work_function_catalog count failed: ' . $e->getMessage());
        return;
    }

    if ($count === 0) {
        $defaults = built_in_work_function_definitions();
        $sortOrder = 1;
        try {
            $insert = $pdo->prepare('INSERT INTO work_function_catalog (slug, label, sort_order) VALUES (?, ?, ?)');
            foreach ($defaults as $slug => $label) {
                try {
                    $insert->execute([$slug, $label, $sortOrder]);
                    $sortOrder++;
                } catch (PDOException $e) {
                    error_log('ensure_work_function_catalog insert failed: ' . $e->getMessage());
                }
            }
        } catch (PDOException $e) {
            error_log('ensure_work_function_catalog prepare failed: ' . $e->getMessage());
        }
    }
}

/*
if (!function_exists('ensure_questionnaire_work_function_schema')) {
    function ensure_questionnaire_work_function_schema(PDO $pdo): void
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaire_work_function (
                questionnaire_id INT NOT NULL,
                work_function VARCHAR(191) NOT NULL,
                PRIMARY KEY (questionnaire_id, work_function)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $columnsStmt = $pdo->query('SHOW COLUMNS FROM questionnaire_work_function');
            $columns = [];
            if ($columnsStmt) {
                while ($column = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
                    $columns[$column['Field']] = $column;
                }
            }

            if (!isset($columns['work_function'])) {
                $pdo->exec('ALTER TABLE questionnaire_work_function ADD COLUMN work_function VARCHAR(191) NOT NULL AFTER questionnaire_id');
            } else {
                $type = strtolower((string)($columns['work_function']['Type'] ?? ''));
                $needsUpdate = true;
                if (str_contains($type, 'varchar')) {
                    $length = 0;
                    if (preg_match('/varchar\\((\\d+)\\)/i', $type, $matches)) {
                        $length = (int)$matches[1];
                    }
                    $needsUpdate = $length < 1 || $length < 191;
                }
                if ($needsUpdate) {
                    $pdo->exec('ALTER TABLE questionnaire_work_function MODIFY COLUMN work_function VARCHAR(191) NOT NULL');
                }
            }

            $primaryIndex = $pdo->query("SHOW INDEX FROM questionnaire_work_function WHERE Key_name = 'PRIMARY'");
            $hasPrimary = $primaryIndex && $primaryIndex->fetch(PDO::FETCH_ASSOC);
            if (!$hasPrimary) {
                $pdo->exec('ALTER TABLE questionnaire_work_function ADD PRIMARY KEY (questionnaire_id, work_function)');
            }

            // Preserve any administrator-defined questionnaire assignments without
            // seeding defaults on every request. The previous behaviour inserted
            // every questionnaire/work function combination which overwrote custom
            // selections made through the admin portal. By limiting this helper to
            // structural concerns we ensure saved assignments remain intact.
        } catch (PDOException $e) {
            error_log('ensure_questionnaire_work_function_schema: ' . $e->getMessage());
        }
    }
}
*/

/**
 * Fetch the active work function definitions from the catalog.
 *
 * @return array<string,string>
 */
function work_function_definitions(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    ensure_work_function_catalog($pdo);

    $definitions = [];
    try {
        $stmt = $pdo->query('SELECT slug, label FROM work_function_catalog WHERE archived_at IS NULL ORDER BY sort_order, label');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $slug = trim((string)($row['slug'] ?? ''));
                $label = trim((string)($row['label'] ?? ''));
                if ($slug === '' || $label === '') {
                    continue;
                }
                $definitions[$slug] = $label;
            }
        }
    } catch (PDOException $e) {
        error_log('work_function_definitions query failed: ' . $e->getMessage());
    }

    if ($definitions === []) {
        $definitions = built_in_work_function_definitions();
    }

    $cache[$cacheKey] = $definitions;

    return $definitions;
}

/**
 * Fetch all catalog entries including archived ones.
 *
 * @return array<string,array{label:string,archived_at:?string,sort_order:int}>
 */
function work_function_catalog(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    ensure_work_function_catalog($pdo);

    $records = [];
    try {
        $stmt = $pdo->query('SELECT slug, label, sort_order, archived_at FROM work_function_catalog ORDER BY sort_order, label');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $records[$slug] = [
                    'label' => trim((string)($row['label'] ?? '')),
                    'sort_order' => (int)($row['sort_order'] ?? 0),
                    'archived_at' => $row['archived_at'] ?? null,
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('work_function_catalog query failed: ' . $e->getMessage());
    }

    $cache[$cacheKey] = $records;

    return $records;
}

function reset_work_function_caches(PDO $pdo): void
{
    work_function_catalog($pdo, true);
    work_function_definitions($pdo, true);
    work_function_assignments($pdo, true);
    work_function_choices($pdo, true);
    available_work_functions($pdo, true);
}

function generate_unique_work_function_slug(string $candidate, array $existing): string
{
    $base = $candidate;
    $suffix = 2;
    while (isset($existing[$candidate])) {
        $candidate = $base . '_' . $suffix;
        $suffix++;
        if ($suffix > 5000) {
            break;
        }
    }

    return $candidate;
}

function create_work_function(PDO $pdo, string $label, ?string $slug = null): array
{
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Label is required');
    }

    $catalog = work_function_catalog($pdo);
    $definitions = work_function_definitions($pdo);
    $existingKeys = array_fill_keys(array_keys($catalog), true);
    foreach (array_keys($definitions) as $key) {
        $existingKeys[$key] = true;
    }

    $slugSource = $slug !== null && trim($slug) !== '' ? (string)$slug : $label;
    $candidate = canonical_work_function_key($slugSource, $definitions + built_in_work_function_definitions());
    if ($candidate === '') {
        $candidate = canonical_work_function_key($label, $definitions + built_in_work_function_definitions());
    }
    if ($candidate === '') {
        throw new InvalidArgumentException('Unable to derive work function key');
    }

    $candidate = generate_unique_work_function_slug($candidate, $existingKeys);

    $pdo->beginTransaction();
    try {
        $sortOrder = 1;
        try {
            $stmt = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM work_function_catalog');
            if ($stmt) {
                $sortOrder = (int)$stmt->fetchColumn();
            }
        } catch (PDOException $e) {
            error_log('create_work_function sort order failed: ' . $e->getMessage());
        }

        $insert = $pdo->prepare('INSERT INTO work_function_catalog (slug, label, sort_order, archived_at) VALUES (?, ?, ?, NULL)');
        $insert->execute([$candidate, $label, $sortOrder]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    reset_work_function_caches($pdo);

    return ['slug' => $candidate, 'label' => $label];
}

function update_work_function_label(PDO $pdo, string $slug, string $label): void
{
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Label is required');
    }

    $catalog = work_function_catalog($pdo);
    if (!isset($catalog[$slug]) || $catalog[$slug]['archived_at'] !== null) {
        throw new InvalidArgumentException('Work function does not exist');
    }

    $stmt = $pdo->prepare('UPDATE work_function_catalog SET label=? WHERE slug=?');
    $stmt->execute([$label, $slug]);

    reset_work_function_caches($pdo);
}

function archive_work_function(PDO $pdo, string $slug): void
{
    $catalog = work_function_catalog($pdo);
    if (!isset($catalog[$slug]) || $catalog[$slug]['archived_at'] !== null) {
        throw new InvalidArgumentException('Work function does not exist');
    }

    $pdo->beginTransaction();
    try {
        $archive = $pdo->prepare('UPDATE work_function_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug = ?');
        $archive->execute([$slug]);

        $removeAssignments = $pdo->prepare('DELETE FROM questionnaire_work_function WHERE work_function = ?');
        $removeAssignments->execute([$slug]);

        $clearUsers = $pdo->prepare('UPDATE users SET work_function = NULL WHERE work_function = ?');
        $clearUsers->execute([$slug]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    reset_work_function_caches($pdo);
}

/**
 * Normalize a work function identifier to the canonical key.
 *
 * @param array<string,string>|null $definitions
 */
function canonical_work_function_key(string $value, ?array $definitions = null): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $definitions = $definitions ?? built_in_work_function_definitions();
    $normalizedDefinitions = [];
    foreach ($definitions as $key => $label) {
        $normalizedDefinitions[strtolower((string)$key)] = (string)$key;
    }

    if (isset($definitions[$value])) {
        return (string)$value;
    }

    $lowerValue = strtolower($value);
    if (isset($normalizedDefinitions[$lowerValue])) {
        return $normalizedDefinitions[$lowerValue];
    }

    foreach ($definitions as $key => $label) {
        if (strcasecmp($value, (string)$label) === 0) {
            return (string)$key;
        }
    }

    $normalized = preg_replace('/[^a-z0-9]+/i', '_', $lowerValue) ?? '';
    $normalized = trim($normalized, '_');

    if ($normalized === '') {
        return '';
    }

    if (isset($normalizedDefinitions[$normalized])) {
        return $normalizedDefinitions[$normalized];
    }

    return $normalized;
}

function canonical(string $value, ?array $definitions = null): string
{
    return canonical_work_function_key($value, $definitions);
}

if (!function_exists('canonical_work_function')) {
    function canonical_work_function(string $value, ?array $definitions = null): string
    {
        return canonical_work_function_key($value, $definitions);
    }
}

/**
 * @return array<string,string>
 */
if (!function_exists('work_function_choices')) {
    function work_function_choices(PDO $pdo, bool $forceRefresh = false): array
    {
        $definitions = work_function_definitions($pdo, $forceRefresh);
        $choices = $definitions;

        $sources = [];
        try {
            $stmt = $pdo->query('SELECT DISTINCT work_function FROM questionnaire_work_function WHERE work_function IS NOT NULL AND work_function <> ""');
            if ($stmt) {
                $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
        } catch (PDOException $e) {
            error_log('work_function_choices (questionnaire_work_function): ' . $e->getMessage());
        }

        try {
            $stmt = $pdo->query("SELECT DISTINCT work_function FROM users WHERE work_function IS NOT NULL AND work_function <> ''");
            if ($stmt) {
                $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
        } catch (PDOException $e) {
            error_log('work_function_choices (users): ' . $e->getMessage());
        }

        foreach ($sources as $rawValue) {
            $key = canonical_work_function_key((string)$rawValue, $definitions);
            if ($key === '') {
                continue;
            }
            if (!isset($choices[$key])) {
                $choices[$key] = $definitions[$key] ?? ucwords(str_replace('_', ' ', $key));
            }
        }

        uasort($choices, static fn($a, $b) => strcasecmp((string)$a, (string)$b));

        return $choices;
    }
}

/**
 * @return array<string,list<int>>
 */
function work_function_assignments(PDO $pdo, bool $forceRefresh = false): array
{
    $assignments = [];
    $definitions = work_function_definitions($pdo, $forceRefresh);

    try {
        $stmt = $pdo->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $questionnaireId = isset($row['questionnaire_id']) ? (int)$row['questionnaire_id'] : 0;
                $workFunction = canonical_work_function_key((string)($row['work_function'] ?? ''), $definitions);
                if ($questionnaireId <= 0 || $workFunction === '') {
                    continue;
                }
                $assignments[$workFunction][$questionnaireId] = $questionnaireId;
            }
        }
    } catch (PDOException $e) {
        error_log('work_function_assignments: ' . $e->getMessage());
    }

    foreach ($assignments as $workFunction => $ids) {
        $list = array_values($ids);
        sort($list, SORT_NUMERIC);
        $assignments[$workFunction] = $list;
    }

    ksort($assignments);

    return $assignments;
}

/**
 * @param array<string,mixed> $input
 * @param list<string>        $allowedWorkFunctions
 * @param list<int>           $allowedQuestionnaireIds
 *
 * @return array<string,list<int>>
 */
function normalize_work_function_assignments(array $input, array $allowedWorkFunctions, array $allowedQuestionnaireIds): array
{
    $allowedWorkFunctions = array_values(array_unique(array_map('strval', $allowedWorkFunctions)));
    $allowedWorkFunctionSet = array_fill_keys($allowedWorkFunctions, true);

    $allowedQuestionnaireIds = array_values(array_unique(array_map('intval', $allowedQuestionnaireIds)));
    $allowedQuestionnaireSet = array_fill_keys($allowedQuestionnaireIds, true);

    $normalized = [];

    foreach ($input as $workFunction => $ids) {
        $rawWorkFunction = trim((string)$workFunction);
        if ($rawWorkFunction === '') {
            continue;
        }

        if (isset($allowedWorkFunctionSet[$rawWorkFunction])) {
            $canonical = $rawWorkFunction;
        } else {
            $canonical = canonical_work_function_key($rawWorkFunction);
        }

        if ($canonical === '' || !isset($allowedWorkFunctionSet[$canonical])) {
            continue;
        }
        if (!is_array($ids)) {
            continue;
        }
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0 || !isset($allowedQuestionnaireSet[$id])) {
                continue;
            }
            $normalized[$canonical][$id] = $id;
        }
    }

    foreach ($allowedWorkFunctions as $workFunction) {
        $values = array_values($normalized[$workFunction] ?? []);
        sort($values, SORT_NUMERIC);
        $normalized[$workFunction] = $values;
    }

    ksort($normalized);

    return $normalized;
}

/**
 * Persist the normalized assignments to the database.
 *
 * @param array<string,list<int>> $assignments
 */
function save_work_function_assignments(PDO $pdo, array $assignments): void
{
    $transactionStarted = false;
    try {
        $transactionStarted = $pdo->beginTransaction();
    } catch (Throwable $e) {
        $transactionStarted = false;
    }

    try {
        $pdo->exec('DELETE FROM questionnaire_work_function');
        $definitions = work_function_definitions($pdo);
        if ($assignments !== []) {
            $insert = $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');
            foreach ($assignments as $workFunction => $questionnaireIds) {
                $workFunction = canonical_work_function_key((string)$workFunction, $definitions);
                if ($workFunction === '') {
                    continue;
                }
                foreach ($questionnaireIds as $questionnaireId) {
                    $questionnaireId = (int)$questionnaireId;
                    if ($questionnaireId <= 0) {
                        continue;
                    }
                    $insert->execute([$questionnaireId, $workFunction]);
                }
            }
        }
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array<string,string>
 */
function available_work_functions(PDO $pdo, bool $forceRefresh = false): array
{
    $definitions = work_function_definitions($pdo, $forceRefresh);
    $choices = work_function_choices($pdo, $forceRefresh);
    $assignments = work_function_assignments($pdo, $forceRefresh);

    $keys = array_unique(array_merge(array_keys($definitions), array_keys($choices), array_keys($assignments)));

    $labels = [];
    foreach ($keys as $key) {
        $canonical = canonical_work_function_key((string)$key, $definitions);
        if ($canonical === '') {
            continue;
        }
        if (isset($choices[$canonical])) {
            $labels[$canonical] = $choices[$canonical];
            continue;
        }
        if (isset($definitions[$canonical])) {
            $labels[$canonical] = $definitions[$canonical];
            continue;
        }
        $labels[$canonical] = ucwords(str_replace('_', ' ', $canonical));
    }

    uasort($labels, static fn($a, $b) => strcasecmp((string)$a, (string)$b));

    return $labels;
}

/**
 * Resolve the display label for a work function key.
 */
/*
if (!function_exists('work_function_label')) {
    function work_function_label(PDO $pdo, string $workFunction): string
    {
        $definitions = work_function_definitions($pdo);
        $canonical = canonical_work_function_key($workFunction, $definitions);
        if ($canonical === '') {
            return '';
        }

        $options = available_work_functions($pdo);
        if (isset($options[$canonical])) {
            return (string) $options[$canonical];
        }

        if (isset($definitions[$canonical])) {
            return (string) $definitions[$canonical];
        }

        return ucwords(str_replace('_', ' ', $canonical));
    }
}
*/
