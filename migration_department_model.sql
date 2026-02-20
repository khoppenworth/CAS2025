-- Department/Team/Work-Role migration script
-- Use for upgrading existing databases that already contain users and questionnaires.
-- Safe to run multiple times.

START TRANSACTION;

-- 1) Ensure target tables exist
CREATE TABLE IF NOT EXISTS department_catalog (
  slug VARCHAR(120) NOT NULL PRIMARY KEY,
  label VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS department_team_catalog (
  slug VARCHAR(120) NOT NULL PRIMARY KEY,
  department_slug VARCHAR(120) NOT NULL,
  label VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  archived_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_department_slug_col = (
  SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'department_team_catalog' AND COLUMN_NAME = 'department_slug'
);
SET @add_department_slug_col_sql = IF(
  @has_department_slug_col = 0,
  'ALTER TABLE department_team_catalog ADD COLUMN department_slug VARCHAR(120) NULL AFTER slug',
  'DO 1'
);
PREPARE stmt FROM @add_department_slug_col_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS questionnaire_department (
  questionnaire_id INT NOT NULL,
  department_slug VARCHAR(120) NOT NULL,
  PRIMARY KEY (questionnaire_id, department_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Seed departments from the former work-function set
INSERT INTO department_catalog (slug, label, sort_order)
VALUES
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
  sort_order = VALUES(sort_order),
  archived_at = NULL;

-- 3) Build questionnaire defaults at department level from previous questionnaire_work_function mappings
INSERT IGNORE INTO questionnaire_department (questionnaire_id, department_slug)
SELECT qwf.questionnaire_id, qwf.work_function
FROM questionnaire_work_function qwf
JOIN department_catalog dc ON dc.slug = qwf.work_function;

-- 4) Normalize users.department to department slug
UPDATE users u
JOIN department_catalog dc ON LOWER(TRIM(u.department)) = LOWER(TRIM(dc.slug))
SET u.department = dc.slug
WHERE u.department IS NOT NULL AND TRIM(u.department) <> '';

UPDATE users u
JOIN department_catalog dc ON LOWER(TRIM(u.department)) = LOWER(TRIM(dc.label))
SET u.department = dc.slug
WHERE u.department IS NOT NULL AND TRIM(u.department) <> '';

-- fallback for unknown/blank department
UPDATE users
SET department = 'general_service'
WHERE department IS NULL OR TRIM(department) = '';

-- 5) Build team catalog linked to department slug.
--    Team slug includes department prefix to prevent cross-department collisions.
INSERT INTO department_team_catalog (slug, department_slug, label, sort_order)
SELECT DISTINCT
  CONCAT(
    LOWER(TRIM(u.department)),
    '__',
    TRIM(BOTH '_' FROM REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(u.cadre)), ' ', '_'), '-', '_'), '/', '_'), '&', '_'), '.', '_'), ',', '_'), '__', '_'))
  ) AS team_slug,
  LOWER(TRIM(u.department)) AS department_slug,
  TRIM(u.cadre) AS label,
  1000
FROM users u
WHERE u.cadre IS NOT NULL
  AND TRIM(u.cadre) <> ''
  AND u.department IS NOT NULL
  AND TRIM(u.department) <> ''
ON DUPLICATE KEY UPDATE
  department_slug = VALUES(department_slug),
  label = VALUES(label),
  archived_at = NULL;

-- 6) Migrate users.cadre from display label to team slug
UPDATE users u
JOIN department_team_catalog dtc
  ON dtc.department_slug = u.department
 AND LOWER(TRIM(dtc.label)) = LOWER(TRIM(u.cadre))
SET u.cadre = dtc.slug
WHERE u.cadre IS NOT NULL AND TRIM(u.cadre) <> '';

-- 7) Collapse work functions to work roles
--    Admin users become director, supervisors become manager, others become expert.
UPDATE users
SET work_function = CASE
  WHEN role = 'admin' THEN 'director'
  WHEN role = 'supervisor' THEN 'manager'
  ELSE 'expert'
END;

INSERT INTO work_function_catalog (slug, label, sort_order, archived_at)
VALUES
  ('director', 'Director', 1, NULL),
  ('manager', 'Manager', 2, NULL),
  ('team_lead', 'Team Lead', 3, NULL),
  ('expert', 'Expert', 4, NULL)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  sort_order = VALUES(sort_order),
  archived_at = NULL;

UPDATE work_function_catalog
SET archived_at = CURRENT_TIMESTAMP
WHERE slug NOT IN ('director', 'manager', 'team_lead', 'expert');

COMMIT;
