<?php
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config.php';
}

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';
$siteTitle = htmlspecialchars($cfg['site_name'] ?? t($t, 'footer_default_site', 'Ethiopian Pharmaceutical Supply Service'), ENT_QUOTES, 'UTF-8');
$orgName = htmlspecialchars(($cfg['footer_org_name'] ?? null) ?: t($t, 'footer_org', 'Ethiopian Pharmaceutical Supply Service'), ENT_QUOTES, 'UTF-8');
$orgShort = htmlspecialchars(($cfg['footer_org_short'] ?? null) ?: t($t, 'footer_org_short', 'EPSS / EPS'), ENT_QUOTES, 'UTF-8');
$rights = htmlspecialchars(($cfg['footer_rights'] ?? null) ?: t($t, 'footer_rights', 'All rights reserved.'), ENT_QUOTES, 'UTF-8');
$websiteLabelRaw = ($cfg['footer_website_label'] ?? null) ?: 'epss.gov.et';
$websiteLabel = htmlspecialchars($websiteLabelRaw, ENT_QUOTES, 'UTF-8');
$websiteUrlRaw = ($cfg['footer_website_url'] ?? null) ?: 'https://epss.gov.et';
if ($websiteUrlRaw && !preg_match('#^https?://#i', $websiteUrlRaw)) {
    $websiteUrlRaw = 'https://' . ltrim($websiteUrlRaw, '/');
}
$websiteUrl = htmlspecialchars($websiteUrlRaw, ENT_QUOTES, 'UTF-8');
$emailRaw = ($cfg['footer_email'] ?? null) ?: 'info@epss.gov.et';
$email = htmlspecialchars($emailRaw, ENT_QUOTES, 'UTF-8');
$phoneRaw = ($cfg['footer_phone'] ?? null) ?: '+251 11 155 9900';
$phone = htmlspecialchars($phoneRaw, ENT_QUOTES, 'UTF-8');
$phoneHref = htmlspecialchars('tel:' . preg_replace('/[^0-9+#*]/', '', (string)$phoneRaw), ENT_QUOTES, 'UTF-8');
$hotlineLabelRaw = ($cfg['footer_hotline_label'] ?? null) ?: 'Hotline 939';
$hotlineLabel = htmlspecialchars($hotlineLabelRaw, ENT_QUOTES, 'UTF-8');
$hotlineNumberSource = ($cfg['footer_hotline_number'] ?? null) ?: $hotlineLabelRaw;
$hotlineNumber = preg_replace('/[^0-9+#*]/', '', (string)$hotlineNumberSource);
if ($hotlineNumber === '') {
    $hotlineNumber = '939';
}
$hotlineHref = htmlspecialchars('tel:' . $hotlineNumber, ENT_QUOTES, 'UTF-8');
$currentYear = date('Y');
?>
</main>
</div>
<footer class="md-footer">
  <div class="md-footer-inner">
    <div class="md-footer-brand">
      <span class="md-footer-brand-short"><?=$orgShort?></span>
      <span class="md-footer-brand-name"><?=$siteTitle?></span>
    </div>
    <div class="md-footer-links">
      <a href="<?=$websiteUrl?>" target="_blank" rel="noopener noreferrer"><?=$websiteLabel?></a>
      <span>•</span>
      <a href="mailto:<?=$email?>"><?=$email?></a>
      <span>•</span>
      <a href="<?=$phoneHref?>"><?=$phone?></a>
      <span>•</span>
      <a href="<?=$hotlineHref?>"><?=$hotlineLabel?></a>
    </div>
    <div class="md-footer-meta">&copy; <?=$currentYear?> <?=$orgName?>. <?=$rights?></div>
  </div>
</footer>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  window.APP_BASE_URL = <?=json_encode(BASE_URL, JSON_THROW_ON_ERROR)?>;
  window.APP_DEFAULT_LOCALE = window.APP_DEFAULT_LOCALE || <?=json_encode($defaultLocale, JSON_THROW_ON_ERROR)?>;
  window.APP_AVAILABLE_LOCALES = window.APP_AVAILABLE_LOCALES || <?=json_encode($availableLocales, JSON_THROW_ON_ERROR)?>;
</script>
<script src="<?=asset_url('assets/adminlte/plugins/chart.js/Chart.bundle.min.js')?>"></script>
<script src="<?=asset_url('assets/js/app.js')?>"></script>
