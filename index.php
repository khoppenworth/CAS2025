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
$landingHeroStyle = '--landing-background: linear-gradient(125deg, #0b2d78 0%, #0f5cd8 48%, #24a6ff 100%);';
if ($landingBackgroundUrl !== '') {
    $landingHeroClass .= ' landing-hero--image';
    $landingHeroStyle .= sprintf(
        ' --landing-hero-image: url("%s");',
        htmlspecialchars($landingBackgroundUrl, ENT_QUOTES, 'UTF-8')
    );
}

$heroStats = [
    [
        'value' => htmlspecialchars(t($t, 'landing_stat_engagement_value', '96%'), ENT_QUOTES, 'UTF-8'),
        'label' => htmlspecialchars(t($t, 'landing_stat_engagement_label', 'Review completion rate'), ENT_QUOTES, 'UTF-8'),
    ],
    [
        'value' => htmlspecialchars(t($t, 'landing_stat_cycle_value', '2.5x'), ENT_QUOTES, 'UTF-8'),
        'label' => htmlspecialchars(t($t, 'landing_stat_cycle_label', 'Faster feedback cycles'), ENT_QUOTES, 'UTF-8'),
    ],
    [
        'value' => htmlspecialchars(t($t, 'landing_stat_growth_value', '24/7'), ENT_QUOTES, 'UTF-8'),
        'label' => htmlspecialchars(t($t, 'landing_stat_growth_label', 'Always-on performance visibility'), ENT_QUOTES, 'UTF-8'),
    ],
];

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

$navItems = [
    ['label' => htmlspecialchars(t($t, 'nav_home', 'Home'), ENT_QUOTES, 'UTF-8'), 'href' => '#home'],
    ['label' => htmlspecialchars(t($t, 'nav_about', 'About'), ENT_QUOTES, 'UTF-8'), 'href' => '#about'],
    ['label' => htmlspecialchars(t($t, 'nav_procurement', 'Procurement'), ENT_QUOTES, 'UTF-8'), 'href' => '#services'],
    ['label' => htmlspecialchars(t($t, 'nav_marketing', 'Marketing'), ENT_QUOTES, 'UTF-8'), 'href' => '#news'],
    ['label' => htmlspecialchars(t($t, 'nav_resources', 'Resources'), ENT_QUOTES, 'UTF-8'), 'href' => '#gallery'],
    ['label' => htmlspecialchars(t($t, 'nav_news', 'News'), ENT_QUOTES, 'UTF-8'), 'href' => '#news'],
    ['label' => htmlspecialchars(t($t, 'nav_contact', 'Contact'), ENT_QUOTES, 'UTF-8'), 'href' => '#contact'],
];

$statTiles = [
    ['value' => '12K+', 'label' => htmlspecialchars(t($t, 'stat_registered_users', 'Registered professionals'), ENT_QUOTES, 'UTF-8')],
    ['value' => '97%', 'label' => htmlspecialchars(t($t, 'stat_timely_reviews', 'On-time review completion'), ENT_QUOTES, 'UTF-8')],
    ['value' => '350+', 'label' => htmlspecialchars(t($t, 'stat_active_programs', 'Active development programmes'), ENT_QUOTES, 'UTF-8')],
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

$partners = ['MoPS', 'MoE', 'Civil Service Commission', 'Regional Bureaus', 'HR Council'];
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
  <style>
    .landing-hero {
      box-shadow: 0 24px 64px rgba(10, 31, 83, 0.35);
    }
    .landing-hero::before,
    .landing-hero::after {
      opacity: 0.45;
    }
    .landing-hero__stats {
      margin: 2rem 0 0;
      padding: 0;
      list-style: none;
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.85rem;
    }
    .landing-hero__stats li {
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.22);
      border-radius: 16px;
      padding: 0.9rem 1rem;
      backdrop-filter: blur(10px);
      min-height: 82px;
    }
    .landing-hero__stat-value {
      display: block;
      font-size: 1.2rem;
      font-weight: 700;
      color: #ffffff;
      margin-bottom: 0.35rem;
    }
    .landing-hero__stat-label {
      display: block;
      font-size: 0.88rem;
      line-height: 1.4;
      color: rgba(236, 243, 255, 0.93);
    }
    @media (max-width: 760px) {
      .landing-hero__stats {
        grid-template-columns: 1fr;
      }
    }
  </style>
  <?php if ($brandStyle !== ''): ?>
    <style id="md-brand-style"><?= htmlspecialchars($brandStyle, ENT_QUOTES, 'UTF-8') ?></style>
  <?php endif; ?>
