-- dummy_data.sql: analytics-rich synthetic demo dataset for existing CAS questionnaires.
-- Safe for MariaDB/MySQL and idempotent without deleting existing demo rows first.

SET @password := '$2y$12$IQkYkVMIQE9G/dFkTcvObO1ekoYyOz2gk.d79KxQMOnPOrldv7drq';

-- Ensure annual performance periods exist --------------------------------------
INSERT INTO performance_period (label, period_start, period_end)
VALUES
('2024', '2024-01-01', '2024-12-31'),
('2025', '2025-01-01', '2025-12-31'),
('2026', '2026-01-01', '2026-12-31'),
('2027', '2027-01-01', '2027-12-31'),
('2028', '2028-01-01', '2028-12-31')
ON DUPLICATE KEY UPDATE
    period_start = VALUES(period_start),
    period_end = VALUES(period_end);

-- Build 1..80 without reopening a TEMPORARY table. MariaDB/MySQL error 1137 is
-- triggered when the same temporary table is referenced multiple times in one
-- statement, so the digit sources below are independent derived tables.
DROP TEMPORARY TABLE IF EXISTS tmp_demo_numbers;
CREATE TEMPORARY TABLE tmp_demo_numbers (n INT NOT NULL PRIMARY KEY) ENGINE=Memory;
INSERT INTO tmp_demo_numbers (n)
SELECT ones.n + (tens.n * 10) + 1
FROM (
    SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
    UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
) AS ones
CROSS JOIN (
    SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
    UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
) AS tens
WHERE ones.n + (tens.n * 10) + 1 <= 80;

DROP TEMPORARY TABLE IF EXISTS tmp_demo_departments;
CREATE TEMPORARY TABLE tmp_demo_departments (
    seq INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL,
    label VARCHAR(255) NOT NULL
) ENGINE=Memory;
INSERT INTO tmp_demo_departments (slug, label)
SELECT slug, label
FROM department_catalog
WHERE archived_at IS NULL
ORDER BY sort_order, label;
INSERT INTO tmp_demo_departments (slug, label)
SELECT 'general_service', 'General Services'
WHERE NOT EXISTS (SELECT 1 FROM tmp_demo_departments);
SET @demo_department_count := (SELECT COUNT(*) FROM tmp_demo_departments);

DROP TEMPORARY TABLE IF EXISTS tmp_demo_locations;
CREATE TEMPORARY TABLE tmp_demo_locations (
    seq INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id INT NOT NULL,
    location_name VARCHAR(200) NOT NULL
) ENGINE=Memory;
INSERT INTO tmp_demo_locations (location_id, location_name)
SELECT id, name
FROM epss_location
WHERE is_active = 1
ORDER BY CASE WHEN location_type = 'hq' THEN 0 ELSE 1 END, name;
SET @demo_location_count := (SELECT COUNT(*) FROM tmp_demo_locations);

DROP TEMPORARY TABLE IF EXISTS tmp_demo_work_functions;
CREATE TEMPORARY TABLE tmp_demo_work_functions (
    seq INT NOT NULL PRIMARY KEY,
    slug VARCHAR(100) NOT NULL
) ENGINE=Memory;
INSERT INTO tmp_demo_work_functions (seq, slug) VALUES
(1, 'finance'),
(2, 'hrm'),
(3, 'wim'),
(4, 'director'),
(5, 'manager'),
(6, 'team_lead'),
(7, 'expert');

