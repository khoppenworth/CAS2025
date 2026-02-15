<?php
declare(strict_types=1);

/**
 * Cleanup script for accidental placeholder department/team data.
 *
 * Usage:
 *   php scripts/cleanup_placeholder_departments.php --dry-run
 *   php scripts/cleanup_placeholder_departments.php --apply
 */

define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../config.php';

function connect_to_database(): PDO
{
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'epss_v300';
    $dbUser = getenv('DB_USER') ?: 'epss_user';
    $dbPass = getenv('DB_PASS') ?: 'StrongPassword123!';
    $dbPortRaw = getenv('DB_PORT');

    $dsn = sprintf(
        'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
        $dbHost,
        ($dbPortRaw !== false && $dbPortRaw !== '') ? 'port=' . ((int)$dbPortRaw) . ';' : '',
        $dbName
    );

    return new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function usage(): void
{
    echo "Usage: php scripts/cleanup_placeholder_departments.php [--dry-run|--apply]\n";
}

$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply'], true)) {
    usage();
    exit(1);
}

$apply = $mode === '--apply';

$placeholderRegex = '^(none|na|n_a|null|unknown|not_applicable)(_[0-9]+)?$';

try {
    $pdo = connect_to_database();
} catch (PDOException $e) {
    fwrite(STDERR, 'Unable to connect to database: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$findDepartmentSlugs = $pdo->prepare(
    "SELECT slug FROM department_catalog WHERE LOWER(slug) REGEXP :re OR LOWER(TRIM(label)) REGEXP :re"
);
$findDepartmentSlugs->execute([':re' => $placeholderRegex]);
$departmentSlugs = array_values(array_unique(array_map(
    static fn(array $r): string => trim((string)($r['slug'] ?? '')),
    $findDepartmentSlugs->fetchAll()
)));
$departmentSlugs = array_values(array_filter($departmentSlugs, static fn(string $s): bool => $s !== ''));

$findTeamSlugs = $pdo->prepare(
    "SELECT slug FROM department_team_catalog WHERE LOWER(slug) REGEXP :re OR LOWER(TRIM(label)) REGEXP :re"
);
$findTeamSlugs->execute([':re' => $placeholderRegex]);
$teamSlugs = array_values(array_unique(array_map(
    static fn(array $r): string => trim((string)($r['slug'] ?? '')),
    $findTeamSlugs->fetchAll()
)));
$teamSlugs = array_values(array_filter($teamSlugs, static fn(string $s): bool => $s !== ''));

$summary = [
    'department_slugs_to_archive' => $departmentSlugs,
    'team_slugs_to_archive' => $teamSlugs,
    'users_department_to_null' => 0,
    'users_cadre_to_null' => 0,
    'questionnaire_department_rows_to_delete' => 0,
];

if ($departmentSlugs !== []) {
    $placeholders = implode(',', array_fill(0, count($departmentSlugs), '?'));

    $countUsersDepartment = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department IN ($placeholders)");
    $countUsersDepartment->execute($departmentSlugs);
    $summary['users_department_to_null'] = (int)$countUsersDepartment->fetchColumn();

    $countQuestionnaireDepartment = $pdo->prepare("SELECT COUNT(*) FROM questionnaire_department WHERE department_slug IN ($placeholders)");
    $countQuestionnaireDepartment->execute($departmentSlugs);
    $summary['questionnaire_department_rows_to_delete'] = (int)$countQuestionnaireDepartment->fetchColumn();
}

if ($teamSlugs !== []) {
    $placeholders = implode(',', array_fill(0, count($teamSlugs), '?'));
    $countUsersCadre = $pdo->prepare("SELECT COUNT(*) FROM users WHERE cadre IN ($placeholders)");
    $countUsersCadre->execute($teamSlugs);
    $summary['users_cadre_to_null'] = (int)$countUsersCadre->fetchColumn();
}

echo "Cleanup mode: " . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (!$apply) {
    exit(0);
}

try {
    $pdo->beginTransaction();

    if ($departmentSlugs !== []) {
        $placeholders = implode(',', array_fill(0, count($departmentSlugs), '?'));

        $clearUsersDepartment = $pdo->prepare("UPDATE users SET department = NULL WHERE department IN ($placeholders)");
        $clearUsersDepartment->execute($departmentSlugs);

        $deleteQuestionnaireDepartment = $pdo->prepare("DELETE FROM questionnaire_department WHERE department_slug IN ($placeholders)");
        $deleteQuestionnaireDepartment->execute($departmentSlugs);

        $archiveDepartments = $pdo->prepare("UPDATE department_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug IN ($placeholders)");
        $archiveDepartments->execute($departmentSlugs);

        $archiveDepartmentTeams = $pdo->prepare("UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE department_slug IN ($placeholders)");
        $archiveDepartmentTeams->execute($departmentSlugs);
    }

    if ($teamSlugs !== []) {
        $placeholders = implode(',', array_fill(0, count($teamSlugs), '?'));

        $clearUsersCadre = $pdo->prepare("UPDATE users SET cadre = NULL WHERE cadre IN ($placeholders)");
        $clearUsersCadre->execute($teamSlugs);

        $archiveTeams = $pdo->prepare("UPDATE department_team_catalog SET archived_at = CURRENT_TIMESTAMP WHERE slug IN ($placeholders)");
        $archiveTeams->execute($teamSlugs);
    }

    $pdo->commit();
    echo "Cleanup applied successfully." . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . PHP_EOL);
    exit(3);
}
