-- dummy_data.sql: realistic HR assessment demo dataset representing five performance cycles
-- All demo accounts share a pre-hashed temporary password and require reset on first login.
SET @password := '$2y$12$IQkYkVMIQE9G/dFkTcvObO1ekoYyOz2gk.d79KxQMOnPOrldv7drq';

-- Clean up previous demo dataset ------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_questionnaires;
CREATE TEMPORARY TABLE tmp_demo_questionnaires (id INT PRIMARY KEY) ENGINE=Memory;
INSERT INTO tmp_demo_questionnaires (id)
SELECT id
FROM questionnaire
WHERE title IN ('EPSA Annual Performance Review 360', 'EPSA Leadership Confidence Pulse');

DELETE tr
FROM training_recommendation tr
JOIN questionnaire_response qr ON qr.id = tr.questionnaire_response_id
JOIN users u ON u.id = qr.user_id
WHERE u.username LIKE 'demo_%';

DELETE qri
FROM questionnaire_response_item qri
JOIN questionnaire_response qr ON qr.id = qri.response_id
JOIN users u ON u.id = qr.user_id
WHERE u.username LIKE 'demo_%';

DELETE FROM questionnaire_response
WHERE questionnaire_id IN (SELECT id FROM tmp_demo_questionnaires)
   OR user_id IN (SELECT id FROM users WHERE username LIKE 'demo_%');

DELETE FROM questionnaire_assignment
WHERE questionnaire_id IN (SELECT id FROM tmp_demo_questionnaires)
   OR staff_id IN (SELECT id FROM users WHERE username LIKE 'demo_%');

DELETE FROM questionnaire_work_function WHERE questionnaire_id IN (SELECT id FROM tmp_demo_questionnaires);

DELETE qio
FROM questionnaire_item_option qio
JOIN questionnaire_item qi ON qi.id = qio.questionnaire_item_id
WHERE qi.questionnaire_id IN (SELECT id FROM tmp_demo_questionnaires);

DELETE FROM questionnaire_item WHERE questionnaire_id IN (SELECT id FROM tmp_demo_questionnaires);
DELETE FROM questionnaire_section WHERE questionnaire_id IN (SELECT id FROM tmp_demo_questionnaires);
DELETE FROM questionnaire WHERE id IN (SELECT id FROM tmp_demo_questionnaires);
DROP TEMPORARY TABLE IF EXISTS tmp_demo_questionnaires;

DELETE FROM analytics_report_schedule
WHERE created_by IN (SELECT id FROM users WHERE username LIKE 'demo_%');

DELETE FROM logs WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'demo_%');

DELETE FROM course_catalogue WHERE code LIKE 'EPSA-%';

DELETE FROM users WHERE username LIKE 'demo_%';

-- Ensure performance periods exist for the last seven cycles --------------------
INSERT INTO performance_period (label, period_start, period_end)
VALUES
('2021 H1', '2021-01-01', '2021-06-30'),
('2021 H2', '2021-07-01', '2021-12-31'),
('2022 H1', '2022-01-01', '2022-06-30'),
('2022 H2', '2022-07-01', '2022-12-31'),
('2023 H1', '2023-01-01', '2023-06-30'),
('2023 H2', '2023-07-01', '2023-12-31'),
('2024 H1', '2024-01-01', '2024-06-30'),
('2024 H2', '2024-07-01', '2024-12-31'),
('2025 H1', '2025-01-01', '2025-06-30'),
('2025 H2', '2025-07-01', '2025-12-31'),
('2026 H1', '2026-01-01', '2026-06-30'),
('2026 H2', '2026-07-01', '2026-12-31'),
('2027 H1', '2027-01-01', '2027-06-30'),
('2027 H2', '2027-07-01', '2027-12-31')
ON DUPLICATE KEY UPDATE
    period_start = VALUES(period_start),
    period_end = VALUES(period_end);

SET @period_2023_h2 := (SELECT id FROM performance_period WHERE label = '2023 H2' LIMIT 1);
SET @period_2024_h1 := (SELECT id FROM performance_period WHERE label = '2024 H1' LIMIT 1);
SET @period_2024_h2 := (SELECT id FROM performance_period WHERE label = '2024 H2' LIMIT 1);
SET @period_2025_h1 := (SELECT id FROM performance_period WHERE label = '2025 H1' LIMIT 1);

-- Insert demo users representing realistic personas ----------------------------
INSERT INTO users (username, password, role, full_name, email, gender, date_of_birth, phone,
                   department, cadre, work_function, profile_completed, must_reset_password,
                   account_status, language)
