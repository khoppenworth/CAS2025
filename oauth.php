<?php
require_once __DIR__ . '/config.php';

$provider = strtolower(trim((string)($_GET['provider'] ?? '')));
$allowedProviders = ['google', 'microsoft'];
if (!in_array($provider, $allowedProviders, true)) {
    http_response_code(400);
    echo 'Unknown provider.';
    exit;
}

$cfg = get_site_config($pdo);
$action = strtolower((string)($_GET['action'] ?? 'start'));
if ($action !== 'callback') {
    $action = 'start';
}

$providerConfig = build_provider_config($cfg, $provider);
if (!$providerConfig['enabled']) {
    oauth_fail('This sign-in method is not currently available. Please use the standard login form.', $provider);
}

if ($action === 'start') {
    $state = bin2hex(random_bytes(24));
    if (!isset($_SESSION['oauth_state']) || !is_array($_SESSION['oauth_state'])) {
        $_SESSION['oauth_state'] = [];
    }
    $_SESSION['oauth_state'][$provider] = $state;

    $redirectUri = oauth_redirect_uri($provider);
    $params = [
        'client_id' => $providerConfig['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => implode(' ', $providerConfig['scopes']),
        'state' => $state,
    ];
    $authParams = array_merge($params, $providerConfig['authorize_params']);
    $authUrl = $providerConfig['authorize_url'] . '?' . http_build_query($authParams, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $authUrl);
    exit;
}

if (isset($_GET['error'])) {
    oauth_fail('Sign-in was cancelled or denied. Please try again.', $provider);
}

$expectedState = $_SESSION['oauth_state'][$provider] ?? '';
$receivedState = (string)($_GET['state'] ?? '');
if ($expectedState === '' || $receivedState === '' || !hash_equals($expectedState, $receivedState)) {
    unset($_SESSION['oauth_state'][$provider]);
    oauth_fail('Authentication attempt could not be validated. Please try again.', $provider);
}
unset($_SESSION['oauth_state'][$provider]);

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    oauth_fail('Missing authorization code from the identity provider.', $provider);
}

$redirectUri = oauth_redirect_uri($provider);
$tokenPayload = [
    'client_id' => $providerConfig['client_id'],
    'client_secret' => $providerConfig['client_secret'],
    'code' => $code,
    'grant_type' => 'authorization_code',
    'redirect_uri' => $redirectUri,
    'scope' => implode(' ', $providerConfig['scopes']),
];

try {
    [$tokenStatus, $tokenData] = oauth_post_json($providerConfig['token_url'], $tokenPayload);
} catch (RuntimeException $e) {
    oauth_fail('Could not complete authentication: ' . oauth_sanitize($e->getMessage()), $provider);
}

if ($tokenStatus >= 400) {
    $errorMessage = isset($tokenData['error_description']) ? $tokenData['error_description'] : ($tokenData['error'] ?? 'Authentication failed.');
    oauth_fail('Authentication failed: ' . oauth_sanitize((string)$errorMessage), $provider);
}

$accessToken = (string)($tokenData['access_token'] ?? '');
if ($accessToken === '') {
    oauth_fail('The identity provider did not return an access token.', $provider);
}

try {
    [$profileStatus, $profileData] = oauth_get_json($providerConfig['userinfo_url'], $accessToken, $providerConfig['userinfo_headers']);
} catch (RuntimeException $e) {
    oauth_fail('Unable to retrieve your profile information: ' . oauth_sanitize($e->getMessage()), $provider);
}

if ($profileStatus >= 400) {
    oauth_fail('Unable to retrieve your profile information. Please try again.', $provider);
}

[$email, $displayName] = extract_identity($profileData, $provider);
if ($email === '') {
    oauth_fail('Unable to determine your account email address from the identity provider.', $provider);
}

