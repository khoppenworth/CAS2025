
-- migration.sql: upgrade existing DB to enhanced schema
SET @qi_weight_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'weight_percent'
);
SET @qi_weight_add_sql = IF(
  @qi_weight_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN weight_percent INT NOT NULL DEFAULT 0 AFTER order_index',
  'DO 1'
);
PREPARE stmt FROM @qi_weight_add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qi_weight_needs_modification = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'weight_percent'
    AND NOT (
      UPPER(DATA_TYPE) IN ('INT', 'INTEGER', 'SMALLINT', 'MEDIUMINT', 'TINYINT', 'BIGINT')
      AND IS_NULLABLE = 'NO'
      AND COALESCE(COLUMN_DEFAULT, '0') IN ('0', '0.0')
    )
);
SET @qi_weight_modify_sql = IF(
  @qi_weight_exists > 0 AND @qi_weight_needs_modification > 0,
  'ALTER TABLE questionnaire_item MODIFY COLUMN weight_percent INT NOT NULL DEFAULT 0 AFTER order_index',
  'DO 1'
);
PREPARE stmt FROM @qi_weight_modify_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE questionnaire_item
SET weight_percent = 0
WHERE weight_percent IS NULL;

SET @qi_multiple_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'allow_multiple'
);
SET @qi_multiple_sql = IF(
  @qi_multiple_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN allow_multiple TINYINT(1) NOT NULL DEFAULT 0',
  'DO 1'
);
PREPARE stmt FROM @qi_multiple_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qi_required_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'is_required'
);
SET @qi_required_sql = IF(
  @qi_required_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0',
  'DO 1'
);
PREPARE stmt FROM @qi_required_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qi_requires_correct_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'requires_correct'
);
SET @qi_requires_correct_sql = IF(
  @qi_requires_correct_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN requires_correct TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required',
  'DO 1'
);
PREPARE stmt FROM @qi_requires_correct_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @q_status_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire'
    AND COLUMN_NAME = 'status'
);
SET @q_status_sql = IF(
  @q_status_exists = 0,
  "ALTER TABLE questionnaire ADD COLUMN status ENUM('draft','published','inactive') NOT NULL DEFAULT 'draft' AFTER description",
  'DO 1'
);
PREPARE stmt FROM @q_status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @q_family_key_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire'
    AND COLUMN_NAME = 'family_key'
);
SET @q_family_key_sql = IF(
  @q_family_key_exists = 0,
  "ALTER TABLE questionnaire ADD COLUMN family_key VARCHAR(100) NULL AFTER status",
  'DO 1'
);
PREPARE stmt FROM @q_family_key_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE questionnaire
SET status = 'draft'
WHERE status IS NULL OR status NOT IN ('draft','published','inactive');

UPDATE questionnaire
SET family_key = CONCAT('questionnaire-', id)
WHERE family_key IS NULL OR TRIM(family_key) = '';

SET @q_family_key_index_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire'
    AND INDEX_NAME = 'idx_questionnaire_family_key'
);
SET @q_family_key_index_sql = IF(
  @q_family_key_index_exists = 0,
  'CREATE INDEX idx_questionnaire_family_key ON questionnaire (family_key)',
  'DO 1'
);
PREPARE stmt FROM @q_family_key_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Safety: never auto-publish draft questionnaires during upgrade/migration.
-- Publishing must remain an explicit admin action.
SET @publish_existing_sql := 'DO 1';
PREPARE stmt FROM @publish_existing_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qs_active_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_section'
    AND COLUMN_NAME = 'is_active'
);
SET @qs_active_sql = IF(
  @qs_active_exists = 0,
  'ALTER TABLE questionnaire_section ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER order_index',
  'DO 1'
);
PREPARE stmt FROM @qs_active_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE questionnaire_section
SET is_active = 1
WHERE is_active IS NULL;

SET @qi_active_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'is_active'
);
SET @qi_active_sql = IF(
  @qi_active_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_required',
  'DO 1'
);
PREPARE stmt FROM @qi_active_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE questionnaire_item
SET is_active = 1
WHERE is_active IS NULL;

ALTER TABLE questionnaire_item MODIFY COLUMN type ENUM('likert','text','textarea','boolean','choice') NOT NULL DEFAULT 'choice';

