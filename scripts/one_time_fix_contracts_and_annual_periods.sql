-- One-time remediation script
-- 1) Recover accidentally inactivated Contract Management questionnaire items/sections.
-- 2) Seed annual performance periods and remove unused legacy H1/H2 periods.

START TRANSACTION;

-- Backup current active flags for Contract Management questionnaires before changes.
CREATE TABLE IF NOT EXISTS backup_questionnaire_item_active_contracts_20260318 AS
SELECT
  qi.id,
  qi.questionnaire_id,
  qi.section_id,
  qi.linkId,
  qi.is_active,
  NOW() AS backup_created_at
FROM questionnaire_item qi
JOIN questionnaire q ON q.id = qi.questionnaire_id
WHERE q.title LIKE '%Contract Management%';

CREATE TABLE IF NOT EXISTS backup_questionnaire_section_active_contracts_20260318 AS
SELECT
  qs.id,
  qs.questionnaire_id,
  qs.title,
  qs.is_active,
  NOW() AS backup_created_at
FROM questionnaire_section qs
JOIN questionnaire q ON q.id = qs.questionnaire_id
WHERE q.title LIKE '%Contract Management%';

-- Reactivate Contract Management form content.
UPDATE questionnaire_section qs
JOIN questionnaire q ON q.id = qs.questionnaire_id
SET qs.is_active = 1
WHERE q.title LIKE '%Contract Management%';

UPDATE questionnaire_item qi
JOIN questionnaire q ON q.id = qi.questionnaire_id
SET qi.is_active = 1
WHERE q.title LIKE '%Contract Management%';

-- Seed annual periods (adjust year range as needed).
INSERT INTO performance_period (label, period_start, period_end) VALUES
('2023', '2023-01-01', '2023-12-31'),
('2024', '2024-01-01', '2024-12-31'),
('2025', '2025-01-01', '2025-12-31'),
('2026', '2026-01-01', '2026-12-31'),
('2027', '2027-01-01', '2027-12-31'),
('2028', '2028-01-01', '2028-12-31'),
('2029', '2029-01-01', '2029-12-31')
ON DUPLICATE KEY UPDATE
  period_start = VALUES(period_start),
  period_end = VALUES(period_end);

-- Remove legacy half-year periods only when they are not referenced by any response.
DELETE pp
FROM performance_period pp
LEFT JOIN questionnaire_response qr ON qr.performance_period_id = pp.id
WHERE pp.label REGEXP '^[0-9]{4} H[12]$'
  AND qr.id IS NULL;

COMMIT;
