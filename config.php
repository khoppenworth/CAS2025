<?php
declare(strict_types=1);

$isBootstrapRequested = !defined('APP_BOOTSTRAPPED');
if ($isBootstrapRequested) {
    define('APP_BOOTSTRAPPED', true);
}

require_once __DIR__ . '/lib/email_templates.php';
require_once __DIR__ . '/lib/work_functions.php';
require_once __DIR__ . '/lib/rate_limiter.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/lib/path.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/notifications.php';

$appDebug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN);
ini_set('display_errors', $appDebug ? '1' : '0');
error_reporting(E_ALL);

define('APP_DEBUG', $appDebug);

if (!defined('JSON_THROW_ON_ERROR')) {
    define('JSON_THROW_ON_ERROR', 0);
}

define('BASE_PATH', __DIR__);

    $envPath = __DIR__ . '/.env';
    if (is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] === '#') {
                    continue;
                }
                if (strpos($trimmed, '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', $trimmed, 2);
                $key = trim($key);
                $value = trim($value);
                $length = strlen($value);
                if ($length >= 2) {
                    $first = $value[0];
                    $last = $value[$length - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }
                if ($key !== '' && getenv($key) === false) {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                }
            }
        }
    }

$baseUrlEnv = getenv('BASE_URL') ?: '/';
$normalizedBaseUrl = rtrim($baseUrlEnv, "/\/");
define('BASE_URL', ($normalizedBaseUrl === '') ? '/' : $normalizedBaseUrl . '/');

if ($isBootstrapRequested) {
    $scriptPath = trim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    $isAuthRoute = in_array($scriptPath, ['login.php', 'admin/login.php', 'oauth.php'], true);
    if ($isAuthRoute) {
        enforce_rate_limit($_SERVER);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $locale = ensure_locale();
    if (!isset($_SESSION['lang']) || $_SESSION['lang'] !== $locale) {
        $_SESSION['lang'] = $locale;
    }

    apply_security_headers($appDebug);

    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'epss_v300';
    $dbUser = getenv('DB_USER') ?: 'epss_user';
    $dbPass = getenv('DB_PASS') ?: 'StrongPassword123!';
    $dbPortRaw = getenv('DB_PORT');
    $dbPort = null;
    if ($dbPortRaw !== false && $dbPortRaw !== '') {
        $portCandidate = (int)$dbPortRaw;
        if ($portCandidate > 0 && $portCandidate <= 65535) {
            $dbPort = $portCandidate;
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort !== null ? 'port=' . $dbPort . ';' : '',
        $dbName
    );
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    } catch (PDOException $e) {
        $friendly = 'Unable to connect to the application database. Please try again later or contact support.';
        error_log('DB connection failed: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $friendly . PHP_EOL);
            exit(1);
        }
        http_response_code(500);
        if ($appDebug) {
            $friendly .= '<br>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Database error</title></head><body>';
        echo '<h1>Service unavailable</h1><p>' . $friendly . '</p>';
        echo '</body></html>';
        exit;
    }
}

if (!defined('APP_FUNCTIONS_LOADED')) {
    define('APP_FUNCTIONS_LOADED', true);

    if (!function_exists('str_starts_with')) {
function str_starts_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strncmp($haystack, $needle, strlen($needle)) === 0;
}
    }

    if (!function_exists('str_contains')) {
function str_contains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strpos($haystack, $needle) !== false;
}
    }

function site_default_brand_color(array $cfg): string
{
    $env = getenv('DEFAULT_BRAND_COLOR');
    $normalized = normalize_hex_color($env !== false ? (string)$env : '');
    if ($normalized !== null) {
        return $normalized;
    }

    $seedSource = trim((string)($cfg['site_name'] ?? ''));
    if ($seedSource === '') {
        $seedSource = 'default-brand-seed';
    }

    return derive_brand_color_from_seed($seedSource);
}


function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function request_csrf_token(): string {
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (isset($_POST['csrf'])) {
        return (string)$_POST['csrf'];
    }
    if (isset($_GET['csrf'])) {
        return (string)$_GET['csrf'];
    }
    return '';
}

function csrf_check(): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
        return;
    }
    $token = request_csrf_token();
    if ($token === '' || !isset($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $token)) {
        http_response_code(400);
        die('Invalid CSRF token');
    }
}

function refresh_current_user(PDO $pdo): void {
    if (!isset($_SESSION['user']['id'])) { return; }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    if ($row = $stmt->fetch()) {
        $_SESSION['user'] = $row;
    }
}

function auth_required(array $roles = []): void {
    if (!isset($_SESSION['user'])) {
        $loginTarget = 'login.php';
        if ($roles && in_array('admin', $roles, true)) {
            $loginTarget = 'admin/login.php';
        }
        header('Location: ' . url_for($loginTarget));
        exit;
    }
    $status = $_SESSION['user']['account_status'] ?? 'active';
    if ($status === 'disabled') {
        $_SESSION['auth_error'] = 'Your account has been disabled. Please contact your administrator.';
        unset($_SESSION['user']);
        header('Location: ' . url_for('login.php'));
        exit;
    }
    if ($status === 'pending') {
        $allowedScripts = ['profile.php', 'logout.php', 'set_lang.php'];
        $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($current, $allowedScripts, true)) {
            $_SESSION['pending_notice'] = true;
            header('Location: ' . url_for('profile.php?pending=1'));
            exit;
        }
    }
    if (!empty($_SESSION['user']['must_reset_password'])) {
        $allowedScripts = ['profile.php', 'logout.php', 'set_lang.php'];
        $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($current, $allowedScripts, true)) {
            $_SESSION['force_password_reset_notice'] = true;
            header('Location: ' . url_for('profile.php?force_password_reset=1'));
            exit;
        }
    }
    if ($roles && !in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403); die('Forbidden');
    }
}
function current_user() { return $_SESSION['user'] ?? null; }

function require_profile_completion(PDO $pdo, string $redirect = 'profile.php'): void {
    if (!isset($_SESSION['user']['id'])) { return; }
    if (($_SESSION['user']['profile_completed'] ?? 0) == 1) { return; }

    $scriptSources = [
        (string)($_SERVER['SCRIPT_NAME'] ?? ''),
        (string)($_SERVER['PHP_SELF'] ?? ''),
    ];

    $currentScript = '';
    foreach ($scriptSources as $source) {
        if ($source === '') {
            continue;
        }
        $basename = basename($source);
        if ($basename !== '') {
            $currentScript = $basename;
            break;
        }
    }

    $redirectString = (string)$redirect;
    $redirectPath = $redirectString;
    $parsedRedirect = @parse_url($redirectString);
    if (is_array($parsedRedirect) && isset($parsedRedirect['path'])) {
        $redirectPath = (string)$parsedRedirect['path'];
    }

    $redirectScript = basename($redirectPath);
    if ($currentScript !== '' && $redirectScript !== '' && $currentScript === $redirectScript) {
        return;
    }

    $defaultTarget = function_exists('url_for') ? url_for('profile.php') : ((defined('BASE_URL') ? (string)BASE_URL : '/') . 'profile.php');
    $target = $redirectString;

    $isAbsolute = is_array($parsedRedirect) && isset($parsedRedirect['scheme']) && $parsedRedirect['scheme'] !== '';
    if (!$isAbsolute) {
        if (function_exists('cleanRedirect')) {
            $target = cleanRedirect($redirectString, $defaultTarget);
        } elseif (function_exists('url_for')) {
            $target = url_for($redirectString);
        } else {
            $base = defined('BASE_URL') ? (string)BASE_URL : '/';
            $normalizedBase = rtrim($base, '/');
            $normalizedPath = '/' . ltrim($redirectPath, '/');
            if ($redirectPath === '' || $redirectPath === '/') {
                $normalizedPath = '/';
            }
            if ($normalizedBase === '') {
                $target = $normalizedPath;
            } else {
                $target = $normalizedBase . $normalizedPath;
            }
            if (is_array($parsedRedirect)) {
                if (isset($parsedRedirect['query']) && $parsedRedirect['query'] !== '') {
                    $target .= '?' . $parsedRedirect['query'];
                }
                if (isset($parsedRedirect['fragment']) && $parsedRedirect['fragment'] !== '') {
                    $target .= '#' . $parsedRedirect['fragment'];
                }
            }
        }
    }

    if ($target === '' || $target === null) {
        $target = $defaultTarget;
    }

    header('Location: ' . $target);
    exit;
}

