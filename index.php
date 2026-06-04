<?php
require_once __DIR__ . '/config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';

$logoRenderPath = site_logo_url($cfg);
$landingBackgroundRenderPath = site_landing_background_url($cfg);
$landingTextRaw = trim((string)($cfg['landing_text'] ?? ''));
$defaultHeroSubtitle = t(
    $t,
    'hero_subtitle',
    'From planning to recognition, help teams stay aligned with clear goals, real-time updates, and easy collaboration.'
);
$landingCopyRaw = ($landingTextRaw !== '' && $locale === $defaultLocale)
    ? $landingTextRaw
    : $defaultHeroSubtitle;

$toAbsoluteUrl = static function (string $url): string {
    $trimmed = trim($url);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $trimmed) === 1) {
        return $trimmed;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if ($host === '') {
        return $trimmed;
    }

    if ($trimmed[0] !== '/') {
        $trimmed = '/' . $trimmed;
    }

    return $scheme . '://' . $host . $trimmed;
};

$logo = htmlspecialchars($logoRenderPath, ENT_QUOTES, 'UTF-8');
$logoAlt = htmlspecialchars($cfg['site_name'] ?? 'Logo', ENT_QUOTES, 'UTF-8');
$siteName = htmlspecialchars($cfg['site_name'] ?? 'My Performance', ENT_QUOTES, 'UTF-8');
$bodyClass = trim(site_body_classes($cfg) . ' landing-body');
$bodyStyle = site_body_style($cfg);
$brandStyle = site_brand_style($cfg);
$baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
$langAttr = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars(url_for('login.php'), ENT_QUOTES, 'UTF-8');
$homeUrl = htmlspecialchars(url_for(''), ENT_QUOTES, 'UTF-8');
$currentLocale = $locale ?: $defaultLocale;
$localeCount = count($availableLocales);
$localeIndex = $localeCount > 0 ? array_search($currentLocale, $availableLocales, true) : false;
$nextLocale = $localeCount > 0
    ? $availableLocales[(($localeIndex === false ? 0 : $localeIndex) + 1) % $localeCount]
    : $currentLocale;
