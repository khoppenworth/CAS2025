-- CAS questionnaire assignment visibility audit
-- READ ONLY: this script performs SELECT statements only.
--
-- Intended visibility model:
--   published questionnaire
--   AND (department assignment OR team assignment OR direct user assignment)
--   THEN optional questionnaire_work_function restriction.
--
-- questionnaire_work_function must never grant access by itself.

-- 1. Published questionnaires and counts for every assignment layer.
SELECT
    q.id,
    q.title,
    COUNT(DISTINCT qd.department_slug) AS department_count,
    COUNT(DISTINCT qt.team_slug) AS team_count,
    COUNT(DISTINCT qa.staff_id) AS direct_user_count,
    COUNT(DISTINCT qwf.work_function) AS work_role_count
FROM questionnaire q
LEFT JOIN questionnaire_department qd
    ON qd.questionnaire_id = q.id
LEFT JOIN questionnaire_team qt
    ON qt.questionnaire_id = q.id
LEFT JOIN questionnaire_assignment qa
    ON qa.questionnaire_id = q.id
LEFT JOIN questionnaire_work_function qwf
    ON qwf.questionnaire_id = q.id
WHERE q.status = 'published'
GROUP BY q.id, q.title
ORDER BY q.title, q.id;

-- 2. Critical regression set: published questionnaires that have Work Role rows
--    but no department/team/direct assignment. These must be invisible after the fix.
SELECT
    q.id,
    q.title,
    GROUP_CONCAT(DISTINCT qwf.work_function ORDER BY qwf.work_function SEPARATOR ', ') AS work_role_values
FROM questionnaire q
JOIN questionnaire_work_function qwf
    ON qwf.questionnaire_id = q.id
LEFT JOIN questionnaire_department qd
    ON qd.questionnaire_id = q.id
LEFT JOIN questionnaire_team qt
    ON qt.questionnaire_id = q.id
LEFT JOIN questionnaire_assignment qa
    ON qa.questionnaire_id = q.id
WHERE q.status = 'published'
GROUP BY q.id, q.title
HAVING COUNT(DISTINCT qd.department_slug) = 0
   AND COUNT(DISTINCT qt.team_slug) = 0
   AND COUNT(DISTINCT qa.staff_id) = 0
ORDER BY q.title, q.id;

-- 3. Work Role values currently used by questionnaires.
--    matching_department identifies values that may be leftover department-style rows.
SELECT
    qwf.work_function,
    COUNT(*) AS assignment_rows,
    SUM(CASE WHEN q.status = 'published' THEN 1 ELSE 0 END) AS published_rows,
    wc.label AS work_role_label,
    wc.archived_at AS work_role_archived_at,
    d.label AS matching_department,
    d.archived_at AS matching_department_archived_at
FROM questionnaire_work_function qwf
JOIN questionnaire q
    ON q.id = qwf.questionnaire_id
LEFT JOIN work_function_catalog wc
    ON wc.slug = qwf.work_function
LEFT JOIN department_catalog d
    ON d.slug = qwf.work_function
GROUP BY
    qwf.work_function,
    wc.label,
    wc.archived_at,
    d.label,
    d.archived_at
ORDER BY qwf.work_function;

-- 4. Direct user assignments, including questionnaire publication status.
SELECT
    qa.staff_id,
    u.username,
    u.full_name,
    u.department,
    u.cadre,
    u.work_function,
    q.id AS questionnaire_id,
    q.title,
    q.status
FROM questionnaire_assignment qa
JOIN users u
    ON u.id = qa.staff_id
JOIN questionnaire q
    ON q.id = qa.questionnaire_id
ORDER BY u.full_name, u.username, q.title;

-- 5. Users whose department does not resolve to an active department catalog row.
SELECT
    u.id,
    u.username,
    u.full_name,
    u.role,
    u.department,
    u.cadre,
    u.work_function
FROM users u
LEFT JOIN department_catalog d
    ON d.slug = u.department
   AND d.archived_at IS NULL
WHERE u.role IN ('staff', 'admin')
  AND (
      u.department IS NULL
      OR TRIM(u.department) = ''
      OR d.slug IS NULL
  )
ORDER BY u.full_name, u.username;

-- 6. Users whose selected team is missing, inactive, or belongs to another department.
--    Empty team values are not reported here because teams are optional in some deployments.
SELECT
    u.id,
    u.username,
    u.full_name,
    u.role,
    u.department,
    u.cadre,
    t.department_slug AS team_department,
    t.label AS team_label,
    t.archived_at AS team_archived_at
FROM users u
LEFT JOIN department_team_catalog t
    ON t.slug = u.cadre
WHERE u.cadre IS NOT NULL
  AND TRIM(u.cadre) <> ''
  AND (
      t.slug IS NULL
      OR t.archived_at IS NOT NULL
      OR t.department_slug <> u.department
  )
ORDER BY u.full_name, u.username;

-- 7. Users whose Work Role is missing from the active Work Role catalog.
SELECT
    u.id,
    u.username,
    u.full_name,
    u.role,
    u.department,
    u.work_function,
    wc.label AS catalog_label,
    wc.archived_at
FROM users u
LEFT JOIN work_function_catalog wc
    ON wc.slug = u.work_function
WHERE u.role IN ('staff', 'admin', 'supervisor')
  AND (
      u.work_function IS NULL
      OR TRIM(u.work_function) = ''
      OR wc.slug IS NULL
      OR wc.archived_at IS NOT NULL
  )
ORDER BY u.full_name, u.username;

-- 8. Legacy directorate/department differences. Visibility uses users.department.
SELECT
    id,
    username,
    full_name,
    role,
    department,
    directorate,
    cadre,
    work_function
FROM users
WHERE COALESCE(TRIM(department), '') <> COALESCE(TRIM(directorate), '')
ORDER BY full_name, username;