CREATE TABLE IF NOT EXISTS questionnaire_item_option (
  id INT AUTO_INCREMENT PRIMARY KEY,
  questionnaire_item_id INT NOT NULL,
  value VARCHAR(500) NOT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  order_index INT NOT NULL DEFAULT 0,
  FOREIGN KEY (questionnaire_item_id) REFERENCES questionnaire_item(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET @qio_correct_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item_option'
    AND COLUMN_NAME = 'is_correct'
);
SET @qio_correct_sql = IF(
  @qio_correct_exists = 0,
  'ALTER TABLE questionnaire_item_option ADD COLUMN is_correct TINYINT(1) NOT NULL DEFAULT 0 AFTER value',
  'DO 1'
);
PREPARE stmt FROM @qio_correct_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS site_config (
  id INT PRIMARY KEY,
  site_name VARCHAR(200) NULL,
  landing_text TEXT NULL,
  address VARCHAR(255) NULL,
  contact VARCHAR(255) NULL,
  landing_metric_submissions INT NULL,
  landing_metric_completion VARCHAR(50) NULL,
  landing_metric_adoption VARCHAR(50) NULL,
  logo_path VARCHAR(255) NULL,
  landing_background_path VARCHAR(255) NULL,
  footer_org_name VARCHAR(255) NULL,
  footer_org_short VARCHAR(100) NULL,
  footer_website_label VARCHAR(255) NULL,
  footer_website_url VARCHAR(255) NULL,
  footer_email VARCHAR(255) NULL,
  footer_phone VARCHAR(255) NULL,
  footer_hotline_label VARCHAR(255) NULL,
  footer_hotline_number VARCHAR(50) NULL,
  footer_rights VARCHAR(255) NULL,
  google_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0,
  google_oauth_client_id VARCHAR(255) NULL,
  google_oauth_client_secret VARCHAR(255) NULL,
  microsoft_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0,
  microsoft_oauth_client_id VARCHAR(255) NULL,
  microsoft_oauth_client_secret VARCHAR(255) NULL,
  microsoft_oauth_tenant VARCHAR(255) NULL,
  color_theme VARCHAR(50) NOT NULL DEFAULT 'light',
  brand_color VARCHAR(7) NULL,
  local_login_enabled TINYINT(1) NOT NULL DEFAULT 1,
  enabled_locales TEXT NULL,
  upgrade_repo VARCHAR(255) NULL,
  review_enabled TINYINT(1) NOT NULL DEFAULT 1,
  scheduled_assessments_enabled TINYINT(1) NOT NULL DEFAULT 1,
  qb_danger_zone_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_templates LONGTEXT NULL,
  ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ai_provider VARCHAR(50) NOT NULL DEFAULT 'ollama',
  ai_base_url VARCHAR(255) NULL,
  ai_api_key VARCHAR(255) NULL,
  ai_model_chat VARCHAR(128) NULL,
  ai_model_fast VARCHAR(128) NULL,
  ai_model_fallback VARCHAR(128) NULL,
  ai_feature_summary_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ai_feature_devplan_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ai_feature_course_rationale_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ai_placement_supervisor_review TINYINT(1) NOT NULL DEFAULT 0,
  ai_placement_admin_analytics TINYINT(1) NOT NULL DEFAULT 0,
  ai_timeout_seconds INT NOT NULL DEFAULT 20,
  ai_max_output_tokens INT NOT NULL DEFAULT 700,
  ai_temperature DECIMAL(3,2) NOT NULL DEFAULT 0.20,
  ai_retry_count INT NOT NULL DEFAULT 1,
  ai_require_human_approval TINYINT(1) NOT NULL DEFAULT 1,
  ai_show_generated_badge TINYINT(1) NOT NULL DEFAULT 1,
  ai_pii_redaction_enabled TINYINT(1) NOT NULL DEFAULT 1,
  gender_options TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Ensure optional site_config columns exist without relying on ADD COLUMN IF NOT EXISTS.
SET @sc_landing_metric_submissions_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'landing_metric_submissions'
);
SET @sc_landing_metric_submissions_sql = IF(
  @sc_landing_metric_submissions_exists = 0,
  'ALTER TABLE site_config ADD COLUMN landing_metric_submissions INT NULL AFTER contact',
  'DO 1'
);
PREPARE stmt FROM @sc_landing_metric_submissions_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_landing_metric_completion_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'landing_metric_completion'
);
SET @sc_landing_metric_completion_sql = IF(
  @sc_landing_metric_completion_exists = 0,
  'ALTER TABLE site_config ADD COLUMN landing_metric_completion VARCHAR(50) NULL AFTER landing_metric_submissions',
  'DO 1'
);
PREPARE stmt FROM @sc_landing_metric_completion_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_landing_metric_adoption_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'landing_metric_adoption'
);
SET @sc_landing_metric_adoption_sql = IF(
  @sc_landing_metric_adoption_exists = 0,
  'ALTER TABLE site_config ADD COLUMN landing_metric_adoption VARCHAR(50) NULL AFTER landing_metric_completion',
  'DO 1'
);
PREPARE stmt FROM @sc_landing_metric_adoption_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_landing_background_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'landing_background_path'
);
SET @sc_landing_background_sql = IF(
  @sc_landing_background_exists = 0,
  'ALTER TABLE site_config ADD COLUMN landing_background_path VARCHAR(255) NULL AFTER logo_path',
  'DO 1'
);
PREPARE stmt FROM @sc_landing_background_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_org_name_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_org_name'
);
SET @sc_footer_org_name_sql = IF(
  @sc_footer_org_name_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_org_name VARCHAR(255) NULL AFTER logo_path',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_org_name_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_org_short_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_org_short'
);
SET @sc_footer_org_short_sql = IF(
  @sc_footer_org_short_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_org_short VARCHAR(100) NULL AFTER footer_org_name',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_org_short_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_website_label_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_website_label'
);
SET @sc_footer_website_label_sql = IF(
  @sc_footer_website_label_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_website_label VARCHAR(255) NULL AFTER footer_org_short',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_website_label_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_website_url_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_website_url'
);
SET @sc_footer_website_url_sql = IF(
  @sc_footer_website_url_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_website_url VARCHAR(255) NULL AFTER footer_website_label',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_website_url_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_email_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_email'
);
SET @sc_footer_email_sql = IF(
  @sc_footer_email_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_email VARCHAR(255) NULL AFTER footer_website_url',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_email_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_phone_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_phone'
);
SET @sc_footer_phone_sql = IF(
  @sc_footer_phone_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_phone VARCHAR(255) NULL AFTER footer_email',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_phone_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_hotline_label_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_hotline_label'
);
SET @sc_footer_hotline_label_sql = IF(
  @sc_footer_hotline_label_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_hotline_label VARCHAR(255) NULL AFTER footer_phone',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_hotline_label_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_hotline_number_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_hotline_number'
);
SET @sc_footer_hotline_number_sql = IF(
  @sc_footer_hotline_number_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_hotline_number VARCHAR(50) NULL AFTER footer_hotline_label',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_hotline_number_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_footer_rights_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'footer_rights'
);
SET @sc_footer_rights_sql = IF(
  @sc_footer_rights_exists = 0,
  'ALTER TABLE site_config ADD COLUMN footer_rights VARCHAR(255) NULL AFTER footer_hotline_number',
  'DO 1'
);
PREPARE stmt FROM @sc_footer_rights_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_google_oauth_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'google_oauth_enabled'
);
SET @sc_google_oauth_enabled_sql = IF(
  @sc_google_oauth_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN google_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER footer_rights',
  'DO 1'
);
PREPARE stmt FROM @sc_google_oauth_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_google_oauth_client_id_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'google_oauth_client_id'
);
SET @sc_google_oauth_client_id_sql = IF(
  @sc_google_oauth_client_id_exists = 0,
  'ALTER TABLE site_config ADD COLUMN google_oauth_client_id VARCHAR(255) NULL AFTER google_oauth_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_google_oauth_client_id_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_google_oauth_client_secret_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'google_oauth_client_secret'
);
SET @sc_google_oauth_client_secret_sql = IF(
  @sc_google_oauth_client_secret_exists = 0,
  'ALTER TABLE site_config ADD COLUMN google_oauth_client_secret VARCHAR(255) NULL AFTER google_oauth_client_id',
  'DO 1'
);
PREPARE stmt FROM @sc_google_oauth_client_secret_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_microsoft_oauth_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'microsoft_oauth_enabled'
);
SET @sc_microsoft_oauth_enabled_sql = IF(
  @sc_microsoft_oauth_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN microsoft_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER google_oauth_client_secret',
  'DO 1'
);
PREPARE stmt FROM @sc_microsoft_oauth_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_microsoft_oauth_client_id_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'microsoft_oauth_client_id'
);
SET @sc_microsoft_oauth_client_id_sql = IF(
  @sc_microsoft_oauth_client_id_exists = 0,
  'ALTER TABLE site_config ADD COLUMN microsoft_oauth_client_id VARCHAR(255) NULL AFTER microsoft_oauth_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_microsoft_oauth_client_id_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_microsoft_oauth_client_secret_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'microsoft_oauth_client_secret'
);
SET @sc_microsoft_oauth_client_secret_sql = IF(
  @sc_microsoft_oauth_client_secret_exists = 0,
  'ALTER TABLE site_config ADD COLUMN microsoft_oauth_client_secret VARCHAR(255) NULL AFTER microsoft_oauth_client_id',
  'DO 1'
);
PREPARE stmt FROM @sc_microsoft_oauth_client_secret_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_microsoft_oauth_tenant_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'microsoft_oauth_tenant'
);
SET @sc_microsoft_oauth_tenant_sql = IF(
  @sc_microsoft_oauth_tenant_exists = 0,
  'ALTER TABLE site_config ADD COLUMN microsoft_oauth_tenant VARCHAR(255) NULL AFTER microsoft_oauth_client_secret',
  'DO 1'
);
PREPARE stmt FROM @sc_microsoft_oauth_tenant_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_color_theme_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'color_theme'
);
SET @sc_color_theme_sql = IF(
  @sc_color_theme_exists = 0,
  'ALTER TABLE site_config ADD COLUMN color_theme VARCHAR(50) NOT NULL DEFAULT ''light'' AFTER microsoft_oauth_tenant',
  'DO 1'
);
PREPARE stmt FROM @sc_color_theme_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_brand_color_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'brand_color'
);
SET @sc_brand_color_sql = IF(
  @sc_brand_color_exists = 0,
  'ALTER TABLE site_config ADD COLUMN brand_color VARCHAR(7) NULL AFTER color_theme',
  'DO 1'
);
PREPARE stmt FROM @sc_brand_color_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_local_login_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'local_login_enabled'
);
SET @sc_local_login_enabled_sql = IF(
  @sc_local_login_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN local_login_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER brand_color',
  'DO 1'
);
PREPARE stmt FROM @sc_local_login_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_enabled'
);
SET @sc_smtp_enabled_sql = IF(
  @sc_smtp_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER local_login_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_host_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_host'
);
SET @sc_smtp_host_sql = IF(
  @sc_smtp_host_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_host VARCHAR(255) NULL AFTER smtp_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_host_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_port_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_port'
);
SET @sc_smtp_port_sql = IF(
  @sc_smtp_port_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_port INT NULL AFTER smtp_host',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_port_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_username_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_username'
);
SET @sc_smtp_username_sql = IF(
  @sc_smtp_username_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_username VARCHAR(255) NULL AFTER smtp_port',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_username_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_password_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_password'
);
SET @sc_smtp_password_sql = IF(
  @sc_smtp_password_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_password VARCHAR(255) NULL AFTER smtp_username',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_password_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_encryption_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_encryption'
);
SET @sc_smtp_encryption_sql = IF(
  @sc_smtp_encryption_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_encryption VARCHAR(10) NOT NULL DEFAULT ''none'' AFTER smtp_password',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_encryption_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_from_email_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_from_email'
);
SET @sc_smtp_from_email_sql = IF(
  @sc_smtp_from_email_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_from_email VARCHAR(255) NULL AFTER smtp_encryption',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_from_email_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_from_name_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_from_name'
);
SET @sc_smtp_from_name_sql = IF(
  @sc_smtp_from_name_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_from_name VARCHAR(255) NULL AFTER smtp_from_email',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_from_name_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_smtp_timeout_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'smtp_timeout'
);
SET @sc_smtp_timeout_sql = IF(
  @sc_smtp_timeout_exists = 0,
  'ALTER TABLE site_config ADD COLUMN smtp_timeout INT NULL AFTER smtp_from_name',
  'DO 1'
);
PREPARE stmt FROM @sc_smtp_timeout_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_enabled_locales_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'enabled_locales'
);
SET @sc_enabled_locales_sql = IF(
  @sc_enabled_locales_exists = 0,
  'ALTER TABLE site_config ADD COLUMN enabled_locales TEXT NULL AFTER smtp_timeout',
  'DO 1'
);
PREPARE stmt FROM @sc_enabled_locales_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_upgrade_repo_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'upgrade_repo'
);
SET @sc_upgrade_repo_sql = IF(
  @sc_upgrade_repo_exists = 0,
  'ALTER TABLE site_config ADD COLUMN upgrade_repo VARCHAR(255) NULL AFTER enabled_locales',
  'DO 1'
);
PREPARE stmt FROM @sc_upgrade_repo_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_review_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'review_enabled'
);
SET @sc_review_enabled_sql = IF(
  @sc_review_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN review_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER upgrade_repo',
  'DO 1'
);
PREPARE stmt FROM @sc_review_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_scheduled_assessments_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'scheduled_assessments_enabled'
);
SET @sc_scheduled_assessments_enabled_sql = IF(
  @sc_scheduled_assessments_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN scheduled_assessments_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER review_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_scheduled_assessments_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_qb_danger_zone_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'qb_danger_zone_enabled'
);
SET @sc_qb_danger_zone_enabled_sql = IF(
  @sc_qb_danger_zone_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN qb_danger_zone_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER scheduled_assessments_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_qb_danger_zone_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_email_templates_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'email_templates'
);
SET @sc_email_templates_sql = IF(
  @sc_email_templates_exists = 0,
  'ALTER TABLE site_config ADD COLUMN email_templates LONGTEXT NULL AFTER qb_danger_zone_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_email_templates_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_enabled'
);
SET @sc_ai_enabled_sql = IF(
  @sc_ai_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER email_templates',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_provider_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_provider'
);
SET @sc_ai_provider_sql = IF(
  @sc_ai_provider_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_provider VARCHAR(50) NOT NULL DEFAULT ''ollama'' AFTER ai_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_provider_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_base_url_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_base_url'
);
SET @sc_ai_base_url_sql = IF(
  @sc_ai_base_url_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_base_url VARCHAR(255) NULL AFTER ai_provider',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_base_url_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_api_key_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_api_key'
);
SET @sc_ai_api_key_sql = IF(
  @sc_ai_api_key_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_api_key VARCHAR(255) NULL AFTER ai_base_url',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_api_key_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_model_chat_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_model_chat'
);
SET @sc_ai_model_chat_sql = IF(
  @sc_ai_model_chat_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_model_chat VARCHAR(128) NULL AFTER ai_api_key',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_model_chat_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_model_fast_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_model_fast'
);
SET @sc_ai_model_fast_sql = IF(
  @sc_ai_model_fast_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_model_fast VARCHAR(128) NULL AFTER ai_model_chat',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_model_fast_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_model_fallback_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_model_fallback'
);
SET @sc_ai_model_fallback_sql = IF(
  @sc_ai_model_fallback_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_model_fallback VARCHAR(128) NULL AFTER ai_model_fast',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_model_fallback_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_feature_summary_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_feature_summary_enabled'
);
SET @sc_ai_feature_summary_enabled_sql = IF(
  @sc_ai_feature_summary_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_feature_summary_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_model_fallback',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_feature_summary_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_feature_devplan_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_feature_devplan_enabled'
);
SET @sc_ai_feature_devplan_enabled_sql = IF(
  @sc_ai_feature_devplan_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_feature_devplan_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_feature_summary_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_feature_devplan_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_feature_course_rationale_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_feature_course_rationale_enabled'
);
SET @sc_ai_feature_course_rationale_enabled_sql = IF(
  @sc_ai_feature_course_rationale_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_feature_course_rationale_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_feature_devplan_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_feature_course_rationale_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_placement_supervisor_review_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_placement_supervisor_review'
);
SET @sc_ai_placement_supervisor_review_sql = IF(
  @sc_ai_placement_supervisor_review_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_placement_supervisor_review TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_feature_course_rationale_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_placement_supervisor_review_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_placement_admin_analytics_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_placement_admin_analytics'
);
SET @sc_ai_placement_admin_analytics_sql = IF(
  @sc_ai_placement_admin_analytics_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_placement_admin_analytics TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_placement_supervisor_review',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_placement_admin_analytics_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_timeout_seconds_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_timeout_seconds'
);
SET @sc_ai_timeout_seconds_sql = IF(
  @sc_ai_timeout_seconds_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_timeout_seconds INT NOT NULL DEFAULT 20 AFTER ai_placement_admin_analytics',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_timeout_seconds_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_max_output_tokens_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_max_output_tokens'
);
SET @sc_ai_max_output_tokens_sql = IF(
  @sc_ai_max_output_tokens_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_max_output_tokens INT NOT NULL DEFAULT 700 AFTER ai_timeout_seconds',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_max_output_tokens_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_temperature_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_temperature'
);
SET @sc_ai_temperature_sql = IF(
  @sc_ai_temperature_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_temperature DECIMAL(3,2) NOT NULL DEFAULT 0.20 AFTER ai_max_output_tokens',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_temperature_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_retry_count_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_retry_count'
);
SET @sc_ai_retry_count_sql = IF(
  @sc_ai_retry_count_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_retry_count INT NOT NULL DEFAULT 1 AFTER ai_temperature',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_retry_count_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_require_human_approval_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_require_human_approval'
);
SET @sc_ai_require_human_approval_sql = IF(
  @sc_ai_require_human_approval_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_require_human_approval TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_retry_count',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_require_human_approval_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_show_generated_badge_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_show_generated_badge'
);
SET @sc_ai_show_generated_badge_sql = IF(
  @sc_ai_show_generated_badge_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_show_generated_badge TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_require_human_approval',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_show_generated_badge_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_ai_pii_redaction_enabled_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'ai_pii_redaction_enabled'
);
SET @sc_ai_pii_redaction_enabled_sql = IF(
  @sc_ai_pii_redaction_enabled_exists = 0,
  'ALTER TABLE site_config ADD COLUMN ai_pii_redaction_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_show_generated_badge',
  'DO 1'
);
PREPARE stmt FROM @sc_ai_pii_redaction_enabled_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sc_gender_options_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'site_config'
    AND COLUMN_NAME = 'gender_options'
);
SET @sc_gender_options_sql = IF(
  @sc_gender_options_exists = 0,
  'ALTER TABLE site_config ADD COLUMN gender_options TEXT NULL AFTER ai_pii_redaction_enabled',
  'DO 1'
);
PREPARE stmt FROM @sc_gender_options_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO site_config (
  id,
  site_name,
  landing_text,
  address,
  contact,
  landing_metric_submissions,
  landing_metric_completion,
  landing_metric_adoption,
  logo_path,
  landing_background_path,
  footer_org_name,
  footer_org_short,
  footer_website_label,
  footer_website_url,
  footer_email,
  footer_phone,
  footer_hotline_label,
  footer_hotline_number,
  footer_rights,
  google_oauth_enabled,
  google_oauth_client_id,
  google_oauth_client_secret,
  microsoft_oauth_enabled,
  microsoft_oauth_client_id,
  microsoft_oauth_client_secret,
  microsoft_oauth_tenant,
  color_theme,
  brand_color,
  local_login_enabled,
  smtp_enabled,
  smtp_host,
  smtp_port,
  smtp_username,
  smtp_password,
  smtp_encryption,
  smtp_from_email,
  smtp_from_name,
  smtp_timeout,
  enabled_locales,
  upgrade_repo,
  review_enabled,
  scheduled_assessments_enabled,
  qb_danger_zone_enabled,
  email_templates,
  ai_enabled,
  ai_provider,
  ai_base_url,
  ai_api_key,
  ai_model_chat,
  ai_model_fast,
  ai_model_fallback,
  ai_feature_summary_enabled,
  ai_feature_devplan_enabled,
  ai_feature_course_rationale_enabled,
  ai_placement_supervisor_review,
  ai_placement_admin_analytics,
  ai_timeout_seconds,
  ai_max_output_tokens,
  ai_temperature,
  ai_retry_count,
  ai_require_human_approval,
  ai_show_generated_badge,
  ai_pii_redaction_enabled
) VALUES (
  1,
  'My Performance',
  NULL,
  NULL,
  NULL,
  4280,
  '12 min',
  '94%',
  NULL,
  NULL,
  'Ethiopian Pharmaceutical Supply Service',
  'EPSS / EPS',
  'epss.gov.et',
  'https://epss.gov.et',
  'info@epss.gov.et',
  '+251 11 155 9900',
  'Hotline 939',
  '939',
  'All rights reserved.',
  0,
  NULL,
  NULL,
  0,
  NULL,
  NULL,
  'common',
  'light',
  NULL,
  1,
  0,
  NULL,
  587,
  NULL,
  NULL,
  'none',
  NULL,
  NULL,
  20,
  '["en","fr","am"]',
  'khoppenworth/HRassessv300',
  1,
  1,
  1,
  '{}',
  0,
  'ollama',
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  0,
  0,
  0,
  0,
  0,
  20,
  700,
  0.20,
  1,
  1,
  1,
  1
);