require_once __DIR__.'/i18n.php';

function ensure_site_config_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_config (
        id INT PRIMARY KEY,
        site_name VARCHAR(200) NULL,
        landing_text TEXT NULL,
        address VARCHAR(255) NULL,
        contact VARCHAR(255) NULL,
        logo_path VARCHAR(255) NULL,
        landing_background_path VARCHAR(255) NULL,
        footer_org_name VARCHAR(255) NULL,
        footer_org_short VARCHAR(100) NULL,
        footer_website_label VARCHAR(255) NULL,
        footer_website_url VARCHAR(255) NULL,
        footer_email VARCHAR(255) NULL,
        footer_phone VARCHAR(255) NULL,
        footer_hotline_label VARCHAR(255) NULL,
        footer_hotline_number VARCHAR(50) NULL,
        footer_rights VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $existing = [];
    $columns = $pdo->query('SHOW COLUMNS FROM site_config');
    if ($columns) {
        while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
            if (isset($col['Field'])) {
                $existing[$col['Field']] = true;
            }
        }
    }

    $schema = [
        'site_name' => 'ALTER TABLE site_config ADD COLUMN site_name VARCHAR(200) NULL',
        'landing_text' => 'ALTER TABLE site_config ADD COLUMN landing_text TEXT NULL',
        'address' => 'ALTER TABLE site_config ADD COLUMN address VARCHAR(255) NULL',
        'contact' => 'ALTER TABLE site_config ADD COLUMN contact VARCHAR(255) NULL',
        'logo_path' => 'ALTER TABLE site_config ADD COLUMN logo_path VARCHAR(255) NULL',
        'landing_background_path' => 'ALTER TABLE site_config ADD COLUMN landing_background_path VARCHAR(255) NULL',
        'footer_org_name' => 'ALTER TABLE site_config ADD COLUMN footer_org_name VARCHAR(255) NULL',
        'footer_org_short' => 'ALTER TABLE site_config ADD COLUMN footer_org_short VARCHAR(100) NULL',
        'footer_website_label' => 'ALTER TABLE site_config ADD COLUMN footer_website_label VARCHAR(255) NULL',
        'footer_website_url' => 'ALTER TABLE site_config ADD COLUMN footer_website_url VARCHAR(255) NULL',
        'footer_email' => 'ALTER TABLE site_config ADD COLUMN footer_email VARCHAR(255) NULL',
        'footer_phone' => 'ALTER TABLE site_config ADD COLUMN footer_phone VARCHAR(255) NULL',
        'footer_hotline_label' => 'ALTER TABLE site_config ADD COLUMN footer_hotline_label VARCHAR(255) NULL',
        'footer_hotline_number' => 'ALTER TABLE site_config ADD COLUMN footer_hotline_number VARCHAR(50) NULL',
        'footer_rights' => 'ALTER TABLE site_config ADD COLUMN footer_rights VARCHAR(255) NULL',
        'google_oauth_enabled' => 'ALTER TABLE site_config ADD COLUMN google_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'google_oauth_client_id' => 'ALTER TABLE site_config ADD COLUMN google_oauth_client_id VARCHAR(255) NULL',
        'google_oauth_client_secret' => 'ALTER TABLE site_config ADD COLUMN google_oauth_client_secret VARCHAR(255) NULL',
        'microsoft_oauth_enabled' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'microsoft_oauth_client_id' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_client_id VARCHAR(255) NULL',
        'microsoft_oauth_client_secret' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_client_secret VARCHAR(255) NULL',
        'microsoft_oauth_tenant' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_tenant VARCHAR(255) NULL',
        'color_theme' => "ALTER TABLE site_config ADD COLUMN color_theme VARCHAR(50) NOT NULL DEFAULT 'light'",
        'brand_color' => "ALTER TABLE site_config ADD COLUMN brand_color VARCHAR(7) NULL AFTER color_theme",
        'local_login_enabled' => 'ALTER TABLE site_config ADD COLUMN local_login_enabled TINYINT(1) NOT NULL DEFAULT 1',
        'smtp_enabled' => 'ALTER TABLE site_config ADD COLUMN smtp_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'smtp_host' => 'ALTER TABLE site_config ADD COLUMN smtp_host VARCHAR(255) NULL',
        'smtp_port' => 'ALTER TABLE site_config ADD COLUMN smtp_port INT NULL',
        'smtp_username' => 'ALTER TABLE site_config ADD COLUMN smtp_username VARCHAR(255) NULL',
        'smtp_password' => 'ALTER TABLE site_config ADD COLUMN smtp_password VARCHAR(255) NULL',
        'smtp_encryption' => "ALTER TABLE site_config ADD COLUMN smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'none'",
        'smtp_from_email' => 'ALTER TABLE site_config ADD COLUMN smtp_from_email VARCHAR(255) NULL',
        'smtp_from_name' => 'ALTER TABLE site_config ADD COLUMN smtp_from_name VARCHAR(255) NULL',
        'smtp_timeout' => 'ALTER TABLE site_config ADD COLUMN smtp_timeout INT NULL',
        'enabled_locales' => 'ALTER TABLE site_config ADD COLUMN enabled_locales TEXT NULL',
        'upgrade_repo' => 'ALTER TABLE site_config ADD COLUMN upgrade_repo VARCHAR(255) NULL',
        'review_enabled' => 'ALTER TABLE site_config ADD COLUMN review_enabled TINYINT(1) NOT NULL DEFAULT 1',
        'email_templates' => 'ALTER TABLE site_config ADD COLUMN email_templates LONGTEXT NULL'
    ];

    foreach ($schema as $field => $sql) {
        if (!isset($existing[$field])) {
            $pdo->exec($sql);
        }
    }

    site_config_available_columns($pdo, true);
}

