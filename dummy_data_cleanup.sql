-- dummy_data_cleanup.sql: remove seeded demo dataset records
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