-- Demo supervisor ---------------------------------------------------------------
INSERT INTO users (
    username, password, role, full_name, email, gender, phone,
    department, directorate, cadre, work_function, location_id,
    profile_role, business_role, job_grade, education_level, highest_degree_subject,
    work_experience_profile, total_work_experience_band, epss_work_experience_band,
    account_status, profile_completed, must_reset_password, language
)
VALUES (
    'demo_supervisor', @password, 'supervisor', 'Demo Supervisor', 'demo.supervisor@example.invalid',
    'female', '+251900000000',
    'leadership_tn', 'Leadership & Team Nurturing', 'leadership_tn_team_leads', 'manager',
    (SELECT id FROM epss_location WHERE is_active = 1 AND location_type = 'hq' ORDER BY id LIMIT 1),
    'manager', 'manager', 'grade_15', 'masters_plus', 'Public Administration',
    'Synthetic demonstration profile for CAS analytics.', '10_plus', '5_10',
    'active', 1, 1, 'en'
)
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    role = VALUES(role),
    full_name = VALUES(full_name),
    email = VALUES(email),
    gender = VALUES(gender),
    phone = VALUES(phone),
    department = VALUES(department),
    directorate = VALUES(directorate),
    cadre = VALUES(cadre),
    work_function = VALUES(work_function),
    location_id = VALUES(location_id),
    profile_role = VALUES(profile_role),
    business_role = VALUES(business_role),
    job_grade = VALUES(job_grade),
    education_level = VALUES(education_level),
    highest_degree_subject = VALUES(highest_degree_subject),
    work_experience_profile = VALUES(work_experience_profile),
    total_work_experience_band = VALUES(total_work_experience_band),
    epss_work_experience_band = VALUES(epss_work_experience_band),
    account_status = 'active',
    profile_completed = 1,
    must_reset_password = 1,
    language = 'en';

SET @demo_supervisor_id := (SELECT id FROM users WHERE username = 'demo_supervisor' LIMIT 1);

-- 80 synthetic staff distributed across all active locations and departments -----
INSERT INTO users (
    username, password, role, full_name, email, gender, date_of_birth, phone,
    department, directorate, cadre, work_function, location_id,
    profile_role, business_role, job_grade, education_level, highest_degree_subject,
    work_experience_profile, total_work_experience_band, epss_work_experience_band,
    account_status, profile_completed, must_reset_password, language
)
SELECT
    CONCAT('demo_staff_', LPAD(n.n, 3, '0')),
    @password,
    'staff',
    CONCAT('Demo Staff ', LPAD(n.n, 3, '0')),
    CONCAT('demo.staff', LPAD(n.n, 3, '0'), '@example.invalid'),
    CASE MOD(n.n - 1, 4)
        WHEN 0 THEN 'female'
        WHEN 1 THEN 'male'
        WHEN 2 THEN 'other'
        ELSE 'prefer_not_say'
    END,
    DATE_SUB('1990-07-01', INTERVAL MOD(n.n * 173, 6200) DAY),
    CONCAT('+2519', LPAD(n.n, 8, '0')),
    d.slug,
    d.label,
    COALESCE(
        (
            SELECT dt.slug
            FROM department_team_catalog dt
            WHERE dt.department_slug = d.slug
              AND dt.archived_at IS NULL
            ORDER BY dt.sort_order, dt.label
            LIMIT 1
        ),
        CONCAT(d.slug, '_demo_team')
    ),
    wf.slug,
    (
        SELECT l.location_id
        FROM tmp_demo_locations l
        WHERE l.seq = MOD(n.n - 1, GREATEST(@demo_location_count, 1)) + 1
        LIMIT 1
    ),
    CASE MOD(n.n - 1, 5)
        WHEN 0 THEN 'director_branch_manager'
        WHEN 1 THEN 'manager'
        WHEN 2 THEN 'team_leader_coordinator'
        WHEN 3 THEN 'officer_level_4'
        ELSE 'officer_level_2'
    END,
    CASE MOD(n.n - 1, 5)
        WHEN 0 THEN 'director'
        WHEN 1 THEN 'manager'
        WHEN 2 THEN 'team_leader'
        WHEN 3 THEN 'staff'
        ELSE 'hub'
    END,
    CONCAT('grade_', 9 + MOD(n.n - 1, 9)),
    CASE MOD(n.n - 1, 3)
        WHEN 0 THEN 'diploma'
        WHEN 1 THEN 'bachelors'
        ELSE 'masters_plus'
    END,
    CASE MOD(n.n - 1, 5)
        WHEN 0 THEN 'Supply Chain Management'
        WHEN 1 THEN 'Pharmacy'
        WHEN 2 THEN 'Finance'
        WHEN 3 THEN 'Information Technology'
        ELSE 'Public Administration'
    END,
    'Synthetic demonstration profile for CAS analytics.',
    CASE MOD(n.n - 1, 4)
        WHEN 0 THEN '0_2'
        WHEN 1 THEN '2_5'
        WHEN 2 THEN '5_10'
        ELSE '10_plus'
    END,
    CASE MOD(n.n + 1, 4)
        WHEN 0 THEN '0_2'
        WHEN 1 THEN '2_5'
        WHEN 2 THEN '5_10'
        ELSE '10_plus'
    END,
    'active', 1, 1, 'en'