function site_config_available_columns(PDO $pdo, bool $refresh = false): array
{
    static $cache = null;

    if ($refresh || $cache === null) {
        $columns = [];

        try {
            $result = $pdo->query('SHOW COLUMNS FROM site_config');
            if ($result) {
                while ($col = $result->fetch(PDO::FETCH_ASSOC)) {
                    if (!isset($col['Field'])) {
                        continue;
                    }
                    $name = strtolower(trim((string)$col['Field']));
                    if ($name !== '') {
                        $columns[$name] = true;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('site_config_available_columns failed: ' . $e->getMessage());
            $columns = [];
        }

        $cache = $columns;
    }

    return $cache ?? [];
}

function initialize_database_schema(PDO $pdo): void
{
    static $schemaInitialized = false;

    if ($schemaInitialized) {
        return;
    }

    ensure_site_config_schema($pdo);
    ensure_users_schema($pdo);
    ensure_questionnaire_item_schema($pdo);
    ensure_questionnaire_work_function_schema($pdo);
    ensure_work_function_catalog($pdo);
    ensure_questionnaire_assignment_schema($pdo);
    ensure_biannual_performance_periods($pdo);
    ensure_analytics_report_schedule_schema($pdo);

    $schemaInitialized = true;
}

function decode_enabled_locales($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $parts = array_map('trim', explode(',', $value));
        return array_filter($parts, static fn($part) => $part !== '');
    }

    return [];
}

function encode_enabled_locales(array $locales): string
{
    $normalized = enforce_locale_requirements($locales);
    $json = json_encode($normalized);
    return $json === false ? '[]' : $json;
}

function site_enabled_locales(array $cfg): array
{
    $raw = $cfg['enabled_locales'] ?? [];
    return enforce_locale_requirements(decode_enabled_locales($raw));
}

function site_config_defaults(): array
{
    return [
        'id' => 1,
        'site_name' => 'My Performance',
        'landing_text' => null,
        'address' => null,
        'contact' => null,
        'logo_path' => null,
        'landing_background_path' => null,
        'footer_org_name' => 'Ethiopian Pharmaceutical Supply Service',
        'footer_org_short' => 'EPSS / EPS',
        'footer_website_label' => 'epss.gov.et',
        'footer_website_url' => 'https://epss.gov.et',
        'footer_email' => 'info@epss.gov.et',
        'footer_phone' => '+251 11 155 9900',
        'footer_hotline_label' => 'Hotline 939',
        'footer_hotline_number' => '939',
        'footer_rights' => 'All rights reserved.',
        'local_login_enabled' => 1,
        'google_oauth_enabled' => 0,
        'google_oauth_client_id' => null,
        'google_oauth_client_secret' => null,
        'microsoft_oauth_enabled' => 0,
        'microsoft_oauth_client_id' => null,
        'microsoft_oauth_client_secret' => null,
        'microsoft_oauth_tenant' => 'common',
        'color_theme' => 'light',
        'brand_color' => null,
        'smtp_enabled' => 0,
        'smtp_host' => null,
        'smtp_port' => 587,
        'smtp_username' => null,
        'smtp_password' => null,
        'smtp_encryption' => 'none',
        'smtp_from_email' => null,
        'smtp_from_name' => null,
        'smtp_timeout' => 20,
        'enabled_locales' => ['en', 'fr', 'am'],
        'upgrade_repo' => 'khoppenworth/HRassessv300',
        'review_enabled' => 1,
        'email_templates' => default_email_templates(),
    ];
}

/** get_site_config(): fetch branding and contact settings (singleton row id=1) */
function get_site_config(PDO $pdo): array
{
    $defaults = site_config_defaults();

    try {
        ensure_site_config_schema($pdo);
        $defaultTemplatesJson = encode_email_templates(default_email_templates());
        $quotedTemplates = $pdo->quote($defaultTemplatesJson);
        $pdo->exec("INSERT IGNORE INTO site_config (id, site_name, landing_text, address, contact, logo_path, landing_background_path, footer_org_name, footer_org_short, footer_website_label, footer_website_url, footer_email, footer_phone, footer_hotline_label, footer_hotline_number, footer_rights, local_login_enabled, google_oauth_enabled, google_oauth_client_id, google_oauth_client_secret, microsoft_oauth_enabled, microsoft_oauth_client_id, microsoft_oauth_client_secret, microsoft_oauth_tenant, color_theme, brand_color, smtp_enabled, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, smtp_from_email, smtp_from_name, smtp_timeout, enabled_locales, upgrade_repo, review_enabled, email_templates) VALUES (1, 'My Performance', NULL, NULL, NULL, NULL, NULL, 'Ethiopian Pharmaceutical Supply Service', 'EPSS / EPS', 'epss.gov.et', 'https://epss.gov.et', 'info@epss.gov.et', '+251 11 155 9900', 'Hotline 939', '939', 'All rights reserved.', 1, 0, NULL, NULL, 0, NULL, NULL, 'common', 'light', '#2073bf', 0, NULL, 587, NULL, NULL, 'none', NULL, NULL, 20, '[\"en\",\"fr\",\"am\"]', 'khoppenworth/HRassessv300', 1, $quotedTemplates)");
        $cfg = $pdo->query('SELECT * FROM site_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('get_site_config failed: ' . $e->getMessage());
        remember_available_locales($defaults['enabled_locales']);
        return $defaults;
    }

    $merged = array_merge($defaults, $cfg ?: []);
    $merged['logo_path'] = normalize_logo_path($merged['logo_path'] ?? null);
    $merged['landing_background_path'] = normalize_landing_background_path($merged['landing_background_path'] ?? null);
    $merged['enabled_locales'] = site_enabled_locales($merged);
    $merged['email_templates'] = normalize_email_templates($merged['email_templates'] ?? []);
    remember_available_locales($merged['enabled_locales']);

    return $merged;
}

function branding_logo_relative_dir(): string
{
    return 'assets/uploads/branding';
}

function branding_logo_directory(): string
{
    return base_path(branding_logo_relative_dir());
}

function ensure_branding_logo_directory(): bool
{
    $dir = branding_logo_directory();
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
    }

    return is_writable($dir);
}

function normalize_logo_path($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $normalized = str_replace('\\', '/', ltrim($trimmed, '/'));
    $relativeDir = branding_logo_relative_dir();
    $expectedPrefix = $relativeDir . '/';
    if (strpos($normalized, $expectedPrefix) !== 0) {
        return null;
    }

    $filename = basename($normalized);
    if ($filename === '' || preg_match('/[^A-Za-z0-9._-]/', $filename)) {
        return null;
    }

    return $relativeDir . '/' . $filename;
}

function normalize_landing_background_path($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $normalized = str_replace('\\', '/', ltrim($trimmed, '/'));
    $relativeDir = branding_logo_relative_dir();
    $expectedPrefix = $relativeDir . '/';
    if (strpos($normalized, $expectedPrefix) !== 0) {
        return null;
    }

    $filename = basename($normalized);
    if ($filename === '' || preg_match('/[^A-Za-z0-9._-]/', $filename)) {
        return null;
    }

    return $relativeDir . '/' . $filename;
}

function branding_logo_full_path(?string $path): ?string
{
    $normalized = normalize_logo_path($path);
    if ($normalized === null) {
        return null;
    }

    return base_path($normalized);
}

function landing_background_full_path(?string $path): ?string
{
    $normalized = normalize_landing_background_path($path);
    if ($normalized === null) {
        return null;
    }

    return base_path($normalized);
}

function site_logo_path(array $cfg): ?string
{
    $normalized = normalize_logo_path($cfg['logo_path'] ?? null);
    if ($normalized === null) {
        return null;
    }

    $fullPath = base_path($normalized);
    if (!is_file($fullPath)) {
        return null;
    }

    return $normalized;
}

function site_landing_background_path(array $cfg): ?string
{
    $normalized = normalize_landing_background_path($cfg['landing_background_path'] ?? null);
    if ($normalized === null) {
        return null;
    }

    $fullPath = base_path($normalized);
    if (!is_file($fullPath)) {
        return null;
    }

    return $normalized;
}

function site_logo_url(array $cfg): string
{
    $path = site_logo_path($cfg);
    if ($path !== null) {
        return asset_url($path);
    }

    return asset_url('logo.php');
}

function site_landing_background_url(array $cfg): string
{
    $path = site_landing_background_path($cfg);
    if ($path !== null) {
        return asset_url($path);
    }

    return '';
}

function detect_mime_type(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return strtolower(trim($mime));
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        if ($mime !== false && $mime !== '') {
            return strtolower(trim((string)$mime));
        }
    }

    return null;
}

function site_logo_mime(array $cfg): ?string
{
    $path = site_logo_path($cfg);
    if ($path === null) {
        return null;
    }

    return detect_mime_type(base_path($path));
}

function delete_branding_logo_file(?string $path): void
{
    $fullPath = branding_logo_full_path($path);
    if ($fullPath !== null && is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function delete_landing_background_file(?string $path): void
{
    $fullPath = landing_background_full_path($path);
    if ($fullPath !== null && is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function ensure_users_schema(PDO $pdo): void
{
    $existing = [];
    $columnDetails = [];
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM users');
    } catch (PDOException $e) {
        error_log('ensure_users_schema: ' . $e->getMessage());
        return;
    }
    $roleColumn = null;
    if ($columns) {
        while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
            if (isset($col['Field'])) {
                $fieldName = (string)$col['Field'];
                $existing[$fieldName] = true;
                $columnDetails[$fieldName] = $col;
            }
            if (($col['Field'] ?? '') === 'role') {
                $roleColumn = $col;
            }
        }
    }

    try {
        if ($roleColumn && isset($roleColumn['Type']) && stripos((string)$roleColumn['Type'], 'enum(') !== false) {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff'");
        }
    } catch (PDOException $e) {
        error_log('ensure_users_schema role update failed: ' . $e->getMessage());
    }

    $workFunctionColumn = $columnDetails['work_function'] ?? null;
    try {
        if ($workFunctionColumn === null) {
            $pdo->exec("ALTER TABLE users ADD COLUMN work_function VARCHAR(100) NULL AFTER cadre");
        } else {
            $type = strtolower((string)($workFunctionColumn['Type'] ?? ''));
            $nullable = strtolower((string)($workFunctionColumn['Null'] ?? '')) === 'yes';
            $needsAlter = false;
            if (str_contains($type, 'enum(')) {
                $needsAlter = true;
            } elseif (!str_contains($type, 'char')) {
                $needsAlter = true;
            } elseif (preg_match('/varchar\((\d+)\)/i', $type, $matches)) {
                if ((int)$matches[1] < 100) {
                    $needsAlter = true;
                }
            }
            if (!$nullable) {
                $needsAlter = true;
            }
            if ($needsAlter) {
                $pdo->exec('ALTER TABLE users MODIFY COLUMN work_function VARCHAR(100) NULL');
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_users_schema work_function update failed: ' . $e->getMessage());
    }

    $changes = [
        'account_status' => "ALTER TABLE users ADD COLUMN account_status ENUM('pending','active','disabled') NOT NULL DEFAULT 'active' AFTER language",
        'must_reset_password' => "ALTER TABLE users ADD COLUMN must_reset_password TINYINT(1) NOT NULL DEFAULT 0 AFTER account_status",
        'next_assessment_date' => 'ALTER TABLE users ADD COLUMN next_assessment_date DATE NULL AFTER account_status',
        'approved_by' => 'ALTER TABLE users ADD COLUMN approved_by INT NULL AFTER next_assessment_date',
        'approved_at' => 'ALTER TABLE users ADD COLUMN approved_at DATETIME NULL AFTER approved_by',
        'sso_provider' => 'ALTER TABLE users ADD COLUMN sso_provider VARCHAR(50) NULL AFTER approved_at',
    ];

    foreach ($changes as $field => $sql) {
        if (!isset($existing[$field])) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                error_log(sprintf('ensure_users_schema add column %s failed: %s', $field, $e->getMessage()));
            }
        }
    }
}

function ensure_questionnaire_item_schema(PDO $pdo): void
{
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM questionnaire_item');
        $existing = [];
        if ($columns) {
            while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
                $existing[$col['Field']] = $col;
            }
        }
        if (!isset($existing['is_required'])) {
            $pdo->exec("ALTER TABLE questionnaire_item ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_multiple");
        }
    } catch (PDOException $e) {
        error_log('ensure_questionnaire_item_schema: ' . $e->getMessage());
    }
}

function ensure_questionnaire_assignment_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaire_assignment (
            staff_id INT NOT NULL,
            questionnaire_id INT NOT NULL,
            assigned_by INT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (staff_id, questionnaire_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columnsStmt = $pdo->query('SHOW COLUMNS FROM questionnaire_assignment');
        $columns = [];
        if ($columnsStmt) {
            while ($column = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[$column['Field']] = $column;
            }
        }

        if (!isset($columns['assigned_by'])) {
            $pdo->exec('ALTER TABLE questionnaire_assignment ADD COLUMN assigned_by INT NULL AFTER questionnaire_id');
        }
        if (!isset($columns['assigned_at'])) {
            $pdo->exec("ALTER TABLE questionnaire_assignment ADD COLUMN assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER assigned_by");
        }

        $indexesStmt = $pdo->query("SHOW INDEX FROM questionnaire_assignment WHERE Key_name = 'idx_assignment_questionnaire'");
        $hasQuestionnaireIdx = $indexesStmt && $indexesStmt->fetch(PDO::FETCH_ASSOC);
        if (!$hasQuestionnaireIdx) {
            $pdo->exec('CREATE INDEX idx_assignment_questionnaire ON questionnaire_assignment (questionnaire_id)');
        }

        $assignedByIdxStmt = $pdo->query("SHOW INDEX FROM questionnaire_assignment WHERE Key_name = 'idx_assignment_assigned_by'");
        $hasAssignedByIdx = $assignedByIdxStmt && $assignedByIdxStmt->fetch(PDO::FETCH_ASSOC);
        if (!$hasAssignedByIdx) {
            $pdo->exec('CREATE INDEX idx_assignment_assigned_by ON questionnaire_assignment (assigned_by)');
        }

        $foreignKeysStmt = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'questionnaire_assignment'");
        $foreignKeys = [];
        if ($foreignKeysStmt) {
            foreach ($foreignKeysStmt->fetchAll(PDO::FETCH_COLUMN) as $constraint) {
                $foreignKeys[$constraint] = true;
            }
        }

        if (!isset($foreignKeys['fk_assignment_staff'])) {
            $pdo->exec('ALTER TABLE questionnaire_assignment ADD CONSTRAINT fk_assignment_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE');
        }
        if (!isset($foreignKeys['fk_assignment_questionnaire'])) {
            $pdo->exec('ALTER TABLE questionnaire_assignment ADD CONSTRAINT fk_assignment_questionnaire FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE');
        }
        if (!isset($foreignKeys['fk_assignment_supervisor'])) {
            $pdo->exec('ALTER TABLE questionnaire_assignment ADD CONSTRAINT fk_assignment_supervisor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL');
        }
    } catch (PDOException $e) {
        error_log('ensure_questionnaire_assignment_schema: ' . $e->getMessage());
    }
}

function ensure_biannual_performance_periods(PDO $pdo): void
{
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'performance_period'");
    } catch (PDOException $e) {
        error_log('ensure_biannual_performance_periods (table check): ' . $e->getMessage());
        return;
    }

    if (!$tableCheck || !$tableCheck->fetch(PDO::FETCH_NUM)) {
        return;
    }

    $currentYear = (int)date('Y');
    $years = range($currentYear - 2, $currentYear + 3);

    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $stmt = $pdo->prepare("INSERT INTO performance_period (label, period_start, period_end) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE period_start = VALUES(period_start), period_end = VALUES(period_end)");
        foreach ($years as $year) {
            $h1Label = sprintf('%d H1', $year);
            $stmt->execute([
                $h1Label,
                sprintf('%d-01-01', $year),
                sprintf('%d-06-30', $year),
            ]);

            $h2Label = sprintf('%d H2', $year);
            $stmt->execute([
                $h2Label,
                sprintf('%d-07-01', $year),
                sprintf('%d-12-31', $year),
            ]);
        }

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (PDOException $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ensure_biannual_performance_periods: ' . $e->getMessage());
    }
}

