-- dummy_data.sql: analytics-rich synthetic demo dataset for existing CAS questionnaires.
--
-- Design goals:
--   * Keep demo rows unmistakably separate via demo_* usernames.
--   * Cover all active EPSS work locations (HQ + hubs) without changing location master data.
--   * Cover departments, work roles, gender, education, grade and experience dimensions.
--   * Populate 2024-2028 so the current 2026-2028 trend dashboard has multiple periods.
--   * Create response-item answers, not only response headers, so section/capacity/gap charts work.
--   * Remain idempotent: enabling the demo dataset first removes the previous demo dataset.
--
-- The Admin -> Settings demo toggle executes this file inside a transaction.

SET @password := '$2y$12$IQkYkVMIQE9G/dFkTcvObO1ekoYyOz2gk.d79KxQMOnPOrldv7drq';

-- Clean up previous demo and dummy user data -------------------------------------
DELETE tr
FROM training_recommendation tr
JOIN questionnaire_response qr ON qr.id = tr.questionnaire_response_id
JOIN users u ON u.id = qr.user_id
WHERE u.username LIKE 'demo_%'
   OR u.username LIKE 'dummy_%';

DELETE qri
FROM questionnaire_response_item qri
JOIN questionnaire_response qr ON qr.id = qri.response_id
JOIN users u ON u.id = qr.user_id
WHERE u.username LIKE 'demo_%'
   OR u.username LIKE 'dummy_%';

DELETE FROM questionnaire_response
WHERE user_id IN (
    SELECT id
    FROM users
    WHERE username LIKE 'demo_%'
       OR username LIKE 'dummy_%'
);

DELETE FROM questionnaire_assignment
WHERE staff_id IN (
    SELECT id
    FROM users
    WHERE username LIKE 'demo_%'
       OR username LIKE 'dummy_%'
);

DELETE FROM analytics_report_schedule
WHERE created_by IN (
    SELECT id
    FROM users
    WHERE username LIKE 'demo_%'
       OR username LIKE 'dummy_%'
);

DELETE FROM analytics_report_snapshot_v2
WHERE generated_by IN (
    SELECT id
    FROM users
    WHERE username LIKE 'demo_%'
       OR username LIKE 'dummy_%'
);

UPDATE questionnaire_assignment
SET assigned_by = NULL
WHERE assigned_by IN (
    SELECT id
    FROM users
    WHERE username LIKE 'demo_%'
       OR username LIKE 'dummy_%'
);

UPDATE questionnaire_response
SET reviewed_by = NULL
WHERE reviewed_by IN (
    SELECT id
    FROM users
    WHERE username LIKE 'demo_%'
       OR username LIKE 'dummy_%'
);

UPDATE users
SET approved_by = NULL
WHERE approved_by IN (
    SELECT demo_user_id
    FROM (
        SELECT id AS demo_user_id
        FROM users
        WHERE username LIKE 'demo_%'
           OR username LIKE 'dummy_%'
    ) AS demo_user_ids
);

UPDATE competency_benchmark_policy
SET created_by = NULL
WHERE created_by IN (
    SELECT id
    FROM users
    WHERE username LIKE 'demo_%'
       OR username LIKE 'dummy_%'
);

DELETE FROM logs
WHERE user_id IN (
    SELECT id
    FROM users
    WHERE username LIKE 'demo_%'
       OR username LIKE 'dummy_%'
);

DELETE FROM users
WHERE username LIKE 'demo_%'
   OR username LIKE 'dummy_%';

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

-- Build compact temporary dimension tables -------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_digit;
CREATE TEMPORARY TABLE tmp_demo_digit (n INT NOT NULL PRIMARY KEY) ENGINE=Memory;
INSERT INTO tmp_demo_digit (n) VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);

DROP TEMPORARY TABLE IF EXISTS tmp_demo_numbers;
CREATE TEMPORARY TABLE tmp_demo_numbers (n INT NOT NULL PRIMARY KEY) ENGINE=Memory;
INSERT INTO tmp_demo_numbers (n)
SELECT d1.n + (d2.n * 10) + (d3.n * 100) + 1
FROM tmp_demo_digit d1
CROSS JOIN tmp_demo_digit d2
CROSS JOIN tmp_demo_digit d3
WHERE d1.n + (d2.n * 10) + (d3.n * 100) + 1 <= 80;

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

-- Insert a demo supervisor with a complete profile -------------------------------
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
);

SET @demo_supervisor_id := (SELECT id FROM users WHERE username = 'demo_supervisor' LIMIT 1);

-- Insert 80 synthetic staff. With 20 active EPSS locations this yields 4 staff
-- per physical location while also cycling across every configured department.
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
  ON wf.seq = MOD(n.n - 1, 7) + 1;

-- Select all existing questionnaires that can contribute to analytics ------------
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

-- Assign every demo staff member to every selected questionnaire. ----------------
INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by, assigned_at)
SELECT
    u.id,
    q.questionnaire_id,
    @demo_supervisor_id,
    DATE_ADD('2024-01-05', INTERVAL MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) * 3 + q.questionnaire_id, 45) DAY)
FROM users u
CROSS JOIN tmp_demo_questionnaires q
WHERE u.username LIKE 'demo_staff_%';

-- Create one response per demo staff/questionnaire/year for most staff. Roughly
-- 12.5% are intentionally left unassessed so completion/coverage indicators are
-- not artificially 100%. Scores trend upward by year while retaining visible gaps.
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
    ) AS demo_score,
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
  AND MOD(CAST(SUBSTRING(u.username, 12) AS UNSIGNED) * 13 + q.questionnaire_id * 5 + p.year_value, 8) <> 0;

-- Populate granular response answers. These rows are what allow the capacity-area,
-- section-gap and heatmap calculations to work. Answer choices are deterministic
-- and correlated with each response's synthetic overall score.
INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT
    qr.id,
    qi.linkId,
    CASE
        WHEN qi.type = 'likert' THEN
            CONCAT(
                '[{"valueInteger":',
                LEAST(5, GREATEST(1, ROUND(qr.score / 20))),
                '}]'
            )
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
 AND qi.is_active = 1;

-- Drop temporary working tables --------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_periods;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_questionnaires;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_work_functions;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_locations;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_departments;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_numbers;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_digit;