FROM tmp_demo_numbers n
JOIN tmp_demo_departments d
  ON d.seq = MOD(n.n - 1, GREATEST(@demo_department_count, 1)) + 1
JOIN tmp_demo_work_functions wf
  ON wf.seq = MOD(n.n - 1, 7) + 1
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    role = VALUES(role),
    full_name = VALUES(full_name),
    email = VALUES(email),
    gender = VALUES(gender),
    date_of_birth = VALUES(date_of_birth),
    phone = VALUES(phone),
    department = VALUES(department),
    directorate = VALUES(directorate),
    cadre = VALUES(cadre),
    work_function = VALUES(work_function),
    location_id = VALUES(location_id),
    profile_role = VALUES(profile_role),
    business_role = VALUES(business_role),
    job_grade = VALUES(job_grade),
    education_level = VALUES(education_level),
    highest_degree_subject = VALUES(highest_degree_subject),
    work_experience_profile = VALUES(work_experience_profile),
    total_work_experience_band = VALUES(total_work_experience_band),
    epss_work_experience_band = VALUES(epss_work_experience_band),
    account_status = 'active',
    profile_completed = 1,
    must_reset_password = 1,
    language = 'en';

-- Existing questionnaires that can contribute to analytics ----------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_questionnaires;
CREATE TEMPORARY TABLE tmp_demo_questionnaires (questionnaire_id INT NOT NULL PRIMARY KEY) ENGINE=Memory;
INSERT INTO tmp_demo_questionnaires (questionnaire_id)
SELECT q.id
FROM questionnaire q
JOIN questionnaire_item qi
  ON qi.questionnaire_id = q.id
 AND qi.is_active = 1
WHERE q.status IN ('draft', 'published')
GROUP BY q.id;

DROP TEMPORARY TABLE IF EXISTS tmp_demo_periods;
CREATE TEMPORARY TABLE tmp_demo_periods (
    period_id INT NOT NULL PRIMARY KEY,
    year_value INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL
) ENGINE=Memory;
INSERT INTO tmp_demo_periods (period_id, year_value, period_start, period_end)
SELECT id, CAST(label AS UNSIGNED), period_start, period_end
FROM performance_period
WHERE label IN ('2024', '2025', '2026', '2027', '2028');

-- Explicit demo assignments -----------------------------------------------------
INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by, assigned_at)
SELECT
    u.id,
    q.questionnaire_id,
    @demo_supervisor_id,
    DATE_ADD('2024-01-05', INTERVAL MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) * 3 + q.questionnaire_id, 45) DAY)
FROM users u
CROSS JOIN tmp_demo_questionnaires q
WHERE u.username LIKE 'demo_staff_%'
ON DUPLICATE KEY UPDATE
    assigned_by = VALUES(assigned_by),
    assigned_at = VALUES(assigned_at);

-- Multi-year responses. About 12.5% are intentionally absent so completion is
-- realistic rather than 100%; scores trend upward by year and vary by location.
INSERT INTO questionnaire_response (
    user_id, questionnaire_id, performance_period_id, status, score,
    reviewed_by, reviewed_at, review_comment, created_at
)
SELECT
    u.id,
    q.questionnaire_id,
    p.period_id,
    CASE
        WHEN MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) + q.questionnaire_id + p.year_value, 4) = 0
            THEN 'submitted'
        ELSE 'approved'
    END,
    LEAST(
        98,
        GREATEST(
            48,
            58
            + MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) * 7 + q.questionnaire_id * 11, 28)
            + ((p.year_value - 2024) * 3)
            - CASE WHEN MOD(COALESCE(u.location_id, 0), 5) = 0 THEN 6 ELSE 0 END
        )
    ),
    CASE
        WHEN MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) + q.questionnaire_id + p.year_value, 4) = 0
            THEN NULL
        ELSE @demo_supervisor_id
    END,
    CASE
        WHEN MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) + q.questionnaire_id + p.year_value, 4) = 0
            THEN NULL
        ELSE DATE_ADD(
            DATE_ADD(p.period_start, INTERVAL 70 + MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) * 9 + q.questionnaire_id * 7, 240) DAY),
            INTERVAL 3 DAY
        )
    END,
    CASE
        WHEN MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) + q.questionnaire_id + p.year_value, 4) = 0
            THEN NULL
        ELSE 'Synthetic supervisor review for demonstration analytics.'
    END,
    DATE_ADD(
        p.period_start,
        INTERVAL 70 + MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) * 9 + q.questionnaire_id * 7, 240) DAY
    )