VALUES
('demo_strategy_admin', @password, 'admin', 'Selam Ayalew', 'demo.strategy.admin@example.com', 'female', '1979-06-12', '+251900200000', 'Strategy & Transformation', 'Director', 'leadership_tn', 1, 1, 'active', 'en'),
('demo_people_lead', @password, 'supervisor', 'Kifle Bekele', 'demo.people.lead@example.com', 'male', '1981-04-18', '+251900200010', 'People & Culture', 'Manager', 'hrm', 1, 1, 'active', 'en'),
('demo_supply_lead', @password, 'supervisor', 'Rahel Demissie', 'demo.supply.lead@example.com', 'female', '1983-02-22', '+251900200020', 'Supply Chain Integration', 'Manager', 'quantification', 1, 1, 'active', 'en'),
('demo_region_lead', @password, 'supervisor', 'Samuel Fekadu', 'demo.region.lead@example.com', 'male', '1980-11-09', '+251900200030', 'Regional Operations', 'Manager', 'general_service', 1, 1, 'active', 'en'),
('demo_finance_controller', @password, 'staff', 'Meron Gudeta', 'demo.finance.controller@example.com', 'female', '1987-01-08', '+251910300001', 'Finance & Grants', 'Controller', 'finance', 1, 1, 'active', 'en'),
('demo_procurement_specialist', @password, 'staff', 'Hanna Mengistu', 'demo.procurement.specialist@example.com', 'female', '1989-03-14', '+251910300002', 'Supply Chain Management', 'Specialist', 'quantification', 1, 1, 'active', 'en'),
('demo_hr_partner', @password, 'staff', 'Natnael Alemu', 'demo.hr.partner@example.com', 'male', '1990-05-20', '+251910300003', 'People & Culture', 'Senior Officer', 'hrm', 1, 1, 'active', 'en'),
('demo_it_analyst', @password, 'staff', 'Saron Birhanu', 'demo.it.analyst@example.com', 'female', '1991-07-02', '+251910300004', 'Digital Health Systems', 'Analyst', 'ict', 1, 1, 'active', 'en'),
('demo_field_adama', @password, 'staff', 'Yonas Getachew', 'demo.field.adama@example.com', 'male', '1988-09-11', '+251910300005', 'Regional Ops - Adama', 'Coordinator', 'general_service', 1, 1, 'active', 'en'),
('demo_field_gondar', @password, 'staff', 'Lulit Endale', 'demo.field.gondar@example.com', 'female', '1992-10-27', '+251910300006', 'Regional Ops - Gondar', 'Coordinator', 'general_service', 1, 1, 'active', 'en'),
('demo_security_coord', @password, 'staff', 'Tadesse Lemma', 'demo.security.coord@example.com', 'male', '1985-08-16', '+251910300007', 'Protection & Fleet', 'Coordinator', 'security', 1, 1, 'active', 'en'),
('demo_driver_fleet', @password, 'staff', 'Hirut Abate', 'demo.driver.fleet@example.com', 'female', '1993-12-05', '+251910300008', 'Protection & Fleet', 'Lead Driver', 'driver', 1, 1, 'active', 'en'),
('demo_quality_manager', @password, 'staff', 'Fitsum Kebede', 'demo.quality.manager@example.com', 'male', '1986-04-03', '+251910300009', 'Monitoring & Evaluation', 'Manager', 'pme', 1, 1, 'active', 'en'),
('demo_lab_advisor', @password, 'staff', 'Rediet Eshetu', 'demo.lab.advisor@example.com', 'female', '1984-06-25', '+251910300010', 'National Laboratory Support', 'Advisor', 'wim', 1, 1, 'active', 'en'),
('demo_records_officer', @password, 'staff', 'Abel Tsegaye', 'demo.records.officer@example.com', 'male', '1987-02-18', '+251910300011', 'Knowledge Management', 'Officer', 'records_documentation', 1, 1, 'active', 'en'),
('demo_comm_lead', @password, 'staff', 'Ruth Desta', 'demo.comm.lead@example.com', 'female', '1985-01-30', '+251910300012', 'Communications & Partnerships', 'Lead', 'communication', 1, 1, 'active', 'en');

SET @demo_admin_id := (SELECT id FROM users WHERE username = 'demo_strategy_admin');
SET @demo_people_lead_id := (SELECT id FROM users WHERE username = 'demo_people_lead');
SET @demo_supply_lead_id := (SELECT id FROM users WHERE username = 'demo_supply_lead');
SET @demo_region_lead_id := (SELECT id FROM users WHERE username = 'demo_region_lead');
SET @demo_hr_partner_id := (SELECT id FROM users WHERE username = 'demo_hr_partner');

UPDATE users
SET approved_by = @demo_admin_id,
    approved_at = DATE_SUB(NOW(), INTERVAL 40 DAY)
WHERE username LIKE 'demo_%' AND role = 'staff';

UPDATE users
SET first_login_at = DATE_SUB(NOW(), INTERVAL 12 DAY)
WHERE username IN ('demo_strategy_admin', 'demo_people_lead', 'demo_supply_lead', 'demo_region_lead');

-- Activity log entries -----------------------------------------------------------
INSERT INTO logs (user_id, action, meta)
VALUES
(@demo_admin_id, 'login', '{"ip":"10.55.0.10","agent":"seed-script"}'),
(@demo_people_lead_id, 'login', '{"ip":"10.55.0.11","agent":"seed-script"}'),
(@demo_supply_lead_id, 'login', '{"ip":"10.55.0.12","agent":"seed-script"}'),
(@demo_region_lead_id, 'login', '{"ip":"10.55.0.13","agent":"seed-script"}'),
(@demo_admin_id, 'analytics_export', '{"report":"balanced_scorecard","scope":"nationwide"}');