function ensure_analytics_report_schedule_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_report_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipients TEXT NOT NULL,
            frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
            next_run_at DATETIME NOT NULL,
            last_run_at DATETIME NULL,
            created_by INT NULL,
            questionnaire_id INT NULL,
            include_details TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_report_schedule_next_run (next_run_at),
            KEY idx_report_schedule_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columnsStmt = $pdo->query('SHOW COLUMNS FROM analytics_report_schedule');
        $existingColumns = [];
        if ($columnsStmt) {
            foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $existingColumns[] = $column['Field'];
            }
        }
        $addColumn = static function (PDO $pdo, array $columns, string $name, string $definition): void {
            if (!in_array($name, $columns, true)) {
                $pdo->exec('ALTER TABLE analytics_report_schedule ADD COLUMN ' . $definition);
            }
        };
        $addColumn($pdo, $existingColumns, 'last_run_at', 'last_run_at DATETIME NULL AFTER next_run_at');
        $addColumn($pdo, $existingColumns, 'created_by', 'created_by INT NULL AFTER last_run_at');
        $addColumn($pdo, $existingColumns, 'questionnaire_id', 'questionnaire_id INT NULL AFTER created_by');
        $addColumn($pdo, $existingColumns, 'include_details', 'include_details TINYINT(1) NOT NULL DEFAULT 0 AFTER questionnaire_id');
        $addColumn($pdo, $existingColumns, 'active', 'active TINYINT(1) NOT NULL DEFAULT 1 AFTER include_details');
        $addColumn($pdo, $existingColumns, 'created_at', 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER active');
        $addColumn($pdo, $existingColumns, 'updated_at', 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

        $indexesStmt = $pdo->query("SHOW INDEX FROM analytics_report_schedule WHERE Key_name = 'idx_report_schedule_next_run'");
        if (!$indexesStmt || !$indexesStmt->fetch()) {
            $pdo->exec('CREATE INDEX idx_report_schedule_next_run ON analytics_report_schedule (next_run_at)');
        }
        $activeIdxStmt = $pdo->query("SHOW INDEX FROM analytics_report_schedule WHERE Key_name = 'idx_report_schedule_active'");
        if (!$activeIdxStmt || !$activeIdxStmt->fetch()) {
            $pdo->exec('CREATE INDEX idx_report_schedule_active ON analytics_report_schedule (active)');
        }

        $fkStmt = $pdo->prepare(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "analytics_report_schedule" AND CONSTRAINT_NAME = ?'
        );
        if ($fkStmt) {
            $fkStmt->execute(['fk_report_schedule_creator']);
            if (!$fkStmt->fetch()) {
                $pdo->exec('ALTER TABLE analytics_report_schedule ADD CONSTRAINT fk_report_schedule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
            }
            $fkStmt->execute(['fk_report_schedule_questionnaire']);
            if (!$fkStmt->fetch()) {
                $pdo->exec('ALTER TABLE analytics_report_schedule ADD CONSTRAINT fk_report_schedule_questionnaire FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE SET NULL');
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_analytics_report_schedule_schema: ' . $e->getMessage());
    }
}

function site_color_theme(array $cfg): string
{
    $theme = strtolower((string)($cfg['color_theme'] ?? 'light'));
    $allowed = ['light', 'dark'];
    if (!in_array($theme, $allowed, true)) {
        $theme = 'light';
    }
    return $theme;
}

function site_body_classes(array $cfg): string
{
    $classes = ['md-bg'];
    $theme = site_color_theme($cfg);
    $classes[] = 'theme-' . $theme;
    return implode(' ', array_unique(array_filter($classes)));
}

function site_brand_color(array $cfg): string
{
    $candidate = normalize_hex_color((string)($cfg['brand_color'] ?? ''));
    if ($candidate !== null) {
        return $candidate;
    }

    return site_default_brand_color($cfg);
}

function site_brand_palette(array $cfg): array
{
    $base = site_brand_color($cfg);
    $rgb = hex_to_rgb($base);
    $primaryDark = shade_color($base, 0.32);
    $primaryDarker = shade_color($base, 0.5);
    $primaryLight = tint_color($base, 0.32);
    $secondary = tint_color($base, 0.45);
    $muted = shade_color($base, 0.6);
    $mutedLight = tint_color($base, 0.55);
    $bgStart = tint_color($primaryLight, 0.35);
    $bgMid = tint_color($secondary, 0.2);
    $bgEnd = tint_color($base, 0.55);

    return [
        'primary' => $base,
        'primaryDark' => $primaryDark,
        'primaryDarker' => $primaryDarker,
        'primaryLight' => $primaryLight,
        'secondary' => $secondary,
        'muted' => $muted,
        'mutedLight' => $mutedLight,
        'rgb' => $rgb,
        'bgStart' => $bgStart,
        'bgMid' => $bgMid,
        'bgEnd' => $bgEnd,
    ];
}

function site_brand_style(array $cfg): string
{
    $tokens = site_theme_tokens($cfg);
    if ($tokens === []) {
        return '';
    }

    $selectors = [
        'root' => ':root',
        'light' => 'body.theme-light',
        'dark' => 'body.theme-dark',
    ];

    $blocks = [];
    foreach ($selectors as $key => $selector) {
        if (!isset($tokens[$key]) || $tokens[$key] === []) {
            continue;
        }
        $pairs = [];
        foreach ($tokens[$key] as $var => $value) {
            $pairs[] = $var . ': ' . $value;
        }
        if ($pairs !== []) {
            $blocks[] = $selector . ' { ' . implode('; ', $pairs) . ' }';
        }
    }

    return implode(' ', $blocks);
}

function site_body_style(array $cfg): string
{
    return site_brand_style($cfg);
}

function render_help_icon(string $tooltip, bool $standalone = false): string
{
    $escaped = htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8');
    $classes = ['md-help-bubble'];
    if ($standalone) {
        $classes[] = 'md-help-bubble--standalone';
    }

    return sprintf(
        '<span class="%s" tabindex="0" data-tooltip="%s" aria-label="%s"><span class="md-help-icon" aria-hidden="true">i</span></span>',
        implode(' ', $classes),
        $escaped,
        $escaped
    );
}

function site_theme_tokens(array $cfg): array
{
    $palette = site_brand_palette($cfg);
    $primary = $palette['primary'];
    $primaryDark = $palette['primaryDark'];
    $primaryDarker = $palette['primaryDarker'];
    $primaryLight = $palette['primaryLight'];
    $secondary = $palette['secondary'];
    $muted = $palette['muted'];
    $mutedLight = $palette['mutedLight'];

    $accent = adjust_hsl($primary, 35.0, 1.05, 1.12);
    $semanticDanger = '#dc2626';
    $semanticWarning = '#f59e0b';
    $semanticSuccess = '#16a34a';
    $warning = $semanticWarning;
    $danger = $semanticDanger;
    $success = $semanticSuccess;
    $info = adjust_hsl($primary, -20.0, 1.08, 1.02);

    $lightSurface = tint_color($primary, 0.9);
    $lightSurfaceAlt = tint_color($primary, 0.95);
    $lightSurfaceMuted = tint_color($primary, 0.85);
    $lightSurfaceHighlight = tint_color($primary, 0.82);
    $lightText = adjust_hsl($primary, 0.0, 1.05, 0.38);
    $lightTextSecondary = adjust_hsl($primary, 0.0, 1.0, 0.46);
    $lightTextMuted = adjust_hsl($primary, 0.0, 0.88, 0.58);
    $inverseText = adjust_hsl($primary, 0.0, 0.25, 0.92);

    $onPrimary = contrast_color($primary);
    $onPrimarySoft = rgba_string($onPrimary, 0.22);
    $onPrimarySubtle = rgba_string($onPrimary, 0.16);
    $onSurface = contrast_color($lightSurfaceAlt);
    $onSurfaceMuted = rgba_string(shade_color($onSurface, 0.18), 1.0);
    $onSurfaceStrong = shade_color($onSurface, 0.08);

    $lightBorder = rgba_string($primary, 0.16);
    $lightBorderStrong = rgba_string($primaryDark, 0.24);
    $lightDivider = rgba_string($primaryDarker, 0.12);

    $shadowSoft = '0 18px 44px ' . rgba_string($primaryDarker, 0.22);
    $shadowStrong = '0 22px 54px ' . rgba_string($primaryDarker, 0.3);
    $appBarShadow = '0 14px 34px ' . rgba_string($primaryDarker, 0.32);
    $chipShadow = '0 8px 20px ' . rgba_string($primaryDark, 0.18);

    $floatingShadow = '0 18px 44px ' . rgba_string($primaryDarker, 0.28);
    $floatingShadowStrong = '0 22px 54px ' . rgba_string($primaryDarker, 0.32);

    $primarySoft = rgba_string($primary, 0.14);
    $primarySofter = rgba_string($primary, 0.2);
    $secondarySoft = rgba_string($secondary, 0.18);

    $successSoft = rgba_string($success, 0.2);
    $warningSoft = rgba_string($warning, 0.22);
    $dangerSoft = rgba_string($danger, 0.24);
    $infoSoft = rgba_string($info, 0.2);
    $semanticDangerSoft = rgba_string($semanticDanger, 0.24);
    $semanticWarningSoft = rgba_string($semanticWarning, 0.22);
    $semanticSuccessSoft = rgba_string($semanticSuccess, 0.2);

    $successSurface = tint_color($success, 0.85);
    $warningSurface = tint_color($warning, 0.86);
    $dangerSurface = tint_color($danger, 0.88);
    $infoSurface = tint_color($info, 0.86);
    $semanticDangerSurface = tint_color($semanticDanger, 0.88);
    $semanticWarningSurface = tint_color($semanticWarning, 0.86);
    $semanticSuccessSurface = tint_color($semanticSuccess, 0.85);

    $successBorder = rgba_string($success, 0.28);
    $warningBorder = rgba_string($warning, 0.28);
    $dangerBorder = rgba_string($danger, 0.28);
    $infoBorder = rgba_string($info, 0.26);
    $semanticDangerBorder = rgba_string($semanticDanger, 0.28);
    $semanticWarningBorder = rgba_string($semanticWarning, 0.28);
    $semanticSuccessBorder = rgba_string($semanticSuccess, 0.28);

    $successText = contrast_color($success);
    $warningText = contrast_color($warning);
    $dangerText = contrast_color($danger);
    $infoText = contrast_color($info);
    $semanticDangerText = contrast_color($semanticDanger);
    $semanticWarningText = contrast_color($semanticWarning);
    $semanticSuccessText = contrast_color($semanticSuccess);
    $semanticDangerStrong = shade_color($semanticDanger, 0.18);

    $successGradient = sprintf('linear-gradient(160deg, %s 0%%, %s 55%%, %s 100%%)', shade_color($success, 0.55), shade_color($success, 0.45), shade_color($success, 0.7));

    $bgGradient = sprintf('linear-gradient(140deg, %s 0%%, %s 45%%, %s 100%%)', $palette['bgStart'], $palette['bgMid'], $palette['bgEnd']);

    $darkBackground = shade_color($primary, 0.88);
    $darkSurface = shade_color($primary, 0.82);
    $darkSurfaceAlt = shade_color($primary, 0.76);
    $darkSurfaceMuted = shade_color($primary, 0.72);
    $darkText = tint_color($primary, 0.68);
    $darkTextSecondary = tint_color($primary, 0.58);
    $darkTextMuted = tint_color($primary, 0.5);
    $darkBorder = rgba_string($darkText, 0.24);
    $darkBorderStrong = rgba_string($darkText, 0.32);
    $darkDivider = rgba_string($darkText, 0.18);
    $darkShadow = '0 18px 46px ' . rgba_string(shade_color($primary, 0.74), 0.7);
    $darkShadowStrong = '0 22px 60px ' . rgba_string(shade_color($primary, 0.7), 0.78);
    $darkPrimarySoft = rgba_string($primaryLight, 0.22);
    $darkPrimarySofter = rgba_string($primaryLight, 0.32);
    $darkSecondary = tint_color($secondary, 0.32);
    $darkSecondarySoft = rgba_string($darkSecondary, 0.28);
    $darkAccent = tint_color($accent, 0.28);
    $darkAccentSoft = rgba_string($darkAccent, 0.32);
    $darkDanger = tint_color($danger, 0.28);
    $darkDangerSoft = rgba_string($darkDanger, 0.38);
    $darkWarning = tint_color($warning, 0.3);
    $darkWarningSoft = rgba_string($darkWarning, 0.34);
    $darkInfo = tint_color($info, 0.32);
    $darkInfoSoft = rgba_string($darkInfo, 0.32);
    $semanticDangerDark = tint_color($semanticDanger, 0.32);
    $semanticWarningDark = tint_color($semanticWarning, 0.32);
    $semanticSuccessDark = tint_color($semanticSuccess, 0.32);
    $semanticDangerDarkSoft = rgba_string($semanticDangerDark, 0.38);
    $semanticWarningDarkSoft = rgba_string($semanticWarningDark, 0.34);
    $semanticSuccessDarkSoft = rgba_string($semanticSuccessDark, 0.32);
    $semanticDangerDarkSurface = tint_color($semanticDangerDark, 0.88);
    $semanticWarningDarkSurface = tint_color($semanticWarningDark, 0.86);
    $semanticSuccessDarkSurface = tint_color($semanticSuccessDark, 0.85);
    $semanticDangerDarkBorder = rgba_string($semanticDangerDark, 0.28);
    $semanticWarningDarkBorder = rgba_string($semanticWarningDark, 0.28);
    $semanticSuccessDarkBorder = rgba_string($semanticSuccessDark, 0.28);
    $semanticDangerDarkText = contrast_color($semanticDangerDark);
    $semanticWarningDarkText = contrast_color($semanticWarningDark);
    $semanticSuccessDarkText = contrast_color($semanticSuccessDark);
    $semanticDangerDarkStrong = shade_color($semanticDangerDark, 0.18);
    $darkInputBg = rgba_string($darkSurfaceAlt, 0.92);
    $darkOnPrimary = contrast_color($primaryLight);
    $darkOnPrimarySoft = rgba_string($darkOnPrimary, 0.22);
    $darkOnPrimarySubtle = rgba_string($darkOnPrimary, 0.16);
    $darkOnSurfaceMuted = tint_color($primary, 0.55);
    $darkBgGradient = sprintf('radial-gradient(circle at top, %s 0%%, %s 45%%, %s 100%%)', shade_color($primary, 0.78), shade_color($primary, 0.82), shade_color($primary, 0.92));

    $root = [
        '--brand-primary' => $primary,
        '--brand-primary-dark' => $primaryDark,
        '--brand-primary-darker' => $primaryDarker,
        '--brand-primary-light' => '#ffffff',
        '--brand-secondary' => $secondary,
        '--brand-muted' => $muted,
        '--brand-muted-light' => $mutedLight,
        '--brand-shadow' => $shadowSoft,
        '--brand-border' => $lightBorder,
        '--brand-bg' => $bgGradient,
        '--appbar-shadow' => $appBarShadow,
        '--chip-shadow' => $chipShadow,
        '--floating-shadow' => $floatingShadow,
        '--floating-shadow-strong' => $floatingShadowStrong,
        '--status-success' => $success,
        '--status-success-soft' => $successSoft,
        '--status-success-text' => $successText,
        '--status-success-border' => $successBorder,
        '--status-success-surface' => $successSurface,
        '--status-success-gradient' => $successGradient,
        '--status-warning' => $warning,
        '--status-warning-soft' => $warningSoft,
        '--status-warning-text' => $warningText,
        '--status-warning-border' => $warningBorder,
        '--status-warning-surface' => $warningSurface,
        '--status-danger' => $danger,
        '--status-danger-soft' => $dangerSoft,
        '--status-danger-text' => $dangerText,
        '--status-danger-border' => $dangerBorder,
        '--status-danger-surface' => $dangerSurface,
        '--status-info' => $info,
        '--status-info-soft' => $infoSoft,
        '--status-info-text' => $infoText,
        '--status-info-border' => $infoBorder,
        '--status-info-surface' => $infoSurface,
        '--semantic-success' => $semanticSuccess,
        '--semantic-success-soft' => $semanticSuccessSoft,
        '--semantic-success-text' => $semanticSuccessText,
        '--semantic-success-border' => $semanticSuccessBorder,
        '--semantic-success-surface' => $semanticSuccessSurface,
        '--semantic-warning' => $semanticWarning,
        '--semantic-warning-soft' => $semanticWarningSoft,
        '--semantic-warning-text' => $semanticWarningText,
        '--semantic-warning-border' => $semanticWarningBorder,
        '--semantic-warning-surface' => $semanticWarningSurface,
        '--semantic-danger' => $semanticDanger,
        '--semantic-danger-strong' => $semanticDangerStrong,
        '--semantic-danger-soft' => $semanticDangerSoft,
        '--semantic-danger-text' => $semanticDangerText,
        '--semantic-danger-border' => $semanticDangerBorder,
        '--semantic-danger-surface' => $semanticDangerSurface,
        '--app-hero-gradient' => $bgGradient,
        '--app-success-gradient' => $successGradient,
    ];

    $light = [
        '--brand-primary-light' => '#ffffff',
        '--app-primary' => $primary,
        '--app-primary-dark' => $primaryDark,
        '--app-primary-darker' => $primaryDarker,
        '--app-primary-light' => $primaryLight,
        '--app-secondary' => $secondary,
        '--app-secondary-soft' => $secondarySoft,
        '--app-accent' => $accent,
        '--app-accent-soft' => rgba_string($accent, 0.24),
        '--app-muted' => $muted,
        '--app-muted-light' => $mutedLight,
        '--app-border' => $lightBorder,
        '--app-border-strong' => $lightBorderStrong,
        '--app-divider' => $lightDivider,
        '--app-surface' => $lightSurface,
        '--app-surface-alt' => $lightSurfaceAlt,
        '--app-surface-muted' => $lightSurfaceMuted,
        '--app-surface-highlight' => $lightSurfaceHighlight,
        '--app-bg' => $bgGradient,
        '--app-shadow-soft' => $shadowSoft,
        '--app-shadow-strong' => $shadowStrong,
        '--app-primary-soft' => $primarySoft,
        '--app-primary-softer' => $primarySofter,
        '--app-danger' => $danger,
        '--app-danger-soft' => $dangerSoft,
        '--app-warning' => $warning,
        '--app-warning-soft' => $warningSoft,
        '--app-info' => $info,
        '--app-info-soft' => $infoSoft,
        '--app-input-bg' => rgba_string($lightSurfaceAlt, 0.96),
        '--app-on-primary' => $onPrimary,
        '--app-on-primary-soft' => $onPrimarySoft,
        '--app-on-primary-subtle' => $onPrimarySubtle,
        '--app-on-surface' => $onSurface,
        '--app-on-surface-muted' => $onSurfaceMuted,
        '--app-on-surface-strong' => $onSurfaceStrong,
        '--app-text-primary' => $lightText,
        '--app-text-secondary' => $lightTextSecondary,
        '--app-text-muted' => $lightTextMuted,
        '--app-text-inverse' => $inverseText,
        '--app-table-stripe' => rgba_string($primary, 0.06),
        '--app-table-border' => $lightDivider,
        '--app-chart-grid' => rgba_string($primary, 0.14),
        '--app-chart-axis' => shade_color($primary, 0.45),
        '--app-chart-label' => $lightTextSecondary,
        '--app-chart-surface' => $lightSurface,
        '--app-chip-bg' => rgba_string($primary, 0.08),
        '--app-chip-border' => $lightBorder,
        '--footer-bg-start' => $onSurfaceStrong,
        '--footer-bg-mid' => $onSurfaceStrong,
        '--footer-bg-end' => $onSurfaceMuted,
        '--footer-text' => $lightSurfaceAlt,
        '--footer-link' => $lightSurfaceHighlight,
        '--footer-link-hover' => $inverseText,
        'color' => $lightText,
    ];

    $dark = [
        '--brand-primary-light' => $primaryLight,
        '--app-primary' => $primaryLight,
        '--app-primary-dark' => $primary,
        '--app-primary-darker' => $primaryDark,
        '--app-primary-light' => $primaryLight,
        '--app-secondary' => $darkSecondary,
        '--app-secondary-soft' => $darkSecondarySoft,
        '--app-accent' => $darkAccent,
        '--app-accent-soft' => $darkAccentSoft,
        '--app-muted' => $darkTextSecondary,
        '--app-muted-light' => $darkTextMuted,
        '--app-border' => $darkBorder,
        '--app-border-strong' => $darkBorderStrong,
        '--app-divider' => $darkDivider,
        '--app-surface' => $darkSurface,
        '--app-surface-alt' => $darkSurfaceAlt,
        '--app-surface-muted' => $darkSurfaceMuted,
        '--app-surface-highlight' => tint_color($darkSurfaceAlt, 0.1),
        '--app-bg' => $darkBgGradient,
        '--app-shadow-soft' => $darkShadow,
        '--app-shadow-strong' => $darkShadowStrong,
        '--app-primary-soft' => $darkPrimarySoft,
        '--app-primary-softer' => $darkPrimarySofter,
        '--app-danger' => $darkDanger,
        '--app-danger-soft' => $darkDangerSoft,
        '--app-warning' => $darkWarning,
        '--app-warning-soft' => $darkWarningSoft,
        '--app-info' => $darkInfo,
        '--app-info-soft' => $darkInfoSoft,
        '--app-input-bg' => $darkInputBg,
        '--app-on-primary' => $darkOnPrimary,
        '--app-on-primary-soft' => $darkOnPrimarySoft,
        '--app-on-primary-subtle' => $darkOnPrimarySubtle,
        '--app-on-surface' => $darkText,
        '--app-on-surface-muted' => $darkOnSurfaceMuted,
        '--app-on-surface-strong' => tint_color($darkText, 0.08),
        '--app-text-primary' => $darkText,
        '--app-text-secondary' => $darkTextSecondary,
        '--app-text-muted' => $darkTextMuted,
        '--app-text-inverse' => $inverseText,
        '--semantic-success' => $semanticSuccessDark,
        '--semantic-success-soft' => $semanticSuccessDarkSoft,
        '--semantic-success-text' => $semanticSuccessDarkText,
        '--semantic-success-border' => $semanticSuccessDarkBorder,
        '--semantic-success-surface' => $semanticSuccessDarkSurface,
        '--semantic-warning' => $semanticWarningDark,
        '--semantic-warning-soft' => $semanticWarningDarkSoft,
        '--semantic-warning-text' => $semanticWarningDarkText,
        '--semantic-warning-border' => $semanticWarningDarkBorder,
        '--semantic-warning-surface' => $semanticWarningDarkSurface,
        '--semantic-danger' => $semanticDangerDark,
        '--semantic-danger-strong' => $semanticDangerDarkStrong,
        '--semantic-danger-soft' => $semanticDangerDarkSoft,
        '--semantic-danger-text' => $semanticDangerDarkText,
        '--semantic-danger-border' => $semanticDangerDarkBorder,
        '--semantic-danger-surface' => $semanticDangerDarkSurface,
        '--app-table-stripe' => rgba_string($darkText, 0.08),
        '--app-table-border' => $darkDivider,
        '--app-chart-grid' => rgba_string($primaryLight, 0.18),
        '--app-chart-axis' => tint_color($darkText, 0.1),
        '--app-chart-label' => $darkText,
        '--app-chart-surface' => $darkSurface,
        '--app-chip-bg' => rgba_string($primaryLight, 0.12),
        '--app-chip-border' => $darkBorder,
        'color' => $darkText,
        'background-color' => $darkBackground,
    ];

    return [
        'root' => $root,
        'light' => $light,
        'dark' => $dark,
    ];
}

function site_chart_palette(array $cfg): array
{
    $tokens = site_theme_tokens($cfg);
    $theme = site_color_theme($cfg);
    $themeVars = $tokens[$theme === 'dark' ? 'dark' : 'light'] ?? [];
    $rootVars = $tokens['root'] ?? [];

    $brand = site_brand_palette($cfg);

    $value = static function (array $vars, string $key, string $fallback) use ($brand) {
        $candidate = $vars[$key] ?? '';
        if (trim($candidate) !== '') {
            return $candidate;
        }
        switch ($fallback) {
            case 'surface':
                return tint_color($brand['primary'], 0.92);
            case 'surfaceAlt':
                return tint_color($brand['primary'], 0.96);
            case 'grid':
                return tint_color($brand['primary'], 0.85);
            case 'labels':
                return shade_color($brand['primary'], 0.55);
            case 'axis':
                return $brand['muted'];
            case 'title':
                return shade_color($brand['primary'], 0.6);
            case 'line':
            default:
                return $brand['primary'];
        }
    };

    return [
        'margin' => $value($themeVars, '--app-surface', 'surface'),
        'plot' => $value($themeVars, '--app-surface-alt', 'surfaceAlt'),
        'grid' => $value($themeVars, '--app-divider', 'grid'),
        'labels' => $value($themeVars, '--app-text-secondary', 'labels'),
        'axis' => $value($themeVars, '--app-muted', 'axis'),
        'title' => $value($themeVars, '--app-text-primary', 'title'),
        'line' => $value($themeVars, '--app-primary', 'line'),
    ];
}

function hex_to_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $int = hexdec($hex);
    return [($int >> 16) & 0xff, ($int >> 8) & 0xff, $int & 0xff];
}

function normalize_hex_color(string $color): ?string
{
    $trimmed = strtolower(trim($color));
    if ($trimmed === '') {
        return null;
    }
    if (!preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/', $trimmed, $matches)) {
        return null;
    }
    $value = ltrim($matches[0], '#');
    if (strlen($value) === 3) {
        $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
    }
    return '#' . $value;
}

function derive_brand_color_from_seed(string $seed): string
{
    $normalized = strtolower(trim($seed));
    if ($normalized === '') {
        $normalized = 'brand-seed';
    }
    $hash = hash('sha256', $normalized);
    $hue = hexdec(substr($hash, 0, 6)) % 360;
    $saturation = 0.55 + (hexdec(substr($hash, 6, 6)) / 0xffffff) * 0.35;
    $lightness = 0.45 + (hexdec(substr($hash, 12, 6)) / 0xffffff) * 0.2;
    return hsl_to_hex($hue, clamp_float($saturation, 0.0, 1.0), clamp_float($lightness, 0.0, 1.0));
}

function mix_colors(string $hex, string $target, float $ratio): string
{
    $ratio = max(0.0, min(1.0, $ratio));
    [$r1, $g1, $b1] = hex_to_rgb($hex);
    [$r2, $g2, $b2] = hex_to_rgb($target);
    $mix = static function ($a, $b) use ($ratio) {
        return (int)round($a * (1 - $ratio) + $b * $ratio);
    };
    return sprintf('#%02x%02x%02x', $mix($r1, $r2), $mix($g1, $g2), $mix($b1, $b2));
}

function tint_color(string $hex, float $ratio): string
{
    [$h, $s, $l] = hex_to_hsl($hex);
    $ratio = clamp_float($ratio, 0.0, 1.0);
    $newLightness = clamp_float($l + (1.0 - $l) * $ratio, 0.0, 1.0);
    $newSaturation = clamp_float($s * (1.0 - 0.35 * $ratio), 0.0, 1.0);
    return hsl_to_hex($h, $newSaturation, $newLightness);
}

function shade_color(string $hex, float $ratio): string
{
    [$h, $s, $l] = hex_to_hsl($hex);
    $ratio = clamp_float($ratio, 0.0, 1.0);
    $newLightness = clamp_float($l * (1.0 - 0.85 * $ratio), 0.0, 1.0);
    $newSaturation = clamp_float($s + (1.0 - $s) * 0.2 * $ratio, 0.0, 1.0);
    return hsl_to_hex($h, $newSaturation, $newLightness);
}

function color_components(string $color): array
{
    $trimmed = trim($color);
    $hex = normalize_hex_color($trimmed);
    if ($hex !== null) {
        return hex_to_rgb($hex);
    }
    if (preg_match('/^rgba?\(([^\)]+)\)$/i', $trimmed, $matches)) {
        $parts = array_map('trim', explode(',', $matches[1]));
        $r = (int)round((float)($parts[0] ?? 0));
        $g = (int)round((float)($parts[1] ?? 0));
        $b = (int)round((float)($parts[2] ?? 0));
        return [max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b))];
    }
    throw new InvalidArgumentException('Unsupported color format: ' . $color);
}

