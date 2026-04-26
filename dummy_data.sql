-- dummy_data.sql: lightweight demo dataset with fictive users only (no questionnaire creation/deletion)
-- Use scripts/seed_dummy_data_from_questionnaires.php to generate questionnaire submissions
-- against existing draft/published questionnaires.

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
