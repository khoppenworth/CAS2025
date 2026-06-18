<?php
require_once __DIR__ . '/config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';
$errors = [];
$success = false;
$selfRegistrationEnabled = (int)($cfg['self_registration_enabled'] ?? 1) === 1;

$values = [
    'full_name' => '',
    'email' => '',
    'username' => '',
];

function registration_email_exists(PDO $pdo, string $email): bool
{
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
        return false;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email IS NOT NULL AND TRIM(email) <> '' AND LOWER(TRIM(email)) = LOWER(?)");
    $stmt->execute([$normalizedEmail]);
    return (int)$stmt->fetchColumn() > 0;
}

function registration_username_exists(PDO $pdo, string $username): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?)');
    $stmt->execute([$username]);
    return (int)$stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$selfRegistrationEnabled) {
        $errors[] = t($t, 'self_registration_disabled', 'Self-registration is currently disabled. Please contact your administrator for access.');
    }

    $values['full_name'] = trim((string)($_POST['full_name'] ?? ''));
    $values['email'] = strtolower(trim((string)($_POST['email'] ?? '')));
    $values['username'] = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($selfRegistrationEnabled) {
        if ($values['full_name'] === '') {
            $errors[] = t($t, 'registration_name_required', 'Full name is required.');
        }
        if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = t($t, 'registration_email_invalid', 'Enter a valid email address.');
        } elseif (registration_email_exists($pdo, $values['email'])) {
            $errors[] = t($t, 'email_exists', 'A user with that email already exists.');
        }
        if ($values['username'] === '') {
            $errors[] = t($t, 'registration_username_required', 'Username is required.');
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $values['username'])) {
            $errors[] = t($t, 'registration_username_invalid', 'Username must be 3 to 100 characters and use only letters, numbers, dots, underscores, or hyphens.');
        } elseif (registration_username_exists($pdo, $values['username'])) {
            $errors[] = t($t, 'username_exists', 'A user with that username already exists.');
        }
        if ($password === '') {
            $errors[] = t($t, 'registration_password_required', 'Password is required.');
        } elseif (!password_meets_policy($password)) {
            $errors[] = t($t, 'password_policy_invalid', 'Password must be at least 8 characters and include at least one number or symbol.');
        }
        if ($password !== $confirmPassword) {
            $errors[] = t($t, 'registration_password_mismatch', 'Password confirmation does not match.');
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO users (username, password, role, full_name, email, profile_completed, account_status, must_reset_password, language) VALUES (?,?,?,?,?,0,?,0,?)');
        try {
            $stmt->execute([
                $values['username'],
                password_hash($password, PASSWORD_DEFAULT),
                'staff',
                $values['full_name'],
                $values['email'],
                'pending',
                $locale,
            ]);
            $success = true;
            $values = ['full_name' => '', 'email' => '', 'username' => ''];
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                $errors[] = t($t, 'registration_duplicate_account', 'An account with that username or email already exists.');
            } else {
                throw $e;
            }
        }
    }
}