function rgba_string(string $hex, float $alpha): string
{
    [$r, $g, $b] = hex_to_rgb($hex);
    $alpha = max(0.0, min(1.0, $alpha));
    return sprintf('rgba(%d, %d, %d, %.3f)', $r, $g, $b, $alpha);
}

function clamp_float(float $value, float $min, float $max): float
{
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function hex_to_hsl(string $hex): array
{
    [$r, $g, $b] = hex_to_rgb($hex);
    $r /= 255;
    $g /= 255;
    $b /= 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, $l];
    }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    switch ($max) {
        case $r:
            $h = (($g - $b) / $d) + ($g < $b ? 6 : 0);
            break;
        case $g:
            $h = (($b - $r) / $d) + 2;
            break;
        default:
            $h = (($r - $g) / $d) + 4;
            break;
    }
    $h *= 60;
    return [$h, $s, $l];
}

function hsl_to_hex(float $h, float $s, float $l): string
{
    $normalizedHue = fmod($h, 360.0);
    if ($normalizedHue < 0.0) {
        $normalizedHue += 360.0;
    }
    $h = $normalizedHue / 360.0;
    $s = clamp_float($s, 0.0, 1.0);
    $l = clamp_float($l, 0.0, 1.0);

    if ($s === 0.0) {
        $v = (int)round($l * 255);
        return sprintf('#%02x%02x%02x', $v, $v, $v);
    }

    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $convert = static function (float $t) use ($p, $q): float {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }
        return $p;
    };

    $r = $convert($h + 1 / 3);
    $g = $convert($h);
    $b = $convert($h - 1 / 3);

    return sprintf('#%02x%02x%02x', (int)round($r * 255), (int)round($g * 255), (int)round($b * 255));
}