INSERT INTO logs (user_id, action, meta)
SELECT u.id,
       'profile_update',
       CONCAT('{"status":"completed","department":"', u.department, '"}')
FROM users u
WHERE u.username LIKE 'demo_%' AND u.role = 'staff';

-- Extended learning catalogue ----------------------------------------------------
INSERT INTO course_catalogue (code, title, moodle_url, recommended_for, min_score, max_score)
VALUES
('EPSA-FIN-410', 'Financial Stewardship for Donor Funds', 'https://moodle.example.com/course/fin410', 'finance', 0, 88),
('EPSA-OPS-375', 'Regional Operations Coaching', 'https://moodle.example.com/course/ops375', 'general_service', 0, 85),
('EPSA-ICT-320', 'Digital Supply Chain Integrations', 'https://moodle.example.com/course/ict320', 'ict', 0, 90),
('EPSA-HRM-215', 'People Analytics for Wellbeing', 'https://moodle.example.com/course/hrm215', 'hrm', 0, 87),
('EPSA-SEC-190', 'Protective Operations Playbooks', 'https://moodle.example.com/course/sec190', 'security', 0, 86),
('EPSA-MEL-360', 'Evidence-based Performance Storytelling', 'https://moodle.example.com/course/mel360', 'pme', 0, 90),
('EPSA-COM-240', 'Stakeholder Confidence Communications', 'https://moodle.example.com/course/com240', 'communication', 0, 90),
('EPSA-DRV-220', 'Fleet Safety Leadership', 'https://moodle.example.com/course/drv220', 'driver', 0, 85);

-- Demo questionnaire -------------------------------------------------------------
INSERT INTO questionnaire (title, description, status)
VALUES ('EPSA Annual Performance Review 360', 'Five-year storyline covering supply chain modernization and people enablement.', 'published');
SET @demo_qid := LAST_INSERT_ID();

INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index, is_active)
VALUES
(@demo_qid, 'Strategic Delivery', 'Measures how teams deliver essential health commodities.', 1, 1),
(@demo_qid, 'Operational Excellence', 'Assesses compliance and continuous improvement practices.', 2, 1),
(@demo_qid, 'Growth & Support', 'Captures development focus and mobility planning.', 3, 1);

SET @section_strategic := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @demo_qid AND order_index = 1);
SET @section_operational := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @demo_qid AND order_index = 2);
SET @section_growth := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @demo_qid AND order_index = 3);

INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple, is_required, is_active)
VALUES
(@demo_qid, @section_strategic, 'strategic_results', 'Delivery reliability to health facilities', 'likert', 1, 30, 0, 1, 1),
(@demo_qid, @section_operational, 'quality_controls', 'All mandatory compliance and safety trainings completed', 'boolean', 2, 15, 0, 1, 1),
(@demo_qid, @section_operational, 'achievement_story', 'Summarize the most significant contribution this cycle', 'textarea', 3, 20, 0, 1, 1),
(@demo_qid, @section_operational, 'stretch_contributions', 'Select notable stretch contributions achieved', 'choice', 4, 10, 1, 0, 1),
(@demo_qid, @section_growth, 'development_focus', 'Describe a development focus for the next cycle', 'textarea', 5, 15, 0, 1, 1),
(@demo_qid, @section_growth, 'mobility_readiness', 'Readiness for broader national responsibilities', 'likert', 6, 10, 0, 0, 1);

INSERT INTO questionnaire_item_option (questionnaire_item_id, value, order_index)
SELECT qi.id, opt.value, opt.order_index
FROM questionnaire_item qi
JOIN (
    SELECT 'strategic_results' AS linkId, '5 - Consistently surpassing delivery targets' AS value, 1 AS order_index UNION ALL
    SELECT 'strategic_results', '4 - Meeting and occasionally exceeding', 2 UNION ALL
    SELECT 'strategic_results', '3 - Meeting baseline service levels', 3 UNION ALL
    SELECT 'strategic_results', '2 - Frequent service gaps', 4 UNION ALL
    SELECT 'strategic_results', '1 - Severe service gaps', 5 UNION ALL
    SELECT 'mobility_readiness', '5 - Ready to lead national initiatives', 1 UNION ALL
    SELECT 'mobility_readiness', '4 - Ready for large multi-region assignments', 2 UNION ALL
    SELECT 'mobility_readiness', '3 - Ready for expanded responsibilities', 3 UNION ALL
    SELECT 'mobility_readiness', '2 - Needs targeted coaching before expansion', 4 UNION ALL
    SELECT 'mobility_readiness', '1 - Focus on current role stabilization', 5 UNION ALL
    SELECT 'stretch_contributions', 'Piloted national stock visibility dashboard', 1 UNION ALL
    SELECT 'stretch_contributions', 'Stood up emergency distribution cell', 2 UNION ALL
    SELECT 'stretch_contributions', 'Mentored regional supply coordinators', 3 UNION ALL
    SELECT 'stretch_contributions', 'Reduced financial variances across grants', 4 UNION ALL
    SELECT 'stretch_contributions', 'Improved facility-level reporting compliance', 5 UNION ALL
    SELECT 'stretch_contributions', 'Advanced leadership coaching for site managers', 6
) AS opt ON opt.linkId = qi.linkId
WHERE qi.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_work_function (questionnaire_id, work_function)
SELECT @demo_qid, wf
FROM (
    SELECT 'finance' AS wf UNION ALL
    SELECT 'quantification' UNION ALL
    SELECT 'hrm' UNION ALL
    SELECT 'ict' UNION ALL
    SELECT 'general_service' UNION ALL
    SELECT 'security' UNION ALL
    SELECT 'driver' UNION ALL
    SELECT 'pme' UNION ALL
    SELECT 'wim' UNION ALL
    SELECT 'records_documentation' UNION ALL
    SELECT 'communication'
) AS functions;

