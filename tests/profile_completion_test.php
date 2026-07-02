<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('APP_BOOTSTRAPPED', true);
require_once $root . '/config.php';

$approvedSelfRegisteredUser = [
    'full_name' => 'Self Registered User',
    'email' => 'user@example.test',
    'department' => 'operations',
    'cadre' => 'field_team',
    'work_function' => 'finance',
    // Profile demographics are intentionally optional for workspace access.
    'gender' => '',
    'phone' => '',
    'profile_role' => '',
    'job_grade' => '',
    'education_level' => '',
    'highest_degree_subject' => '',
    'total_work_experience_band' => '',
    'epss_work_experience_band' => '',
    'date_of_birth' => '',
    'work_experience_profile' => '',
];

if (!user_profile_is_complete($approvedSelfRegisteredUser)) {
    throw new RuntimeException('Approved self-registered users with directorate, team, and work role should be able to access the workspace. Missing fields: ' . implode(', ', user_profile_missing_required_fields($approvedSelfRegisteredUser)));
}

$missingWorkRole = $approvedSelfRegisteredUser;
$missingWorkRole['work_function'] = '';
if (user_profile_is_complete($missingWorkRole)) {
    throw new RuntimeException('Profile completion should require the administrator-assigned work role needed for questionnaire visibility.');
}

$missingTeam = $approvedSelfRegisteredUser;
$missingTeam['cadre'] = '';
if (user_profile_is_complete($missingTeam)) {
    throw new RuntimeException('Profile completion should require the team needed for questionnaire visibility.');
}

$requiredFields = user_profile_required_fields();
$expectedRequiredFields = ['full_name', 'email', 'department', 'cadre', 'work_function'];
if ($requiredFields !== $expectedRequiredFields) {
    throw new RuntimeException('Unexpected profile completion requirements: ' . json_encode($requiredFields));
}

echo "Profile completion tests passed.\n";
