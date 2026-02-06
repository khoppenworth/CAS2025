<?php
require_once __DIR__ . '/config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';
$err = '';
$localLoginEnabled = (int)($cfg['local_login_enabled'] ?? 1) === 1;

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

if (!empty($_SESSION['oauth_error'])) {
    $err = (string)$_SESSION['oauth_error'];
    unset($_SESSION['oauth_error']);
}
if (!empty($_SESSION['auth_error'])) {
    $err = (string)$_SESSION['auth_error'];
    unset($_SESSION['auth_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$localLoginEnabled) {
        $err = t(
            $t,
            'local_login_disabled',
            'Local account sign-in is disabled. Use single sign-on to continue.'
        );
    }

    if ($err !== '') {
        // Skip credential processing when local login is disabled.
        goto render_login;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $u = $stmt->fetch();

    if ($u && password_verify($password, $u['password'])) {
        if (($u['account_status'] ?? 'active') === 'disabled') {
            $err = t($t, 'account_disabled', 'Your account has been disabled. Please contact your administrator.');
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
            } elseif (($u['account_status'] ?? 'active') === 'pending') {
                $_SESSION['pending_notice'] = true;
                header('Location: ' . url_for('profile.php?pending=1'));
            } else {
                header('Location: ' . url_for('my_performance.php'));
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
$landingTextRaw = $cfg['landing_text'] ?? '';
$landingText = htmlspecialchars($landingTextRaw, ENT_QUOTES, 'UTF-8');
$address = htmlspecialchars($cfg['address'] ?? '', ENT_QUOTES, 'UTF-8');
$contact = htmlspecialchars($cfg['contact'] ?? '', ENT_QUOTES, 'UTF-8');
$bodyClassRaw = trim(site_body_classes($cfg) . ' md-login-page');
$bodyClass = htmlspecialchars($bodyClassRaw, ENT_QUOTES, 'UTF-8');
$bodyStyle = htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8');
$baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
$langAttr = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
$brandStyle = site_brand_style($cfg);
$introText = $landingText !== ''
    ? $landingText
    : htmlspecialchars(
        t(
            $t,
            'welcome_msg',
            'Sign in to start your self-assessment and track your performance over time.'
        ),
        ENT_QUOTES,
        'UTF-8'
    );
$languageLabel = htmlspecialchars(t($t, 'language_label', 'Language'), ENT_QUOTES, 'UTF-8');
$signInHeading = t($t, 'sign_in_heading', 'Welcome back');
$signInSubheading = t(
    $t,
    'sign_in_subheading',
    'Use your credentials to continue to your personalized workspace.'
);
$formAction = htmlspecialchars(url_for('login.php'), ENT_QUOTES, 'UTF-8');
$offlineRedirect = htmlspecialchars(url_for('my_performance.php'), ENT_QUOTES, 'UTF-8');
$offlineWarmRoutes = htmlspecialchars(implode(',', [
    url_for('my_performance.php'),
    url_for('submit_assessment.php'),
    url_for('profile.php'),
    url_for('dashboard.php'),
]), ENT_QUOTES, 'UTF-8');
$offlineUnavailable = htmlspecialchars(t($t, 'offline_login_unavailable', 'Offline login is not available yet. Connect to the internet and sign in once to enable offline access.'), ENT_QUOTES, 'UTF-8');
$offlineInvalid = htmlspecialchars(t($t, 'offline_login_invalid', 'Offline sign-in failed. Double-check your username and password.'), ENT_QUOTES, 'UTF-8');
$offlineError = htmlspecialchars(t($t, 'offline_login_error', 'We could not complete offline sign-in. Try again when you have a connection.'), ENT_QUOTES, 'UTF-8');
$loginHighlights = [
    htmlspecialchars(t($t, 'login_highlight_one', 'Single, secure access for every role.'), ENT_QUOTES, 'UTF-8'),
    htmlspecialchars(t($t, 'login_highlight_two', 'Keep your assessments and feedback in sync.'), ENT_QUOTES, 'UTF-8'),
    htmlspecialchars(t($t, 'login_highlight_three', 'Optimized for fast check-ins on any device.'), ENT_QUOTES, 'UTF-8'),
];

render_login:
?>
<!doctype html>
<html lang="<?= $langAttr ?>" data-base-url="<?= $baseUrl ?>">
<head>
  <meta charset="utf-8">
  <title><?= $siteName ?></title>
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
<body class="<?= $bodyClass ?>" style="<?= $bodyStyle ?>">
  <div class="login-shell">
    <div class="login-tile">
      <div class="login-visual">
        <div class="login-visual__brand">
          <img src="<?= $logo ?>" alt="<?= $logoAlt ?>" class="login-visual__logo">
          <div>
            <p class="login-visual__tagline"><?= htmlspecialchars(t($t, 'login_tagline', 'Secure staff performance portal'), ENT_QUOTES, 'UTF-8') ?></p>
            <h1 class="login-visual__title"><?= $siteName ?></h1>
          </div>
        </div>
        <?php if ($introText !== ''): ?>
          <p class="login-visual__intro"><?= $introText ?></p>
        <?php endif; ?>
        <ul class="login-visual__highlights" role="list">
          <?php foreach ($loginHighlights as $highlight): ?>
            <li>
              <span class="login-visual__bullet" aria-hidden="true"></span>
              <span><?= $highlight ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="login-panel">
        <section class="login-panel__card" aria-labelledby="sign-in-heading">
          <div class="login-panel__header">
            <h2 id="sign-in-heading"><?= htmlspecialchars($signInHeading, ENT_QUOTES, 'UTF-8') ?></h2>
            <?php if (trim($signInSubheading) !== ''): ?>
              <p><?= htmlspecialchars($signInSubheading, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>

          <?php if ($err !== ''): ?>
            <div class="md-alert error" role="alert"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <?php if ($localLoginEnabled): ?>
            <form
              method="post"
              class="md-form md-login-form"
              action="<?= $formAction ?>"
              data-offline-redirect="<?= $offlineRedirect ?>"
              data-offline-unavailable="<?= $offlineUnavailable ?>"
              data-offline-invalid="<?= $offlineInvalid ?>"
              data-offline-error="<?= $offlineError ?>"
              data-offline-warm-routes="<?= $offlineWarmRoutes ?>"
            >
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
                <button class="md-button md-elev-1 md-sso-btn google" type="submit">
                  <?= t($t, 'sign_in', 'Sign In') ?>
                </button>
              </div>
            </form>
          <?php else: ?>
            <div class="md-alert info" role="note">
              <?= htmlspecialchars(t($t, 'local_login_disabled_notice', 'Local account sign-in is disabled. Use Google single sign-on to continue.'), ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>

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
        </section>

        <div class="login-panel__footer">
          <?php if ($address !== ''): ?>
            <div>
              <span class="md-login-footer-label"><?= t($t, 'address_label', 'Address') ?></span>
              <span class="md-login-footer-value"><?= $address ?></span>
            </div>
          <?php endif; ?>
          <?php if ($contact !== ''): ?>
            <div>
              <span class="md-login-footer-label"><?= t($t, 'contact_label', 'Contact') ?></span>
              <span class="md-login-footer-value"><?= $contact ?></span>
            </div>
          <?php endif; ?>
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

  <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
    window.APP_BASE_URL = <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>;
    window.APP_DEFAULT_LOCALE = <?= json_encode($defaultLocale, JSON_THROW_ON_ERROR) ?>;
    window.APP_AVAILABLE_LOCALES = <?= json_encode($availableLocales, JSON_THROW_ON_ERROR) ?>;
  </script>
  <script src="<?= asset_url('assets/js/app.js') ?>"></script>
  <script src="<?= asset_url('assets/js/login.js') ?>" defer></script>
</body>
</html>