-- Add supporting index for faster timeline queries without full table scans.
SET @response_idx_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_response'
    AND INDEX_NAME = 'idx_response_user_created'
);
SET @response_idx_sql = IF(
  @response_idx_exists = 0,
  'ALTER TABLE questionnaire_response ADD INDEX idx_response_user_created (user_id, created_at)',
  'DO 1'
);
PREPARE stmt FROM @response_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE site_config
SET brand_color = NULL,
    enabled_locales = '["en","fr","am"]'
WHERE id = 1 AND (enabled_locales IS NULL OR enabled_locales = '');

UPDATE site_config
SET brand_color = '#2073bf'
WHERE id = 1
  AND (brand_color IS NULL OR brand_color = '');

UPDATE site_config
SET logo_path = CONCAT('/', TRIM(BOTH '/' FROM logo_path))
WHERE id = 1
  AND logo_path IS NOT NULL
  AND logo_path <> ''
  AND logo_path NOT LIKE 'http://%'
  AND logo_path NOT LIKE 'https://%';

UPDATE site_config
SET landing_background_path = CONCAT('/', TRIM(BOTH '/' FROM landing_background_path))
WHERE id = 1
  AND landing_background_path IS NOT NULL
  AND landing_background_path <> ''
  AND landing_background_path NOT LIKE 'http://%'
  AND landing_background_path NOT LIKE 'https://%';