function adjust_hsl(string $hex, float $hShift, float $sMul, float $lMul): string
{
    [$h, $s, $l] = hex_to_hsl($hex);
    $h = $h + $hShift;
    $s = clamp_float($s * $sMul, 0.0, 1.0);
    $l = clamp_float($l * $lMul, 0.0, 1.0);
    return hsl_to_hex($h, $s, $l);
}

function relative_luminance(array $rgb): float
{
    $transform = static function (float $value): float {
        $value /= 255;
        if ($value <= 0.03928) {
            return $value / 12.92;
        }
        return pow(($value + 0.055) / 1.055, 2.4);
    };

    $r = $transform($rgb[0]);
    $g = $transform($rgb[1]);
    $b = $transform($rgb[2]);

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function contrast_color(string $hex): string
{
    $rgb = hex_to_rgb($hex);
    $luminance = relative_luminance($rgb);
    if ($luminance > 0.5) {
        return shade_color($hex, 0.85);
    }
    return tint_color($hex, 0.85);
}



    if ($isBootstrapRequested && isset($pdo) && function_exists('initialize_database_schema')) {
        try {
            initialize_database_schema($pdo);
        } catch (Throwable $e) {
            error_log('Database schema initialization failed: ' . $e->getMessage());
        }
    }
    $configMigrationPath = __DIR__ . '/config.migrations.php';
    if (is_readable($configMigrationPath)) {
        require_once $configMigrationPath;
    }
}
?>