FROM users u
CROSS JOIN tmp_demo_questionnaires q
CROSS JOIN tmp_demo_periods p
WHERE u.username LIKE 'demo_staff_%'
  AND MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) * 13 + q.questionnaire_id * 5 + p.year_value, 8) <> 0
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    score = VALUES(score),
    reviewed_by = VALUES(reviewed_by),
    reviewed_at = VALUES(reviewed_at),
    review_comment = VALUES(review_comment),
    created_at = VALUES(created_at);

-- Granular answers for section/capacity/gap analytics. Re-enabling the dataset
-- does not duplicate response items already present for the same response/linkId.
INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT
    qr.id,
    qi.linkId,
    CASE
        WHEN qi.type = 'likert' THEN
            CONCAT('[{"valueInteger":', LEAST(5, GREATEST(1, ROUND(qr.score / 20))), '}]')
        WHEN qi.type = 'boolean' THEN
            CASE
                WHEN MOD(qr.user_id * 17 + qi.id * 13 + YEAR(qr.created_at), 100) < qr.score
                    THEN '[{"valueBoolean":true}]'
                ELSE '[{"valueBoolean":false}]'
            END
        WHEN qi.type = 'choice' THEN
            CONCAT(
                '[{"valueString":',
                JSON_QUOTE(
                    COALESCE(
                        CASE
                            WHEN qi.allow_multiple = 0
                             AND qi.requires_correct = 1
                             AND MOD(qr.user_id * 17 + qi.id * 13 + YEAR(qr.created_at), 100) < qr.score
                                THEN (
                                    SELECT qio.value
                                    FROM questionnaire_item_option qio
                                    WHERE qio.questionnaire_item_id = qi.id
                                      AND qio.is_correct = 1
                                    ORDER BY qio.order_index, qio.id
                                    LIMIT 1
                                )
                            WHEN qi.allow_multiple = 0
                             AND qi.requires_correct = 1
                                THEN (
                                    SELECT qio.value
                                    FROM questionnaire_item_option qio
                                    WHERE qio.questionnaire_item_id = qi.id
                                      AND qio.is_correct = 0
                                    ORDER BY qio.order_index, qio.id
                                    LIMIT 1
                                )
                            ELSE (
                                SELECT qio.value
                                FROM questionnaire_item_option qio
                                WHERE qio.questionnaire_item_id = qi.id
                                ORDER BY qio.order_index, qio.id
                                LIMIT 1
                            )
                        END,
                        (
                            SELECT qio.value
                            FROM questionnaire_item_option qio
                            WHERE qio.questionnaire_item_id = qi.id
                            ORDER BY qio.order_index, qio.id
                            LIMIT 1
                        ),
                        'Demo response'
                    )
                ),
                '}]'
            )
        ELSE
            CONCAT(
                '[{"valueString":',
                JSON_QUOTE(
                    CASE MOD(qr.user_id + qi.id, 4)
                        WHEN 0 THEN 'Demonstrated consistent delivery against planned priorities.'
                        WHEN 1 THEN 'Collaborated across teams to improve service quality outcomes.'
                        WHEN 2 THEN 'Documented lessons learned and proposed targeted improvements.'
                        ELSE 'Maintained strong compliance while meeting cycle deliverables.'
                    END
                ),
                '}]'
            )
    END
FROM questionnaire_response qr
JOIN users u
  ON u.id = qr.user_id
 AND u.username LIKE 'demo_staff_%'
JOIN questionnaire_item qi
  ON qi.questionnaire_id = qr.questionnaire_id
 AND qi.is_active = 1
WHERE NOT EXISTS (
    SELECT 1
    FROM questionnaire_response_item existing
    WHERE existing.response_id = qr.id
      AND existing.linkId = qi.linkId
);

-- Drop temporary working tables --------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_periods;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_questionnaires;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_work_functions;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_locations;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_departments;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_numbers;
