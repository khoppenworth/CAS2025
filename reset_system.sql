-- reset_system.sql: restore the application database to its seeded state
-- This script truncates application tables and re-populates default records
-- so the system can start a new assessment cycle.

SET @original_foreign_key_checks := @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE training_recommendation;
TRUNCATE TABLE analytics_report_schedule;
TRUNCATE TABLE questionnaire_assignment;
TRUNCATE TABLE questionnaire_work_function;
TRUNCATE TABLE questionnaire_response_item;
TRUNCATE TABLE questionnaire_response;
TRUNCATE TABLE questionnaire_item_option;
TRUNCATE TABLE questionnaire_item;
TRUNCATE TABLE questionnaire_section;
TRUNCATE TABLE questionnaire;
TRUNCATE TABLE course_catalogue;
TRUNCATE TABLE logs;
TRUNCATE TABLE users;
TRUNCATE TABLE performance_period;
TRUNCATE TABLE site_config;

SET FOREIGN_KEY_CHECKS = @original_foreign_key_checks;

REPLACE INTO site_config (
  id,
  site_name,
  landing_text,
  address,
  contact,
  landing_metric_submissions,
  landing_metric_completion,
  landing_metric_adoption,
  logo_path,
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
  enabled_locales,
  smtp_enabled,
  smtp_host,
  smtp_port,
  smtp_username,
  smtp_password,
  smtp_encryption,
  smtp_from_email,
  smtp_from_name,
  smtp_timeout,
  upgrade_repo,
  review_enabled,
  email_templates
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
  '#2073bf',
  '["en","fr","am"]',
  0,
  NULL,
  587,
  NULL,
  NULL,
  'none',
  NULL,
  NULL,
  20,
  'khoppenworth/HRassessv300',
  1,
  '{}'
);

REPLACE INTO users (username, password, role, full_name, email, account_status, must_reset_password)
VALUES
  ('admin', '__TEMP_DISABLE_admin_da9e140cdeec43d7__', 'admin', 'System Admin', 'admin@example.com', 'disabled', 1),
  ('super', '__TEMP_DISABLE_super_58b113836a493e63__', 'supervisor', 'Default Supervisor', 'super@example.com', 'disabled', 1),
  ('staff', '__TEMP_DISABLE_staff_47aab2ebd8db15ae__', 'staff', 'Sample Staff', 'staff@example.com', 'disabled', 1);

INSERT INTO questionnaire (title, description)
VALUES ('Baseline Staff Self-Assessment', 'Initial EPSS self-assessment');
SET @qid := LAST_INSERT_ID();

INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES
  (@qid, 'general_service'),
  (@qid, 'hrm'),
  (@qid, 'ict'),
  (@qid, 'finance'),
  (@qid, 'leadership_tn');

INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index) VALUES
  (@qid, 'Core Competencies', 'General capability checks', 1),
  (@qid, 'Facility & Process', 'Process and facility checks', 2);

SET @s1 := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @qid AND order_index = 1 LIMIT 1);
SET @s2 := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @qid AND order_index = 2 LIMIT 1);

INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent) VALUES
  (@qid, @s1, 'q1', 'Understands SOPs for dispensing?', 'boolean', 1, 20),
  (@qid, @s1, 'q2', 'List key essential medicines handled daily', 'text', 2, 20),
  (@qid, @s1, 'q3', 'Describe one challenge faced this week', 'textarea', 3, 20),
  (@qid, @s2, 'q4', 'Daily temperature monitoring completed?', 'boolean', 1, 20),
  (@qid, @s2, 'q5', 'Any stockouts this week?', 'boolean', 2, 20);

INSERT INTO course_catalogue (code, title, moodle_url, recommended_for, min_score, max_score) VALUES
  ('FIN-101', 'Financial Management Fundamentals', 'https://moodle.example.com/course/fin101', 'finance', 0, 79),
  ('ICT-201', 'Digital Security Essentials', 'https://moodle.example.com/course/ict201', 'ict', 0, 89),
  ('HRM-110', 'People Management Basics', 'https://moodle.example.com/course/hrm110', 'hrm', 0, 89),
  ('GEN-050', 'Customer Service Excellence', 'https://moodle.example.com/course/gen050', 'general_service', 0, 89),
  ('LEAD-300', 'Leadership and Team Nurturing', 'https://moodle.example.com/course/lead300', 'leadership_tn', 0, 94),
  ('SAFE-210', 'Security Awareness Refresher', 'https://moodle.example.com/course/safe210', 'security', 0, 94);

SET @start_year := YEAR(CURDATE()) - 1;
SET @end_year := YEAR(CURDATE()) + 1;

WITH RECURSIVE year_span(year_val) AS (
  SELECT @start_year
  UNION ALL
  SELECT year_val + 1 FROM year_span WHERE year_val < @end_year
)
INSERT INTO performance_period (label, period_start, period_end)
SELECT CONCAT(year_val, ' H1'), CONCAT(year_val, '-01-01'), CONCAT(year_val, '-06-30')
FROM year_span
UNION ALL
SELECT CONCAT(year_val, ' H2'), CONCAT(year_val, '-07-01'), CONCAT(year_val, '-12-31')
FROM year_span
ON DUPLICATE KEY UPDATE period_start = VALUES(period_start), period_end = VALUES(period_end);
