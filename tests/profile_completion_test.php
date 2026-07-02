<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('APP_BOOTSTRAPPED', true);
require_once $root . '/config.php';
require_once $root . '/lib/profile_completion.php';

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

if (!cas_profile_is_complete($completeApprovedUser)) {
    throw new RuntimeException('A user with the mandatory profile fields and administrator-assigned Work Role should be complete. Missing fields: ' . implode(', ', cas_profile_missing_required_fields($completeApprovedUser)));
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
    if (cas_profile_is_complete($candidate)) {
        throw new RuntimeException($requiredField . ' should be required for profile completion/workspace access.');
    }
}

$requiredFields = cas_profile_required_fields();
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
if ($styleSource === false || !str_contains($styleSource, '.md-required-marker')) {
    throw new RuntimeException('Required field asterisk CSS must style the explicit marker.');
}
foreach ([
    "\$profileFieldLabel(t(\$t,'full_name','Full Name'), true)",
    "\$profileFieldLabel(t(\$t,'phone','Phone Number'), true)",
    "\$profileFieldLabel(t(\$t,'job_grade_label','Please select your Job Grade in the chosen directorate'), true)",
    "\$profileFieldLabel(t(\$t,'profile_role_other_label','Other (please specify)'), true)",
    'class="md-required-marker"',
] as $requiredMarkerSource) {
    if (!str_contains($profileSource, $requiredMarkerSource)) {
        throw new RuntimeException('Required field marker is missing from profile source: ' . $requiredMarkerSource);
    }
}

$completionSource = file_get_contents($root . '/lib/profile_completion.php');
if ($completionSource === false) {
    throw new RuntimeException('Unable to read profile completion library.');
}
foreach ([
    "if (!function_exists('cas_profile_required_fields'))",
    "if (!function_exists('cas_profile_missing_required_fields'))",
    "if (!function_exists('cas_profile_is_complete'))",
    "if (!function_exists('cas_require_profile_completion'))",
] as $completionGuard) {
    if (!str_contains($completionSource, $completionGuard)) {
        throw new RuntimeException('Profile completion library must include guarded helper: ' . $completionGuard);
    }
}
if (!str_contains($profileSource, "require_once __DIR__ . '/lib/profile_completion.php';")) {
    throw new RuntimeException('profile.php must load the dedicated profile completion library.');
}
if (str_contains($profileSource, 'user_profile_is_complete(')) {
    throw new RuntimeException('profile.php must call CAS-specific completion helpers to avoid stale config.php functions.');
}


foreach ([
    'dashboard.php',
    'submit_assessment.php',
    'my_performance.php',
    'my_performance_download.php',
    'download.php',
    'swagger.php',
    'charts/performance_timeline.php',
] as $workspacePage) {
    $pageSource = file_get_contents($root . '/' . $workspacePage);
    if ($pageSource === false) {
        throw new RuntimeException('Unable to read workspace page: ' . $workspacePage);
    }
    if (!str_contains($pageSource, 'cas_require_profile_completion($pdo);')) {
        throw new RuntimeException($workspacePage . ' must use the CAS-specific profile completion gate.');
    }
    if (preg_match('/(?<!cas_)require_profile_completion\(\$pdo\);/', $pageSource) === 1) {
        throw new RuntimeException($workspacePage . ' must not use the legacy config.php profile completion gate.');
    }
}


$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    full_name TEXT,
    email TEXT,
    gender TEXT,
    phone TEXT,
    department TEXT,
    cadre TEXT,
    profile_role TEXT,
    job_grade TEXT,
    education_level TEXT,
    highest_degree_subject TEXT,
    total_work_experience_band TEXT,
    epss_work_experience_band TEXT,
    work_function TEXT,
    profile_completed INTEGER DEFAULT 0
)');
$insert = $pdo->prepare('INSERT INTO users (
    id,
    full_name,
    email,
    gender,
    phone,
    department,
    cadre,
    profile_role,
    job_grade,
    education_level,
    highest_degree_subject,
    total_work_experience_band,
    epss_work_experience_band,
    work_function,
    profile_completed
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([
    77,
    $completeApprovedUser['full_name'],
    $completeApprovedUser['email'],
    $completeApprovedUser['gender'],
    $completeApprovedUser['phone'],
    $completeApprovedUser['department'],
    $completeApprovedUser['cadre'],
    $completeApprovedUser['profile_role'],
    $completeApprovedUser['job_grade'],
    $completeApprovedUser['education_level'],
    $completeApprovedUser['highest_degree_subject'],
    $completeApprovedUser['total_work_experience_band'],
    $completeApprovedUser['epss_work_experience_band'],
    $completeApprovedUser['work_function'],
    0,
]);
$_SESSION['user'] = ['id' => 77, 'profile_completed' => 0];
$_SERVER['SCRIPT_NAME'] = '/submit_assessment.php';
$_SERVER['PHP_SELF'] = '/submit_assessment.php';
cas_require_profile_completion($pdo);
$completed = (int)$pdo->query('SELECT profile_completed FROM users WHERE id = 77')->fetchColumn();
if ($completed !== 1 || (int)($_SESSION['user']['profile_completed'] ?? 0) !== 1) {
    throw new RuntimeException('A complete approved self-registration user should be marked complete and pass the workspace gate without a profile.php loop.');
}

foreach (['md-profile-next-actions', 'profile_completion_missing_notice', 'profile_workspace_is_complete'] as $removedComplexity) {
    if (str_contains($profileSource, $removedComplexity)) {
        throw new RuntimeException('Profile page should not contain removed complexity: ' . $removedComplexity);
    }
}

echo "Profile completion tests passed.\n";
