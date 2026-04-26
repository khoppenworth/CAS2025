-- dummy_data_cleanup.sql: remove seeded demo dataset records without deleting questionnaire definitions

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