-- Assign questionnaire to demo staff --------------------------------------------
SET @assignment_offset := 0;
INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by, assigned_at)
SELECT u.id,
       @demo_qid,
       CASE
           WHEN u.work_function IN ('finance', 'quantification', 'pme', 'records_documentation') THEN @demo_supply_lead_id
           WHEN u.work_function IN ('hrm', 'communication') THEN @demo_people_lead_id
           ELSE @demo_region_lead_id
       END,
       DATE_SUB(NOW(), INTERVAL (@assignment_offset := @assignment_offset + 1) DAY)
FROM users u
WHERE u.username LIKE 'demo_%' AND u.role = 'staff'
ORDER BY u.username;

-- Metadata describing each performance cycle ------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_demo_period_meta;
CREATE TEMPORARY TABLE tmp_demo_period_meta (
    label VARCHAR(4) PRIMARY KEY,
    status ENUM('draft','submitted','approved','rejected') NOT NULL,
    review_days INT NULL,
    review_comment VARCHAR(255) NULL,
    score_adjustment INT NOT NULL,
    narrative_focus VARCHAR(255) NOT NULL,
    dev_theme VARCHAR(255) NOT NULL
);

INSERT INTO tmp_demo_period_meta (label, status, review_days, review_comment, score_adjustment, narrative_focus, dev_theme)
VALUES
('2021', 'approved', 32, 'First Balanced Scorecard cycle closed with targeted coaching', 0, 'Stabilized last mile availability', 'Advance analytics and reporting capability'),
('2022', 'approved', 27, 'Digital tracking adoption reached three new regions', 2, 'Expanded visibility for strategic stock items', 'Strengthen coaching skills for line managers'),
('2023', 'approved', 21, 'Quality loops embedded with partners', 4, 'Reduced emergency orders and strengthened cold chain discipline', 'Deepen mentoring for regional leads'),
('2024', 'approved', 15, 'Automation dashboards validated at headquarters', 6, 'Closed donor scorecard reporting gaps', 'Lead enterprise change management sprints'),
('2025', 'submitted', NULL, NULL, 8, 'Piloting predictive demand planning prototypes', 'Document and scale knowledge transfer playbooks');

-- Responses for 2021-2025 cycles -------------------------------------------------
INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
SELECT u.id,
       @demo_qid,
       pp.id,
       pm.status,
       CASE
           WHEN pm.status = 'approved' THEN LEAST(100,
               80 + pm.score_adjustment +
               CASE u.work_function
                   WHEN 'finance' THEN 6
                   WHEN 'quantification' THEN 5
                   WHEN 'hrm' THEN 4
                   WHEN 'ict' THEN 5
                   WHEN 'general_service' THEN 3
                   WHEN 'security' THEN 2
                   WHEN 'driver' THEN 1
                   WHEN 'pme' THEN 6
                   WHEN 'wim' THEN 5
                   WHEN 'records_documentation' THEN 4
                   WHEN 'communication' THEN 4
                   ELSE 3
               END
               - (u.id % 5)
           )
           ELSE NULL
       END AS score,
       CASE
           WHEN pm.status = 'approved' THEN CASE
               WHEN u.work_function IN ('finance', 'quantification', 'pme', 'records_documentation') THEN @demo_supply_lead_id
               WHEN u.work_function IN ('hrm', 'communication') THEN @demo_people_lead_id
               ELSE @demo_region_lead_id
           END
           ELSE NULL
       END AS reviewed_by,
       CASE
           WHEN pm.status = 'approved' THEN DATE_SUB(NOW(), INTERVAL (pm.review_days + (u.id % 6)) DAY)
           ELSE NULL
       END AS reviewed_at,
       CASE
           WHEN pm.status = 'approved' THEN CONCAT(pm.review_comment, ' | Focus area: ',
               CASE u.work_function
                   WHEN 'finance' THEN 'Supply planning accuracy'
                   WHEN 'quantification' THEN 'Forecast collaboration with donors'
                   WHEN 'hrm' THEN 'Workforce wellbeing metrics'
                   WHEN 'ict' THEN 'Systems interoperability and uptime'
                   WHEN 'general_service' THEN 'Regional coordination discipline'
                   WHEN 'security' THEN 'Risk mitigation readiness'
                   WHEN 'driver' THEN 'Fleet adherence and coaching'
                   WHEN 'pme' THEN 'Evidence packaging for partners'
                   WHEN 'wim' THEN 'Laboratory mentorship coverage'
                   WHEN 'records_documentation' THEN 'Knowledge capture routines'
                   WHEN 'communication' THEN 'Stakeholder confidence storytelling'
                   ELSE 'Cross functional contribution'
               END)
           ELSE NULL
       END AS review_comment,
       DATE_ADD(pp.period_start, INTERVAL (12 + (u.id % 16)) DAY) AS created_at
