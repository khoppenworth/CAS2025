<?php
require_once __DIR__ . '/config.php';
if (!function_exists('resolve_department_slug')) {
    require_once __DIR__ . '/lib/department_teams.php';
}

auth_required();
refresh_current_user($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$message = '';
$error = '';
$workFunctionOptions = work_function_choices($pdo);
$departmentOptions = department_options($pdo);
$teamCatalog = department_team_catalog($pdo);
$pendingStatus = ($user['account_status'] ?? 'active') === 'pending';
$pendingNotice = $pendingStatus;
$forcePasswordReset = !empty($user['must_reset_password']);
$forceResetNotice = $forcePasswordReset;
if (!empty($_SESSION['pending_notice'])) {
    unset($_SESSION['pending_notice']);
}
if (!empty($_SESSION['force_password_reset_notice'])) {
    $forceResetNotice = true;
    unset($_SESSION['force_password_reset_notice']);
}
if (isset($_GET['force_password_reset'])) {
    $forceResetNotice = true;
}

$phoneCountries = require __DIR__ . '/lib/phone_countries.php';
if (!is_array($phoneCountries) || !$phoneCountries) {
    $phoneCountries = [
        ['code' => '+251', 'label' => 'Ethiopia', 'flag' => "\u{1F1EA}\u{1F1F9}"],
    ];
}

$preferredDefaultCode = '+251';
$defaultPhoneCountry = $preferredDefaultCode;
foreach ($phoneCountries as $country) {
    if ($country['code'] === $preferredDefaultCode) {
        $defaultPhoneCountry = $country['code'];
        break;
    }
}
if (!in_array($defaultPhoneCountry, array_column($phoneCountries, 'code'), true)) {
    $defaultPhoneCountry = $phoneCountries[0]['code'];
}

$splitPhone = static function (?string $phone) use ($phoneCountries, $defaultPhoneCountry): array {
    $phone = trim((string)$phone);
    $digitsOnly = preg_replace('/[^0-9]/', '', $phone);
    foreach ($phoneCountries as $country) {
        if ($phone !== '' && strpos($phone, $country['code']) === 0) {
            $local = trim(substr($phone, strlen($country['code'])));
            $localDigits = preg_replace('/[^0-9]/', '', $local);
            if ($localDigits === '' && $digitsOnly !== '') {
                $localDigits = $digitsOnly;
            }
            return [$country['code'], $localDigits];
        }
    }
    return [$defaultPhoneCountry, $digitsOnly];
};

[$phoneCountryValue, $phoneLocalValue] = $splitPhone($user['phone'] ?? '');
$phoneFlags = [];
foreach ($phoneCountries as $country) {
    $phoneFlags[$country['code']] = $country['flag'];
}
$phoneFlagValue = $phoneFlags[$phoneCountryValue] ?? $phoneCountries[0]['flag'];

$profileRoleOptions = [
    'director_branch_manager' => 'Director / Branch Manager',
    'team_leader_coordinator' => 'Team Leader / Coordinator',
    'officer_level_4' => 'Officer Level 4',
    'officer_level_3' => 'Officer Level 3',
    'officer_level_2' => 'Officer Level 2',
    'officer_level_1' => 'Officer Level 1',
    'other' => 'Other',
];
$jobGradeOptions = [
    'grade_17' => 'Grade 17',
    'grade_16' => 'Grade 16',
    'grade_15' => 'Grade 15',
    'grade_14' => 'Grade 14',
    'grade_13' => 'Grade 13',
    'grade_12' => 'Grade 12',
    'grade_11' => 'Grade 11',
    'grade_10' => 'Grade 10',
    'grade_9' => 'Grade 9',
];
$educationLevelOptions = [
    'diploma' => 'Diploma',
    'bachelors' => "Bachelor's Degree",
    'masters_plus' => "Master's Degree & above",
];
$experienceBandOptions = [
    '0_2' => '0-2 years',
    '2_5' => '2-5 years',
    '5_10' => '5-10 years',
    '10_plus' => 'More than 10 years',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['date_of_birth'] ?? '';
    $phoneCountry = $_POST['phone_country'] ?? $phoneCountryValue;
    $phoneLocalRaw = $_POST['phone_local'] ?? '';
    $phoneCombined = trim($_POST['phone'] ?? '');
    $departmentInput = trim((string)($_POST['department'] ?? ''));
    $department = resolve_department_slug($pdo, $departmentInput);
    $teamInput = trim((string)($_POST['cadre'] ?? ''));
    $cadre = resolve_team_slug($pdo, $teamInput, $department);
    $workFunction = $_POST['work_function'] ?? '';
    $profileRole = trim((string)($_POST['profile_role'] ?? ''));
    $profileRoleOther = trim((string)($_POST['profile_role_other'] ?? ''));
    $jobGrade = trim((string)($_POST['job_grade'] ?? ''));
    $educationLevel = trim((string)($_POST['education_level'] ?? ''));
    $highestDegreeSubject = trim((string)($_POST['highest_degree_subject'] ?? ''));
    $workExperienceProfile = trim((string)($_POST['work_experience_profile'] ?? ''));
    $totalWorkExperienceBand = trim((string)($_POST['total_work_experience_band'] ?? ''));
    $epssWorkExperienceBand = trim((string)($_POST['epss_work_experience_band'] ?? ''));
    $language = $_POST['language'] ?? ($_SESSION['lang'] ?? 'en');
    $password = $_POST['password'] ?? '';

    $validCountryCodes = array_column($phoneCountries, 'code');
    if (!in_array($phoneCountry, $validCountryCodes, true)) {
        $phoneCountry = $defaultPhoneCountry;
    }

    $phoneLocalDigits = preg_replace('/[^0-9]/', '', (string)$phoneLocalRaw);
    if ($phoneLocalDigits === '' && $phoneCombined !== '') {
        [$derivedCountry, $derivedLocal] = $splitPhone($phoneCombined);
        $phoneCountry = $derivedCountry;
        $phoneLocalDigits = $derivedLocal;
    }

    $language = in_array($language, ['en','am','fr'], true) ? $language : 'en';
    $phoneCountryValue = $phoneCountry;
    $phoneLocalValue = $phoneLocalDigits;
    $phoneFlagValue = $phoneFlags[$phoneCountryValue] ?? $phoneCountries[0]['flag'];
    $fullPhone = $phoneCountryValue . $phoneLocalDigits;

    if (
        $fullName === '' ||
        $email === '' ||
        $gender === '' ||
        $dob === '' ||
        $phoneLocalDigits === '' ||
        $department === '' ||
        $cadre === '' ||
        $workFunction === '' ||
        $profileRole === '' ||
        $jobGrade === '' ||
        $educationLevel === '' ||
        $highestDegreeSubject === '' ||
        $workExperienceProfile === '' ||
        $totalWorkExperienceBand === '' ||
        $epssWorkExperienceBand === ''
    ) {
        $error = t($t,'profile_required','Please complete all required fields.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t($t,'invalid_email','Provide a valid email address.');
    } elseif (!in_array($gender, ['female','male','other','prefer_not_say'], true)) {
        $error = t($t,'invalid_gender','Select a valid gender option.');
    } elseif (!isset($departmentOptions[$department])) {
        $error = t($t,'invalid_department','Select a valid department.');
    } elseif ($cadre === '') {
        $error = t($t,'invalid_team_department','Select a valid team in the department.');
    } elseif (!isset($workFunctionOptions[$workFunction])) {
        $error = t($t,'invalid_work_function','Select a valid work function.');
    } elseif (!isset($profileRoleOptions[$profileRole])) {
        $error = t($t,'invalid_profile_role','Select a valid role option.');
    } elseif ($profileRole === 'other' && $profileRoleOther === '') {
        $error = t($t,'invalid_profile_role_other','Please specify your role when selecting Other.');
    } elseif (!isset($jobGradeOptions[$jobGrade])) {
        $error = t($t,'invalid_job_grade','Select a valid job grade.');
    } elseif (!isset($educationLevelOptions[$educationLevel])) {
        $error = t($t,'invalid_education_level','Select a valid education level.');
    } elseif (!isset($experienceBandOptions[$totalWorkExperienceBand])) {
        $error = t($t,'invalid_total_experience_band','Select a valid total work experience option.');
    } elseif (!isset($experienceBandOptions[$epssWorkExperienceBand])) {
        $error = t($t,'invalid_epss_experience_band','Select a valid EPSS experience option.');
    } elseif (strlen($phoneLocalDigits) < 6 || strlen($phoneLocalDigits) > 12) {
        $error = t($t,'invalid_phone','Enter a valid phone number including the country code.');
    } elseif ($forcePasswordReset && trim((string)$password) === '') {
        $error = t($t,'password_reset_required','Please set a new password before continuing.');
    } elseif ($password !== '' && !password_meets_policy($password)) {
        $error = t($t,'password_policy_invalid','Password must be at least 8 characters and include at least one number or symbol.');
    } else {
        $fields = [
            'full_name' => $fullName,
            'email' => $email,
            'gender' => $gender,
            'date_of_birth' => $dob,
            'phone' => $fullPhone,
            'department' => $department,
            'cadre' => $cadre,
            'work_function' => $workFunction,
            'profile_role' => $profileRole,
            'profile_role_other' => ($profileRole === 'other' ? $profileRoleOther : null),
            'job_grade' => $jobGrade,
            'education_level' => $educationLevel,
            'highest_degree_subject' => $highestDegreeSubject,
            'work_experience_profile' => $workExperienceProfile,
            'total_work_experience_band' => $totalWorkExperienceBand,
            'epss_work_experience_band' => $epssWorkExperienceBand,
            'language' => $language,
            'profile_completed' => 1,
        ];
        $params = array_values($fields);
        $set = implode(', ', array_map(static function ($key) { return "$key=?"; }, array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE users SET $set WHERE id=?");
        $params[] = $user['id'];
        $stmt->execute($params);
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password=?, must_reset_password=0 WHERE id=?')->execute([$hash, $user['id']]);
            $forcePasswordReset = false;
        }
        if (!$error) {
            $_SESSION['lang'] = $language;
            $locale = ensure_locale();
            $t = load_lang($locale);
            refresh_current_user($pdo);
            $user = current_user();
            [$phoneCountryValue, $phoneLocalValue] = $splitPhone($user['phone'] ?? '');
            $phoneFlagValue = $phoneFlags[$phoneCountryValue] ?? $phoneCountries[0]['flag'];
            $message = t($t,'profile_updated','Profile updated successfully.');
            $forceResetNotice = !empty($user['must_reset_password']);
        }
    }
}
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'profile','Profile'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>" style="<?=htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section md-profile-section">
  <header class="md-page-header md-profile-header">
    <div class="md-page-header__content">
      <h1 class="md-page-title"><?=t($t,'profile','Profile')?></h1>
      <p class="md-page-subtitle"><?=t($t,'profile_summary','Update your profile details and settings.')?></p>
    </div>
  </header>

  <?php if ($message): ?><div class="md-alert success"><?=htmlspecialchars($message, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <?php if ($error): ?><div class="md-alert error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <?php if ($pendingNotice): ?>
    <div class="md-alert warning">
      <?=htmlspecialchars(t($t, 'pending_account_notice', 'Your account is pending supervisor approval. You can update your profile while you wait.'), ENT_QUOTES, 'UTF-8')?>
    </div>
  <?php endif; ?>
  <?php if ($forceResetNotice): ?>
    <div class="md-alert warning">
      <?=htmlspecialchars(t($t, 'force_password_reset_notice', 'For security, you must set a new password before continuing.'), ENT_QUOTES, 'UTF-8')?>
    </div>
  <?php endif; ?>

  <div class="md-required-popup" data-required-popup hidden>
    <div class="md-required-popup__backdrop" data-required-popup-close></div>
    <div class="md-required-popup__dialog" role="dialog" aria-modal="true" aria-labelledby="required-popup-title">
      <div class="md-required-popup__header">
        <div class="md-required-popup__title" id="required-popup-title">
          <?=t($t,'required_fields_title','Required fields missing')?>
        </div>
        <button type="button" class="md-required-popup__close" data-required-popup-close aria-label="<?=htmlspecialchars(t($t,'close','Close'), ENT_QUOTES, 'UTF-8')?>">×</button>
      </div>
      <p class="md-required-popup__body">
        <?=t($t,'required_fields_body','Please complete all mandatory fields marked in red before saving your profile.')?>
      </p>
    </div>
  </div>

  <div class="md-required-popup" data-password-popup hidden>
    <div class="md-required-popup__backdrop" data-password-popup-close></div>
    <div class="md-required-popup__dialog" role="dialog" aria-modal="true" aria-labelledby="password-popup-title">
      <div class="md-required-popup__header">
        <div class="md-required-popup__title" id="password-popup-title">
          <?=t($t,'password_policy_title','Password requirements not met')?>
        </div>
        <button type="button" class="md-required-popup__close" data-password-popup-close aria-label="<?=htmlspecialchars(t($t,'close','Close'), ENT_QUOTES, 'UTF-8')?>">×</button>
      </div>
      <p class="md-required-popup__body">
        <?=t($t,'password_policy_body','Use at least 8 characters and include at least one number or symbol.')?>
      </p>
    </div>
  </div>

  <form method="post" class="md-profile-layout" action="<?=htmlspecialchars(url_for('profile.php'), ENT_QUOTES, 'UTF-8')?>" data-profile-form>
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">

    <article class="md-card md-elev-2 md-profile-card">
      <h2 class="md-card-title"><?=t($t,'profile_information','Profile Information')?></h2>
      <div class="md-form-grid md-profile-fields">
      <label class="md-field md-field--required">
        <span><?=t($t,'full_name','Full Name')?></span>
        <input name="full_name" value="<?=htmlspecialchars($user['full_name'] ?? '')?>" required>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'email','Email')?></span>
        <input name="email" type="email" value="<?=htmlspecialchars($user['email'] ?? '')?>" required>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'gender','Gender')?></span>
        <select name="gender" required>
          <?php $gval = $user['gender'] ?? ''; ?>
          <option value="" disabled <?= $gval ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <option value="female" <?=$gval==='female'?'selected':''?>><?=t($t,'female','Female')?></option>
          <option value="male" <?=$gval==='male'?'selected':''?>><?=t($t,'male','Male')?></option>
          <option value="other" <?=$gval==='other'?'selected':''?>><?=t($t,'other','Other')?></option>
          <option value="prefer_not_say" <?=$gval==='prefer_not_say'?'selected':''?>><?=t($t,'prefer_not_say','Prefer not to say')?></option>
        </select>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'date_of_birth','Date of Birth')?></span>
        <input type="date" name="date_of_birth" value="<?=htmlspecialchars($user['date_of_birth'] ?? '')?>" required>
      </label>
      <label class="md-field md-field-inline md-field--required">
        <span>
          <?=t($t,'phone','Phone Number')?>
          <?=render_help_icon(t($t,'phone_number_hint','Choose a country code and enter digits only.'))?>
        </span>
        <div class="md-phone-input" data-phone-field>
          <span class="md-phone-flag" data-phone-flag><?=htmlspecialchars($phoneFlagValue, ENT_QUOTES, 'UTF-8')?></span>
          <select class="md-phone-country" name="phone_country" id="phone_country" data-phone-country aria-label="<?=htmlspecialchars(t($t,'phone_country','Country code'), ENT_QUOTES, 'UTF-8')?>">
            <?php foreach ($phoneCountries as $country): ?>
              <option value="<?=htmlspecialchars($country['code'], ENT_QUOTES, 'UTF-8')?>" <?=$phoneCountryValue === $country['code'] ? 'selected' : ''?> data-flag="<?=htmlspecialchars($country['flag'], ENT_QUOTES, 'UTF-8')?>">
                <?=htmlspecialchars($country['flag'], ENT_QUOTES, 'UTF-8')?> <?=htmlspecialchars($country['code'], ENT_QUOTES, 'UTF-8')?> — <?=htmlspecialchars($country['label'], ENT_QUOTES, 'UTF-8')?>
              </option>
            <?php endforeach; ?>
          </select>
          <input class="md-phone-local" type="text" name="phone_local" id="phone_local" data-phone-local inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="12" placeholder="<?=htmlspecialchars(t($t,'phone_number_placeholder','9-digit number'), ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars($phoneLocalValue, ENT_QUOTES, 'UTF-8')?>" aria-label="<?=htmlspecialchars(t($t,'phone','Phone Number'), ENT_QUOTES, 'UTF-8')?>" required>
          <input type="hidden" name="phone" value="<?=htmlspecialchars($phoneCountryValue . $phoneLocalValue, ENT_QUOTES, 'UTF-8')?>" data-phone-full>
        </div>
      </label>
      <?php $currentDepartmentSlug = resolve_department_slug($pdo, (string)($user['department'] ?? '')); ?>
      <?php $currentTeamSlug = resolve_team_slug($pdo, (string)($user['cadre'] ?? ''), $currentDepartmentSlug); ?>
      <?php
        if ($currentDepartmentSlug === '' && $currentTeamSlug !== '' && isset($teamCatalog[$currentTeamSlug])) {
          $currentDepartmentSlug = (string)($teamCatalog[$currentTeamSlug]['department_slug'] ?? '');
          $currentTeamSlug = resolve_team_slug($pdo, (string)($user['cadre'] ?? ''), $currentDepartmentSlug);
        }
      ?>
      <label class="md-field md-field--required">
        <span><?=t($t,'department','Department')?></span>
        <select name="department" required data-department-select>
          <option value="" disabled <?= $currentDepartmentSlug === '' ? 'selected' : '' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($departmentOptions as $departmentSlug => $departmentLabel): ?>
            <option value="<?=htmlspecialchars($departmentSlug, ENT_QUOTES, 'UTF-8')?>" <?=$currentDepartmentSlug===$departmentSlug?'selected':''?>><?=htmlspecialchars($departmentLabel, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'cadre','Team in the Department')?></span>
        <select name="cadre" required data-team-select>
          <option value="" disabled <?= $currentTeamSlug === '' ? 'selected' : '' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($teamCatalog as $teamSlug => $teamRecord): ?>
            <?php if (($teamRecord['archived_at'] ?? null) !== null) { continue; } ?>
            <option value="<?=htmlspecialchars($teamSlug, ENT_QUOTES, 'UTF-8')?>" data-department="<?=htmlspecialchars((string)($teamRecord['department_slug'] ?? ''), ENT_QUOTES, 'UTF-8')?>" <?=$currentTeamSlug===$teamSlug?'selected':''?>><?=htmlspecialchars((string)($teamRecord['label'] ?? $teamSlug), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'work_function','Work Role')?></span>
        <select name="work_function" required>
          <?php $wval = $user['work_function'] ?? ''; ?>
          <option value="" disabled <?= $wval ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($workFunctionOptions as $function => $label): ?>
            <option value="<?=$function?>" <?=$wval===$function?'selected':''?>><?=htmlspecialchars($label ?? $function, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'profile_role_label','Select your role')?></span>
        <?php $profileRoleValue = (string)($user['profile_role'] ?? ''); ?>
        <select name="profile_role" required data-profile-role-select>
          <option value="" disabled <?= $profileRoleValue !== '' ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($profileRoleOptions as $optionValue => $optionLabel): ?>
            <option value="<?=htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8')?>" <?=$profileRoleValue === $optionValue ? 'selected' : ''?>><?=htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field md-field--required" data-profile-role-other-wrapper hidden>
        <span><?=t($t,'profile_role_other_label','Other (please specify)')?></span>
        <input name="profile_role_other" value="<?=htmlspecialchars((string)($user['profile_role_other'] ?? ''), ENT_QUOTES, 'UTF-8')?>" data-profile-role-other-input>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'job_grade_label','Please select your Job Grade in the chosen directorate')?></span>
        <?php $jobGradeValue = (string)($user['job_grade'] ?? ''); ?>
        <select name="job_grade" required>
          <option value="" disabled <?= $jobGradeValue !== '' ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($jobGradeOptions as $optionValue => $optionLabel): ?>
            <option value="<?=htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8')?>" <?=$jobGradeValue === $optionValue ? 'selected' : ''?>><?=htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'education_level_label','Your Education Profile')?></span>
        <?php $educationLevelValue = (string)($user['education_level'] ?? ''); ?>
        <select name="education_level" required>
          <option value="" disabled <?= $educationLevelValue !== '' ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($educationLevelOptions as $optionValue => $optionLabel): ?>
            <option value="<?=htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8')?>" <?=$educationLevelValue === $optionValue ? 'selected' : ''?>><?=htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'highest_degree_subject_label','What is the subject of your highest degree?')?></span>
        <input name="highest_degree_subject" value="<?=htmlspecialchars((string)($user['highest_degree_subject'] ?? ''), ENT_QUOTES, 'UTF-8')?>" required>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'work_experience_profile_label','Your work Experience')?></span>
        <input name="work_experience_profile" value="<?=htmlspecialchars((string)($user['work_experience_profile'] ?? ''), ENT_QUOTES, 'UTF-8')?>" required>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'total_work_experience_band_label','How many years of work experience do you have in total?')?></span>
        <?php $totalExperienceValue = (string)($user['total_work_experience_band'] ?? ''); ?>
        <select name="total_work_experience_band" required>
          <option value="" disabled <?= $totalExperienceValue !== '' ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($experienceBandOptions as $optionValue => $optionLabel): ?>
            <option value="<?=htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8')?>" <?=$totalExperienceValue === $optionValue ? 'selected' : ''?>><?=htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'epss_work_experience_band_label','How long have you been working in EPSS?')?></span>
        <?php $epssExperienceValue = (string)($user['epss_work_experience_band'] ?? ''); ?>
        <select name="epss_work_experience_band" required>
          <option value="" disabled <?= $epssExperienceValue !== '' ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($experienceBandOptions as $optionValue => $optionLabel): ?>
            <option value="<?=htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8')?>" <?=$epssExperienceValue === $optionValue ? 'selected' : ''?>><?=htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      </div>
    </article>

    <article class="md-card md-elev-2 md-profile-card md-profile-card--preferences">
      <h2 class="md-card-title"><?=htmlspecialchars(t($t,'account_tools','Account & Tools'), ENT_QUOTES, 'UTF-8')?></h2>
      <div class="md-form-grid md-profile-fields">
      <label class="md-field">
        <span><?=t($t,'preferred_language','Preferred Language')?></span>
        <?php $lval = $_SESSION['lang'] ?? ($user['language'] ?? 'en'); ?>
        <select name="language">
          <option value="en" <?=$lval==='en'?'selected':''?>>English</option>
          <option value="am" <?=$lval==='am'?'selected':''?>>Amharic</option>
          <option value="fr" <?=$lval==='fr'?'selected':''?>>Français</option>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t,'new_password','New Password (optional)')?></span>
        <input type="password" name="password" minlength="8" data-password-field>
      </label>
      </div>
      <div class="md-form-actions md-profile-actions">
        <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
      </div>
    </article>
  </form>
