<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('available_work_functions')) {
    require_once __DIR__ . '/../lib/work_functions.php';
}

auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$workFunctionChoices = work_function_choices($pdo);
$qbStrings = [
    'title' => t($t, 'manage_questionnaires', 'Manage questionnaires'),
    'subtitle' => t($t, 'qb_v2_subtitle', 'Reengineered questionnaire builder (preview).'),
    'addQuestionnaire' => t($t, 'add_questionnaire', 'Add questionnaire'),
    'noQuestionnaires' => t($t, 'no_questionnaires', 'No questionnaires found.'),
    'selectQuestionnaire' => t($t, 'select_questionnaire', 'Select a questionnaire to begin.'),
    'questionnaireTitle' => t($t, 'questionnaire_title', 'Title'),
    'questionnaireDescription' => t($t, 'questionnaire_description', 'Description'),
    'questionnaireStatus' => t($t, 'questionnaire_status', 'Status'),
    'sectionTitle' => t($t, 'section_title', 'Section title'),
    'sectionDescription' => t($t, 'section_description', 'Section description'),
    'addSection' => t($t, 'add_section', 'Add section'),
    'addItem' => t($t, 'add_item', 'Add question'),
    'itemCode' => t($t, 'question_code', 'Question code'),
    'itemText' => t($t, 'question_text', 'Question text'),
    'itemType' => t($t, 'question_type', 'Type'),
    'required' => t($t, 'required', 'Required'),
    'active' => t($t, 'active', 'Active'),
    'allowMultiple' => t($t, 'allow_multiple', 'Allow multiple'),
    'requiresCorrect' => t($t, 'requires_correct', 'Require correct answer'),
    'options' => t($t, 'options', 'Options'),
    'addOption' => t($t, 'add_option', 'Add option'),
    'save' => t($t, 'save', 'Save changes'),
    'publish' => t($t, 'publish', 'Publish'),
    'saving' => t($t, 'saving', 'Saving…'),
    'publishing' => t($t, 'publishing', 'Publishing…'),
    'saved' => t($t, 'saved', 'Changes saved'),
    'published' => t($t, 'published', 'Questionnaire published'),
    'error' => t($t, 'error', 'Something went wrong.'),
    'responsesLocked' => t($t, 'responses_locked', 'Responses exist; only activation can change.'),
    'invalidStatus' => t($t, 'qb_v2_invalid_status', 'Published questionnaires cannot return to Draft. Use Inactive instead.'),
    'modalTitle' => t($t, 'qb_v2_modal_title', 'Published questionnaire'),
    'modalBody' => t($t, 'qb_v2_modal_body', 'To make changes, create a new draft version. The published questionnaire will remain unchanged.'),
    'modalConfirm' => t($t, 'qb_v2_modal_confirm', 'Clone & edit draft'),
    'modalCancel' => t($t, 'qb_v2_modal_cancel', 'Cancel'),
    'clone' => t($t, 'qb_v2_clone', 'Clone questionnaire'),
    'versionLabel' => t($t, 'qb_v2_version', 'Version'),
];
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars($qbStrings['title'], ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <meta name="csrf-token" content="<?=htmlspecialchars(csrf_token(), ENT_QUOTES)?>">
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/questionnaire-builder-v2.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php $drawerKey = 'admin.manage_questionnaires'; ?>
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section qb2">
  <div class="md-card md-elev-2 qb2-card">
    <header class="qb2-header">
      <div>
        <h2><?=htmlspecialchars($qbStrings['title'], ENT_QUOTES, 'UTF-8')?></h2>
        <p class="md-hint"><?=htmlspecialchars($qbStrings['subtitle'], ENT_QUOTES, 'UTF-8')?></p>
      </div>
      <div class="qb2-actions">
        <button class="md-button md-outline" data-qb2-add><?=htmlspecialchars($qbStrings['addQuestionnaire'], ENT_QUOTES, 'UTF-8')?></button>
        <button class="md-button md-outline" data-qb2-clone><?=htmlspecialchars($qbStrings['clone'], ENT_QUOTES, 'UTF-8')?></button>
        <button class="md-button md-primary" data-qb2-save><?=htmlspecialchars($qbStrings['save'], ENT_QUOTES, 'UTF-8')?></button>
        <button class="md-button md-secondary" data-qb2-publish><?=htmlspecialchars($qbStrings['publish'], ENT_QUOTES, 'UTF-8')?></button>
      </div>
    </header>
    <div class="qb2-message" role="status" aria-live="polite" data-qb2-message></div>
    <div class="qb2-body">
      <aside class="qb2-nav" data-qb2-nav>
        <p class="md-hint qb2-empty" data-qb2-empty><?=htmlspecialchars($qbStrings['noQuestionnaires'], ENT_QUOTES, 'UTF-8')?></p>
        <div class="qb2-nav-list" data-qb2-nav-list></div>
      </aside>
      <main class="qb2-editor" data-qb2-editor>
        <div class="qb2-placeholder" data-qb2-placeholder>
          <?=htmlspecialchars($qbStrings['selectQuestionnaire'], ENT_QUOTES, 'UTF-8')?>
        </div>
      </main>
    </div>
  </div>
</section>
<div class="qb2-modal" data-qb2-modal hidden>
  <div class="qb2-modal__backdrop" data-qb2-modal-close></div>
  <div class="qb2-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="qb2-modal-title">
    <h3 id="qb2-modal-title"></h3>
    <p data-qb2-modal-body></p>
    <div class="qb2-modal__actions">
      <button type="button" class="md-button md-outline" data-qb2-modal-cancel></button>
      <button type="button" class="md-button md-primary" data-qb2-modal-confirm></button>
    </div>
  </div>
</div>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  window.QB2_STRINGS = <?=json_encode($qbStrings, JSON_THROW_ON_ERROR)?>;
  window.QB2_WORK_FUNCTIONS = <?=json_encode(array_keys($workFunctionChoices), JSON_THROW_ON_ERROR)?>;
</script>
<script type="module" src="<?=asset_url('assets/js/questionnaire-builder-v2.js')?>" defer></script>
</body>
</html>
