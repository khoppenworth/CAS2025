-- delete_questionnaire_and_responses.sql
--
-- Purpose:
--   Delete one questionnaire (including published questionnaires) and every
--   dependent response/assignment/training record linked to it.
--
-- Usage example (by questionnaire id):
--   SET @target_questionnaire_id := 42;
--   SET @target_questionnaire_title := NULL;
--   SOURCE scripts/delete_questionnaire_and_responses.sql;
--
-- Usage example (by exact title):
--   SET @target_questionnaire_id := NULL;
--   SET @target_questionnaire_title := 'EPSA Leadership Confidence Pulse';
--   SOURCE scripts/delete_questionnaire_and_responses.sql;
--
-- Safety notes:
--   - Provide exactly one selector: @target_questionnaire_id OR
--     @target_questionnaire_title.
--   - The script runs in a transaction and rolls back automatically on errors.

SET @selector_count := IF(@target_questionnaire_id IS NULL, 0, 1)
                     + IF(@target_questionnaire_title IS NULL, 0, 1);

SET @input_error_message := CASE
  WHEN @selector_count = 0 THEN 'Set @target_questionnaire_id or @target_questionnaire_title before running this script.'
  WHEN @selector_count > 1 THEN 'Set only one selector: @target_questionnaire_id OR @target_questionnaire_title.'
  ELSE NULL
END;

SET @guard_sql := IF(
  @input_error_message IS NULL,
  'DO 1',
  CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''', REPLACE(@input_error_message, '''', ''''''), '''')
);
PREPARE stmt FROM @guard_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TEMPORARY TABLE IF EXISTS tmp_questionnaire_delete;
CREATE TEMPORARY TABLE tmp_questionnaire_delete (
  id INT PRIMARY KEY
) ENGINE=Memory;

INSERT INTO tmp_questionnaire_delete (id)
SELECT q.id
FROM questionnaire q
WHERE (@target_questionnaire_id IS NOT NULL AND q.id = @target_questionnaire_id)
   OR (@target_questionnaire_title IS NOT NULL AND q.title = @target_questionnaire_title);

SET @match_count := (SELECT COUNT(*) FROM tmp_questionnaire_delete);

SET @match_error_message := CASE
  WHEN @match_count = 0 THEN 'No questionnaire matched the provided selector.'
  WHEN @match_count > 1 THEN 'Selector matched multiple questionnaires. Refine your selector (prefer id).'
  ELSE NULL
END;

SET @guard_sql := IF(
  @match_error_message IS NULL,
  'DO 1',
  CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''', REPLACE(@match_error_message, '''', ''''''), '''')
);
PREPARE stmt FROM @guard_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

START TRANSACTION;

DELETE tr
FROM training_recommendation tr
JOIN questionnaire_response qr ON qr.id = tr.questionnaire_response_id
WHERE qr.questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

DELETE qri
FROM questionnaire_response_item qri
JOIN questionnaire_response qr ON qr.id = qri.response_id
WHERE qr.questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

DELETE FROM questionnaire_response
WHERE questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

DELETE FROM questionnaire_assignment
WHERE questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

DELETE FROM questionnaire_work_function
WHERE questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

DELETE qio
FROM questionnaire_item_option qio
JOIN questionnaire_item qi ON qi.id = qio.questionnaire_item_id
WHERE qi.questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

DELETE FROM questionnaire_item
WHERE questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

DELETE FROM questionnaire_section
WHERE questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

UPDATE analytics_report_schedule
SET questionnaire_id = NULL
WHERE questionnaire_id IN (SELECT id FROM tmp_questionnaire_delete);

DELETE FROM questionnaire
WHERE id IN (SELECT id FROM tmp_questionnaire_delete);

COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_questionnaire_delete;