$currentLocaleBadge = htmlspecialchars(strtoupper((string)$currentLocale), ENT_QUOTES, 'UTF-8');
$currentLocaleFlag = htmlspecialchars(asset_url('assets/images/flags/flag-' . $currentLocale . '.svg'), ENT_QUOTES, 'UTF-8');
$languageSwitchLabel = htmlspecialchars(t($t, 'language_switch', 'Switch language'), ENT_QUOTES, 'UTF-8');
$languageSwitchUrl = htmlspecialchars(url_for('set_lang.php?lang=' . $nextLocale), ENT_QUOTES, 'UTF-8');
$primaryCta = htmlspecialchars(t($t, 'sign_in', 'Sign In'), ENT_QUOTES, 'UTF-8');
$heroEyebrow = htmlspecialchars(t($t, 'hero_eyebrow', 'National performance excellence platform'), ENT_QUOTES, 'UTF-8');
$heroTitle = htmlspecialchars(t($t, 'hero_title', 'Bring every performance conversation into one vibrant workspace'), ENT_QUOTES, 'UTF-8');
$heroSubtitle = htmlspecialchars(
    $landingCopyRaw,
    ENT_QUOTES,
    'UTF-8'
);
$heroDescription = htmlspecialchars(
    trim((string)preg_replace('/\s+/', ' ', strip_tags($landingCopyRaw))),
    ENT_QUOTES,
    'UTF-8'
);
$landingHeroClass = 'landing-section landing-section--hero';
$landingHeroStyle = '';
if ($landingBackgroundRenderPath !== '') {
    $landingHeroClass .= ' landing-section--hero-image';
    $landingHeroStyle = htmlspecialchars(sprintf(
        "--landing-hero-image: url('%s');",
        $landingBackgroundRenderPath
    ), ENT_QUOTES, 'UTF-8');
}
$metaImagePath = $landingBackgroundRenderPath !== '' ? $landingBackgroundRenderPath : $logoRenderPath;
$metaImageUrl = htmlspecialchars($toAbsoluteUrl($metaImagePath), ENT_QUOTES, 'UTF-8');
$canonicalUrl = htmlspecialchars($toAbsoluteUrl(url_for('')), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $langAttr ?>" data-base-url="<?= $baseUrl ?>">
<head>
  <meta charset="utf-8">
  <title><?= $siteName ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= $heroDescription ?>">
  <link rel="canonical" href="<?= $canonicalUrl ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= $siteName ?>">
  <meta property="og:description" content="<?= $heroDescription ?>">
  <meta property="og:url" content="<?= $canonicalUrl ?>">
  <?php if ($metaImageUrl !== ''): ?>
    <meta property="og:image" content="<?= $metaImageUrl ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?= $metaImageUrl ?>">
  <?php else: ?>
    <meta name="twitter:card" content="summary">
  <?php endif; ?>
  <meta name="twitter:title" content="<?= $siteName ?>">
  <meta name="twitter:description" content="<?= $heroDescription ?>">
  <meta name="app-base-url" content="<?= $baseUrl ?>">
  <?php if ($landingBackgroundRenderPath !== ''): ?>
    <link rel="preload" as="image" href="<?= htmlspecialchars($landingBackgroundRenderPath, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <link rel="manifest" href="<?= asset_url('manifest.php') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/material.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/styles.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/landing.css') ?>">
  <?php if ($brandStyle !== ''): ?>
    <style id="md-brand-style"><?= htmlspecialchars($brandStyle, ENT_QUOTES, 'UTF-8') ?></style>
  <?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>" style="<?= htmlspecialchars($bodyStyle, ENT_QUOTES, 'UTF-8') ?>" data-disable-dark-mode="1">
  <div class="landing-page">
    <header class="landing-topbar" id="home">
      <div class="landing-topbar__inner">
        <a class="landing-topbar__logo-link" href="#home" aria-label="<?= $siteName ?>">
          <img src="<?= $logo ?>" alt="<?= $logoAlt ?>" class="landing-topbar__logo">
        </a>
        <div class="landing-topbar__title"><?= $siteName ?></div>
        <div class="landing-topbar__actions">
          <a
            href="<?= $languageSwitchUrl ?>"
            class="landing-language-button"
            aria-label="<?= $languageSwitchLabel ?>"
            title="<?= $languageSwitchLabel ?>"
          >
            <img src="<?= $currentLocaleFlag ?>" alt="" width="24" height="24" loading="lazy" decoding="async">
            <span><?= $currentLocaleBadge ?></span>
          </a>
          <a class="landing-button landing-button--primary" href="<?= $loginUrl ?>"><?= $primaryCta ?></a>
        </div>
      </div>
    </header>

    <main class="landing-main" aria-label="<?= htmlspecialchars(t($t, 'landing_main_label', 'Landing content'), ENT_QUOTES, 'UTF-8') ?>">
      <section class="<?= htmlspecialchars($landingHeroClass, ENT_QUOTES, 'UTF-8') ?>">
        <div class="landing-hero-panel"<?= $landingHeroStyle !== '' ? ' style="' . $landingHeroStyle . ' min-height: clamp(18rem, 44vw, 34rem);"' : ' style="min-height: clamp(18rem, 44vw, 34rem);"' ?> aria-hidden="true"></div>
      </section>

      <section class="landing-section landing-section--hero-copy" aria-labelledby="landing-hero-title" style="margin-top: clamp(1.5rem, 4vw, 2.5rem);">
        <div class="landing-hero-copy" style="width: var(--landing-container-width); margin: 0 auto;">
          <p class="landing-hero-copy__eyebrow"><?= $heroEyebrow ?></p>
          <h1 class="landing-hero-copy__title" id="landing-hero-title"><?= $heroTitle ?></h1>
          <p class="landing-hero-copy__subtitle"><?= $heroSubtitle ?></p>
        </div>
      </section>

    </main>

  </div>
  <script src="<?= asset_url('assets/js/app.js') ?>"></script>
</body>
</html>
