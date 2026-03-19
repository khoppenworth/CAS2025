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
$landingCopyRaw = $landingTextRaw !== ''
    ? $landingTextRaw
    : t($t, 'hero_subtitle', 'From planning to recognition, help teams stay aligned with clear goals, real-time updates, and easy collaboration.');

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
$organizationName = trim((string)($cfg['footer_org_name'] ?? ''));
$organizationNameEscaped = htmlspecialchars($organizationName !== '' ? $organizationName : ($cfg['site_name'] ?? 'My Performance'), ENT_QUOTES, 'UTF-8');
$organizationShort = trim((string)($cfg['footer_org_short'] ?? ''));
$organizationShortEscaped = htmlspecialchars($organizationShort, ENT_QUOTES, 'UTF-8');
$footerWebsiteLabelRaw = trim((string)($cfg['footer_website_label'] ?? ''));
$footerWebsiteUrlRaw = trim((string)($cfg['footer_website_url'] ?? ''));
$footerEmailRaw = trim((string)($cfg['footer_email'] ?? ''));
$footerPhoneRaw = trim((string)($cfg['footer_phone'] ?? ''));
$footerHotlineLabelRaw = trim((string)($cfg['footer_hotline_label'] ?? ''));
$footerHotlineNumberRaw = trim((string)($cfg['footer_hotline_number'] ?? ''));
$addressRaw = trim((string)($cfg['address'] ?? ''));
$contactRaw = trim((string)($cfg['contact'] ?? ''));
$footerRightsEscaped = htmlspecialchars(trim((string)($cfg['footer_rights'] ?? '')), ENT_QUOTES, 'UTF-8');
$footerWebsiteLabel = htmlspecialchars($footerWebsiteLabelRaw, ENT_QUOTES, 'UTF-8');
$footerWebsiteUrl = htmlspecialchars($footerWebsiteUrlRaw, ENT_QUOTES, 'UTF-8');
$footerEmail = htmlspecialchars($footerEmailRaw, ENT_QUOTES, 'UTF-8');
$footerPhone = htmlspecialchars($footerPhoneRaw, ENT_QUOTES, 'UTF-8');
$footerHotlineLabel = htmlspecialchars($footerHotlineLabelRaw, ENT_QUOTES, 'UTF-8');
$footerHotlineNumber = htmlspecialchars($footerHotlineNumberRaw, ENT_QUOTES, 'UTF-8');
$address = htmlspecialchars($addressRaw, ENT_QUOTES, 'UTF-8');
$contact = htmlspecialchars($contactRaw, ENT_QUOTES, 'UTF-8');
$statSubmissionsValue = trim((string)($cfg['landing_metric_submissions'] ?? ''));
$statCompletionValue = trim((string)($cfg['landing_metric_completion'] ?? ''));
$statAdoptionValue = trim((string)($cfg['landing_metric_adoption'] ?? ''));
$heroBadgeOne = htmlspecialchars(t($t, 'hero_badge_one', 'Goal alignment'), ENT_QUOTES, 'UTF-8');
$heroBadgeTwo = htmlspecialchars(t($t, 'hero_badge_two', '360° feedback'), ENT_QUOTES, 'UTF-8');
$heroBadgeThree = htmlspecialchars(t($t, 'hero_badge_three', 'Learning insights'), ENT_QUOTES, 'UTF-8');
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

$statTiles = [
    ['value' => htmlspecialchars($statSubmissionsValue !== '' ? number_format((int)$statSubmissionsValue) . '+' : '12K+', ENT_QUOTES, 'UTF-8'), 'label' => htmlspecialchars(t($t, 'stat_registered_users', 'Annual submissions'), ENT_QUOTES, 'UTF-8')],
    ['value' => htmlspecialchars($statCompletionValue !== '' ? $statCompletionValue : '97%', ENT_QUOTES, 'UTF-8'), 'label' => htmlspecialchars(t($t, 'stat_timely_reviews', 'On-time review completion'), ENT_QUOTES, 'UTF-8')],
    ['value' => htmlspecialchars($statAdoptionValue !== '' ? $statAdoptionValue : '350+', ENT_QUOTES, 'UTF-8'), 'label' => htmlspecialchars(t($t, 'stat_active_programs', 'Platform adoption'), ENT_QUOTES, 'UTF-8')],
    ['value' => '24/7', 'label' => htmlspecialchars(t($t, 'stat_portal_access', 'Portal availability'), ENT_QUOTES, 'UTF-8')],
];