</section>

<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
document.addEventListener('DOMContentLoaded', () => {
  const departmentSelect = document.querySelector('[data-department-select]');
  const teamSelect = document.querySelector('[data-team-select]');
  const roleSelect = document.querySelector('[data-profile-role-select]');
  const roleOtherWrapper = document.querySelector('[data-profile-role-other-wrapper]');
  const roleOtherInput = document.querySelector('[data-profile-role-other-input]');
  if (!departmentSelect || !teamSelect) return;
  const syncTeams = () => {
    const dep = departmentSelect.value;
    let hasVisibleSelected = false;
    [...teamSelect.options].forEach((opt) => {
      if (!opt.value) return;
      const show = opt.dataset.department === dep;
      opt.hidden = !show;
      if (!show && opt.selected) {
        opt.selected = false;
      }
      if (show && opt.selected) hasVisibleSelected = true;
    });
    if (!hasVisibleSelected) {
      teamSelect.value = '';
    }
  };
  departmentSelect.addEventListener('change', syncTeams);
  syncTeams();

  const syncRoleOtherField = () => {
    if (!roleSelect || !roleOtherWrapper || !roleOtherInput) return;
    const needsOther = roleSelect.value === 'other';
    roleOtherWrapper.hidden = !needsOther;
    roleOtherInput.required = needsOther;
    if (!needsOther) {
      roleOtherInput.value = '';
    }
  };
  if (roleSelect && roleOtherWrapper && roleOtherInput) {
    roleSelect.addEventListener('change', syncRoleOtherField);
    syncRoleOtherField();
  }
});
</script>

