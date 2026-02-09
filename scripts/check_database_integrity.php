<?php
declare(strict_types=1);

/**
 * Script to validate the database schema used by the application.
 *
 * Usage: php scripts/check_database_integrity.php
 */

// Prevent config.php from auto bootstrapping (sessions, schema migrations, etc.).
define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../config.php';

/**
 * Establish a PDO connection using the same environment defaults as the app.
 */
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
        ($dbPortRaw !== false && $dbPortRaw !== '') ? 'port=' . ((int) $dbPortRaw) . ';' : '',
        $dbName
    );

    return new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Fetch the columns for a table and normalise the result set.
 *
 * @return array<string, array{type:string,null:string,key:?string,default:mixed,extra:string}>
 */
function fetch_table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $columns = [];

    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$col['Field']] = [
            'type' => strtolower((string) ($col['Type'] ?? '')),
            'null' => strtoupper((string) ($col['Null'] ?? '')),
            'key' => $col['Key'] !== '' ? $col['Key'] : null,
            'default' => $col['Default'] ?? null,
            'extra' => strtolower((string) ($col['Extra'] ?? '')),
        ];
    }

    return $columns;
}

/**
 * Compare actual schema against expected requirements.
 *
 * @param array<string, array{type?:string, null?:string, default?:string|null, key?:string|null, extra?:string|null}> $expectedColumns
 * @return list<string>
 */
function validate_table_schema(PDO $pdo, string $table, array $expectedColumns): array
{
    try {
        $columns = fetch_table_columns($pdo, $table);
    } catch (PDOException $e) {
        return [sprintf('Table "%s" is missing: %s', $table, $e->getMessage())];
    }

    $issues = [];

    foreach ($expectedColumns as $name => $requirements) {
        if (!isset($columns[$name])) {
            $issues[] = sprintf('Missing column "%s.%s"', $table, $name);
            continue;
        }

        $actual = $columns[$name];
        if (isset($requirements['type']) && stripos($actual['type'], (string) $requirements['type']) === false) {
            $issues[] = sprintf(
                'Column "%s.%s" has type "%s" but expected it to include "%s"',
                $table,
                $name,
                $actual['type'],
                $requirements['type']
            );
        }

        if (isset($requirements['null']) && $actual['null'] !== strtoupper((string) $requirements['null'])) {
            $issues[] = sprintf(
                'Column "%s.%s" NULL flag is "%s" but expected "%s"',
                $table,
                $name,
                $actual['null'],
                strtoupper((string) $requirements['null'])
            );
        }

        if (array_key_exists('default', $requirements)) {
            $expectedDefault = $requirements['default'];
            $actualDefault = $actual['default'];
            if ($expectedDefault === null && $actualDefault !== null) {
                $issues[] = sprintf('Column "%s.%s" default is "%s" but expected NULL', $table, $name, (string) $actualDefault);
            } elseif ($expectedDefault !== null && (string) $actualDefault !== (string) $expectedDefault) {
                $issues[] = sprintf(
                    'Column "%s.%s" default is "%s" but expected "%s"',
                    $table,
                    $name,
                    (string) $actualDefault,
                    (string) $expectedDefault
                );
            }
        }

        if (isset($requirements['key'])) {
            $expectedKey = strtoupper((string) $requirements['key']);
            $actualKey = $actual['key'] !== null ? strtoupper($actual['key']) : '';
            if ($actualKey !== $expectedKey) {
                $issues[] = sprintf(
                    'Column "%s.%s" key is "%s" but expected "%s"',
                    $table,
                    $name,
                    $actualKey !== '' ? $actualKey : 'NONE',
                    $expectedKey
                );
            }
        }

        if (isset($requirements['extra']) && stripos($actual['extra'], (string) $requirements['extra']) === false) {
            $issues[] = sprintf(
                'Column "%s.%s" extra attributes are "%s" but expected to include "%s"',
                $table,
                $name,
                $actual['extra'],
                $requirements['extra']
            );
        }
    }

    return $issues;
}

/**
 * Validate that the singleton site configuration record exists.
 *
 * @return list<string>
 */
function validate_site_config_row(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT COUNT(*) AS c FROM site_config WHERE id = 1');
    $count = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    if ($count !== 1) {
        return ['site_config table should contain exactly one row with id = 1'];
    }

    return [];
}

