<?php
$fatalError = null;
$fatalDebugDetails = null;


if (!function_exists('settings_sanitize_sql')) {
    function settings_sanitize_sql(string $sql): string
    {
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $clean[] = $line;
        }
        return implode("\n", $clean);
    }
}

if (!function_exists('settings_split_sql_statements')) {
    function settings_split_sql_statements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            if ($inString) {
                if ($char === $stringChar) {
                    $escaped = $i > 0 && $sql[$i - 1] === '\\';
                    if (!$escaped) {
                        $inString = false;
                        $stringChar = '';
                    }
                }
                $buffer .= $char;
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $inString = true;
                $stringChar = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}

if (!function_exists('settings_apply_sql_file')) {
    function settings_apply_sql_file(PDO $pdo, string $path): int
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('SQL seed file not found: ' . $path);
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read SQL seed file: ' . $path);
        }

        $statements = settings_split_sql_statements(settings_sanitize_sql($sql));
        $count = 0;
        foreach ($statements as $statement) {
            $pdo->exec($statement);
            $count++;
        }

        return $count;
    }
}

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

        $demoDatasetAction = trim((string)($_POST['demo_dataset_action'] ?? ''));
        if (in_array($demoDatasetAction, ['enable', 'disable'], true)) {
            try {
                $pdo->beginTransaction();
                if ($demoDatasetAction === 'enable') {
                    $executed = settings_apply_sql_file($pdo, __DIR__ . '/../dummy_data.sql');
                    $msg = t($t, 'demo_dataset_enabled', 'Demo dataset has been enabled.') . ' ' . sprintf(t($t, 'demo_dataset_statements_executed', '%d SQL statements executed.'), $executed);
                } else {
                    $executed = settings_apply_sql_file($pdo, __DIR__ . '/../dummy_data_cleanup.sql');
                    $msg = t($t, 'demo_dataset_disabled', 'Demo dataset has been disabled and cleaned up.') . ' ' . sprintf(t($t, 'demo_dataset_statements_executed', '%d SQL statements executed.'), $executed);
                }
                $pdo->commit();
            } catch (Throwable $datasetException) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = t($t, 'demo_dataset_toggle_failed', 'Unable to toggle demo dataset right now.') . ' ' . $datasetException->getMessage();
            }
            $cfg = get_site_config($pdo);
            $enabledLocales = site_enabled_locales($cfg);
            $emailTemplates = normalize_email_templates($cfg['email_templates'] ?? []);
        } else {
        $review_enabled = isset($_POST['review_enabled']) ? 1 : 0;
        $qb_danger_zone_enabled = isset($_POST['qb_danger_zone_enabled']) ? 1 : 0;
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

        $ai_enabled = isset($_POST['ai_enabled']) ? 1 : 0;
        $ai_provider = strtolower(trim((string)($_POST['ai_provider'] ?? 'ollama')));
        if (!in_array($ai_provider, ['ollama'], true)) {
            $ai_provider = 'ollama';
        }
        $ai_base_url = trim((string)($_POST['ai_base_url'] ?? ''));
        $ai_api_key_input = trim((string)($_POST['ai_api_key'] ?? ''));
        $ai_api_key = $ai_api_key_input !== '' ? $ai_api_key_input : (string)($cfg['ai_api_key'] ?? '');
        $ai_model_chat = trim((string)($_POST['ai_model_chat'] ?? ''));
        $ai_model_fast = trim((string)($_POST['ai_model_fast'] ?? ''));
        $ai_model_fallback = trim((string)($_POST['ai_model_fallback'] ?? ''));

        $ai_feature_summary_enabled = isset($_POST['ai_feature_summary_enabled']) ? 1 : 0;
        $ai_feature_devplan_enabled = isset($_POST['ai_feature_devplan_enabled']) ? 1 : 0;
        $ai_feature_course_rationale_enabled = isset($_POST['ai_feature_course_rationale_enabled']) ? 1 : 0;
        $ai_placement_supervisor_review = isset($_POST['ai_placement_supervisor_review']) ? 1 : 0;
        $ai_placement_admin_analytics = isset($_POST['ai_placement_admin_analytics']) ? 1 : 0;

        $ai_timeout_seconds = max(5, min(120, (int)($_POST['ai_timeout_seconds'] ?? 20)));
        $ai_max_output_tokens = max(100, min(4000, (int)($_POST['ai_max_output_tokens'] ?? 700)));
        $ai_temperature = (float)($_POST['ai_temperature'] ?? 0.2);
        if (!is_finite($ai_temperature)) {
            $ai_temperature = 0.2;
        }
        $ai_temperature = max(0.0, min(1.0, $ai_temperature));
        $ai_retry_count = max(0, min(2, (int)($_POST['ai_retry_count'] ?? 1)));
        $ai_require_human_approval = isset($_POST['ai_require_human_approval']) ? 1 : 0;
        $ai_show_generated_badge = isset($_POST['ai_show_generated_badge']) ? 1 : 0;
        $ai_pii_redaction_enabled = isset($_POST['ai_pii_redaction_enabled']) ? 1 : 0;

        $enabledLocalesInput = $_POST['enabled_locales'] ?? [];
        if (!is_array($enabledLocalesInput)) {
            $enabledLocalesInput = [];
        }
        $selectedLocales = sanitize_locale_selection($enabledLocalesInput);
        if (!array_intersect($selectedLocales, ['en', 'fr'])) {
            $errors[] = t($t, 'language_required_notice', 'At least English or French must remain enabled.');
        }

        $aiFeaturesSelected = $ai_feature_summary_enabled === 1
            || $ai_feature_devplan_enabled === 1
            || $ai_feature_course_rationale_enabled === 1;
        if ($ai_enabled === 1) {
            if ($ai_base_url === '') {
                $errors[] = t($t, 'ai_base_url_required', 'AI Base URL is required when AI features are enabled.');
            } else {
                $scheme = strtolower((string)parse_url($ai_base_url, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    $errors[] = t($t, 'ai_base_url_invalid', 'AI Base URL must start with http:// or https://.');
                }
            }
            if (!$aiFeaturesSelected) {
                $errors[] = t($t, 'ai_feature_required', 'Enable at least one AI feature when AI is enabled.');
            }
        } elseif ($aiFeaturesSelected) {
            $errors[] = t($t, 'ai_enable_required', 'Enable AI before turning on AI features.');
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
            'qb_danger_zone_enabled' => $qb_danger_zone_enabled,
            'email_templates' => encode_email_templates($emailTemplates),
            'ai_enabled' => $ai_enabled,
            'ai_provider' => $ai_provider,
            'ai_base_url' => $ai_base_url !== '' ? $ai_base_url : null,
            'ai_api_key' => $ai_api_key !== '' ? $ai_api_key : null,
            'ai_model_chat' => $ai_model_chat !== '' ? $ai_model_chat : null,
            'ai_model_fast' => $ai_model_fast !== '' ? $ai_model_fast : null,
            'ai_model_fallback' => $ai_model_fallback !== '' ? $ai_model_fallback : null,
            'ai_feature_summary_enabled' => $ai_feature_summary_enabled,
            'ai_feature_devplan_enabled' => $ai_feature_devplan_enabled,
            'ai_feature_course_rationale_enabled' => $ai_feature_course_rationale_enabled,
            'ai_placement_supervisor_review' => $ai_placement_supervisor_review,
            'ai_placement_admin_analytics' => $ai_placement_admin_analytics,
            'ai_timeout_seconds' => $ai_timeout_seconds,
            'ai_max_output_tokens' => $ai_max_output_tokens,
            'ai_temperature' => number_format($ai_temperature, 2, '.', ''),
            'ai_retry_count' => $ai_retry_count,
            'ai_require_human_approval' => $ai_require_human_approval,
            'ai_show_generated_badge' => $ai_show_generated_badge,
            'ai_pii_redaction_enabled' => $ai_pii_redaction_enabled,
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
    <?php $currentLandingBackgroundUrl = site_landing_background_url($cfg); ?>
    <div class="md-field" style="margin-bottom: 1.2rem;">
      <span><?=t($t,'landing_background','Landing Background')?></span>
      <?php if ($currentLandingBackgroundUrl !== ''): ?>
        <div class="branding-logo-preview">
          <img src="<?=htmlspecialchars($currentLandingBackgroundUrl, ENT_QUOTES, 'UTF-8')?>" alt="<?=htmlspecialchars(t($t, 'landing_background_preview', 'Landing background preview'), ENT_QUOTES, 'UTF-8')?>">
          <div>
            <p class="md-hint"><?=t($t,'landing_background_preview','Current landing background preview')?></p>
            <p class="md-hint"><?=t($t,'landing_background_manage_hint','To change this image, go to Branding & Landing.')?></p>
          </div>
        </div>
      <?php else: ?>
        <p class="md-hint"><?=t($t,'landing_background_empty_hint','The landing hero uses a solid color background when no image is set.')?></p>
        <p class="md-hint"><?=t($t,'landing_background_manage_hint','To upload a background image, go to Branding & Landing.')?></p>
      <?php endif; ?>
    </div>
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
      <h3 class="md-subhead">
        <?=t($t,'questionnaire_builder_settings','Questionnaire Builder')?>
        <?=render_help_icon(t($t,'questionnaire_builder_settings_hint','Show or hide advanced questionnaire builder controls used during setup.'))?>
      </h3>
      <div class="md-control">
        <label>
          <input type="checkbox" name="qb_danger_zone_enabled" value="1" <?=((int)($cfg['qb_danger_zone_enabled'] ?? 1) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'qb_show_danger_zone','Show Danger Zone tile in Questionnaire Builder')?></span>
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
      <h3 class="md-subhead">
        <?=t($t,'ai_settings_heading','AI Integration')?>
        <?=render_help_icon(t($t,'ai_settings_hint','Connect an AI provider and control which AI capabilities are available in each part of the platform.'))?>
      </h3>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_enabled" value="1" <?=((int)($cfg['ai_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_enable_master','Enable AI features')?></span>
        </label>
      </div>
      <label class="md-field"><span><?=t($t,'ai_provider','AI Provider')?></span>
        <?php $aiProvider = strtolower((string)($cfg['ai_provider'] ?? 'ollama')); ?>
        <select name="ai_provider">
          <option value="ollama" <?=$aiProvider==='ollama'?'selected':''?>>Ollama</option>
        </select>
      </label>
      <label class="md-field"><span><?=t($t,'ai_base_url','AI Base URL')?></span><input name="ai_base_url" placeholder="https://ai.example.com" value="<?=htmlspecialchars((string)($cfg['ai_base_url'] ?? ''))?>"></label>
      <label class="md-field"><span><?=t($t,'ai_api_key','AI API Key')?></span><input type="password" name="ai_api_key" placeholder="<?=htmlspecialchars(t($t,'leave_blank_keep_password','Leave blank to keep current password.'), ENT_QUOTES, 'UTF-8')?>"></label>
      <label class="md-field"><span><?=t($t,'ai_model_chat','Chat Model')?></span><input name="ai_model_chat" placeholder="llama3.1:8b" value="<?=htmlspecialchars((string)($cfg['ai_model_chat'] ?? ''))?>"></label>
      <label class="md-field"><span><?=t($t,'ai_model_fast','Fast Model')?></span><input name="ai_model_fast" placeholder="phi3:mini" value="<?=htmlspecialchars((string)($cfg['ai_model_fast'] ?? ''))?>"></label>
      <label class="md-field"><span><?=t($t,'ai_model_fallback','Fallback Model')?></span><input name="ai_model_fallback" placeholder="llama3.2:3b" value="<?=htmlspecialchars((string)($cfg['ai_model_fallback'] ?? ''))?>"></label>
      <h4 class="md-subhead"><?=t($t,'ai_features_heading','AI Features')?></h4>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_feature_summary_enabled" value="1" <?=((int)($cfg['ai_feature_summary_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_feature_summary','Assessment response summary')?></span>
        </label>
      </div>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_feature_devplan_enabled" value="1" <?=((int)($cfg['ai_feature_devplan_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_feature_devplan','Draft development plan')?></span>
        </label>
      </div>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_feature_course_rationale_enabled" value="1" <?=((int)($cfg['ai_feature_course_rationale_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_feature_course_rationale','Training recommendation rationale')?></span>
        </label>
      </div>
      <h4 class="md-subhead"><?=t($t,'ai_placements_heading','AI Placements')?></h4>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_placement_supervisor_review" value="1" <?=((int)($cfg['ai_placement_supervisor_review'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_placement_supervisor_review','Supervisor review pages')?></span>
        </label>
      </div>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_placement_admin_analytics" value="1" <?=((int)($cfg['ai_placement_admin_analytics'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_placement_admin_analytics','Admin analytics pages')?></span>
        </label>
      </div>
      <h4 class="md-subhead"><?=t($t,'ai_runtime_heading','AI Runtime & Safety')?></h4>
      <label class="md-field"><span><?=t($t,'ai_timeout_seconds','Timeout (seconds)')?></span><input type="number" name="ai_timeout_seconds" min="5" max="120" value="<?=htmlspecialchars((string)($cfg['ai_timeout_seconds'] ?? 20))?>"></label>
      <label class="md-field"><span><?=t($t,'ai_max_output_tokens','Max output tokens')?></span><input type="number" name="ai_max_output_tokens" min="100" max="4000" value="<?=htmlspecialchars((string)($cfg['ai_max_output_tokens'] ?? 700))?>"></label>
      <label class="md-field"><span><?=t($t,'ai_temperature','Temperature')?></span><input type="number" name="ai_temperature" min="0" max="1" step="0.01" value="<?=htmlspecialchars((string)($cfg['ai_temperature'] ?? '0.20'))?>"></label>
      <label class="md-field"><span><?=t($t,'ai_retry_count','Retry count')?></span><input type="number" name="ai_retry_count" min="0" max="2" value="<?=htmlspecialchars((string)($cfg['ai_retry_count'] ?? 1))?>"></label>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_require_human_approval" value="1" <?=((int)($cfg['ai_require_human_approval'] ?? 1) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_require_human_approval','Require human approval for AI-generated output')?></span>
        </label>
      </div>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_show_generated_badge" value="1" <?=((int)($cfg['ai_show_generated_badge'] ?? 1) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_show_generated_badge','Display "AI-generated" indicator in the UI')?></span>
        </label>
      </div>
      <div class="md-control">
        <label>
          <input type="checkbox" name="ai_pii_redaction_enabled" value="1" <?=((int)($cfg['ai_pii_redaction_enabled'] ?? 1) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'ai_pii_redaction_enabled','Enable PII redaction before model requests')?></span>
        </label>
      </div>
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
      <h3 class="md-subhead">
        <?=t($t,'demo_dataset_heading','Demo Dataset')?>
        <?=render_help_icon(t($t,'demo_dataset_hint','Load sample users, assessments, analytics history, and training recommendations for demonstrations. Disable to remove the seeded demo records.'))?>
      </h3>
      <p class="md-help-note"><?=t($t,'demo_dataset_note','These actions only affect records created by the demo seed files (demo_* users and EPSA-* training mappings).')?></p>
      <div class="md-form-actions" style="justify-content:flex-start; gap:10px;">
        <button class="md-button md-secondary" type="submit" name="demo_dataset_action" value="enable"><?=t($t,'demo_dataset_enable','Enable Demo Dataset')?></button>
        <button class="md-button" type="submit" name="demo_dataset_action" value="disable"><?=t($t,'demo_dataset_disable','Disable Demo Dataset')?></button>
      </div>
      <div class="md-form-actions">
        <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
