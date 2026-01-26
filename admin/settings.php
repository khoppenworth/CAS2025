<?php
$fatalError = null;
$fatalDebugDetails = null;

try {
    require_once __DIR__ . '/../config.php';
    auth_required(['admin']);
    refresh_current_user($pdo);
    require_profile_completion($pdo);
    $locale = ensure_locale();
    $t = load_lang($locale);
    $cfg = get_site_config($pdo);
    if (!is_array($cfg)) {
        $cfg = [];
    }
    $previousReviewEnabled = (int)($cfg['review_enabled'] ?? 1) === 1;

    $msg = '';
    $errors = [];
    $enabledLocales = site_enabled_locales($cfg);
    $emailTemplates = normalize_email_templates($cfg['email_templates'] ?? []);

    $emailTemplateDefinitions = [];
    foreach (email_template_registry() as $key => $definition) {
        $placeholders = [];
        foreach ($definition['placeholders'] as $token => $placeholder) {
            $placeholders['{{' . $token . '}}'] = t($t, $placeholder['key'], $placeholder['fallback']);
        }

        $emailTemplateDefinitions[$key] = [
            'title' => t($t, $definition['title']['key'], $definition['title']['fallback']),
            'description' => t($t, $definition['description']['key'], $definition['description']['fallback']),
            'placeholders' => $placeholders,
        ];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();

        $review_enabled = isset($_POST['review_enabled']) ? 1 : 0;
        $local_login_enabled = isset($_POST['local_login_enabled']) ? 1 : 0;
        $google_oauth_enabled = isset($_POST['google_oauth_enabled']) ? 1 : 0;
        $google_oauth_client_id = trim($_POST['google_oauth_client_id'] ?? '');
        $google_oauth_client_secret = trim($_POST['google_oauth_client_secret'] ?? '');
        $microsoft_oauth_enabled = isset($_POST['microsoft_oauth_enabled']) ? 1 : 0;
        $microsoft_oauth_client_id = trim($_POST['microsoft_oauth_client_id'] ?? '');
        $microsoft_oauth_client_secret = trim($_POST['microsoft_oauth_client_secret'] ?? '');
        $microsoft_oauth_tenant = trim($_POST['microsoft_oauth_tenant'] ?? '');
        if ($microsoft_oauth_tenant === '') {
            $microsoft_oauth_tenant = 'common';
        }

        $smtp_enabled = isset($_POST['smtp_enabled']) ? 1 : 0;
        $smtp_host = trim($_POST['smtp_host'] ?? '');
        $smtp_port = (int)($_POST['smtp_port'] ?? 0);
        $smtp_username = trim($_POST['smtp_username'] ?? '');
        $smtp_password_input = trim($_POST['smtp_password'] ?? '');
        $smtp_password = $smtp_password_input !== '' ? $smtp_password_input : (string)($cfg['smtp_password'] ?? '');
        $smtp_encryption = strtolower(trim($_POST['smtp_encryption'] ?? 'none'));
        if (!in_array($smtp_encryption, ['none','tls','ssl'], true)) {
            $smtp_encryption = 'none';
        }
        $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
        $smtp_from_name = trim($_POST['smtp_from_name'] ?? '');
        $smtp_timeout = (int)($_POST['smtp_timeout'] ?? 20);
        if ($smtp_timeout <= 0) {
            $smtp_timeout = 20;
        }

        $enabledLocalesInput = $_POST['enabled_locales'] ?? [];
        if (!is_array($enabledLocalesInput)) {
            $enabledLocalesInput = [];
        }
        $selectedLocales = sanitize_locale_selection($enabledLocalesInput);
        if (!array_intersect($selectedLocales, ['en', 'fr'])) {
            $errors[] = t($t, 'language_required_notice', 'At least English or French must remain enabled.');
        }

        $emailTemplatesInput = $_POST['email_templates'] ?? [];
        if (!is_array($emailTemplatesInput)) {
            $emailTemplatesInput = [];
        }
        $submittedTemplates = [];
        foreach (default_email_templates() as $key => $defaultTemplate) {
            $existingTemplate = $emailTemplates[$key] ?? $defaultTemplate;
            $inputRow = isset($emailTemplatesInput[$key]) && is_array($emailTemplatesInput[$key]) ? $emailTemplatesInput[$key] : [];
            $subjectRaw = isset($inputRow['subject']) ? (string)$inputRow['subject'] : (string)($existingTemplate['subject'] ?? '');
            $htmlRaw = isset($inputRow['html']) ? (string)$inputRow['html'] : (string)($existingTemplate['html'] ?? '');
            $subjectTrimmed = trim($subjectRaw);
            $htmlTrimmed = trim($htmlRaw);
            $submittedTemplates[$key] = [
                'subject' => $subjectTrimmed !== '' ? $subjectTrimmed : $defaultTemplate['subject'],
                'html' => $htmlTrimmed !== '' ? $htmlRaw : $defaultTemplate['html'],
            ];
        }
        $emailTemplates = normalize_email_templates($submittedTemplates);

        $fields = [
            'google_oauth_enabled' => $google_oauth_enabled,
            'google_oauth_client_id' => $google_oauth_client_id,
            'google_oauth_client_secret' => $google_oauth_client_secret,
            'microsoft_oauth_enabled' => $microsoft_oauth_enabled,
            'microsoft_oauth_client_id' => $microsoft_oauth_client_id,
            'microsoft_oauth_client_secret' => $microsoft_oauth_client_secret,
            'microsoft_oauth_tenant' => $microsoft_oauth_tenant,
            'local_login_enabled' => $local_login_enabled,
            'smtp_enabled' => $smtp_enabled,
            'smtp_host' => $smtp_host !== '' ? $smtp_host : null,
            'smtp_port' => $smtp_port > 0 ? $smtp_port : 587,
            'smtp_username' => $smtp_username !== '' ? $smtp_username : null,
            'smtp_password' => $smtp_password !== '' ? $smtp_password : null,
            'smtp_encryption' => $smtp_encryption,
            'smtp_from_email' => $smtp_from_email !== '' ? $smtp_from_email : null,
            'smtp_from_name' => $smtp_from_name !== '' ? $smtp_from_name : null,
            'smtp_timeout' => $smtp_timeout,
            'review_enabled' => $review_enabled,
            'email_templates' => encode_email_templates($emailTemplates),
        ];

        $siteConfigColumns = site_config_available_columns($pdo);
        $reviewColumnAvailable = isset($siteConfigColumns['review_enabled']);
        if (!$reviewColumnAvailable) {
            ensure_site_config_schema($pdo);
            $siteConfigColumns = site_config_available_columns($pdo, true);
            $reviewColumnAvailable = isset($siteConfigColumns['review_enabled']);
        }

        foreach (array_keys($fields) as $column) {
            if (!isset($siteConfigColumns[$column])) {
                if ($column === 'review_enabled') {
                    $errors[] = t($t, 'review_column_missing_notice', 'The review workflow setting could not be saved because the database is missing the required column. Please run the latest upgrade script and try again.');
                }
                unset($fields[$column]);
            }
        }

        if ($fields === []) {
            $errors[] = t($t, 'settings_missing_columns_notice', 'Settings could not be saved because the configuration table is missing required columns.');
        }

        if ($errors === []) {
            $enabledLocales = enforce_locale_requirements($selectedLocales);
            $fields['enabled_locales'] = encode_enabled_locales($enabledLocales);

            $values = [];
            foreach ($fields as $column => $value) {
                $values[] = ($value !== '') ? $value : null;
            }

            $columns = array_keys($fields);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $updates = [];
            foreach ($columns as $column) {
                $updates[] = "$column=VALUES($column)";
            }

            $sql = 'INSERT INTO site_config (id, ' . implode(', ', $columns) . ') VALUES (1, ' . $placeholders . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
            $stm = $pdo->prepare($sql);
            $stm->execute($values);
            $autoApproveNotice = '';
            if ($reviewColumnAvailable && $previousReviewEnabled && $review_enabled === 0) {
                try {
                    $autoApproved = $pdo->exec("UPDATE questionnaire_response SET status='approved', reviewed_by=NULL, reviewed_at=NOW(), review_comment=NULL WHERE status='submitted'");
                    if (is_int($autoApproved) && $autoApproved > 0) {
                        $autoApproveNotice = ' ' . t($t, 'auto_approve_notice', 'Pending submissions were automatically approved.');
                    }
                } catch (PDOException $e) {
                    error_log('auto-approve pending submissions failed: ' . $e->getMessage());
                    $errors[] = t($t, 'auto_approve_failed', 'Settings saved, but pending submissions could not be finalized automatically.');
                }
            }
            if ($errors === []) {
                $msg = t($t, 'settings_updated', 'Settings updated successfully.') . $autoApproveNotice;
            }
            $cfg = get_site_config($pdo);
            $enabledLocales = site_enabled_locales($cfg);
            $emailTemplates = normalize_email_templates($cfg['email_templates'] ?? []);
        }
        if ($errors !== []) {
            $enabledLocales = $selectedLocales;
        }
    }
} catch (Throwable $e) {
    error_log('admin/settings bootstrap failed: ' . $e->getMessage());

    if (!isset($locale)) {
        $locale = 'en';
    }
    if (!isset($t) || !is_array($t)) {
        $t = load_lang($locale);
    }
    if (!isset($cfg) || !is_array($cfg)) {
        $cfg = site_config_defaults();
    }
    if (!isset($enabledLocales) || !is_array($enabledLocales)) {
        $enabledLocales = site_enabled_locales($cfg);
    }
    if (!isset($errors) || !is_array($errors)) {
        $errors = [];
    }
    $msg = $msg ?? '';
    if (!isset($emailTemplates) || !is_array($emailTemplates)) {
        $emailTemplates = default_email_templates();
    }

    $fatalError = APP_DEBUG ? $e->getMessage() : t($t, 'unexpected_error_notice', 'An unexpected error occurred while loading the settings.');
    $errors[] = $fatalError;
    if (APP_DEBUG) {
        $fatalDebugDetails = $e->getTraceAsString();
    }
}
$pageHelpKey = 'admin.settings';
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'settings','Settings'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>" style="<?=htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'settings','Settings')?></h2>
    <?php if ($msg): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($errors): ?>
      <div class="md-alert error">
        <?php foreach ($errors as $error): ?>
          <p><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></p>
        <?php endforeach; ?>
        <?php if ($fatalDebugDetails): ?>
          <pre class="md-debug-trace"><?=htmlspecialchars($fatalDebugDetails, ENT_QUOTES, 'UTF-8')?></pre>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <form method="post" action="<?=htmlspecialchars(url_for('admin/settings.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')?>">
      <h3 class="md-subhead">
        <?=t($t,'language_settings','Languages')?>
        <?=render_help_icon(t($t,'language_settings_hint','Choose which interface languages are available to users.'))?>
      </h3>
      <?php foreach (SUPPORTED_LOCALES as $localeOption): ?>
        <?php $isChecked = in_array($localeOption, $enabledLocales, true); ?>
        <div class="md-control">
          <label>
            <input type="checkbox" name="enabled_locales[]" value="<?=htmlspecialchars($localeOption, ENT_QUOTES, 'UTF-8')?>" <?=$isChecked ? 'checked' : ''?>>
            <span><?=htmlspecialchars(t($t, 'language_label_' . $localeOption, locale_display_name($localeOption)), ENT_QUOTES, 'UTF-8')?></span>
          </label>
        </div>
      <?php endforeach; ?>
      <div class="md-help-note">
        <?=render_help_icon(t($t,'language_required_notice','At least English or French must remain enabled.'), true)?>
      </div>
      <h3 class="md-subhead">
        <?=t($t,'review_settings','Reviews')?>
        <?=render_help_icon(t($t,'review_settings_hint','Toggle the supervisor review workflow on or off for the entire system.'))?>
      </h3>
      <div class="md-control">
        <label>
          <input type="checkbox" name="review_enabled" value="1" <?=((int)($cfg['review_enabled'] ?? 1) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'enable_review_feature','Enable supervisor review workflow')?></span>
        </label>
      </div>
      <h3 class="md-subhead"><?=t($t,'sso_settings','Single Sign-On (SSO)')?></h3>
      <div class="md-control">
        <label>
          <input type="checkbox" name="local_login_enabled" value="1" <?=((int)($cfg['local_login_enabled'] ?? 1) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'enable_local_login','Allow username/password sign-in')?></span>
        </label>
        <p class="md-help-note" style="margin: 6px 0 0;">
          <?=t($t,'local_login_toggle_hint','Disable this option to require SSO (for example, Google) on the main login page. Administrators can still use the dedicated admin login.')?>
        </p>
      </div>
      <div class="md-control">
        <label>
          <input type="checkbox" name="google_oauth_enabled" value="1" <?=((int)($cfg['google_oauth_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'enable_google_sign_in','Enable Google sign-in')?></span>
        </label>
      </div>
      <label class="md-field"><span><?=t($t,'google_client_id','Google Client ID')?></span><input name="google_oauth_client_id" value="<?=htmlspecialchars($cfg['google_oauth_client_id'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'google_client_secret','Google Client Secret')?></span><input type="password" name="google_oauth_client_secret" value="<?=htmlspecialchars($cfg['google_oauth_client_secret'] ?? '')?>"></label>
      <div class="md-control">
        <label>
          <input type="checkbox" name="microsoft_oauth_enabled" value="1" <?=((int)($cfg['microsoft_oauth_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'enable_microsoft_sign_in','Enable Microsoft sign-in')?></span>
        </label>
      </div>
      <label class="md-field"><span><?=t($t,'microsoft_client_id','Microsoft Client ID')?></span><input name="microsoft_oauth_client_id" value="<?=htmlspecialchars($cfg['microsoft_oauth_client_id'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'microsoft_client_secret','Microsoft Client Secret')?></span><input type="password" name="microsoft_oauth_client_secret" value="<?=htmlspecialchars($cfg['microsoft_oauth_client_secret'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'microsoft_tenant','Microsoft Tenant (directory)')?></span><input name="microsoft_oauth_tenant" value="<?=htmlspecialchars($cfg['microsoft_oauth_tenant'] ?? 'common')?>"></label>
      <h3 class="md-subhead"><?=t($t,'email_notifications','Email Notifications')?></h3>
      <div class="md-control">
        <label>
          <input type="checkbox" name="smtp_enabled" value="1" <?=((int)($cfg['smtp_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'enable_smtp_notifications','Enable SMTP notifications')?></span>
        </label>
      </div>
      <label class="md-field"><span><?=t($t,'smtp_host','SMTP Host')?></span><input name="smtp_host" value="<?=htmlspecialchars($cfg['smtp_host'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_port','SMTP Port')?></span><input type="number" name="smtp_port" min="1" value="<?=htmlspecialchars((string)($cfg['smtp_port'] ?? 587))?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_encryption','Encryption')?></span>
        <?php $enc = strtolower((string)($cfg['smtp_encryption'] ?? 'none')); ?>
        <select name="smtp_encryption">
          <option value="none" <?=$enc==='none'?'selected':''?>><?=t($t,'smtp_encryption_none','None')?></option>
          <option value="tls" <?=$enc==='tls'?'selected':''?>>TLS</option>
          <option value="ssl" <?=$enc==='ssl'?'selected':''?>>SSL</option>
        </select>
      </label>
      <label class="md-field"><span><?=t($t,'smtp_username','SMTP Username')?></span><input name="smtp_username" value="<?=htmlspecialchars($cfg['smtp_username'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_password','SMTP Password')?></span><input type="password" name="smtp_password" placeholder="<?=htmlspecialchars(t($t,'leave_blank_keep_password','Leave blank to keep current password.'), ENT_QUOTES, 'UTF-8')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_from_email','From Email')?></span><input name="smtp_from_email" value="<?=htmlspecialchars($cfg['smtp_from_email'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_from_name','From Name')?></span><input name="smtp_from_name" value="<?=htmlspecialchars($cfg['smtp_from_name'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_timeout','Connection Timeout (seconds)')?></span><input type="number" name="smtp_timeout" min="5" value="<?=htmlspecialchars((string)($cfg['smtp_timeout'] ?? 20))?>"></label>
      <h3 class="md-subhead"><?=t($t,'email_template_settings','Email Templates')?></h3>
      <p class="md-help-note"><?=t($t,'email_template_settings_hint','Customize the subject and HTML content for outgoing notification emails. You can use hyperlinks and the placeholders listed for each template.')?></p>
      <?php foreach ($emailTemplateDefinitions as $key => $meta): ?>
        <div class="email-template-block">
          <h4 class="md-subhead"><?=htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8')?></h4>
          <p class="md-help-note"><?=htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8')?></p>
          <div class="md-help-note email-template-placeholders">
            <strong><?=t($t,'email_template_placeholders','Available placeholders:')?></strong>
            <ul>
              <?php foreach ($meta['placeholders'] as $placeholder => $label): ?>
                <li><code><?=htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8')?></code> – <?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <label class="md-field"><span><?=t($t,'email_subject','Subject')?></span><input name="email_templates[<?=$key?>][subject]" value="<?=htmlspecialchars($emailTemplates[$key]['subject'] ?? '')?>"></label>
          <label class="md-field md-field-textarea"><span><?=t($t,'email_html_body','HTML Body')?></span><textarea name="email_templates[<?=$key?>][html]" rows="8"><?=htmlspecialchars($emailTemplates[$key]['html'] ?? '')?></textarea></label>
        </div>
      <?php endforeach; ?>
      <div class="md-form-actions">
        <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
