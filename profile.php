<?php
require_once __DIR__ . '/config.php';
auth_required();
refresh_current_user($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$message = '';
$error = '';
$workFunctionOptions = work_function_choices($pdo);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = $_POST['date_of_birth'] ?? '';
    $phoneCountry = $_POST['phone_country'] ?? $phoneCountryValue;
    $phoneLocalRaw = $_POST['phone_local'] ?? '';
    $phoneCombined = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $cadre = trim($_POST['cadre'] ?? '');
    $workFunction = $_POST['work_function'] ?? '';
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

    if ($fullName === '' || $email === '' || $gender === '' || $dob === '' || $phoneLocalDigits === '' || $department === '' || $cadre === '' || $workFunction === '') {
        $error = t($t,'profile_required','Please complete all required fields.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t($t,'invalid_email','Provide a valid email address.');
    } elseif (!in_array($gender, ['female','male','other','prefer_not_say'], true)) {
        $error = t($t,'invalid_gender','Select a valid gender option.');
    } elseif (!isset($workFunctionOptions[$workFunction])) {
        $error = t($t,'invalid_work_function','Select a valid work function.');
    } elseif (strlen($phoneLocalDigits) < 6 || strlen($phoneLocalDigits) > 12) {
        $error = t($t,'invalid_phone','Enter a valid phone number including the country code.');
    } elseif ($forcePasswordReset && trim((string)$password) === '') {
        $error = t($t,'password_reset_required','Please set a new password before continuing.');
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
            'language' => $language,
            'profile_completed' => 1,
        ];
        $params = array_values($fields);
        $set = implode(', ', array_map(static function ($key) { return "$key=?"; }, array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE users SET $set WHERE id=?");
        $params[] = $user['id'];
        $stmt->execute($params);
        if ($password !== '') {
            if (strlen($password) < 6) {
                $error = t($t,'password_too_short','Password must be at least 6 characters long.');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password=?, must_reset_password=0 WHERE id=?')->execute([$hash, $user['id']]);
                $forcePasswordReset = false;
            }
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
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'profile_information','Profile Information')?></h2>
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
    <form method="post" class="md-form-grid" action="<?=htmlspecialchars(url_for('profile.php'), ENT_QUOTES, 'UTF-8')?>" data-profile-form>
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
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
      <label class="md-field md-field--required">
        <span><?=t($t,'department','Department')?></span>
        <input name="department" value="<?=htmlspecialchars($user['department'] ?? '')?>" required>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'cadre','Cadre')?></span>
        <input name="cadre" value="<?=htmlspecialchars($user['cadre'] ?? '')?>" required>
      </label>
      <label class="md-field md-field--required">
        <span><?=t($t,'work_function','Work Function / Cadre')?></span>
        <select name="work_function" required>
          <?php $wval = $user['work_function'] ?? ''; ?>
          <option value="" disabled <?= $wval ? '' : 'selected' ?>><?=t($t,'select_option','Select')?></option>
          <?php foreach ($workFunctionOptions as $function => $label): ?>
            <option value="<?=$function?>" <?=$wval===$function?'selected':''?>><?=htmlspecialchars($label ?? $function, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
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
        <input type="password" name="password" minlength="6">
      </label>
      <div class="md-form-actions">
        <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
<script type="module" src="<?=asset_url('assets/js/phone-input.js')?>"></script>
<script>
  (function () {
    const form = document.querySelector('[data-profile-form]');
    const popup = document.querySelector('[data-required-popup]');
    if (!form || !popup) {
      return;
    }

    const closeButtons = popup.querySelectorAll('[data-required-popup-close]');
    closeButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        popup.hidden = true;
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
      }
    });
  })();
</script>
</body></html>