-- Ensure optional users columns exist without relying on unconditional ALTER TABLE statements.
SET @users_gender_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'gender'
);
SET @users_gender_sql = IF(
  @users_gender_exists = 0,
  'ALTER TABLE users ADD COLUMN gender ENUM(''female'',''male'',''other'',''prefer_not_say'') NULL AFTER email',
  'DO 1'
);
PREPARE stmt FROM @users_gender_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_date_of_birth_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'date_of_birth'
);
SET @users_date_of_birth_sql = IF(
  @users_date_of_birth_exists = 0,
  'ALTER TABLE users ADD COLUMN date_of_birth DATE NULL AFTER gender',
  'DO 1'
);
PREPARE stmt FROM @users_date_of_birth_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_phone_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'phone'
);
SET @users_phone_sql = IF(
  @users_phone_exists = 0,
  'ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER date_of_birth',
  'DO 1'
);
PREPARE stmt FROM @users_phone_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_department_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'department'
);
SET @users_department_sql = IF(
  @users_department_exists = 0,
  'ALTER TABLE users ADD COLUMN department VARCHAR(150) NULL AFTER phone',
  'DO 1'
);
PREPARE stmt FROM @users_department_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_cadre_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'cadre'
);
SET @users_cadre_sql = IF(
  @users_cadre_exists = 0,
  'ALTER TABLE users ADD COLUMN cadre VARCHAR(150) NULL AFTER department',
  'DO 1'
);
PREPARE stmt FROM @users_cadre_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_work_function_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'work_function'
);
SET @users_work_function_sql = IF(
  @users_work_function_exists = 0,
  'ALTER TABLE users ADD COLUMN work_function VARCHAR(100) NULL AFTER cadre',
  'ALTER TABLE users MODIFY COLUMN work_function VARCHAR(100) NULL'
);
PREPARE stmt FROM @users_work_function_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_profile_completed_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'profile_completed'
);
SET @users_profile_completed_sql = IF(
  @users_profile_completed_exists = 0,
  'ALTER TABLE users ADD COLUMN profile_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER work_function',
  'DO 1'
);
PREPARE stmt FROM @users_profile_completed_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_language_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'language'
);
SET @users_language_sql = IF(
  @users_language_exists = 0,
  'ALTER TABLE users ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT ''en'' AFTER profile_completed',
  'DO 1'
);
PREPARE stmt FROM @users_language_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_account_status_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'account_status'
);
SET @users_account_status_sql = IF(
  @users_account_status_exists = 0,
  'ALTER TABLE users ADD COLUMN account_status ENUM(''pending'',''active'',''disabled'') NOT NULL DEFAULT ''active'' AFTER language',
  'DO 1'
);
PREPARE stmt FROM @users_account_status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_must_reset_password_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'must_reset_password'
);
SET @users_must_reset_password_sql = IF(
  @users_must_reset_password_exists = 0,
  'ALTER TABLE users ADD COLUMN must_reset_password TINYINT(1) NOT NULL DEFAULT 0 AFTER account_status',
  'DO 1'
);
PREPARE stmt FROM @users_must_reset_password_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_next_assessment_date_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'next_assessment_date'
);
SET @users_next_assessment_date_sql = IF(
  @users_next_assessment_date_exists = 0,
  'ALTER TABLE users ADD COLUMN next_assessment_date DATE NULL AFTER must_reset_password',
  'DO 1'
);
PREPARE stmt FROM @users_next_assessment_date_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_first_login_at_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'first_login_at'
);
SET @users_first_login_at_sql = IF(
  @users_first_login_at_exists = 0,
  'ALTER TABLE users ADD COLUMN first_login_at DATETIME NULL AFTER next_assessment_date',
  'DO 1'
);
PREPARE stmt FROM @users_first_login_at_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_approved_by_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'approved_by'
);
SET @users_approved_by_sql = IF(
  @users_approved_by_exists = 0,
  'ALTER TABLE users ADD COLUMN approved_by INT NULL AFTER first_login_at',
  'DO 1'
);
PREPARE stmt FROM @users_approved_by_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_approved_at_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'approved_at'
);
SET @users_approved_at_sql = IF(
  @users_approved_at_exists = 0,
  'ALTER TABLE users ADD COLUMN approved_at DATETIME NULL AFTER approved_by',
  'DO 1'
);
PREPARE stmt FROM @users_approved_at_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_sso_provider_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'sso_provider'
);
SET @users_sso_provider_sql = IF(
  @users_sso_provider_exists = 0,
  'ALTER TABLE users ADD COLUMN sso_provider VARCHAR(50) NULL AFTER approved_at',
  'DO 1'
);
PREPARE stmt FROM @users_sso_provider_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @users_sso_subject_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'sso_subject'
);
SET @users_sso_subject_sql = IF(
  @users_sso_subject_exists = 0,
  'ALTER TABLE users ADD COLUMN sso_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER sso_provider',
  'DO 1'
);
PREPARE stmt FROM @users_sso_subject_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @users_sso_identity_index_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uniq_sso_identity'
);
SET @users_sso_identity_index_sql = IF(
  @users_sso_identity_index_exists = 0,
  'ALTER TABLE users ADD UNIQUE KEY uniq_sso_identity (sso_provider, sso_subject)',
  'DO 1'
);
PREPARE stmt FROM @users_sso_identity_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @users_profile_role_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'profile_role'
);
SET @users_profile_role_sql = IF(
  @users_profile_role_exists = 0,
  'ALTER TABLE users ADD COLUMN profile_role VARCHAR(100) NULL AFTER work_function',
  'DO 1'
);
PREPARE stmt FROM @users_profile_role_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_profile_role_other_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'profile_role_other'
);
SET @users_profile_role_other_sql = IF(
  @users_profile_role_other_exists = 0,
  'ALTER TABLE users ADD COLUMN profile_role_other VARCHAR(200) NULL AFTER profile_role',
  'DO 1'
);
PREPARE stmt FROM @users_profile_role_other_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_job_grade_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'job_grade'
);
SET @users_job_grade_sql = IF(
  @users_job_grade_exists = 0,
  'ALTER TABLE users ADD COLUMN job_grade VARCHAR(50) NULL AFTER profile_role_other',
  'DO 1'
);
PREPARE stmt FROM @users_job_grade_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_education_level_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'education_level'
);
SET @users_education_level_sql = IF(
  @users_education_level_exists = 0,
  'ALTER TABLE users ADD COLUMN education_level VARCHAR(50) NULL AFTER job_grade',
  'DO 1'
);
PREPARE stmt FROM @users_education_level_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_highest_degree_subject_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'highest_degree_subject'
);
SET @users_highest_degree_subject_sql = IF(
  @users_highest_degree_subject_exists = 0,
  'ALTER TABLE users ADD COLUMN highest_degree_subject VARCHAR(200) NULL AFTER education_level',
  'DO 1'
);
PREPARE stmt FROM @users_highest_degree_subject_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_work_experience_profile_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'work_experience_profile'
);
SET @users_work_experience_profile_sql = IF(
  @users_work_experience_profile_exists = 0,
  'ALTER TABLE users ADD COLUMN work_experience_profile VARCHAR(255) NULL AFTER highest_degree_subject',
  'DO 1'
);
PREPARE stmt FROM @users_work_experience_profile_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_total_work_experience_band_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'total_work_experience_band'
);
SET @users_total_work_experience_band_sql = IF(
  @users_total_work_experience_band_exists = 0,
  'ALTER TABLE users ADD COLUMN total_work_experience_band VARCHAR(50) NULL AFTER work_experience_profile',
  'DO 1'
);
PREPARE stmt FROM @users_total_work_experience_band_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @users_epss_work_experience_band_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'epss_work_experience_band'
);
SET @users_epss_work_experience_band_sql = IF(
  @users_epss_work_experience_band_exists = 0,
  'ALTER TABLE users ADD COLUMN epss_work_experience_band VARCHAR(50) NULL AFTER total_work_experience_band',
  'DO 1'
);
PREPARE stmt FROM @users_epss_work_experience_band_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