</head>
<body class="<?= $bodyClass ?>" style="<?= $bodyStyle ?>" data-disable-dark-mode="1">
  <div class="landing-page">
    <header class="landing-topbar" id="home">
      <div class="landing-topbar__inner">
        <a class="landing-brand" href="#home">
          <img src="<?= $logo ?>" alt="<?= $logoAlt ?>" class="landing-brand__logo">
          <span class="landing-brand__name"><?= $siteName ?></span>
        </a>
        <nav class="landing-nav" aria-label="<?= htmlspecialchars(t($t, 'main_navigation', 'Main navigation'), ENT_QUOTES, 'UTF-8') ?>">
          <?php foreach ($navItems as $item): ?>
            <a href="<?= $item['href'] ?>"><?= $item['label'] ?></a>
          <?php endforeach; ?>
        </nav>
        <a class="landing-button landing-button--primary" href="<?= $loginUrl ?>"><?= $primaryCta ?></a>
      </div>
    </header>

    <section class="<?= htmlspecialchars($landingHeroClass, ENT_QUOTES, 'UTF-8') ?>"<?= $landingHeroStyle !== '' ? ' style="' . $landingHeroStyle . '"' : '' ?>>
      <div class="landing-hero__content" aria-labelledby="landing-title" id="about">
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
        <ul class="landing-hero__stats" role="list" aria-label="<?= htmlspecialchars(t($t, 'landing_stats_label', 'Performance highlights'), ENT_QUOTES, 'UTF-8') ?>">
          <?php foreach ($heroStats as $stat): ?>
            <li>
              <span class="landing-hero__stat-value"><?= $stat['value'] ?></span>
              <span class="landing-hero__stat-label"><?= $stat['label'] ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <main class="landing-main" aria-labelledby="features-heading">
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

      <section class="landing-section landing-section--partners">
        <div class="landing-section__header">
          <h2><?= htmlspecialchars(t($t, 'partners_heading', 'Trusted partners'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="landing-partners-grid">
          <?php foreach ($partners as $partner): ?>
            <span><?= htmlspecialchars($partner, ENT_QUOTES, 'UTF-8') ?></span>
          <?php endforeach; ?>
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
      <div class="landing-footer__contact" aria-label="<?= htmlspecialchars(t($t, 'contact_details_label', 'Contact details'), ENT_QUOTES, 'UTF-8') ?>">
        <h3><?= htmlspecialchars(t($t, 'contact_us', 'Contact us'), ENT_QUOTES, 'UTF-8') ?></h3>
        <?php if ($address !== ''): ?>
          <div><strong><?= $addressLabel ?>:</strong> <?= $address ?></div>
        <?php endif; ?>
        <?php if ($contact !== ''): ?>
          <div><strong><?= $contactLabel ?>:</strong> <?= $contact ?></div>
        <?php endif; ?>
      </div>
      <div class="landing-footer__links">
        <h3><?= htmlspecialchars(t($t, 'quick_links', 'Quick links'), ENT_QUOTES, 'UTF-8') ?></h3>
        <a href="#services"><?= htmlspecialchars(t($t, 'services', 'Services'), ENT_QUOTES, 'UTF-8') ?></a>
        <a href="#events"><?= htmlspecialchars(t($t, 'events', 'Events'), ENT_QUOTES, 'UTF-8') ?></a>
        <a href="#news"><?= htmlspecialchars(t($t, 'news', 'News'), ENT_QUOTES, 'UTF-8') ?></a>
      </div>
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