$user = lookup_user_by_identity($pdo, $email, $displayName);
$created = false;
if (!$user) {
    try {
        $user = create_sso_user($pdo, $email, $displayName, $provider);
        if ($user) {
            $created = true;
            notify_supervisors_of_pending_user($pdo, $cfg, $user);
        }
    } catch (Exception $e) {
        error_log('SSO auto-provision failed: ' . $e->getMessage());
    }
    if (!$user) {
        oauth_fail('Unable to create an account for ' . $email . '. Please contact your administrator.', $provider);
    }
}

if (($user['account_status'] ?? 'active') === 'disabled') {
    oauth_fail('Your account has been disabled. Please contact your administrator.', $provider);
}

if (empty($user['first_login_at'])) {
    $pdo->prepare('UPDATE users SET first_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
}

$_SESSION['user'] = $user;
refresh_current_user($pdo);
if (isset($_SESSION['user']['language']) && $_SESSION['user']['language'] !== '') {
    $_SESSION['lang'] = $_SESSION['user']['language'];
}

$status = $_SESSION['user']['account_status'] ?? 'active';
if ($status === 'pending') {
    $_SESSION['pending_notice'] = true;
    if ($created) {
        $_SESSION['oauth_error'] = 'Your account has been created and is awaiting supervisor approval. You can complete your profile while you wait.';
    }
    header('Location: ' . url_for('profile.php?pending=1'));
    exit;
}

header('Location: ' . url_for('submit_assessment.php'));
exit;

function oauth_fail(string $message, string $provider): void
{
    $_SESSION['oauth_error'] = $message;
    header('Location: ' . url_for('login.php'));
    exit;
}

function oauth_redirect_uri(string $provider): string
{
    $base = rtrim(BASE_URL, '/');
    $path = $base === '' ? '/oauth.php' : $base . '/oauth.php';
    if (!preg_match('#^https?://#i', $path)) {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $path = $scheme . '://' . $host . $path;
    }
    return $path . '?provider=' . rawurlencode($provider) . '&action=callback';
}

function build_provider_config(array $cfg, string $provider): array
{
    $defaults = [
        'enabled' => false,
        'client_id' => null,
        'client_secret' => null,
        'authorize_url' => '',
        'authorize_params' => [],
        'token_url' => '',
        'userinfo_url' => '',
        'userinfo_headers' => ['Accept: application/json'],
        'scopes' => [],
    ];

    if ($provider === 'google') {
        $clientId = trim((string)($cfg['google_oauth_client_id'] ?? ''));
        $clientSecret = trim((string)($cfg['google_oauth_client_secret'] ?? ''));
        return array_merge($defaults, [
            'enabled' => ((int)($cfg['google_oauth_enabled'] ?? 0) === 1) && $clientId !== '' && $clientSecret !== '',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'authorize_params' => ['prompt' => 'select_account', 'access_type' => 'online'],
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes' => ['openid', 'email', 'profile'],
        ]);
    }

    $clientId = trim((string)($cfg['microsoft_oauth_client_id'] ?? ''));
    $clientSecret = trim((string)($cfg['microsoft_oauth_client_secret'] ?? ''));
    $tenant = trim((string)($cfg['microsoft_oauth_tenant'] ?? 'common'));
    if ($tenant === '') {
        $tenant = 'common';
    }
    $tenantSafe = preg_replace('/[^A-Za-z0-9\.-]/', '', $tenant) ?: 'common';
    $tenantSafe = strtolower($tenantSafe);

    return array_merge($defaults, [
        'enabled' => ((int)($cfg['microsoft_oauth_enabled'] ?? 0) === 1) && $clientId !== '' && $clientSecret !== '',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'authorize_url' => 'https://login.microsoftonline.com/' . rawurlencode($tenantSafe) . '/oauth2/v2.0/authorize',
        'authorize_params' => ['response_mode' => 'query'],
        'token_url' => 'https://login.microsoftonline.com/' . rawurlencode($tenantSafe) . '/oauth2/v2.0/token',
        'userinfo_url' => 'https://graph.microsoft.com/v1.0/me?$select=displayName,mail,userPrincipalName',
        'userinfo_headers' => ['Accept: application/json; charset=utf-8'],
        'scopes' => ['openid', 'email', 'profile', 'User.Read'],
    ]);
}

function oauth_post_json(string $url, array $params): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialise HTTP client.');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP request failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Unexpected response from identity provider.');
    }
    return [$status, $data];
}