CREATE TABLE IF NOT EXISTS performance_period (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(50) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  UNIQUE KEY uniq_period_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO performance_period (id, label, period_start, period_end) VALUES
(1,'2023','2023-01-01','2023-12-31'),
(2,'2024','2024-01-01','2024-12-31'),
(3,'2025','2025-01-01','2025-12-31'),
(4,'2026','2026-01-01','2026-12-31'),
(5,'2027','2027-01-01','2027-12-31'),
(6,'2028','2028-01-01','2028-12-31'),
(7,'2029','2029-01-01','2029-12-31');

SET @qr_period_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_response'
    AND COLUMN_NAME = 'performance_period_id'
);
SET @qr_period_sql = IF(
  @qr_period_exists = 0,
  'ALTER TABLE questionnaire_response ADD COLUMN performance_period_id INT NOT NULL DEFAULT 1 AFTER questionnaire_id',
  'DO 1'
);
PREPARE stmt FROM @qr_period_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qr_period_modify = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_response'
    AND COLUMN_NAME = 'performance_period_id'
    AND IS_NULLABLE = 'NO'
);
SET @qr_period_modify_sql = IF(
  @qr_period_modify = 0,
  'ALTER TABLE questionnaire_response MODIFY COLUMN performance_period_id INT NOT NULL DEFAULT 1',
  'DO 1'
);
PREPARE stmt FROM @qr_period_modify_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qr_period_fk_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_qr_period'
    AND TABLE_NAME = 'questionnaire_response'
);
SET @qr_period_fk_sql = IF(
  @qr_period_fk_exists = 0,
  'ALTER TABLE questionnaire_response ADD CONSTRAINT fk_qr_period FOREIGN KEY (performance_period_id) REFERENCES performance_period(id) ON DELETE RESTRICT',
  'DO 1'
);
PREPARE stmt FROM @qr_period_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qr_unique_conflicts = (
  SELECT COUNT(1)
  FROM (
    SELECT user_id, questionnaire_id, performance_period_id
    FROM questionnaire_response
    GROUP BY user_id, questionnaire_id, performance_period_id
    HAVING COUNT(*) > 1
  ) AS dup
);
SET @qr_unique_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_response'
    AND INDEX_NAME = 'uniq_user_questionnaire_period'
);
SET @qr_unique_sql = IF(
  @qr_unique_exists = 0 AND @qr_unique_conflicts = 0,
  'ALTER TABLE questionnaire_response ADD UNIQUE KEY uniq_user_questionnaire_period (user_id, questionnaire_id, performance_period_id)',
  'DO 1'
);
PREPARE stmt FROM @qr_unique_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE questionnaire_response
  MODIFY COLUMN status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'submitted';

