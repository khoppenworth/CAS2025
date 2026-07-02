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


$profileSource = file_get_contents($root . '/profile.php');
if ($profileSource === false) {
    throw new RuntimeException('Unable to read profile.php for profile form checks.');
}
$requiredFieldBlock = '';
if (preg_match('/\$requiredFieldValues\s*=\s*\[(.*?)\];/s', $profileSource, $matches) === 1) {
    $requiredFieldBlock = $matches[1];
}
foreach ([
    'gender',
    'phone_local',
    'job_grade',
    'education_level',
    'highest_degree_subject',
    'total_work_experience_band',
    'epss_work_experience_band',
] as $optionalField) {
    if (str_contains($requiredFieldBlock, "'" . $optionalField . "'")) {
        throw new RuntimeException($optionalField . ' should not be in the server-side required field list for workspace access.');
    }
}
foreach ([
    '/<select\s+name="gender"\s+required>/i',
    '/name="phone_local"[^>]*\srequired(?:\s|>)/i',
    '/<select\s+name="job_grade"\s+required>/i',
    '/<select\s+name="education_level"\s+required>/i',
    '/name="highest_degree_subject"[^>]*\srequired(?:\s|>)/i',
    '/<select\s+name="total_work_experience_band"[^>]*\srequired(?:\s|>)/i',
    '/<select\s+name="epss_work_experience_band"[^>]*\srequired(?:\s|>)/i',
] as $forbiddenRequiredPattern) {
    if (preg_match($forbiddenRequiredPattern, $profileSource) === 1) {
        throw new RuntimeException('Optional profile field still has required markup matching: ' . $forbiddenRequiredPattern);
    }
}


echo "Profile completion tests passed.\n";
