<?php
require_once __DIR__ . '/config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';

$logoRenderPath = site_logo_url($cfg);

$logo = htmlspecialchars($logoRenderPath, ENT_QUOTES, 'UTF-8');
$logoAlt = htmlspecialchars($cfg['site_name'] ?? 'Logo', ENT_QUOTES, 'UTF-8');
$siteName = htmlspecialchars($cfg['site_name'] ?? 'My Performance', ENT_QUOTES, 'UTF-8');
$landingText = htmlspecialchars($cfg['landing_text'] ?? '', ENT_QUOTES, 'UTF-8');
$address = htmlspecialchars($cfg['address'] ?? '', ENT_QUOTES, 'UTF-8');
$contact = htmlspecialchars($cfg['contact'] ?? '', ENT_QUOTES, 'UTF-8');
$bodyClass = trim(htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8') . ' landing-body');
$bodyStyle = htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8');
$brandStyle = site_brand_style($cfg);
$landingBackgroundUrl = site_landing_background_url($cfg);
$baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
$langAttr = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars(url_for('login.php'), ENT_QUOTES, 'UTF-8');
$heroSubtitle = $landingText !== ''
    ? $landingText
    : htmlspecialchars(t(
        $t,
        'landing_intro',
        "Welcome to the performance management portal. Discover resources and updates about your organisation's assessment program."
    ), ENT_QUOTES, 'UTF-8');

$primaryCta = htmlspecialchars(t($t, 'sign_in', 'Sign In'), ENT_QUOTES, 'UTF-8');
$addressLabel = htmlspecialchars(t($t, 'address_label', 'Address'), ENT_QUOTES, 'UTF-8');
$contactLabel = htmlspecialchars(t($t, 'contact_label', 'Contact'), ENT_QUOTES, 'UTF-8');
$landingHeroClass = 'landing-hero';
$landingHeroStyle = '';
if ($landingBackgroundUrl !== '') {
    $landingHeroClass .= ' landing-hero--image';
    $landingHeroStyle = sprintf(
        '--landing-hero-image: url("%s");',
        htmlspecialchars($landingBackgroundUrl, ENT_QUOTES, 'UTF-8')
    );
}

$highlightItems = [
    [
        'label' => htmlspecialchars(t($t, 'landing_highlight_one', 'Track progress with live dashboards'), ENT_QUOTES, 'UTF-8'),
    ],
    [
        'label' => htmlspecialchars(t($t, 'landing_highlight_two', 'Spot coaching needs before reviews are due'), ENT_QUOTES, 'UTF-8'),
    ],
    [
        'label' => htmlspecialchars(t($t, 'landing_highlight_three', 'Share consistent reports with leadership'), ENT_QUOTES, 'UTF-8'),
    ],
];

$featureItems = [
    [
        'title' => htmlspecialchars(t($t, 'feature_insights_title', 'Actionable insights'), ENT_QUOTES, 'UTF-8'),
        'description' => htmlspecialchars(t(
            $t,
            'feature_insights_body',
            'Understand progress at a glance with dashboards tailored to your role and priorities.'
        ), ENT_QUOTES, 'UTF-8'),
    ],
    [
        'title' => htmlspecialchars(t($t, 'feature_collaboration_title', 'Collaborative reviews'), ENT_QUOTES, 'UTF-8'),
        'description' => htmlspecialchars(t(
            $t,
            'feature_collaboration_body',
            'Coordinate assessments with managers and peers through guided workflows and reminders.'
        ), ENT_QUOTES, 'UTF-8'),
    ],
    [
        'title' => htmlspecialchars(t($t, 'feature_growth_title', 'Continuous growth'), ENT_QUOTES, 'UTF-8'),
        'description' => htmlspecialchars(t(
            $t,
            'feature_growth_body',
            'Empower your teams with curated learning paths, development goals, and timely recognition.'
        ), ENT_QUOTES, 'UTF-8'),
    ],
];
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
  <link rel="stylesheet" href="<?= asset_url('assets/css/landing.css') ?>">
  <?php if ($brandStyle !== ''): ?>
    <style id="md-brand-style"><?= htmlspecialchars($brandStyle, ENT_QUOTES, 'UTF-8') ?></style>
  <?php endif; ?>
</head>
<body class="<?= $bodyClass ?>" style="<?= $bodyStyle ?>">
  <div class="landing-page">
    <header class="<?= htmlspecialchars($landingHeroClass, ENT_QUOTES, 'UTF-8') ?>"<?= $landingHeroStyle !== '' ? ' style="' . $landingHeroStyle . '"' : '' ?>>
      <div class="landing-hero__content" aria-labelledby="landing-title">
        <div class="landing-brand">
          <img src="<?= $logo ?>" alt="<?= $logoAlt ?>" class="landing-brand__logo">
          <span class="landing-brand__name"><?= $siteName ?></span>
        </div>
        <h1 id="landing-title" class="landing-hero__title"><?= htmlspecialchars(t($t, 'landing_title', 'Performance that powers people'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="landing-hero__subtitle"><?= $heroSubtitle ?></p>
        <div class="landing-hero__summary" aria-label="<?= htmlspecialchars(t($t, 'landing_summary_title', 'Built for confident, modern HR teams'), ENT_QUOTES, 'UTF-8') ?>">
          <h2><?= htmlspecialchars(t($t, 'landing_summary_title', 'Built for confident, modern HR teams'), ENT_QUOTES, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars(t($t, 'landing_summary_body', 'Use a single hub to align feedback, track completion, and surface development wins.'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="landing-hero__actions">
          <a class="landing-button landing-button--primary" href="<?= $loginUrl ?>"><?= $primaryCta ?></a>
          <p class="landing-hero__cta-note"><?= htmlspecialchars(t($t, 'landing_cta_note', 'One secure sign-in for managers, reviewers, and employees.'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <ul class="landing-hero__highlights" role="list">
          <?php foreach ($highlightItems as $highlight): ?>
            <li>
              <span class="landing-hero__bullet" aria-hidden="true"></span>
              <span><?= $highlight['label'] ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </header>

    <main class="landing-main" aria-labelledby="features-heading">
      <section class="landing-section landing-section--features">
        <div class="landing-section__header">
          <h2 id="features-heading"><?= htmlspecialchars(t($t, 'features_heading', 'What sets the experience apart'), ENT_QUOTES, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars(t($t, 'features_subheading', 'Every element of the portal is crafted to elevate employee growth and organisational performance.'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="landing-features">
          <?php foreach ($featureItems as $feature): ?>
            <article class="landing-feature-card">
              <h3><?= $feature['title'] ?></h3>
              <p><?= $feature['description'] ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

    </main>

    <footer class="landing-footer">
      <div class="landing-footer__contact" aria-label="<?= htmlspecialchars(t($t, 'contact_details_label', 'Contact details'), ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($address !== ''): ?>
          <div><strong><?= $addressLabel ?>:</strong> <?= $address ?></div>
        <?php endif; ?>
        <?php if ($contact !== ''): ?>
          <div><strong><?= $contactLabel ?>:</strong> <?= $contact ?></div>
        <?php endif; ?>
      </div>
      <div class="landing-footer__meta">
        <div class="landing-footer__languages" aria-label="<?= htmlspecialchars(t($t, 'language_switch_label', 'Change language'), ENT_QUOTES, 'UTF-8') ?>">
          <?php
          $links = [];
          foreach ($availableLocales as $loc) {
              $url = htmlspecialchars(url_for('set_lang.php?lang=' . $loc), ENT_QUOTES, 'UTF-8');
              $label = htmlspecialchars(strtoupper($loc), ENT_QUOTES, 'UTF-8');
              $links[] = "<a href='" . $url . "'>" . $label . "</a>";
          }
          echo implode(' · ', $links);
          ?>
        </div>
      </div>
    </footer>
  </div>
</body>
</html>
