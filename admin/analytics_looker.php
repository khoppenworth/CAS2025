<?php
require_once __DIR__ . '/../config.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$lookerStudioQuery = <<<'SQL'
SELECT
  qr.id AS response_id,
  qr.created_at AS response_created_at,
  qr.status AS response_status,
  qr.score AS response_score,
  qr.reviewed_at,
  qr.review_comment,
  pp.label AS performance_period,
  q.id AS questionnaire_id,
  COALESCE(q.family_key, CONCAT('questionnaire-', q.id)) AS questionnaire_family_key,
  q.title AS questionnaire_title,
  u.id AS user_id,
  u.username,
  u.full_name,
  u.email,
  u.department,
  u.cadre,
  u.work_function,
  u.gender,
  u.account_status,
  u.created_at AS user_created_at,
  reviewer.full_name AS reviewer_name,
  reviewer.email AS reviewer_email,
  qi.id AS item_id,
  qi.linkId AS item_link_id,
  qi.text AS item_text,
  qi.type AS item_type,
  qs.title AS section_title,
  qri.answer AS item_answer,
  tr.recommended_courses,
  tr.recommendation_reasons
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN questionnaire q ON q.id = qr.questionnaire_id
LEFT JOIN users reviewer ON reviewer.id = qr.reviewed_by
LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id
LEFT JOIN questionnaire_response_item qri ON qri.response_id = qr.id
LEFT JOIN questionnaire_item qi ON qi.linkId = qri.linkId AND qi.questionnaire_id = qr.questionnaire_id
LEFT JOIN questionnaire_section qs ON qs.id = qi.section_id
LEFT JOIN (
  SELECT
    tr.questionnaire_response_id,
    GROUP_CONCAT(cc.title ORDER BY cc.title SEPARATOR '; ') AS recommended_courses,
    GROUP_CONCAT(tr.recommendation_reason ORDER BY cc.title SEPARATOR '; ') AS recommendation_reasons
  FROM training_recommendation tr
  JOIN course_catalogue cc ON cc.id = tr.course_id
  GROUP BY tr.questionnaire_response_id
) tr ON tr.questionnaire_response_id = qr.id;
SQL;
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t, 'analytics_looker_resources', 'Analytics guide & Looker Studio query'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_looker_resources', 'Analytics guide & Looker Studio query')?></h2>
    <p><?=t($t, 'analytics_looker_resources_hint', 'Share the PDF guide and copy the SQL query into Google Looker Studio to build custom dashboards.')?></p>
    <p>
      <a class="md-button" href="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'back_to_analytics', 'Back to analytics')?></a>
      <a class="md-button" href="<?=htmlspecialchars(url_for('admin/analytics_automation.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'open_analytics_automation', 'Open automation')?></a>
    </p>
    <div class="md-analytics-guide">
      <div class="md-guide-actions">
        <a class="md-button md-primary md-elev-1" href="<?=htmlspecialchars(asset_url('assets/analytics-guide.pdf'), ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener noreferrer">
          <?=t($t, 'analytics_download_guide', 'Download analytics guide (PDF)')?>
        </a>
      </div>
      <div class="md-query-card">
        <div class="md-query-header">
          <h3><?=t($t, 'analytics_looker_query_title', 'Google Looker Studio query')?></h3>
          <button class="md-button md-outline" type="button" data-copy-target="looker-query" data-copy-default="<?=htmlspecialchars(t($t, 'copy_query', 'Copy query'), ENT_QUOTES, 'UTF-8')?>" data-copy-success="<?=htmlspecialchars(t($t, 'copy_query_success', 'Copied!'), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'copy_query', 'Copy query')?></button>
        </div>
        <pre class="md-query-pre" id="looker-query"><code><?=htmlspecialchars($lookerStudioQuery, ENT_QUOTES, 'UTF-8')?></code></pre>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
