<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('available_work_functions')) {
    require_once __DIR__ . '/../lib/work_functions.php';
}
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$workFunctionOptions = work_function_choices($pdo);
$defaultWorkFunction = array_key_first($workFunctionOptions) ?? 'general_service';

$defaultAssignmentsByWorkFunction = [];
try {
    $defaultsStmt = $pdo->query("SELECT qd.department_slug, q.id, q.title, q.description FROM questionnaire_department qd JOIN questionnaire q ON q.id = qd.questionnaire_id WHERE q.status='published' ORDER BY q.title ASC");
    if ($defaultsStmt) {
        foreach ($defaultsStmt->fetchAll(PDO::FETCH_ASSOC) as $defaultRow) {
            $wf = trim((string)($defaultRow['department_slug'] ?? ''));
            $qid = isset($defaultRow['id']) ? (int)$defaultRow['id'] : 0;
            if ($wf === '' || $qid <= 0) {
                continue;
            }
            $defaultAssignmentsByWorkFunction[$wf][] = [
                'id' => $qid,
                'title' => trim((string)($defaultRow['title'] ?? '')),
                'description' => trim((string)($defaultRow['description'] ?? '')),
            ];
        }
    }
} catch (PDOException $e) {
    error_log('Admin user questionnaire defaults failed: ' . $e->getMessage());
    $defaultAssignmentsByWorkFunction = [];
}