try {
    $pdo = connect_to_database();
} catch (PDOException $e) {
    fwrite(STDERR, 'Unable to connect to the database: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$expectedSchemas = [
    'site_config' => [
        'id' => ['type' => 'int', 'null' => 'NO', 'key' => 'PRI'],
        'site_name' => ['type' => 'varchar(200)', 'null' => 'YES'],
        'landing_text' => ['type' => 'text', 'null' => 'YES'],
        'address' => ['type' => 'varchar', 'null' => 'YES'],
        'contact' => ['type' => 'varchar', 'null' => 'YES'],
        'logo_path' => ['type' => 'varchar', 'null' => 'YES'],
        'landing_background_path' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_org_name' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_org_short' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_website_label' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_website_url' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_email' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_phone' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_hotline_label' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_hotline_number' => ['type' => 'varchar', 'null' => 'YES'],
        'footer_rights' => ['type' => 'varchar', 'null' => 'YES'],
        'google_oauth_enabled' => ['type' => 'tinyint', 'null' => 'NO', 'default' => '0'],
        'google_oauth_client_id' => ['type' => 'varchar', 'null' => 'YES'],
        'google_oauth_client_secret' => ['type' => 'varchar', 'null' => 'YES'],
        'microsoft_oauth_enabled' => ['type' => 'tinyint', 'null' => 'NO', 'default' => '0'],
        'microsoft_oauth_client_id' => ['type' => 'varchar', 'null' => 'YES'],
        'microsoft_oauth_client_secret' => ['type' => 'varchar', 'null' => 'YES'],
        'microsoft_oauth_tenant' => ['type' => 'varchar', 'null' => 'YES'],
        'color_theme' => ['type' => 'varchar', 'null' => 'NO', 'default' => 'light'],
        'brand_color' => ['type' => 'varchar(7)', 'null' => 'YES'],
        'smtp_enabled' => ['type' => 'tinyint', 'null' => 'NO', 'default' => '0'],
        'smtp_host' => ['type' => 'varchar', 'null' => 'YES'],
        'smtp_port' => ['type' => 'int', 'null' => 'YES'],
        'smtp_username' => ['type' => 'varchar', 'null' => 'YES'],
        'smtp_password' => ['type' => 'varchar', 'null' => 'YES'],
        'smtp_encryption' => ['type' => 'varchar', 'null' => 'NO', 'default' => 'none'],
        'smtp_from_email' => ['type' => 'varchar', 'null' => 'YES'],
        'smtp_from_name' => ['type' => 'varchar', 'null' => 'YES'],
        'smtp_timeout' => ['type' => 'int', 'null' => 'YES'],
        'enabled_locales' => ['type' => 'text', 'null' => 'YES'],
        'upgrade_repo' => ['type' => 'varchar', 'null' => 'YES'],
    ],
    'users' => [
        'id' => ['type' => 'int', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
        'username' => ['type' => 'varchar', 'null' => 'NO'],
        'password' => ['type' => 'varchar', 'null' => 'NO'],
        'role' => ['type' => 'varchar', 'null' => 'NO', 'default' => 'staff'],
        'work_function' => ['type' => 'varchar', 'null' => 'YES'],
        'language' => ['type' => 'varchar', 'null' => 'YES'],
        'account_status' => ['type' => "enum('pending','active','disabled')", 'null' => 'NO', 'default' => 'active'],
        'must_reset_password' => ['type' => 'tinyint', 'null' => 'NO', 'default' => '0'],
        'next_assessment_date' => ['type' => 'date', 'null' => 'YES'],
        'approved_by' => ['type' => 'int', 'null' => 'YES'],
        'approved_at' => ['type' => 'datetime', 'null' => 'YES'],
        'sso_provider' => ['type' => 'varchar', 'null' => 'YES'],
        'first_login_at' => ['type' => 'datetime', 'null' => 'YES'],
    ],
    'questionnaire_item' => [
        'is_required' => ['type' => 'tinyint', 'null' => 'NO', 'default' => '0'],
    ],
    'questionnaire_work_function' => [
        'questionnaire_id' => ['type' => 'int', 'null' => 'NO'],
        'work_function' => ['type' => 'varchar', 'null' => 'NO'],
    ],
    'work_function_catalog' => [
        'slug' => ['type' => 'varchar', 'null' => 'NO', 'key' => 'PRI'],
        'label' => ['type' => 'varchar', 'null' => 'NO'],
        'sort_order' => ['type' => 'int', 'null' => 'NO'],
        'archived_at' => ['type' => 'datetime', 'null' => 'YES'],
        'created_at' => ['type' => 'datetime', 'null' => 'NO'],
    ],
    'questionnaire_assignment' => [
        'staff_id' => ['type' => 'int', 'null' => 'NO'],
        'questionnaire_id' => ['type' => 'int', 'null' => 'NO'],
        'assigned_by' => ['type' => 'int', 'null' => 'YES'],
        'assigned_at' => ['type' => 'datetime', 'null' => 'NO'],
    ],
    'analytics_report_schedule' => [
        'id' => ['type' => 'int', 'null' => 'NO', 'key' => 'PRI', 'extra' => 'auto_increment'],
        'recipients' => ['type' => 'text', 'null' => 'NO'],
        'frequency' => ['type' => "enum('daily','weekly','monthly')", 'null' => 'NO'],
        'next_run_at' => ['type' => 'datetime', 'null' => 'NO'],
        'last_run_at' => ['type' => 'datetime', 'null' => 'YES'],
        'created_by' => ['type' => 'int', 'null' => 'YES'],
        'questionnaire_id' => ['type' => 'int', 'null' => 'YES'],
        'include_details' => ['type' => 'tinyint', 'null' => 'NO'],
        'active' => ['type' => 'tinyint', 'null' => 'NO'],
        'created_at' => ['type' => 'datetime', 'null' => 'NO'],
        'updated_at' => ['type' => 'datetime', 'null' => 'NO'],
    ],
];

$issues = [];
foreach ($expectedSchemas as $table => $columns) {
    $issues = array_merge($issues, validate_table_schema($pdo, $table, $columns));
}

$issues = array_merge($issues, validate_site_config_row($pdo));

if (empty($issues)) {
    fwrite(STDOUT, "Database structure matches application expectations.\n");
    exit(0);
}

fwrite(STDERR, "Database integrity issues detected:\n");
foreach ($issues as $issue) {
    fwrite(STDERR, ' - ' . $issue . PHP_EOL);
}

exit(1);