UPDATE questionnaire_response SET performance_period_id = 1 WHERE performance_period_id IS NULL;

CREATE TABLE IF NOT EXISTS course_catalogue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  course_objective TEXT NULL,
  expected_competency TEXT NULL,
  thematic_area VARCHAR(255) NULL,
  mode_of_delivery VARCHAR(100) NULL,
  duration VARCHAR(50) NULL,
  ceu VARCHAR(50) NULL,
  course_owner VARCHAR(100) NULL,
  moodle_url VARCHAR(255) NULL,
  recommended_for VARCHAR(100) NOT NULL,
  questionnaire_id INT NULL,
  min_score INT NOT NULL DEFAULT 0,
  max_score INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


SET @cc_course_objective_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'course_objective'
);
SET @cc_course_objective_sql = IF(
  @cc_course_objective_exists = 0,
  'ALTER TABLE course_catalogue ADD COLUMN course_objective TEXT NULL AFTER title',
  'DO 1'
);
PREPARE stmt FROM @cc_course_objective_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_expected_competency_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'expected_competency'
);
SET @cc_expected_competency_sql = IF(
  @cc_expected_competency_exists = 0,
  'ALTER TABLE course_catalogue ADD COLUMN expected_competency TEXT NULL AFTER course_objective',
  'DO 1'
);
PREPARE stmt FROM @cc_expected_competency_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_thematic_area_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'thematic_area'
);
SET @cc_thematic_area_sql = IF(
  @cc_thematic_area_exists = 0,
  'ALTER TABLE course_catalogue ADD COLUMN thematic_area VARCHAR(255) NULL AFTER expected_competency',
  'DO 1'
);
PREPARE stmt FROM @cc_thematic_area_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_mode_of_delivery_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'mode_of_delivery'
);
SET @cc_mode_of_delivery_sql = IF(
  @cc_mode_of_delivery_exists = 0,
  'ALTER TABLE course_catalogue ADD COLUMN mode_of_delivery VARCHAR(100) NULL AFTER thematic_area',
  'DO 1'
);
PREPARE stmt FROM @cc_mode_of_delivery_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_duration_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'duration'
);
SET @cc_duration_sql = IF(
  @cc_duration_exists = 0,
  'ALTER TABLE course_catalogue ADD COLUMN duration VARCHAR(50) NULL AFTER mode_of_delivery',
  'DO 1'
);
PREPARE stmt FROM @cc_duration_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_ceu_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'ceu'
);
SET @cc_ceu_sql = IF(
  @cc_ceu_exists = 0,
  'ALTER TABLE course_catalogue ADD COLUMN ceu VARCHAR(50) NULL AFTER duration',
  'DO 1'
);
PREPARE stmt FROM @cc_ceu_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_course_owner_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'course_owner'
);
SET @cc_course_owner_sql = IF(
  @cc_course_owner_exists = 0,
  'ALTER TABLE course_catalogue ADD COLUMN course_owner VARCHAR(100) NULL AFTER ceu',
  'DO 1'
);
PREPARE stmt FROM @cc_course_owner_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_recommended_for_needs_modification = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'recommended_for'
    AND (DATA_TYPE <> 'varchar' OR CHARACTER_MAXIMUM_LENGTH < 100)
);
SET @cc_recommended_for_sql = IF(
  @cc_recommended_for_needs_modification > 0,
  'ALTER TABLE course_catalogue MODIFY COLUMN recommended_for VARCHAR(100) NOT NULL',
  'DO 1'
);
PREPARE stmt FROM @cc_recommended_for_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_max_score_needs_modification = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'max_score'
    AND COALESCE(COLUMN_DEFAULT, '') <> '100'
);
SET @cc_max_score_sql = IF(
  @cc_max_score_needs_modification > 0,
  'ALTER TABLE course_catalogue MODIFY COLUMN max_score INT NOT NULL DEFAULT 100',
  'DO 1'
);
PREPARE stmt FROM @cc_max_score_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @cc_is_active_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'is_active'
);
SET @cc_is_active_sql = IF(
  @cc_is_active_exists = 0,
  'ALTER TABLE course_catalogue ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER max_score',
  'DO 1'
);
PREPARE stmt FROM @cc_is_active_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE course_catalogue SET is_active = 1 WHERE is_active IS NULL;