if ($defaultAssignmentsByWorkFunction === []) {
    try {
        $legacyStmt = $pdo->query("SELECT qwf.work_function, q.id, q.title, q.description FROM questionnaire_work_function qwf JOIN questionnaire q ON q.id = qwf.questionnaire_id WHERE q.status='published' ORDER BY q.title ASC");
        if ($legacyStmt) {
            foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $defaultRow) {
                $dep = resolve_department_slug($pdo, (string)($defaultRow['work_function'] ?? ''));
                $qid = isset($defaultRow['id']) ? (int)$defaultRow['id'] : 0;
                if ($dep === '' || $qid <= 0) {
                    continue;
                }
                $defaultAssignmentsByWorkFunction[$dep][] = [
                    'id' => $qid,
                    'title' => trim((string)($defaultRow['title'] ?? '')),
                    'description' => trim((string)($defaultRow['description'] ?? '')),
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('Admin user legacy defaults failed: ' . $e->getMessage());
    }
}

$msg = $_SESSION['admin_users_flash'] ?? '';
$msgVariant = $msg !== '' ? 'success' : '';
if ($msg !== '') {
    unset($_SESSION['admin_users_flash']);
}
$roleOptions = [
    [
        'role_key' => 'staff',
        'label' => t($t, 'role_staff', 'staff'),
    ],
    [
        'role_key' => 'supervisor',
        'label' => t($t, 'role_supervisor', 'supervisor'),
    ],
    [
        'role_key' => 'admin',
        'label' => t($t, 'role_admin', 'admin'),
    ],
];
$roleMap = [];
foreach ($roleOptions as $roleRow) {
    $key = (string)$roleRow['role_key'];
    $roleMap[$key] = $roleRow;
}
$defaultRoleKey = isset($roleMap['staff']) ? 'staff' : ($roleOptions ? (string)$roleOptions[0]['role_key'] : 'staff');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['create'])) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $workFunction = $_POST['work_function'] ?? $defaultWorkFunction;
        $accountStatus = $_POST['account_status'] ?? 'active';
        $nextAssessment = trim($_POST['next_assessment_date'] ?? '');
        if (!in_array($accountStatus, ['active','pending','disabled'], true)) {
            $accountStatus = 'active';
        }
        if ($nextAssessment !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $nextAssessment);
            if (!$dt) {
                $msg = t($t, 'invalid_date', 'Please provide a valid next assessment date.');
                $msgVariant = 'error';
            } else {
                $nextAssessment = $dt->format('Y-m-d');
            }
        } else {
            $nextAssessment = null;
        }

        if ($msg === '') {
            if ($username === '' || $password === '') {
                $msg = t($t, 'admin_user_required', 'Username and password are required.');
                $msgVariant = 'error';
            } elseif (!isset($roleMap[$role])) {
                $msg = t($t, 'invalid_role', 'Invalid role selection.');
                $msgVariant = 'error';
            } else {
                if (!isset($workFunctionOptions[$workFunction])) { $workFunction = $defaultWorkFunction; }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                try {
                    $stm = $pdo->prepare("INSERT INTO users (username,password,role,full_name,email,work_function,account_status,must_reset_password,next_assessment_date) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stm->execute([
                        $username,
                        $hash,
                        $role,
                        $_POST['full_name'] ?? null,
                        $_POST['email'] ?? null,
                        $workFunction,
                        $accountStatus,
                        1,
                        $nextAssessment
                    ]);
                    if ($accountStatus === 'active' && $nextAssessment) {
                        $newUserId = (int)$pdo->lastInsertId();
                        if ($newUserId > 0) {
                            try {
                                $createdStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                                $createdStmt->execute([$newUserId]);
                                $createdUser = $createdStmt->fetch(PDO::FETCH_ASSOC);
                                if ($createdUser) {
                                    notify_user_next_assessment($cfg, $createdUser, $nextAssessment);
                                }
                            } catch (PDOException $e) {
                                error_log('Admin user create notification fetch failed: ' . $e->getMessage());
                            }
                        }
                    }
                    $msg = t($t, 'user_created', 'User created successfully.');
                    $msgVariant = 'success';
                } catch (PDOException $e) {
                    if ((int)$e->getCode() === 23000) {
                        $msg = t($t, 'username_exists', 'A user with that username already exists.');
                        $msgVariant = 'error';
                    } else {
                        error_log('Admin user create failed: ' . $e->getMessage());
                        $msg = t($t, 'user_create_failed', 'Unable to create user. Please try again.');
                        $msgVariant = 'error';
                    }
                }
            }
        }
    }

    if (isset($_POST['reset'])) {
        $id = (int)($_POST['id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $workFunction = $_POST['work_function'] ?? $defaultWorkFunction;
        $accountStatus = $_POST['account_status'] ?? 'active';
        $nextAssessment = trim($_POST['next_assessment_date'] ?? '');
        if (!in_array($accountStatus, ['active','pending','disabled'], true)) {
            $accountStatus = 'active';
        }
        if ($nextAssessment !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $nextAssessment);
            if (!$dt) {
                $msg = t($t, 'invalid_date', 'Please provide a valid next assessment date.');
                $msgVariant = 'error';
            } else {
                $nextAssessment = $dt->format('Y-m-d');
            }
        } else {
            $nextAssessment = null;
        }

        if ($msg === '') {
            if ($id <= 0) {
                $msg = t($t, 'admin_reset_required', 'User selection is required.');
                $msgVariant = 'error';
            } elseif (!isset($roleMap[$role])) {
                $msg = t($t, 'invalid_role', 'Invalid role selection.');
                $msgVariant = 'error';
            } else {
                if (!isset($workFunctionOptions[$workFunction])) { $workFunction = $defaultWorkFunction; }
                $existingUser = null;
                if ($id > 0) {
                    try {
                        $existingStmt = $pdo->prepare('SELECT id, email, full_name, username, account_status, next_assessment_date FROM users WHERE id = ?');
                        $existingStmt->execute([$id]);
                        $existingUser = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    } catch (PDOException $e) {
                        error_log('Admin user existing fetch failed: ' . $e->getMessage());
                    }
                }
                $fields = ['role = ?', 'work_function = ?', 'account_status = ?', 'next_assessment_date = ?'];
                $params = [$role, $workFunction, $accountStatus, $nextAssessment, $id];
                $profileReset = '';
                if (is_string($newPassword) && trim($newPassword) !== '') {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    array_unshift($params, $hash);
                    $fields = array_merge(['password = ?'], $fields);
                    $fields[] = 'must_reset_password = 1';
                    $profileReset = ', profile_completed = 0';
                }
                $sql = 'UPDATE users SET ' . implode(', ', $fields) . $profileReset . ' WHERE id = ?';
                try {
                    $pdo->beginTransaction();
                    $stm = $pdo->prepare($sql);
                    $stm->execute($params);
                    $pdo->commit();
                    if ($accountStatus === 'active' && $nextAssessment) {
                        try {
                            $updatedStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                            $updatedStmt->execute([$id]);
                            $updatedUser = $updatedStmt->fetch(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $updatedUser = null;
                            error_log('Admin user update notification fetch failed: ' . $e->getMessage());
                        }
                        if (!empty($updatedUser)) {
                            $previousDate = $existingUser['next_assessment_date'] ?? null;
                            $previousStatus = $existingUser['account_status'] ?? null;
                            if ($nextAssessment !== $previousDate || $previousStatus !== 'active') {
                                notify_user_next_assessment($cfg, $updatedUser, $nextAssessment);
                            }
                        }
                    } else {
                        try {
                            $updatedStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                            $updatedStmt->execute([$id]);
                            $updatedUser = $updatedStmt->fetch(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $updatedUser = null;
                            error_log('Admin user update post-fetch failed: ' . $e->getMessage());
                        }
                    }
                    if (!isset($updatedUser)) {
                        $updatedUser = null;
                    }
                    $msg = t($t, 'user_updated', 'User updated successfully.');
                    $msgVariant = 'success';
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Admin user update failed: ' . $e->getMessage());
                    $msg = t($t, 'user_update_failed', 'Unable to update user. Please try again.');
                    $msgVariant = 'error';
                }
            }
        }
    }

    if (isset($_POST['delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stm = $pdo->prepare("UPDATE users SET account_status = 'disabled' WHERE id=?");
            $stm->execute([$id]);
            $_SESSION['admin_users_flash'] = t($t, 'user_deactivated', 'User deactivated successfully.');
            header('Location: ' . url_for('admin/users.php'));
            exit;
        }
    }
}
$rows = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
$roleLabels = [];
foreach ($roleOptions as $option) {
    $label = (string)($option['label'] ?? $option['role_key']);
    $roleLabels[(string)$option['role_key']] = $label;
}
$statusLabels = [
    'active' => t($t,'status_active','Active'),
    'pending' => t($t,'status_pending','Pending approval'),
    'disabled' => t($t,'status_disabled','Disabled'),
];
$userRecords = [];
foreach ($rows as $r) {
    $statusKey = $r['account_status'] ?? 'active';
    $statusLabel = $statusLabels[$statusKey] ?? $statusKey;
    $statusSlug = preg_replace('/[^a-z0-9_-]/i', '', (string)$statusKey);
    if ($statusSlug === '') {
        $statusSlug = 'unknown';
    }
    $statusClass = 'status-' . $statusSlug;
    $fullName = trim((string)($r['full_name'] ?? ''));
    $displayName = $fullName !== '' ? $fullName : $r['username'];
    $nameParts = preg_split('/\s+/u', trim($displayName));
    $initials = '';
    if ($nameParts && $nameParts[0] !== '') {
        $initials .= mb_substr($nameParts[0], 0, 1, 'UTF-8');
    }
    if ($nameParts && count($nameParts) > 1) {
        $initials .= mb_substr($nameParts[count($nameParts) - 1], 0, 1, 'UTF-8');
    }
    if ($initials === '') {
        $initials = mb_substr((string)$r['username'], 0, 2, 'UTF-8');
    }
    $initials = mb_strtoupper(mb_substr($initials, 0, 2, 'UTF-8'), 'UTF-8');
    $email = trim((string)($r['email'] ?? ''));
    $workFunctionKey = (string)($r['work_function'] ?? '');
    $workFunctionLabel = work_function_label($pdo, $workFunctionKey);
    $nextAssessment = $r['next_assessment_date'] ?? '';
    $nextAssessmentDisplay = '—';
    if ($nextAssessment !== '') {
        $ts = strtotime((string)$nextAssessment);
        $nextAssessmentDisplay = $ts ? date('M j, Y', $ts) : $nextAssessment;
    }
    $createdAt = $r['created_at'] ?? '';
    $createdDisplay = '—';
    if ($createdAt !== '') {
        $ts = strtotime((string)$createdAt);
        $createdDisplay = $ts ? date('M j, Y', $ts) : $createdAt;
    }
    $roleKey = $r['role'] ?? 'staff';
    $roleLabel = $roleLabels[$roleKey] ?? $roleKey;
    $userId = (int)$r['id'];
    $departmentKey = resolve_department_slug($pdo, (string)($r['department'] ?? ''));
    $defaultEntries = $defaultAssignmentsByWorkFunction[$departmentKey] ?? [];
    $defaultTitles = [];
    foreach ($defaultEntries as $entry) {
        $title = trim((string)($entry['title'] ?? ''));
        if ($title === '') {
            $title = t($t, 'questionnaire', 'Questionnaire');
        }
        $description = trim((string)($entry['description'] ?? ''));
        if ($description !== '') {
            $defaultTitles[] = $title . ' — ' . $description;
        } else {
            $defaultTitles[] = $title;
        }
    }
    $lastName = '';
    if ($fullName !== '') {
        $lastNameParts = preg_split('/\s+/u', trim($fullName));
        if ($lastNameParts && count($lastNameParts) > 0) {
            $lastName = (string)end($lastNameParts);
        }
    }
    if ($lastName === '') {
        $lastName = (string)$r['username'];
    }
    $searchLast = mb_strtolower($lastName, 'UTF-8');
    $searchFull = mb_strtolower($displayName, 'UTF-8');
    $searchUser = mb_strtolower((string)$r['username'], 'UTF-8');

    $userRecords[] = [
        'raw' => $r,
        'id' => (int)$r['id'],
        'status_key' => $statusKey,
        'status_label' => $statusLabel,
        'status_class' => $statusClass,
        'display_name' => $displayName,
        'username' => (string)$r['username'],
        'initials' => $initials,
        'email' => $email,
        'work_function_label' => $workFunctionLabel,
        'next_assessment' => $nextAssessment,
        'next_assessment_display' => $nextAssessmentDisplay,
        'created_display' => $createdDisplay,
        'created_at' => $createdAt,
        'role_key' => $roleKey,
        'role_label' => $roleLabel,
        'work_function_key' => $workFunctionKey,
        'default_titles' => $defaultTitles,
        'search_last' => $searchLast,
        'search_full' => $searchFull,
        'search_username' => $searchUser,
    ];
}
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head><meta charset="utf-8"><title><?=htmlspecialchars(t($t,'manage_users','Manage Users'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
<style>
  .md-user-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
    margin-bottom: 1rem;
  }
  .md-user-search {
    max-width: 320px;
  }
  .md-user-view-btn.is-active {
    color: #000;
  }
  .md-user-card--hidden {
    display: none !important;
  }
  .md-user-grid[data-has-results="false"]::before {
    content: attr(data-empty-message);
    display: block;
    padding: 1rem;
    color: var(--app-muted);
    font-style: italic;
  }
  .md-user-assignment-defaults {
    margin: 1rem 0;
    padding: 0.75rem;
    border-radius: 8px;
    border: 1px solid var(--app-border, #d0d5dd);
    background: var(--app-surface-alt, rgba(229, 231, 235, 0.5));
    font-size: 0.9rem;
  }
  .md-user-assignment-defaults strong {
    display: block;
    margin-bottom: 0.35rem;
    color: var(--app-text-primary, #1f2937);
  }
  .md-user-assignment-defaults ul {
    margin: 0.5rem 0 0;
    padding-left: 1.1rem;
    columns: 2;
    column-gap: 1.25rem;
    list-style: disc;
  }
  @media (max-width: 720px) {
    .md-user-assignment-defaults ul {
      columns: 1;
    }
  }
  .md-user-assignment-defaults li {
    margin: 0.25rem 0;
  }
  .md-user-assignment-empty {
    margin: 0.35rem 0 0;
    color: var(--app-muted, #475467);
  }
</style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
<?php if ($msg): ?><div class="md-alert <?=$msgVariant === 'error' ? 'error' : 'success'?>"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
<div class="md-card md-elev-2"><h2 class="md-card-title"><?=t($t,'create_user','Create User')?></h2>
<form method="post" class="md-form-grid" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>">
<input type="hidden" name="csrf" value="<?=csrf_token()?>">
<label class="md-field"><span><?=t($t,'username','Username')?></span><input name="username" required></label>
<label class="md-field"><span><?=t($t,'password','Password')?></span><input name="password" type="password" required></label>
  <label class="md-field"><span><?=t($t,'role','Role')?></span>
    <select name="role">
      <?php foreach ($roleOptions as $option): ?>
        <?php $optionKey = (string)$option['role_key']; ?>
        <option value="<?=htmlspecialchars($optionKey, ENT_QUOTES, 'UTF-8')?>" <?=$optionKey===$defaultRoleKey?'selected':''?>><?=htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8')?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="md-field"><span><?=t($t,'account_status','Account Status')?></span>
    <select name="account_status">
      <option value="active"><?=t($t,'status_active','Active')?></option>
      <option value="pending"><?=t($t,'status_pending','Pending approval')?></option>
      <option value="disabled"><?=t($t,'status_disabled','Disabled')?></option>
    </select>
  </label>
<label class="md-field"><span><?=t($t,'full_name','Full Name')?></span><input name="full_name"></label>
<label class="md-field"><span><?=t($t,'email','Email')?></span><input name="email"></label>
<label class="md-field"><span><?=t($t,'work_function','Work Role')?></span>
  <select name="work_function">
    <?php foreach ($workFunctionOptions as $function => $label): ?>
      <option value="<?=$function?>"><?=htmlspecialchars($label ?? $function, ENT_QUOTES, 'UTF-8')?></option>
    <?php endforeach; ?>
  </select>
</label>
<label class="md-field"><span><?=t($t,'next_assessment','Next Assessment Date')?></span><input type="date" name="next_assessment_date"></label>
<button name="create" class="md-button md-primary md-elev-2 md-user-action-button"><?=t($t,'create','Create')?></button>
</form></div>

<div id="questionnaire-assignments"></div>
<div class="md-card md-elev-2"><h2 class="md-card-title"><?=t($t,'manage_users','Manage Users')?></h2>
  <?php if (!$rows): ?>
    <p class="md-empty-state"><?=t($t,'no_users_found','No user accounts were found. Create a new account to get started.')?></p>
  <?php else: ?>
    <div class="md-user-controls">
      <label class="md-field md-user-search">
        <span><?=t($t,'search_last_name','Search by last name')?></span>
        <input type="search" placeholder="<?=htmlspecialchars(t($t,'search_last_name_placeholder','Start typing a last name'), ENT_QUOTES, 'UTF-8')?>" data-user-search>
      </label>
      <div class="md-user-toggle" role="group" aria-label="<?=htmlspecialchars(t($t,'user_view_toggle_label','Change user layout'), ENT_QUOTES, 'UTF-8')?>">
        <button type="button" class="md-button md-outline md-user-view-btn is-active" data-user-view="cards"><?=t($t,'view_tiles','Card view')?></button>
        <button type="button" class="md-button md-outline md-user-view-btn" data-user-view="list"><?=t($t,'view_list','List view')?></button>
      </div>
    </div>
    <div class="md-user-grid" data-user-cards data-has-results="true" data-empty-message="<?=htmlspecialchars(t($t,'no_matching_users','No users match your search.'), ENT_QUOTES, 'UTF-8')?>">
      <?php foreach ($userRecords as $record): ?>
        <?php $r = $record['raw']; ?>
        <article id="user-card-<?=$record['id']?>" class="md-user-card" data-last-name="<?=htmlspecialchars($record['search_last'], ENT_QUOTES, 'UTF-8')?>" data-full-name="<?=htmlspecialchars($record['search_full'], ENT_QUOTES, 'UTF-8')?>" data-username="<?=htmlspecialchars($record['search_username'], ENT_QUOTES, 'UTF-8')?>">
          <header class="md-user-card__header">
            <div class="md-user-avatar" aria-hidden="true"><?=htmlspecialchars($record['initials'], ENT_QUOTES, 'UTF-8')?></div>
            <div class="md-user-card__heading">
              <h3><?=htmlspecialchars($record['display_name'], ENT_QUOTES, 'UTF-8')?></h3>
              <p>@<?=htmlspecialchars($record['username'], ENT_QUOTES, 'UTF-8')?></p>
            </div>
            <span class="md-user-chip <?=$record['status_class']?>"><?=htmlspecialchars($record['status_label'], ENT_QUOTES, 'UTF-8')?></span>
          </header>
          <dl class="md-user-meta">
            <div>
              <dt><?=t($t,'role','Role')?></dt>
              <dd><?=htmlspecialchars($record['role_label'], ENT_QUOTES, 'UTF-8')?></dd>
            </div>
            <div>
              <dt><?=t($t,'work_function','Work Role')?></dt>
              <dd><?=htmlspecialchars($record['work_function_label'] ?? '', ENT_QUOTES, 'UTF-8')?></dd>
            </div>
            <div>
              <dt><?=t($t,'email','Email')?></dt>
              <dd><?= $record['email'] !== '' ? htmlspecialchars($record['email'], ENT_QUOTES, 'UTF-8') : '—' ?></dd>
            </div>
            <div>
              <dt><?=t($t,'next_assessment','Next Assessment')?></dt>
              <dd><?=htmlspecialchars($record['next_assessment_display'], ENT_QUOTES, 'UTF-8')?></dd>
            </div>
            <div>
              <dt><?=t($t,'created','Created')?></dt>
              <dd><?=htmlspecialchars($record['created_display'], ENT_QUOTES, 'UTF-8')?></dd>
            </div>
          </dl>
          <div class="md-user-card__footer">
            <form method="post" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" class="md-user-update-form">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$record['id']?>">
              <div class="md-user-form-grid">
                <label class="md-field md-field--compact">
                  <span><?=t($t,'username','Username')?></span>
                  <input value="<?=htmlspecialchars($record['username'], ENT_QUOTES, 'UTF-8')?>" readonly>
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'new_password_reset','New Password')?></span>
                  <input name="new_password" type="password" autocomplete="new-password" placeholder="<?=htmlspecialchars(t($t,'leave_blank_to_keep','Leave blank to keep current password'), ENT_QUOTES, 'UTF-8')?>">
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'role','Role')?></span>
                  <select name="role">
                    <?php foreach ($roleOptions as $option): ?>
                      <option value="<?=htmlspecialchars($option['role_key'], ENT_QUOTES, 'UTF-8')?>" <?=$record['role_key']===$option['role_key']?'selected':''?>><?=htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8')?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'account_status','Account Status')?></span>
                  <select name="account_status">
                    <option value="active" <?=$record['status_key']==='active'?'selected':''?>><?=t($t,'status_active','Active')?></option>
                    <option value="pending" <?=$record['status_key']==='pending'?'selected':''?>><?=t($t,'status_pending','Pending approval')?></option>
                    <option value="disabled" <?=$record['status_key']==='disabled'?'selected':''?>><?=t($t,'status_disabled','Disabled')?></option>
                  </select>
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'work_function','Work Role')?></span>
                  <select name="work_function">
                    <?php foreach ($workFunctionOptions as $function => $label): ?>
                      <option value="<?=$function?>" <?=$record['work_function_key']===$function?'selected':''?>><?=htmlspecialchars($label ?? $function, ENT_QUOTES, 'UTF-8')?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'next_assessment','Next Assessment Date')?></span>
                  <input type="date" name="next_assessment_date" value="<?=htmlspecialchars($record['next_assessment'], ENT_QUOTES, 'UTF-8')?>">
                </label>
                <div class="md-user-assignment-defaults">
                  <?php if ($record['role_key'] === 'staff'): ?>
                    <strong><?=t($t,'assignment_defaults_hint','These questionnaires are automatically available because of the staff member\'s department. They cannot be removed here.')?></strong>
                    <?php if ($record['default_titles']): ?>
                      <ul>
                        <?php foreach ($record['default_titles'] as $defaultTitle): ?>
                          <li><?=htmlspecialchars($defaultTitle, ENT_QUOTES, 'UTF-8')?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php else: ?>
                      <p class="md-user-assignment-empty"><?=t($t,'assignment_defaults_none','This department does not have default questionnaires yet.')?></p>
                    <?php endif; ?>
                    <p class="md-user-assignment-empty"><?=t($t,'assignment_manage_from_defaults','Update the work function defaults to change which questionnaires appear here.')?></p>
                  <?php else: ?>
                    <strong><?=t($t,'assignment_staff_only','Questionnaires are only assigned to staff accounts. These selections will take effect once the user role is set to staff.')?></strong>
                    <p class="md-user-assignment-empty"><?=t($t,'assignment_manage_from_defaults','Update the work function defaults to change which questionnaires appear here.')?></p>
                  <?php endif; ?>
                </div>
              </div>
              <div class="md-user-form-actions">
                <button name="reset" class="md-button md-elev-1 md-user-action-button"><?=t($t,'apply','Apply')?></button>
              </div>
            </form>
            <form method="post" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" class="md-user-delete-form" data-verify-user="<?=htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8')?>" data-verify-prompt="<?=htmlspecialchars(t($t,'confirm_deactivate_prompt','Type the username to confirm deactivation.'), ENT_QUOTES, 'UTF-8')?>" data-verify-mismatch="<?=htmlspecialchars(t($t,'deactivate_verification_failed','The entered username did not match. No changes were made.'), ENT_QUOTES, 'UTF-8')?>">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$record['id']?>">
              <button name="delete" class="md-button md-danger md-elev-1 md-user-action-button" type="submit"><?=t($t,'deactivate','Deactivate')?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="md-user-list is-hidden" data-user-list data-has-results="true" data-empty-message="<?=htmlspecialchars(t($t,'no_matching_users','No users match your search.'), ENT_QUOTES, 'UTF-8')?>">
      <table class="md-table md-user-table">
        <thead>
          <tr>
            <th><?=t($t,'name','Name')?></th>
            <th><?=t($t,'username','Username')?></th>
            <th><?=t($t,'role','Role')?></th>
            <th><?=t($t,'work_function','Work Role')?></th>
            <th><?=t($t,'status','Status')?></th>
            <th><?=t($t,'next_assessment','Next Assessment Date')?></th>
            <th><?=t($t,'created','Created')?></th>
            <th><?=t($t,'actions','Actions')?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($userRecords as $record): ?>
            <tr data-last-name="<?=htmlspecialchars($record['search_last'], ENT_QUOTES, 'UTF-8')?>" data-full-name="<?=htmlspecialchars($record['search_full'], ENT_QUOTES, 'UTF-8')?>" data-username="<?=htmlspecialchars($record['search_username'], ENT_QUOTES, 'UTF-8')?>">
              <td>
                <div class="md-user-list-name">
                  <span class="md-user-avatar" aria-hidden="true"><?=htmlspecialchars($record['initials'], ENT_QUOTES, 'UTF-8')?></span>
                  <div>
                    <strong><?=htmlspecialchars($record['display_name'], ENT_QUOTES, 'UTF-8')?></strong><br>
                    <span class="md-muted">@<?=htmlspecialchars($record['username'], ENT_QUOTES, 'UTF-8')?></span>
                  </div>
                </div>
              </td>
              <td><?=htmlspecialchars($record['username'], ENT_QUOTES, 'UTF-8')?></td>
              <td><?=htmlspecialchars($record['role_label'], ENT_QUOTES, 'UTF-8')?></td>
              <td><?=htmlspecialchars($record['work_function_label'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
              <td><span class="md-user-chip <?=$record['status_class']?>"><?=htmlspecialchars($record['status_label'], ENT_QUOTES, 'UTF-8')?></span></td>
              <td><?=htmlspecialchars($record['next_assessment_display'], ENT_QUOTES, 'UTF-8')?></td>
              <td><?=htmlspecialchars($record['created_display'], ENT_QUOTES, 'UTF-8')?></td>
              <td>
                <button type="button" class="md-button md-outline md-user-manage md-user-action-button" data-scroll-target="user-card-<?=$record['id']?>"><?=t($t,'manage','Manage')?></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
(function() {
  const forms = document.querySelectorAll('[data-verify-user]');
  forms.forEach((form) => {
    form.addEventListener('submit', (event) => {
      const expected = form.dataset.verifyUser || '';
      const promptMessage = form.dataset.verifyPrompt || 'Type the username to confirm deactivation.';
      const mismatch = form.dataset.verifyMismatch || 'The entered username did not match. No changes were made.';
      const response = window.prompt(promptMessage, '');
      if (response === null) {
        event.preventDefault();
        return;
      }
      if (expected && response.trim() !== expected) {
        window.alert(mismatch);
        event.preventDefault();
      }
    });
  });

  const searchInput = document.querySelector('[data-user-search]');
  const grid = document.querySelector('[data-user-cards]');
  const list = document.querySelector('[data-user-list]');
  const cards = grid ? Array.from(grid.querySelectorAll('.md-user-card')) : [];
  const listRows = list ? Array.from(list.querySelectorAll('tbody tr')) : [];
  const viewButtons = Array.from(document.querySelectorAll('[data-user-view]'));

  function applySearch(term) {
    const value = term.trim().toLowerCase();
    let visibleCards = 0;
    let visibleRows = 0;

    cards.forEach((card) => {
      const last = (card.dataset.lastName || '').toLowerCase();
      const full = (card.dataset.fullName || '').toLowerCase();
      const username = (card.dataset.username || '').toLowerCase();
      const matches = !value || last.includes(value) || full.includes(value) || username.includes(value);
      card.classList.toggle('md-user-card--hidden', !matches);
      if (matches) {
        visibleCards += 1;
      }
    });
    if (grid) {
      grid.dataset.hasResults = visibleCards > 0 ? 'true' : 'false';
    }

    listRows.forEach((row) => {
      const last = (row.dataset.lastName || '').toLowerCase();
      const full = (row.dataset.fullName || '').toLowerCase();
      const username = (row.dataset.username || '').toLowerCase();
      const matches = !value || last.includes(value) || full.includes(value) || username.includes(value);
      row.classList.toggle('is-hidden', !matches);
      if (matches) {
        visibleRows += 1;
      }
    });
    if (list) {
      list.dataset.hasResults = visibleRows > 0 ? 'true' : 'false';
    }
  }

  function setView(mode) {
    viewButtons.forEach((btn) => {
      btn.classList.toggle('is-active', btn.dataset.userView === mode);
    });
    if (grid) {
      grid.classList.toggle('is-hidden', mode !== 'cards');
    }
    if (list) {
      list.classList.toggle('is-hidden', mode !== 'list');
    }
  }

  viewButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const mode = button.dataset.userView || 'cards';
      setView(mode);
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', () => applySearch(searchInput.value));
    applySearch(searchInput.value || '');
  }

  const manageButtons = document.querySelectorAll('.md-user-manage');
  manageButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.scrollTarget;
      if (!targetId) {
        return;
      }
      setView('cards');
      const target = document.getElementById(targetId);
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        target.classList.add('md-user-card--highlight');
        setTimeout(() => target.classList.remove('md-user-card--highlight'), 1200);
      }
    });
  });
})();
</script>
</body></html>