$logoRenderPath = site_logo_url($cfg);
$logo = htmlspecialchars($logoRenderPath, ENT_QUOTES, 'UTF-8');
$logoAlt = htmlspecialchars($cfg['site_name'] ?? 'Logo', ENT_QUOTES, 'UTF-8');
$siteName = htmlspecialchars($cfg['site_name'] ?? 'My Performance', ENT_QUOTES, 'UTF-8');
$bodyClass = htmlspecialchars(trim(site_body_classes($cfg) . ' md-login-page'), ENT_QUOTES, 'UTF-8');
$bodyStyle = htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8');
$baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
$langAttr = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
$brandStyle = site_brand_style($cfg);
$languageLabel = htmlspecialchars(t($t, 'language_label', 'Language'), ENT_QUOTES, 'UTF-8');
$formAction = htmlspecialchars(url_for('register.php'), ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars(url_for('login.php'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $langAttr ?>" data-base-url="<?= $baseUrl ?>">
<head>
  <meta charset="utf-8">
  <title><?= $siteName ?> - <?= htmlspecialchars(t($t, 'create_account', 'Create Account'), ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?= $baseUrl ?>">
  <link rel="manifest" href="<?= asset_url('manifest.php') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/material.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/styles.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/auth.css') ?>">
  <?php if ($brandStyle !== ''): ?>
    <style id="md-brand-style"><?= htmlspecialchars($brandStyle, ENT_QUOTES, 'UTF-8') ?></style>
  <?php endif; ?>
</head>
<body class="<?= $bodyClass ?>" style="<?= $bodyStyle ?>" data-disable-dark-mode="1">
  <div class="login-shell">
    <div class="login-tile">
      <div class="login-visual">
        <div class="login-visual__brand">
          <img src="<?= $logo ?>" alt="<?= $logoAlt ?>" class="login-visual__logo">
          <div><h1 class="login-visual__title"><?= $siteName ?></h1></div>
        </div>
      </div>
      <div class="login-panel">
        <section class="login-panel__card" aria-labelledby="register-heading">
          <div class="login-panel__header">
            <h2 id="register-heading"><?= htmlspecialchars(t($t, 'create_account', 'Create Account'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($selfRegistrationEnabled ? t($t, 'registration_pending_intro', 'Request access with a local account. Your account will remain pending until an administrator approves it.') : t($t, 'self_registration_disabled', 'Self-registration is currently disabled. Please contact your administrator for access.'), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
          <?php if ($success): ?>
            <div class="md-alert success" role="status"><?= htmlspecialchars(t($t, 'registration_success_pending', 'Registration submitted. You can sign in, but access remains pending until an administrator approves your account.'), ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
          <?php if ($errors): ?>
            <div class="md-alert error" role="alert">
              <ul class="auth-error-list">
                <?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <?php if ($selfRegistrationEnabled): ?>
          <form method="post" class="md-form md-login-form" action="<?= $formAction ?>">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <label class="md-field"><span><?= htmlspecialchars(t($t, 'name', 'Name'), ENT_QUOTES, 'UTF-8') ?></span><input name="full_name" autocomplete="name" value="<?= htmlspecialchars($values['full_name'], ENT_QUOTES, 'UTF-8') ?>" required></label>
            <label class="md-field"><span><?= htmlspecialchars(t($t, 'email', 'Email'), ENT_QUOTES, 'UTF-8') ?></span><input type="email" name="email" autocomplete="email" value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>" required></label>
            <label class="md-field"><span><?= htmlspecialchars(t($t, 'username', 'Username'), ENT_QUOTES, 'UTF-8') ?></span><input name="username" autocomplete="username" value="<?= htmlspecialchars($values['username'], ENT_QUOTES, 'UTF-8') ?>" required></label>
            <label class="md-field"><span><?= htmlspecialchars(t($t, 'password', 'Password'), ENT_QUOTES, 'UTF-8') ?></span><input type="password" name="password" autocomplete="new-password" required></label>
            <label class="md-field"><span><?= htmlspecialchars(t($t, 'confirm_password', 'Confirm Password'), ENT_QUOTES, 'UTF-8') ?></span><input type="password" name="confirm_password" autocomplete="new-password" required></label>
            <p class="login-panel__note"><?= htmlspecialchars(t($t, 'password_policy_body', 'Use at least 8 characters and include at least one number or symbol.'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="md-form-actions md-form-actions--center md-login-actions"><button class="md-button md-primary" type="submit"><?= htmlspecialchars(t($t, 'submit_registration', 'Submit Registration'), ENT_QUOTES, 'UTF-8') ?></button></div>
          </form>
          <?php endif; ?>
          <p class="login-panel__note"><a class="md-login-footer-link" href="<?= $loginUrl ?>"><?= htmlspecialchars(t($t, 'back_to_sign_in', 'Back to sign in'), ENT_QUOTES, 'UTF-8') ?></a></p>
        </section>
        <div class="login-panel__footer"><div class="login-panel__footer-language"><span class="md-login-footer-label"><?= $languageLabel ?></span><nav class="md-login-footer-value md-login-footer-locale lang-switch" aria-label="<?= $languageLabel ?>"><?php foreach ($availableLocales as $loc): ?><a href="<?= htmlspecialchars(url_for('set_lang.php?lang=' . $loc), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(strtoupper($loc), ENT_QUOTES, 'UTF-8') ?>"><img src="<?= htmlspecialchars(asset_url('assets/images/flags/flag-' . $loc . '.svg'), ENT_QUOTES, 'UTF-8') ?>" alt="" width="26" height="26" loading="lazy" decoding="async" /><span><?= htmlspecialchars(strtoupper($loc), ENT_QUOTES, 'UTF-8') ?></span></a><?php endforeach; ?></nav></div></div>
      </div>
    </div>
  </div>
  <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
    window.APP_BASE_URL = <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>;
    window.APP_DEFAULT_LOCALE = <?= json_encode($defaultLocale, JSON_THROW_ON_ERROR) ?>;
    window.APP_AVAILABLE_LOCALES = <?= json_encode($availableLocales, JSON_THROW_ON_ERROR) ?>;
  </script>
</body>
</html>
