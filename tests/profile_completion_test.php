<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('APP_BOOTSTRAPPED', true);
require_once $root . '/config.php';

$completeApprovedUser = [
    'full_name' => 'Self Registered User',
    'email' => 'user@example.test',
    'gender' => 'male',
    'phone' => '+251983729293847',
    'department' => 'operations',
    'cadre' => 'field_team',
    'profile_role' => 'officer_level_4',
    'job_grade' => 'grade_10',
    'education_level' => 'masters_plus',
    'highest_degree_subject' => 'Pharmacy',
    'total_work_experience_band' => '10_plus',
    'epss_work_experience_band' => '5_10',
    'work_function' => 'finance',
    // Legacy fields are intentionally absent from completion rules.
    'date_of_birth' => '',
    'work_experience_profile' => '',
];

if (!user_profile_is_complete($completeApprovedUser)) {
    throw new RuntimeException('A user with the mandatory profile fields and administrator-assigned Work Role should be complete. Missing fields: ' . implode(', ', user_profile_missing_required_fields($completeApprovedUser)));
}

foreach ([
    'gender',
    'phone',
    'profile_role',
    'job_grade',
    'education_level',
    'highest_degree_subject',
    'total_work_experience_band',
    'epss_work_experience_band',
    'work_function',
] as $requiredField) {
    $candidate = $completeApprovedUser;
    $candidate[$requiredField] = '';
    if (user_profile_is_complete($candidate)) {
        throw new RuntimeException($requiredField . ' should be required for profile completion/workspace access.');
    }
}

$requiredFields = user_profile_required_fields();
$expectedRequiredFields = [
    'full_name',
    'email',
    'gender',
    'phone',
    'department',
    'cadre',
    'profile_role',
    'job_grade',
    'education_level',
    'highest_degree_subject',
    'total_work_experience_band',
    'epss_work_experience_band',
    'work_function',
];
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
    'full_name',
    'email',
    'gender',
    'phone_local',
    'department',
    'cadre',
    'profile_role',
    'job_grade',
    'education_level',
    'highest_degree_subject',
    'total_work_experience_band',
    'epss_work_experience_band',
] as $mandatoryProfileField) {
    if (!str_contains($requiredFieldBlock, "'" . $mandatoryProfileField . "'")) {
        throw new RuntimeException($mandatoryProfileField . ' must remain mandatory on the profile form.');
    }
}

if (preg_match('/name="phone_local".*?required/s', $profileSource) !== 1) {
    throw new RuntimeException('phone_local must remain mandatory on the profile form.');
}
if (preg_match('/name="highest_degree_subject".*?required/s', $profileSource) !== 1) {
    throw new RuntimeException('highest_degree_subject must remain mandatory on the profile form.');
}

foreach ([
    '/<select\s+name="gender"\s+required>/i',
    '/<select\s+name="profile_role"[^>]*\srequired\b/i',
    '/<select\s+name="job_grade"\s+required>/i',
    '/<select\s+name="education_level"\s+required>/i',
    '/<select\s+name="total_work_experience_band"[^>]*\srequired\b/i',
    '/<select\s+name="epss_work_experience_band"[^>]*\srequired\b/i',
] as $requiredProfilePattern) {
    if (preg_match($requiredProfilePattern, $profileSource) !== 1) {
        throw new RuntimeException('Mandatory profile field is missing required markup matching: ' . $requiredProfilePattern);
    }
}



foreach ([
    "\$profileRoleOtherRequired = \$profileRoleValue === 'other';",
    "\$fieldClass('profile_role_other', \$profileRoleOtherRequired)",
    "profileRoleOtherWrapper.classList.toggle('md-field--required', isOther)",
] as $conditionalOtherRequirement) {
    if (!str_contains($profileSource, $conditionalOtherRequirement)) {
        throw new RuntimeException('Other role field must toggle required asterisk state: ' . $conditionalOtherRequirement);
    }
}

$styleSource = file_get_contents($root . '/assets/css/styles.css');
if ($styleSource === false || !str_contains($styleSource, '.md-profile-fields .md-field--required > span:first-child::after')) {
    throw new RuntimeException('Required field asterisk CSS must include the profile-field specific selector.');
}

foreach ([
    "if (!function_exists('user_profile_required_fields'))",
    "if (!function_exists('user_profile_missing_required_fields'))",
    "if (!function_exists('user_profile_is_complete'))",
] as $fallbackGuard) {
    if (!str_contains($profileSource, $fallbackGuard)) {
        throw new RuntimeException('profile.php must include fallback guard to avoid profile-save fatals when helpers are unavailable: ' . $fallbackGuard);
    }
}

foreach (['md-profile-next-actions', 'profile_completion_missing_notice', 'profile_workspace_is_complete'] as $removedComplexity) {
    if (str_contains($profileSource, $removedComplexity)) {
        throw new RuntimeException('Profile page should not contain removed complexity: ' . $removedComplexity);
    }
}

echo "Profile completion tests passed.\n";
