<?php
declare(strict_types=1);

if (defined('APP_QUESTIONNAIRE_VISIBILITY_LOADED')) {
    return;
}
define('APP_QUESTIONNAIRE_VISIBILITY_LOADED', true);

require_once __DIR__ . '/work_functions.php';
if (!function_exists('resolve_department_slug')) {
    require_once __DIR__ . '/department_teams.php';
}


/**
 * @param list<array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function questionnaire_rows_by_id(array $rows): array
{
    $indexed = [];
    foreach ($rows as $row) {
        $questionnaireId = (int)($row['id'] ?? 0);
        if ($questionnaireId > 0) {
            $indexed[$questionnaireId] = $row;
        }
    }
    return $indexed;
}

/**
 * Return the published questionnaires that the supplied user may access.
 *
 * Department, team, and direct-user assignments are the only sources that grant
 * questionnaire access. questionnaire_work_function rows are role restrictions
 * applied after an assignment has granted scope; they never grant access on
 * their own. Staff and admins may receive department, team, and direct-user
 * assignments. Supervisors are intentionally limited to direct assignments for
 * submission access.
 *
 * @param array<string,mixed> $user
 * @return list<array<string,mixed>>
 */
function available_questionnaires_for_user(PDO $pdo, array $user): array
{
    if (function_exists('ensure_questionnaire_department_schema')) {
        ensure_questionnaire_department_schema($pdo);
    }
    if (function_exists('ensure_questionnaire_team_schema')) {
        ensure_questionnaire_team_schema($pdo);
    }

    $departmentAssigned = [];
    $teamAssigned = [];
    $directAssigned = [];
    $role = (string)($user['role'] ?? '');
    $usesProfileAssignments = in_array($role, ['staff', 'admin'], true);
    $workRole = user_questionnaire_work_role($pdo, $user);

    if ($usesProfileAssignments) {
        $rawDepartment = trim((string)($user['department'] ?? ''));
        $department = function_exists('resolve_department_slug')
            ? resolve_department_slug($pdo, $rawDepartment)
            : $rawDepartment;

        if ($department !== '') {
            try {
                $departmentStmt = $pdo->prepare(
                    "SELECT q.id AS id, q.title AS title FROM questionnaire_department qd " .
                    "JOIN questionnaire q ON q.id = qd.questionnaire_id " .
                    "WHERE qd.department_slug = :department AND q.status='published' ORDER BY q.title"
                );
                $departmentStmt->execute([':department' => $department]);
                foreach ($departmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $questionnaireId = (int)($row['id'] ?? 0);
                    if ($questionnaireId > 0) {
                        $departmentAssigned[$questionnaireId] = $row;
                    }
                }
            } catch (PDOException $e) {
                error_log('available_questionnaires_for_user department lookup failed: ' . $e->getMessage());
            }
        }

        $rawTeam = trim((string)($user['cadre'] ?? ''));
        $team = function_exists('resolve_department_team_slug')
            ? resolve_department_team_slug($pdo, $rawTeam, $department)
            : $rawTeam;

        if ($team !== '') {
            try {
                $teamStmt = $pdo->prepare(
                    "SELECT q.id AS id, q.title AS title FROM questionnaire_team qt " .
                    "JOIN questionnaire q ON q.id = qt.questionnaire_id " .
                    "WHERE qt.team_slug = :team AND q.status='published' ORDER BY q.title"
                );
                $teamStmt->execute([':team' => $team]);
                foreach ($teamStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $questionnaireId = (int)($row['id'] ?? 0);
                    if ($questionnaireId > 0) {
                        $teamAssigned[$questionnaireId] = $row;
                    }
                }
            } catch (PDOException $e) {
                error_log('available_questionnaires_for_user team lookup failed: ' . $e->getMessage());
            }
        }

        $departmentAssigned = questionnaire_rows_by_id(filter_questionnaires_by_work_role($pdo, $departmentAssigned, $workRole));
        $teamAssigned = questionnaire_rows_by_id(filter_questionnaires_by_work_role($pdo, $teamAssigned, $workRole));
    }

    try {
        $directAssignmentStmt = $pdo->prepare(
            "SELECT q.id AS id, q.title AS title FROM questionnaire_assignment qa " .
            "JOIN questionnaire q ON q.id = qa.questionnaire_id " .
            "WHERE qa.staff_id = :staff_id AND q.status='published' ORDER BY q.title"
        );
        $directAssignmentStmt->execute([':staff_id' => (int)($user['id'] ?? 0)]);
        foreach ($directAssignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $questionnaireId = (int)($row['id'] ?? 0);
            if ($questionnaireId > 0) {
                $directAssigned[$questionnaireId] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log('available_questionnaires_for_user direct assignment lookup failed: ' . $e->getMessage());
    }

    $directAssigned = questionnaire_rows_by_id(filter_questionnaires_by_work_role($pdo, $directAssigned, $workRole));

    $assigned = $departmentAssigned + $teamAssigned + $directAssigned;

    return array_values($assigned);
}