FROM users u
JOIN tmp_demo_period_meta pm ON 1
JOIN performance_period pp ON pp.label = pm.label
WHERE u.username LIKE 'demo_%' AND u.role = 'staff'
ORDER BY u.username, pp.label;

-- Populate questionnaire response items -----------------------------------------
INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'strategic_results',
       CASE
           WHEN qr.score >= 90 THEN '[{"valueInteger":5,"valueString":"5 - Consistently surpassing delivery targets"}]'
           WHEN qr.score >= 84 THEN '[{"valueInteger":4,"valueString":"4 - Meeting and occasionally exceeding"}]'
           WHEN qr.score IS NULL THEN '[{"valueInteger":4,"valueString":"4 - Meeting and occasionally exceeding"}]'
           ELSE '[{"valueInteger":3,"valueString":"3 - Meeting baseline service levels"}]'
       END
FROM questionnaire_response qr
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'quality_controls',
       CASE
           WHEN pp.label = '2021' AND u.work_function IN ('driver', 'security') THEN '[{"valueBoolean":false}]'
           WHEN pp.label = '2022' AND u.username = 'demo_field_gondar' THEN '[{"valueBoolean":false}]'
           WHEN pp.label = '2025' AND u.username = 'demo_field_gondar' THEN '[{"valueBoolean":false}]'
           ELSE '[{"valueBoolean":true}]'
       END
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'achievement_story',
       CONCAT('[{"valueString":"', pm.narrative_focus, ' within ', u.department, ' during ', pp.label, '."}]')
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
JOIN tmp_demo_period_meta pm ON pm.label = pp.label
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'stretch_contributions',
       CASE
           WHEN u.work_function = 'finance' THEN CASE
               WHEN pp.label IN ('2021', '2022') THEN '[{"valueString":"Reduced financial variances across grants"},{"valueString":"Improved facility-level reporting compliance"}]'
               ELSE '[{"valueString":"Reduced financial variances across grants"},{"valueString":"Piloted national stock visibility dashboard"}]'
           END
           WHEN u.work_function = 'quantification' THEN CASE
               WHEN pp.label IN ('2024', '2025') THEN '[{"valueString":"Piloted national stock visibility dashboard"},{"valueString":"Mentored regional supply coordinators"}]'
               ELSE '[{"valueString":"Stood up emergency distribution cell"},{"valueString":"Improved facility-level reporting compliance"}]'
           END
           WHEN u.work_function = 'hrm' THEN '[{"valueString":"Mentored regional supply coordinators"},{"valueString":"Advanced leadership coaching for site managers"}]'
           WHEN u.work_function = 'ict' THEN CASE
               WHEN pp.label IN ('2023', '2024', '2025') THEN '[{"valueString":"Piloted national stock visibility dashboard"},{"valueString":"Advanced leadership coaching for site managers"}]'
               ELSE '[{"valueString":"Piloted national stock visibility dashboard"},{"valueString":"Improved facility-level reporting compliance"}]'
           END
           WHEN u.work_function = 'general_service' THEN CASE
               WHEN pp.label = '2021' THEN '[{"valueString":"Stood up emergency distribution cell"}]'
               WHEN pp.label = '2025' THEN '[{"valueString":"Mentored regional supply coordinators"},{"valueString":"Improved facility-level reporting compliance"}]'
               ELSE '[{"valueString":"Stood up emergency distribution cell"},{"valueString":"Mentored regional supply coordinators"}]'
           END
           WHEN u.work_function = 'security' THEN '[{"valueString":"Stood up emergency distribution cell"},{"valueString":"Improved facility-level reporting compliance"}]'
           WHEN u.work_function = 'driver' THEN CASE
               WHEN pp.label = '2021' THEN '[{"valueString":"Stood up emergency distribution cell"}]'
               ELSE '[{"valueString":"Improved facility-level reporting compliance"}]'
           END
           WHEN u.work_function IN ('pme', 'wim', 'records_documentation') THEN '[{"valueString":"Mentored regional supply coordinators"},{"valueString":"Advanced leadership coaching for site managers"}]'
           WHEN u.work_function = 'communication' THEN '[{"valueString":"Mentored regional supply coordinators"},{"valueString":"Improved facility-level reporting compliance"}]'
           ELSE '[{"valueString":"Improved facility-level reporting compliance"}]'
       END
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'development_focus',
       CONCAT('[{"valueString":"', pm.dev_theme, ' focusing on ',
           CASE u.work_function
               WHEN 'finance' THEN 'data driven supply planning'
               WHEN 'quantification' THEN 'collaborative forecasting'
               WHEN 'hrm' THEN 'talent pipelines and wellbeing dashboards'
               WHEN 'ict' THEN 'systems integration and analytics'
               WHEN 'general_service' THEN 'regional coordination practices'
               WHEN 'security' THEN 'risk mitigation playbooks'
               WHEN 'driver' THEN 'fleet mentoring routines'
               WHEN 'pme' THEN 'monitoring and evaluation storytelling'
               WHEN 'wim' THEN 'laboratory quality mentorship'
               WHEN 'records_documentation' THEN 'knowledge capture routines'
               WHEN 'communication' THEN 'stakeholder engagement campaigns'
               ELSE 'cross functional collaboration'
           END,
       '."}]')
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
JOIN tmp_demo_period_meta pm ON pm.label = pp.label
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'mobility_readiness',
       CASE
           WHEN qr.score >= 92 THEN '[{"valueInteger":5,"valueString":"5 - Ready to lead national initiatives"}]'
           WHEN qr.score >= 86 THEN '[{"valueInteger":4,"valueString":"4 - Ready for large multi-region assignments"}]'
           WHEN qr.score IS NULL THEN CASE
               WHEN u.work_function IN ('driver', 'security') THEN '[{"valueInteger":3,"valueString":"3 - Ready for expanded responsibilities"}]'
               ELSE '[{"valueInteger":4,"valueString":"4 - Ready for large multi-region assignments"}]'
           END
           ELSE '[{"valueInteger":3,"valueString":"3 - Ready for expanded responsibilities"}]'
       END
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
WHERE qr.questionnaire_id = @demo_qid;