CREATE TABLE IF NOT EXISTS training_recommendation (
  id INT AUTO_INCREMENT PRIMARY KEY,
  questionnaire_response_id INT NOT NULL,
  course_id INT NOT NULL,
  recommendation_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (questionnaire_response_id) REFERENCES questionnaire_response(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES course_catalogue(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS questionnaire_work_function (
  questionnaire_id INT NOT NULL,
  work_function VARCHAR(255) NOT NULL,
  PRIMARY KEY (questionnaire_id, work_function),
  FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS work_function_catalog (
  slug VARCHAR(100) NOT NULL PRIMARY KEY,
  label VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO work_function_catalog (slug, label, sort_order) VALUES
  ('cmd', 'Change Management & Development', 1),
  ('communication', 'Communications & Partnerships', 2),
  ('dfm', 'Demand Forecasting & Management', 3),
  ('driver', 'Driver Services', 4),
  ('ethics', 'Ethics & Compliance', 5),
  ('finance', 'Finance & Grants', 6),
  ('general_service', 'General Services', 7),
  ('hrm', 'Human Resources Management', 8),
  ('ict', 'Information & Communication Technology', 9),
  ('leadership_tn', 'Leadership & Team Nurturing', 10),
  ('legal_service', 'Legal Services', 11),
  ('pme', 'Planning, Monitoring & Evaluation', 12),
  ('quantification', 'Quantification & Procurement', 13),
  ('records_documentation', 'Records & Documentation', 14),
  ('security', 'Security Operations', 15),
  ('security_driver', 'Security & Driver Management', 16),
  ('tmd', 'Training & Mentorship Development', 17),
  ('wim', 'Warehouse & Inventory Management', 18)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  sort_order = VALUES(sort_order);

SET @qi_condition_source_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'condition_source_linkid'
);
SET @qi_condition_source_sql = IF(
  @qi_condition_source_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN condition_source_linkid VARCHAR(255) NULL AFTER requires_correct',
  'DO 1'
);
PREPARE stmt FROM @qi_condition_source_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qi_condition_operator_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'condition_operator'
);
SET @qi_condition_operator_sql = IF(
  @qi_condition_operator_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN condition_operator VARCHAR(20) NULL AFTER condition_source_linkid',
  'DO 1'
);
PREPARE stmt FROM @qi_condition_operator_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qi_condition_value_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'condition_value'
);
SET @qi_condition_value_sql = IF(
  @qi_condition_value_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN condition_value VARCHAR(500) NULL AFTER condition_operator',
  'DO 1'
);
PREPARE stmt FROM @qi_condition_value_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Team-level questionnaire defaults. Safe to run repeatedly on existing installs.
CREATE TABLE IF NOT EXISTS questionnaire_team (
  questionnaire_id INT NOT NULL,
  team_slug VARCHAR(120) NOT NULL,
  PRIMARY KEY (questionnaire_id, team_slug),
  KEY idx_questionnaire_team_team (team_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @questionnaire_team_slug_len = (
  SELECT COALESCE(CHARACTER_MAXIMUM_LENGTH, 0)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_team'
    AND COLUMN_NAME = 'team_slug'
  LIMIT 1
);
SET @questionnaire_team_slug_sql = IF(
  @questionnaire_team_slug_len > 0 AND @questionnaire_team_slug_len < 120,
  'ALTER TABLE questionnaire_team MODIFY COLUMN team_slug VARCHAR(120) NOT NULL',
  'DO 1'
);
PREPARE stmt FROM @questionnaire_team_slug_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @questionnaire_team_index_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_team'
    AND INDEX_NAME = 'idx_questionnaire_team_team'
);
SET @questionnaire_team_index_sql = IF(
  @questionnaire_team_index_exists = 0,
  'CREATE INDEX idx_questionnaire_team_team ON questionnaire_team (team_slug)',
  'DO 1'
);
PREPARE stmt FROM @questionnaire_team_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