function oauth_get_json(string $url, string $accessToken, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialise HTTP client.');
    }
    $headers = array_merge($extraHeaders, ['Authorization: Bearer ' . $accessToken]);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP request failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Unexpected response from identity provider.');
    }
    return [$status, $data];
}

function extract_identity(array $profile, string $provider): array
{
    $email = '';
    $name = '';
    if ($provider === 'google') {
        $email = strtolower(trim((string)($profile['email'] ?? '')));
        $name = trim((string)($profile['name'] ?? ''));
    } else {
        $emailRaw = (string)($profile['mail'] ?? '');
        if ($emailRaw === '') {
            $emailRaw = (string)($profile['userPrincipalName'] ?? '');
        }
        $email = strtolower(trim($emailRaw));
        $name = trim((string)($profile['displayName'] ?? ''));
    }
    return [$email, $name];
}

function lookup_user_by_identity(PDO $pdo, string $email, string $displayName)
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }

    if (str_contains($email, '@')) {
        $local = substr($email, 0, strpos($email, '@'));
        if ($local !== '') {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
            $stmt->execute([$local]);
            $user = $stmt->fetch();
            if ($user) {
                return $user;
            }
        }
    }

    if ($displayName !== '') {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1');
        $stmt->execute([$displayName]);
        $user = $stmt->fetch();
        if ($user) {
            return $user;
        }
    }

    return false;
}

function create_sso_user(PDO $pdo, string $email, string $displayName, string $provider)
{
    $username = generate_unique_username($pdo, $email, $displayName);
    if ($username === '') {
        return false;
    }
    $password = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, password, role, full_name, email, profile_completed, account_status, sso_provider, language) VALUES (?,?,?,?,?,0,?, ?, ?)');
    $language = 'en';
    $stmt->execute([
        $username,
        $hash,
        'staff',
        $displayName !== '' ? $displayName : null,
        $email !== '' ? $email : null,
        'pending',
        $provider,
        $language,
    ]);
    $id = (int)$pdo->lastInsertId();
    $lookup = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $lookup->execute([$id]);
    return $lookup->fetch();
}

function generate_unique_username(PDO $pdo, string $email, string $displayName): string
{
    $candidates = [];
    $email = trim(strtolower($email));
    if ($email !== '' && strpos($email, '@') !== false) {
        $local = substr($email, 0, strpos($email, '@'));
        $local = preg_replace('/[^a-z0-9_.-]+/i', '', (string)$local);
        if ($local !== '') {
            $candidates[] = $local;
        }
        $emailSanitized = preg_replace('/[^a-z0-9_.-]+/i', '', $email);
        if ($emailSanitized !== '') {
            $candidates[] = $emailSanitized;
        }
    }
    $nameSlug = preg_replace('/[^a-z0-9]+/i', '.', strtolower($displayName));
    $nameSlug = trim($nameSlug, '.');
    if ($nameSlug !== '') {
        $candidates[] = $nameSlug;
    }
    $candidates[] = 'user';

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $base = substr($candidate, 0, 100);
        $test = $base;
        $suffix = 1;
        while (true) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $stmt->execute([$test]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $test;
            }
            $suffix++;
            $test = substr($base, 0, 90) . $suffix;
            if ($suffix > 5000) {
                break;
            }
        }
    }
    return '';
}

function oauth_sanitize(string $message): string
{
    $clean = strip_tags($message);
    $clean = preg_replace('/\s+/', ' ', $clean ?? '');
    return trim((string)$clean);
}