-- Leadership confidence pulse questionnaire --------------------------------------
INSERT INTO questionnaire (title, description, status)
VALUES ('EPSA Leadership Confidence Pulse', 'Quarterly signal on leadership alignment and support needs.', 'published');
SET @pulse_qid := LAST_INSERT_ID();

INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index, is_active)
VALUES
(@pulse_qid, 'Leadership Behaviors', 'Signals from senior leaders on vision and coaching.', 1, 1),
(@pulse_qid, 'Support Systems', 'Escalation readiness and organisational clarity needs.', 2, 1);

SET @pulse_section_leadership := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @pulse_qid AND order_index = 1);
SET @pulse_section_support := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @pulse_qid AND order_index = 2);

INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple, is_required, is_active)
VALUES
(@pulse_qid, @pulse_section_leadership, 'vision_alignment', 'Alignment with EPSA transformation vision', 'likert', 1, 35, 0, 1, 1),
(@pulse_qid, @pulse_section_leadership, 'coaching_frequency', 'How frequently do you coach your team?', 'likert', 2, 25, 0, 1, 1),
(@pulse_qid, @pulse_section_support, 'escalation_clear', 'Escalation paths are clear and used effectively', 'boolean', 3, 10, 0, 0, 1),
(@pulse_qid, @pulse_section_support, 'priority_support', 'Where do you need senior support next?', 'textarea', 4, 15, 0, 0, 1),
(@pulse_qid, @pulse_section_support, 'org_clarity', 'Organisational direction is clear', 'likert', 5, 15, 0, 1, 1);

INSERT INTO questionnaire_item_option (questionnaire_item_id, value, order_index)
SELECT qi.id, opt.value, opt.order_index
FROM questionnaire_item qi
JOIN (
    SELECT 'vision_alignment' AS linkId, '5 - Always aligns teams' AS value, 1 AS order_index UNION ALL
    SELECT 'vision_alignment', '4 - Aligns across most initiatives', 2 UNION ALL
    SELECT 'vision_alignment', '3 - Alignment requires reminders', 3 UNION ALL
    SELECT 'vision_alignment', '2 - Alignment often drifts', 4 UNION ALL
    SELECT 'vision_alignment', '1 - No clear alignment', 5 UNION ALL
    SELECT 'coaching_frequency', '5 - Weekly coaching sessions', 1 UNION ALL
    SELECT 'coaching_frequency', '4 - Bi-weekly conversations', 2 UNION ALL
    SELECT 'coaching_frequency', '3 - Quarterly coaching cadence', 3 UNION ALL
    SELECT 'coaching_frequency', '2 - Twice per year', 4 UNION ALL
    SELECT 'coaching_frequency', '1 - Rarely or never', 5 UNION ALL
    SELECT 'org_clarity', '5 - Vision is crystal clear', 1 UNION ALL
    SELECT 'org_clarity', '4 - Clarity in most areas', 2 UNION ALL
    SELECT 'org_clarity', '3 - Clarity improving but inconsistent', 3 UNION ALL
    SELECT 'org_clarity', '2 - Needs significant clarification', 4 UNION ALL
    SELECT 'org_clarity', '1 - Very unclear direction', 5
) AS opt ON opt.linkId = qi.linkId
WHERE qi.questionnaire_id = @pulse_qid;

INSERT INTO questionnaire_work_function (questionnaire_id, work_function)
SELECT @pulse_qid, wf
FROM (
    SELECT 'leadership_tn' AS wf UNION ALL
    SELECT 'hrm' UNION ALL
    SELECT 'quantification' UNION ALL
    SELECT 'general_service' UNION ALL
    SELECT 'ict'
) AS functions;

INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by, assigned_at)
VALUES
(@demo_people_lead_id, @pulse_qid, @demo_admin_id, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(@demo_supply_lead_id, @pulse_qid, @demo_admin_id, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(@demo_region_lead_id, @pulse_qid, @demo_admin_id, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(@demo_hr_partner_id, @pulse_qid, @demo_people_lead_id, DATE_SUB(NOW(), INTERVAL 7 DAY));

INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
VALUES
(@demo_people_lead_id, @pulse_qid, @period_2024_h2, 'approved', 92, @demo_admin_id, DATE_SUB(NOW(), INTERVAL 28 DAY), 'Executive team endorsed regional mentoring pilots.', DATE_SUB(NOW(), INTERVAL 60 DAY));
SET @pulse_resp_people := LAST_INSERT_ID();

INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
VALUES
(@demo_supply_lead_id, @pulse_qid, @period_2024_h2, 'submitted', NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 35 DAY));
SET @pulse_resp_supply := LAST_INSERT_ID();

INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
VALUES
(@demo_region_lead_id, @pulse_qid, @period_2025_h1, 'submitted', NULL, NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 18 DAY));
SET @pulse_resp_region := LAST_INSERT_ID();

INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
VALUES
(@demo_hr_partner_id, @pulse_qid, @period_2025_h1, 'approved', 94, @demo_people_lead_id, DATE_SUB(NOW(), INTERVAL 12 DAY), 'Coaching cadence recognised during people committee.', DATE_SUB(NOW(), INTERVAL 22 DAY));
SET @pulse_resp_hr := LAST_INSERT_ID();

INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
VALUES
(@demo_people_lead_id, @pulse_qid, @period_2023_h2, 'approved', 88, @demo_admin_id, DATE_SUB(NOW(), INTERVAL 410 DAY), 'Leadership clinics accelerated approvals for strategic projects.', DATE_SUB(NOW(), INTERVAL 425 DAY));
SET @pulse_resp_people_prev := LAST_INSERT_ID();

INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
VALUES
(@demo_supply_lead_id, @pulse_qid, @period_2023_h2, 'approved', 85, @demo_admin_id, DATE_SUB(NOW(), INTERVAL 398 DAY), 'Supply dashboards stabilised weekly cadence expectations.', DATE_SUB(NOW(), INTERVAL 415 DAY));
SET @pulse_resp_supply_prev := LAST_INSERT_ID();

INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
VALUES
(@demo_admin_id, @pulse_qid, @period_2024_h1, 'approved', 96, @demo_people_lead_id, DATE_SUB(NOW(), INTERVAL 85 DAY), 'Transformation office showcased readiness labs for regions.', DATE_SUB(NOW(), INTERVAL 110 DAY));
SET @pulse_resp_admin := LAST_INSERT_ID();

INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES
(@pulse_resp_people, 'vision_alignment', '[{"valueInteger":5,"valueString":"5 - Always aligns teams"}]'),
(@pulse_resp_people, 'coaching_frequency', '[{"valueInteger":4,"valueString":"4 - Coaching conversations monthly"}]'),
(@pulse_resp_people, 'escalation_clear', '[{"valueBoolean":true}]'),
(@pulse_resp_people, 'priority_support', '[{"valueString":"Maintaining executive visibility on distribution reforms."}]'),
(@pulse_resp_people, 'org_clarity', '[{"valueInteger":4,"valueString":"4 - Direction is clear with minor gaps"}]'),
(@pulse_resp_supply, 'vision_alignment', '[{"valueInteger":4,"valueString":"4 - Aligns across most initiatives"}]'),
(@pulse_resp_supply, 'coaching_frequency', '[{"valueInteger":3,"valueString":"3 - Quarterly coaching cadence"}]'),
(@pulse_resp_supply, 'escalation_clear', '[{"valueBoolean":true}]'),
(@pulse_resp_supply, 'priority_support', '[{"valueString":"Need faster budget approvals for automation pilots."}]'),
(@pulse_resp_supply, 'org_clarity', '[{"valueInteger":3,"valueString":"3 - Clarity in most areas"}]'),
(@pulse_resp_region, 'vision_alignment', '[{"valueInteger":3,"valueString":"3 - Alignment requires reminders"}]'),
(@pulse_resp_region, 'coaching_frequency', '[{"valueInteger":3,"valueString":"3 - Quarterly coaching cadence"}]'),
(@pulse_resp_region, 'escalation_clear', '[{"valueBoolean":false}]'),
(@pulse_resp_region, 'priority_support', '[{"valueString":"Request more structured mentoring for emerging coordinators."}]'),
(@pulse_resp_region, 'org_clarity', '[{"valueInteger":3,"valueString":"3 - Clarity improving but inconsistent"}]'),
(@pulse_resp_hr, 'vision_alignment', '[{"valueInteger":5,"valueString":"5 - Strong alignment with transformation goals"}]'),
(@pulse_resp_hr, 'coaching_frequency', '[{"valueInteger":5,"valueString":"5 - Weekly coaching sessions"}]'),
(@pulse_resp_hr, 'escalation_clear', '[{"valueBoolean":true}]'),
(@pulse_resp_hr, 'priority_support', '[{"valueString":"Scaling wellbeing resources to regions."}]'),
(@pulse_resp_hr, 'org_clarity', '[{"valueInteger":4,"valueString":"4 - Clarity in most areas"}]');

INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES
(@pulse_resp_people_prev, 'vision_alignment', '[{"valueInteger":4,"valueString":"4 - Aligns across most initiatives"}]'),
(@pulse_resp_people_prev, 'coaching_frequency', '[{"valueInteger":3,"valueString":"3 - Quarterly coaching cadence"}]'),
(@pulse_resp_people_prev, 'escalation_clear', '[{"valueBoolean":true}]'),
(@pulse_resp_people_prev, 'priority_support', '[{"valueString":"Extend executive storytelling support to regional leads."}]'),
(@pulse_resp_people_prev, 'org_clarity', '[{"valueInteger":4,"valueString":"4 - Clarity in most areas"}]');

INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES
(@pulse_resp_supply_prev, 'vision_alignment', '[{"valueInteger":3,"valueString":"3 - Alignment requires reminders"}]'),
(@pulse_resp_supply_prev, 'coaching_frequency', '[{"valueInteger":3,"valueString":"3 - Quarterly coaching cadence"}]'),
(@pulse_resp_supply_prev, 'escalation_clear', '[{"valueBoolean":false}]'),
(@pulse_resp_supply_prev, 'priority_support', '[{"valueString":"Need clearer funding guardrails for automation pilots."}]'),
(@pulse_resp_supply_prev, 'org_clarity', '[{"valueInteger":3,"valueString":"3 - Clarity in most areas"}]');

INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES
(@pulse_resp_admin, 'vision_alignment', '[{"valueInteger":5,"valueString":"5 - Always aligns teams"}]'),
(@pulse_resp_admin, 'coaching_frequency', '[{"valueInteger":5,"valueString":"5 - Weekly coaching sessions"}]'),
(@pulse_resp_admin, 'escalation_clear', '[{"valueBoolean":true}]'),
(@pulse_resp_admin, 'priority_support', '[{"valueString":"Share roadmap briefings with donor steering group."}]'),
(@pulse_resp_admin, 'org_clarity', '[{"valueInteger":5,"valueString":"5 - Vision is crystal clear"}]');

-- Training recommendations based on outcomes ------------------------------------
INSERT INTO training_recommendation (questionnaire_response_id, course_id, recommendation_reason)
SELECT qr.id,
       cc.id,
       CONCAT('Score ', qr.score, ' indicates value in ', cc.title)
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
JOIN course_catalogue cc ON cc.code = CASE
    WHEN u.work_function = 'finance' THEN 'EPSA-FIN-410'
    WHEN u.work_function = 'quantification' THEN 'EPSA-OPS-375'
    WHEN u.work_function = 'hrm' THEN 'EPSA-HRM-215'
    WHEN u.work_function = 'ict' THEN 'EPSA-ICT-320'
    WHEN u.work_function = 'general_service' THEN 'EPSA-OPS-375'
    WHEN u.work_function = 'security' THEN 'EPSA-SEC-190'
    WHEN u.work_function = 'driver' THEN 'EPSA-DRV-220'
    WHEN u.work_function IN ('pme', 'wim', 'records_documentation') THEN 'EPSA-MEL-360'
    WHEN u.work_function = 'communication' THEN 'EPSA-COM-240'
    ELSE 'EPSA-OPS-375'
END
WHERE qr.questionnaire_id = @demo_qid
  AND qr.status = 'approved'
  AND qr.score IS NOT NULL
  AND qr.score < 90;

-- Analytics report schedules -----------------------------------------------------
INSERT INTO analytics_report_schedule (recipients, frequency, next_run_at, last_run_at, created_by, questionnaire_id, include_details, active)
VALUES
('demo.people.lead@example.com,demo.supply.lead@example.com', 'weekly', DATE_ADD(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), @demo_admin_id, @demo_qid, 1, 1),
('executive.board@example.com', 'monthly', DATE_ADD(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 26 DAY), @demo_admin_id, @demo_qid, 0, 1),
('regional.leads@example.com', 'quarterly', DATE_ADD(NOW(), INTERVAL 35 DAY), DATE_SUB(NOW(), INTERVAL 96 DAY), @demo_admin_id, @demo_qid, 1, 1);

-- Additional logs capturing submission activities --------------------------------
INSERT INTO logs (user_id, action, meta)
SELECT qr.user_id,
       'submit_review',
       CONCAT('{"period":"', pp.label, '","status":"', qr.status, '"}')
FROM questionnaire_response qr
JOIN performance_period pp ON pp.id = qr.performance_period_id
WHERE qr.questionnaire_id = @demo_qid
  AND pp.label IN ('2024', '2025');

DROP TEMPORARY TABLE IF EXISTS tmp_demo_period_meta;

-- End of realistic demo dataset --------------------------------------------------