$eventCards = [
    [
        'title' => htmlspecialchars(t($t, 'event_one_title', 'Leadership coaching workshop'), ENT_QUOTES, 'UTF-8'),
        'meta' => htmlspecialchars(t($t, 'event_one_meta', '12 May 2026 · Addis Ababa'), ENT_QUOTES, 'UTF-8'),
    ],
    [
        'title' => htmlspecialchars(t($t, 'event_two_title', 'Digital appraisal rollout briefing'), ENT_QUOTES, 'UTF-8'),
        'meta' => htmlspecialchars(t($t, 'event_two_meta', '18 May 2026 · Virtual session'), ENT_QUOTES, 'UTF-8'),
    ],
    [
        'title' => htmlspecialchars(t($t, 'event_three_title', 'Performance data quality clinic'), ENT_QUOTES, 'UTF-8'),
        'meta' => htmlspecialchars(t($t, 'event_three_meta', '26 May 2026 · Hawassa'), ENT_QUOTES, 'UTF-8'),
    ],
];

$newsCards = [
    htmlspecialchars(t($t, 'news_one', 'New quarterly performance framework and templates are now available.'), ENT_QUOTES, 'UTF-8'),
    htmlspecialchars(t($t, 'news_two', 'Regional managers can now submit consolidated reports in a single workflow.'), ENT_QUOTES, 'UTF-8'),
    htmlspecialchars(t($t, 'news_three', 'Supervisor scorecards include stronger competency benchmarking insights.'), ENT_QUOTES, 'UTF-8'),
];

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
        <a class="landing-brand" href="#home">
          <img src="<?= $logo ?>" alt="<?= $logoAlt ?>" class="landing-brand__logo">
          <span class="landing-brand__name"><?= $siteName ?></span>
        </a>
        <a class="landing-button landing-button--primary" href="<?= $loginUrl ?>"><?= $primaryCta ?></a>
      </div>
    </header>

    <main class="landing-main" aria-labelledby="features-heading">
      <section class="<?= htmlspecialchars($landingHeroClass, ENT_QUOTES, 'UTF-8') ?>">
        <div class="landing-hero-panel"<?= $landingHeroStyle !== '' ? ' style="' . $landingHeroStyle . '"' : '' ?>>
          <div class="landing-hero-copy">
            <p class="landing-hero-copy__eyebrow"><?= $heroEyebrow ?></p>
            <h1 class="landing-hero-copy__title"><?= $heroTitle ?></h1>
            <p class="landing-hero-copy__subtitle"><?= $heroSubtitle ?></p>
            <div class="landing-hero-copy__actions">
              <a class="landing-button landing-button--accent" href="<?= $loginUrl ?>"><?= $primaryCta ?></a>
            </div>
          </div>
          <div class="landing-hero-badges" aria-label="<?= htmlspecialchars(t($t, 'hero_badges_label', 'Platform highlights'), ENT_QUOTES, 'UTF-8') ?>">
            <span><?= $heroBadgeOne ?></span>
            <span><?= $heroBadgeTwo ?></span>
            <span><?= $heroBadgeThree ?></span>
          </div>
        </div>
      </section>

      <section class="landing-section landing-section--stats">
        <div class="landing-stats-grid">
          <?php foreach ($statTiles as $tile): ?>
            <article class="landing-stat-card">
              <h3><?= $tile['value'] ?></h3>
              <p><?= $tile['label'] ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="landing-section landing-section--features" id="services">
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

      <section class="landing-section landing-section--events" id="events">
        <div class="landing-section__header">
          <h2><?= htmlspecialchars(t($t, 'events_heading', 'Upcoming events'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="landing-events-grid">
          <?php foreach ($eventCards as $event): ?>
            <article class="landing-event-card">
              <h3><?= $event['title'] ?></h3>
              <p><?= $event['meta'] ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="landing-section landing-section--news" id="news">
        <div class="landing-section__header">
          <h2><?= htmlspecialchars(t($t, 'latest_news', 'Latest news'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="landing-news-grid">
          <?php foreach ($newsCards as $news): ?>
            <article class="landing-news-card"><p><?= $news ?></p></article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="landing-section landing-section--gallery" id="gallery">
        <div class="landing-section__header">
          <h2><?= htmlspecialchars(t($t, 'gallery_heading', 'Resources and highlights'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="landing-gallery-band">
          <span><?= htmlspecialchars(t($t, 'gallery_one', 'Policy templates'), ENT_QUOTES, 'UTF-8') ?></span>
          <span><?= htmlspecialchars(t($t, 'gallery_two', 'Training playbooks'), ENT_QUOTES, 'UTF-8') ?></span>
          <span><?= htmlspecialchars(t($t, 'gallery_three', 'Assessment guides'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </section>

      <section class="landing-section landing-section--cta">
        <div class="landing-section__content">
          <h2><?= htmlspecialchars(t($t, 'cta_heading', 'Start your next performance cycle with confidence'), ENT_QUOTES, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars(t($t, 'cta_description', 'Access evaluations, insights, and resources from one secure platform.'), ENT_QUOTES, 'UTF-8') ?></p>
          <a class="landing-button landing-button--accent" href="<?= $loginUrl ?>"><?= $primaryCta ?></a>
        </div>
      </section>

    </main>

    <footer class="landing-footer" id="contact">
      <div class="landing-footer__meta">
        <h3><?= htmlspecialchars(t($t, 'footer_settings', 'Footer Details'), ENT_QUOTES, 'UTF-8') ?></h3>
        <p class="landing-footer__org"><?= $organizationNameEscaped ?></p>
        <?php if ($organizationShort !== ''): ?>
          <p class="landing-footer__secondary-link"><?= $organizationShortEscaped ?></p>
        <?php endif; ?>
        <?php if ($footerRightsEscaped !== ''): ?>
          <p class="landing-footer__secondary-link"><?= $footerRightsEscaped ?></p>
        <?php endif; ?>
      </div>
      <div class="landing-footer__meta landing-footer__contact">
        <h3><?= htmlspecialchars(t($t, 'contact_label', 'Contact'), ENT_QUOTES, 'UTF-8') ?></h3>
        <?php if ($addressRaw !== ''): ?><p><strong><?= htmlspecialchars(t($t, 'address_label', 'Address'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= $address ?></p><?php endif; ?>
        <?php if ($contactRaw !== ''): ?><p><strong><?= htmlspecialchars(t($t, 'contact_label', 'Contact'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= $contact ?></p><?php endif; ?>
        <?php if ($footerPhoneRaw !== ''): ?><p><strong><?= htmlspecialchars(t($t, 'footer_phone_label', 'Phone Number'), ENT_QUOTES, 'UTF-8') ?>:</strong> <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $footerPhoneRaw), ENT_QUOTES, 'UTF-8') ?>"><?= $footerPhone ?></a></p><?php endif; ?>
        <?php if ($footerEmailRaw !== ''): ?><p><strong><?= htmlspecialchars(t($t, 'footer_email_label', 'Contact Email'), ENT_QUOTES, 'UTF-8') ?>:</strong> <a href="mailto:<?= $footerEmail ?>"><?= $footerEmail ?></a></p><?php endif; ?>
        <?php if ($footerHotlineNumberRaw !== ''): ?><p><strong><?= $footerHotlineLabel !== '' ? $footerHotlineLabel : htmlspecialchars(t($t, 'footer_hotline_label_label', 'Hotline'), ENT_QUOTES, 'UTF-8') ?>:</strong> <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $footerHotlineNumberRaw), ENT_QUOTES, 'UTF-8') ?>"><?= $footerHotlineNumber ?></a></p><?php endif; ?>
      </div>
      <?php if ($footerWebsiteUrlRaw !== ''): ?>
        <div class="landing-footer__meta">
          <h3><?= htmlspecialchars(t($t, 'gallery_heading', 'Resources and highlights'), ENT_QUOTES, 'UTF-8') ?></h3>
          <div class="landing-footer__links">
            <a href="<?= $footerWebsiteUrl ?>" target="_blank" rel="noopener noreferrer"><?= $footerWebsiteLabel !== '' ? $footerWebsiteLabel : $footerWebsiteUrl ?></a>
            <a href="<?= $homeUrl ?>#services"><?= htmlspecialchars(t($t, 'features_heading', 'What sets the experience apart'), ENT_QUOTES, 'UTF-8') ?></a>
            <a href="<?= $homeUrl ?>#news"><?= htmlspecialchars(t($t, 'latest_news', 'Latest news'), ENT_QUOTES, 'UTF-8') ?></a>
          </div>
        </div>
      <?php endif; ?>
      <div class="landing-footer__meta">
        <h3><?= htmlspecialchars(t($t, 'languages', 'Languages'), ENT_QUOTES, 'UTF-8') ?></h3>
        <div class="landing-footer__languages" aria-label="<?= htmlspecialchars(t($t, 'language_switch_label', 'Change language'), ENT_QUOTES, 'UTF-8') ?>">
          <?php
          $links = [];
          foreach ($availableLocales as $loc) {
              $url = htmlspecialchars(url_for('set_lang.php?lang=' . $loc), ENT_QUOTES, 'UTF-8');
              $label = htmlspecialchars(strtoupper($loc), ENT_QUOTES, 'UTF-8');
              $flag = htmlspecialchars(asset_url('assets/images/flags/flag-' . $loc . '.svg'), ENT_QUOTES, 'UTF-8');
              $links[] = "<a href='" . $url . "' class='landing-language-link' aria-label='" . $label . "'><img src='" . $flag . "' alt='' width='28' height='28' loading='lazy' decoding='async' /><span>" . $label . "</span></a>";
          }
          echo implode('', $links);
          ?>
        </div>
      </div>
    </footer>
  </div>
</body>
</html>