<?php include __DIR__.'/templates/footer.php'; ?>
<script type="module" src="<?=asset_url('assets/js/phone-input.js')?>"></script>
<script>
  (function () {
    const form = document.querySelector('[data-profile-form]');
    const popup = document.querySelector('[data-required-popup]');
    const passwordPopup = document.querySelector('[data-password-popup]');
    const passwordField = form ? form.querySelector('[data-password-field]') : null;
    const passwordPolicyRegex = /^(?=.{8,}$)(?=.*[\d\W_]).+$/;
    if (!form || !popup || !passwordPopup || !passwordField) {
      return;
    }

    const closeButtons = popup.querySelectorAll('[data-required-popup-close]');
    closeButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        popup.hidden = true;
      });
    });

    const passwordCloseButtons = passwordPopup.querySelectorAll('[data-password-popup-close]');
    passwordCloseButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        passwordPopup.hidden = true;
      });
    });

    const requiredFields = Array.from(form.querySelectorAll('[required]'));
    const markField = (field, hasError) => {
      const wrapper = field.closest('.md-field');
      if (wrapper) {
        wrapper.classList.toggle('md-field--error', hasError);
      }
    };

    const validateField = (field) => {
      const isSelect = field.tagName === 'SELECT';
      const value = isSelect ? field.value : field.value.trim();
      const hasError = value === '';
      markField(field, hasError);
      return !hasError;
    };

    const validatePassword = () => {
      const value = passwordField.value;
      if (value.trim() === '') {
        markField(passwordField, false);
        return true;
      }
      const valid = passwordPolicyRegex.test(value);
      markField(passwordField, !valid);
      return valid;
    };

    requiredFields.forEach((field) => {
      field.addEventListener('blur', () => {
        validateField(field);
      });
      field.addEventListener('input', () => {
        if (field.value.trim() !== '') {
          markField(field, false);
        }
      });
      field.addEventListener('change', () => {
        validateField(field);
      });
    });

    passwordField.addEventListener('blur', validatePassword);
    passwordField.addEventListener('input', () => {
      if (passwordField.value.trim() === '') {
        markField(passwordField, false);
        passwordPopup.hidden = true;
        return;
      }
      validatePassword();
    });

    form.addEventListener('submit', (event) => {
      let hasError = false;
      requiredFields.forEach((field) => {
        if (!validateField(field)) {
          hasError = true;
        }
      });
      if (hasError) {
        event.preventDefault();
        popup.hidden = false;
        return;
      }

      if (!validatePassword()) {
        event.preventDefault();
        passwordPopup.hidden = false;
      }
    });
  })();
</script>
</body></html>
