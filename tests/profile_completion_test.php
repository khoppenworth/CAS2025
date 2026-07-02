<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('APP_BOOTSTRAPPED', true);
require_once $root . '/config.php';

$completeUser = [
    'full_name' => 'Self Registered User',
    'email' => 'user@example.test',
    'gender' => 'prefer_not_say',
    'phone' => '+251911111111',
    'department' => 'operations',
    'cadre' => 'field_team',
    'profile_role' => 'officer_level_2',
    'job_grade' => 'grade_12',
    'education_level' => 'bachelors',
    'highest_degree_subject' => 'Public Health',
    'total_work_experience_band' => '5_10',
    'epss_work_experience_band' => '2_5',
    'date_of_birth' => '',
    'work_experience_profile' => '',
];

if (!user_profile_is_complete($completeUser)) {
    throw new RuntimeException('Current profile form fields should satisfy profile completion without legacy date/work-experience fields.');
}

$missingRole = $completeUser;
$missingRole['profile_role'] = '';
if (user_profile_is_complete($missingRole)) {
    throw new RuntimeException('Profile completion should require the selected profile/work role.');
}

echo "Profile completion tests passed.\n";
