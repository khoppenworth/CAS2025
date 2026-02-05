<?php
require_once __DIR__ . '/../config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$availableLocales = available_locales();
$err = '';

$oauthProviders = [];
if (
    (int)($cfg['google_oauth_enabled'] ?? 0) === 1
    && !empty($cfg['google_oauth_client_id'])
    && !empty($cfg['google_oauth_client_secret'])
) {
    $oauthProviders['google'] = t($t, 'sign_in_with_google', 'Sign in with Google');
}
if (
    (int)($cfg['microsoft_oauth_enabled'] ?? 0) === 1
    && !empty($cfg['microsoft_oauth_client_id'])
    && !empty($cfg['microsoft_oauth_client_secret'])
) {
    $oauthProviders['microsoft'] = t($t, 'sign_in_with_microsoft', 'Sign in with Microsoft');
}

if (!empty($_SESSION['user'])) {
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        header('Location: ' . url_for('admin/dashboard.php'));
        exit;
    }
    header('Location: ' . url_for('my_performance.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $u = $stmt->fetch();

    if ($u && password_verify($password, $u['password'])) {
        if (($u['account_status'] ?? 'active') === 'disabled') {
            $err = t($t, 'account_disabled', 'Your account has been disabled. Please contact your administrator.');
        } elseif (($u['account_status'] ?? 'active') === 'pending') {
            $err = t($t, 'account_pending_admin', 'This account is pending approval. An administrator must activate it before you can continue.');
        } elseif (($u['role'] ?? '') !== 'admin') {
            $err = t($t, 'admin_only_access', 'Administrator access is required to use this page.');
        } else {
            if (empty($u['first_login_at'])) {
                $pdo->prepare('UPDATE users SET first_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
                $u['first_login_at'] = date('Y-m-d H:i:s');
            }
            $_SESSION['user'] = $u;
            $_SESSION['lang'] = resolve_locale($u['language'] ?? ($_SESSION['lang'] ?? 'en'));

            if (!empty($u['must_reset_password'])) {
                $_SESSION['force_password_reset_notice'] = true;
                header('Location: ' . url_for('profile.php?force_password_reset=1'));
            } else {
                header('Location: ' . url_for('admin/dashboard.php'));
            }
            exit;
        }
    } else {
        $err = t($t, 'invalid_login', 'Invalid username or password');
    }
}

$logoRenderPath = site_logo_url($cfg);
$logo = htmlspecialchars($logoRenderPath, ENT_QUOTES, 'UTF-8');
$logoAlt = htmlspecialchars($cfg['site_name'] ?? 'Logo', ENT_QUOTES, 'UTF-8');
$siteName = htmlspecialchars($cfg['site_name'] ?? 'My Performance', ENT_QUOTES, 'UTF-8');
$bodyClassRaw = trim(site_body_classes($cfg) . ' md-login-page');
$bodyClass = htmlspecialchars($bodyClassRaw, ENT_QUOTES, 'UTF-8');
$bodyStyle = htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8');
$baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
$langAttr = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
$brandStyle = site_brand_style($cfg);
$formAction = htmlspecialchars(url_for('admin/login.php'), ENT_QUOTES, 'UTF-8');
$languageLabel = htmlspecialchars(t($t, 'language_label', 'Language'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $langAttr ?>" data-base-url="<?= $baseUrl ?>">
<head>
  <meta charset="utf-8">
  <title><?= $siteName ?> — Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?= $baseUrl ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/material.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/styles.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/auth.css') ?>">
  <?php if ($brandStyle !== ''): ?>
    <style id="md-brand-style"><?= htmlspecialchars($brandStyle, ENT_QUOTES, 'UTF-8') ?></style>
  <?php endif; ?>
</head>
<body class="<?= $bodyClass ?>" style="<?= $bodyStyle ?>">
  <div class="login-shell">
    <div class="login-tile">
      <div class="login-visual">
        <div class="login-visual__brand">
          <img src="<?= $logo ?>" alt="<?= $logoAlt ?>" class="login-visual__logo">
          <div>
            <p class="login-visual__tagline">
              <?= htmlspecialchars(t($t, 'admin_portal_tagline', 'Secure administrator access'), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <h1 class="login-visual__title">Admin — <?= $siteName ?></h1>
          </div>
        </div>
        <p class="login-visual__intro">
          <?= htmlspecialchars(t($t, 'admin_login_intro', 'Manage system settings, users, and reviews from the dedicated admin area.'), ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>
      <div class="login-panel">
        <section class="login-panel__card" aria-labelledby="admin-sign-in-heading">
          <div class="login-panel__header">
            <h2 id="admin-sign-in-heading">
              <?= htmlspecialchars(t($t, 'admin_sign_in_heading', 'Administrator sign in'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <p><?= htmlspecialchars(t($t, 'admin_sign_in_subheading', 'Use your administrator credentials to access the dashboard.'), ENT_QUOTES, 'UTF-8') ?></p>
          </div>

          <?php if ($err !== ''): ?>
            <div class="md-alert error" role="alert"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <form method="post" class="md-form md-login-form" action="<?= $formAction ?>">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <label class="md-field">
              <span><?= t($t, 'username', 'Username') ?></span>
              <input name="username" autocomplete="username" required>
            </label>
            <label class="md-field">
              <span><?= t($t, 'password', 'Password') ?></span>
              <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <div class="md-form-actions md-form-actions--center md-login-actions">
              <button class="md-button md-primary md-elev-2" type="submit">
                <?= t($t, 'sign_in', 'Sign In') ?>
              </button>
            </div>
          </form>

          <?php if (!empty($oauthProviders)): ?>
            <div class="md-login-divider"><span><?= htmlspecialchars(t($t, 'or_continue_with', 'or continue with'), ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="md-sso-buttons">
              <?php foreach ($oauthProviders as $provider => $label): ?>
                <a
                  class="md-button md-elev-1 md-sso-btn <?= htmlspecialchars($provider, ENT_QUOTES, 'UTF-8') ?>"
                  href="<?= htmlspecialchars(url_for('oauth.php?provider=' . $provider), ENT_QUOTES, 'UTF-8') ?>"
                ><?= $label ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <p class="md-help-note login-panel__note">
            <a class="md-login-footer-link" href="<?= htmlspecialchars(url_for('login.php'), ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars(t($t, 'back_to_user_login', 'Back to employee login'), ENT_QUOTES, 'UTF-8') ?>
            </a>
          </p>
        </section>
        <div class="login-panel__footer">
          <div>
            <span class="md-login-footer-label"><?= $languageLabel ?></span>
            <nav class="md-login-footer-value md-login-footer-locale lang-switch" aria-label="<?= $languageLabel ?>">
              <?php foreach ($availableLocales as $loc): ?>
                <a href="<?= htmlspecialchars(url_for('set_lang.php?lang=' . $loc), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($loc), ENT_QUOTES, 'UTF-8') ?></a>
              <?php endforeach; ?>
            </nav>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="<?= asset_url('assets/js/app.js') ?>" defer></script>
</body>
</html>
