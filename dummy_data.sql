-- dummy_data.sql: lightweight demo dataset for existing forms (no questionnaire creation/deletion)

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

-- Ensure annual performance periods exist for 2020-2025 --------------------------
INSERT INTO performance_period (label, period_start, period_end)
VALUES
('2020', '2020-01-01', '2020-12-31'),
('2021', '2021-01-01', '2021-12-31'),
('2022', '2022-01-01', '2022-12-31'),
('2023', '2023-01-01', '2023-12-31'),
('2024', '2024-01-01', '2024-12-31'),
('2025', '2025-01-01', '2025-12-31')
ON DUPLICATE KEY UPDATE
    period_start = VALUES(period_start),
    period_end = VALUES(period_end);

-- Insert fictive demo users ------------------------------------------------------
INSERT INTO users (username, password, role, full_name, email, work_function, account_status, profile_completed, must_reset_password, language)
VALUES
('demo_supervisor', @password, 'supervisor', 'Demo Supervisor', 'demo.supervisor@example.com', 'leadership_tn', 'active', 1, 1, 'en'),
('demo_staff_finance', @password, 'staff', 'Demo Finance Staff', 'demo.finance@example.com', 'finance', 'active', 1, 1, 'en'),
('demo_staff_hr', @password, 'staff', 'Demo HR Staff', 'demo.hr@example.com', 'hrm', 'active', 1, 1, 'en'),
('demo_staff_ict', @password, 'staff', 'Demo ICT Staff', 'demo.ict@example.com', 'ict', 'active', 1, 1, 'en'),
('demo_staff_ops', @password, 'staff', 'Demo Operations Staff', 'demo.ops@example.com', 'general_service', 'active', 1, 1, 'en');

-- Seed analytics-friendly assignments and responses against existing forms -------
SET @demo_supervisor_id := (SELECT id FROM users WHERE username = 'demo_supervisor' LIMIT 1);

DROP TEMPORARY TABLE IF EXISTS tmp_demo_staff;
CREATE TEMPORARY TABLE tmp_demo_staff (staff_id INT PRIMARY KEY) ENGINE=Memory;
INSERT INTO tmp_demo_staff (staff_id)
SELECT id
FROM users
WHERE username LIKE 'demo_staff_%';

DROP TEMPORARY TABLE IF EXISTS tmp_demo_questionnaires;
CREATE TEMPORARY TABLE tmp_demo_questionnaires (questionnaire_id INT PRIMARY KEY) ENGINE=Memory;
INSERT INTO tmp_demo_questionnaires (questionnaire_id)
SELECT q.id
FROM questionnaire q
JOIN questionnaire_item qi ON qi.questionnaire_id = q.id AND qi.is_active = 1
WHERE q.status IN ('draft', 'published')
GROUP BY q.id;

DROP TEMPORARY TABLE IF EXISTS tmp_demo_periods;
CREATE TEMPORARY TABLE tmp_demo_periods (
    period_id INT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL
) ENGINE=Memory;
INSERT INTO tmp_demo_periods (period_id, period_start, period_end)
SELECT id, period_start, period_end
FROM performance_period
WHERE label IN ('2020', '2021', '2022', '2023', '2024', '2025');

INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by, assigned_at)
SELECT s.staff_id,
       q.questionnaire_id,
       @demo_supervisor_id,
       DATE_ADD('2020-01-01', INTERVAL FLOOR(RAND() * 1500) DAY)
FROM tmp_demo_staff s
CROSS JOIN tmp_demo_questionnaires q;

INSERT INTO questionnaire_response (
    user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at
)
SELECT
    s.staff_id,
    q.questionnaire_id,
    p.period_id,
    'approved' AS response_status,
    ROUND(58 + (RAND() * 38), 0) AS score,
    @demo_supervisor_id,
    DATE_ADD(p.period_start, INTERVAL 280 + FLOOR(RAND() * 60) DAY) AS reviewed_at,
    'Seeded demo response for analytics preview.',
    DATE_ADD(p.period_start, INTERVAL FLOOR(RAND() * 250) DAY) AS created_at
FROM tmp_demo_staff s
CROSS JOIN tmp_demo_questionnaires q
CROSS JOIN tmp_demo_periods p;

DROP TEMPORARY TABLE IF EXISTS tmp_demo_periods;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_questionnaires;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_staff;
