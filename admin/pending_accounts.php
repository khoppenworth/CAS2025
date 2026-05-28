<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin','supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['id'] ?? 0);
    $nextAssessment = trim($_POST['next_assessment_date'] ?? '');
    $approvedUser = null;

    if ($userId <= 0) {
        $error = t($t, 'invalid_user_selection', 'Please choose a valid user.');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) {
            $error = t($t, 'user_not_found', 'User not found.');
        } else {
            if ($nextAssessment !== '') {
                $dt = DateTime::createFromFormat('Y-m-d', $nextAssessment);
                if (!$dt) {
                    $error = t($t, 'invalid_date', 'Please provide a valid next assessment date.');
                } else {
                    $nextAssessment = $dt->format('Y-m-d');
                }
            } else {
                $nextAssessment = null;
            }

            if (!$error && $action === 'approve') {
                if (($target['account_status'] ?? 'active') !== 'pending') {
                    $error = t($t, 'user_already_processed', 'This account has already been processed.');
                } else {
                    $update = $pdo->prepare('UPDATE users SET account_status = "active", approved_by = ?, approved_at = NOW(), next_assessment_date = ? WHERE id = ?');
                    $update->execute([$_SESSION['user']['id'], $nextAssessment, $userId]);
                    $message = t($t, 'user_approved', 'Account approved successfully.');
                    $stmt->execute([$userId]);
                    $approvedUser = $stmt->fetch();
                    if ($approvedUser) {
                        notify_user_account_approved($cfg, $approvedUser, $approvedUser['next_assessment_date'] ?? null);
                        if ($nextAssessment) {
                            notify_user_next_assessment($cfg, $approvedUser, $approvedUser['next_assessment_date']);
                        }
                    }
                }
            } elseif (!$error && $action === 'disable') {
                $pdo->prepare('UPDATE users SET account_status = "disabled" WHERE id = ?')->execute([$userId]);
                $message = t($t, 'user_disabled', 'Account disabled.');
            } elseif (!$error && $action === 'set-date') {
                $pdo->prepare('UPDATE users SET next_assessment_date = ? WHERE id = ?')->execute([$nextAssessment, $userId]);
                $message = t($t, 'next_assessment_updated', 'Next assessment date updated.');
                if ($nextAssessment) {
                    $stmt->execute([$userId]);
                    $updated = $stmt->fetch();
                    if ($updated && ($updated['account_status'] ?? '') === 'active') {
                        notify_user_next_assessment($cfg, $updated, $nextAssessment);
                    }
                }
            }
        }
    }
}

$pendingUsers = $pdo->query("SELECT * FROM users WHERE account_status='pending' ORDER BY created_at ASC")->fetchAll();
$activeStaffStmt = $pdo->prepare("SELECT u.*, approver.full_name AS approver_name FROM users u LEFT JOIN users approver ON approver.id = u.approved_by WHERE u.account_status='active' AND u.role='staff' ORDER BY (u.next_assessment_date IS NULL), u.next_assessment_date ASC, u.full_name ASC");
$activeStaffStmt->execute();
$activeStaff = $activeStaffStmt->fetchAll();

$activeStaffDateMeta = [];
foreach ($activeStaff as $staffRow) {
    $id = (int)($staffRow['id'] ?? 0);
    $nextRaw = (string)($staffRow['next_assessment_date'] ?? '');
    $approvedRaw = (string)($staffRow['approved_at'] ?? '');
    $activeStaffDateMeta[$id] = [
        'next_iso' => $nextRaw !== '' ? $nextRaw . 'T00:00:00' : '',
        'approved_iso' => $approvedRaw !== '' ? date(DATE_ATOM, strtotime($approvedRaw)) : '',
    ];
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'pending_accounts','Pending Approvals'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'pending_accounts','Pending Approvals')?></h2>
    <?php if ($message): ?><div class="md-alert success"><?=htmlspecialchars($message, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($error): ?><div class="md-alert error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if (!$pendingUsers): ?>
      <p><?=t($t,'no_pending_accounts','No accounts require approval at this time.')?></p>
    <?php else: ?>
    <table class="md-table">
      <thead>
        <tr>
          <th><?=t($t,'name','Name')?></th>
          <th><?=t($t,'email','Email')?></th>
          <th><?=t($t,'department','Department')?></th>
          <th><?=t($t,'profile_complete','Profile Complete?')?></th>
          <th><?=t($t,'requested_on','Requested On')?></th>
          <th><?=t($t,'next_assessment','Next Assessment')?></th>
          <th><?=t($t,'action','Action')?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pendingUsers as $pending): ?>
        <tr>
          <td><?=htmlspecialchars($pending['full_name'] ?: $pending['username'])?></td>
          <td><?=htmlspecialchars($pending['email'] ?? '')?></td>
          <td><?=htmlspecialchars($pending['department'] ?? '-')?></td>
          <td><?=$pending['profile_completed'] ? t($t,'yes','Yes') : t($t,'no','No')?></td>
          <td><?=htmlspecialchars($pending['created_at'])?></td>
          <td>
            <form method="post" class="md-inline-form" action="<?=htmlspecialchars(url_for('admin/pending_accounts.php'), ENT_QUOTES, 'UTF-8')?>">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$pending['id']?>">
              <input type="date" name="next_assessment_date" value="<?=htmlspecialchars($pending['next_assessment_date'] ?? '')?>" placeholder="YYYY-MM-DD">
              <div class="md-inline-actions">
                <button class="md-button md-primary" type="submit" name="action" value="approve"><?=t($t,'approve','Approve')?></button>
                <button class="md-button" type="submit" name="action" value="set-date"><?=t($t,'save','Save')?></button>
              </div>
            </form>
          </td>
          <td>
            <form method="post" class="md-inline-form" action="<?=htmlspecialchars(url_for('admin/pending_accounts.php'), ENT_QUOTES, 'UTF-8')?>" onsubmit="return confirm('<?=htmlspecialchars(t($t,'confirm_disable','Disable this account?'), ENT_QUOTES, 'UTF-8')?>');">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$pending['id']?>">
              <button class="md-button md-danger" type="submit" name="action" value="disable"><?=t($t,'disable','Disable')?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'scheduled_assessments','Scheduled Assessments')?></h2>
    <?php if (!$activeStaff): ?>
      <p><?=t($t,'no_active_staff','No active staff records available.')?></p>
    <?php else: ?>
    <table class="md-table">
      <thead>
        <tr>
          <th><?=t($t,'name','Name')?></th>
          <th><?=t($t,'email','Email')?></th>
          <th><?=t($t,'next_assessment','Next Assessment')?></th>
          <th><?=t($t,'last_approved','Approved On')?></th>
          <th><?=t($t,'change_next_aseessment_date','Change Next Aseessment Date')?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($activeStaff as $staff): ?>
        <tr>
          <td><?=htmlspecialchars($staff['full_name'] ?: $staff['username'])?></td>
          <td><?=htmlspecialchars($staff['email'] ?? '')?></td>
          <td data-client-date="<?=htmlspecialchars($activeStaffDateMeta[(int)$staff['id']]['next_iso'] ?? '', ENT_QUOTES, 'UTF-8')?>" data-client-date-mode="date"><?=htmlspecialchars($staff['next_assessment_date'] ?? t($t,'not_set','Not set'))?></td>
          <td>
            <?php if (!empty($staff['approved_at'])): ?>
              <span data-client-date="<?=htmlspecialchars($activeStaffDateMeta[(int)$staff['id']]['approved_iso'] ?? '', ENT_QUOTES, 'UTF-8')?>" data-client-date-mode="datetime"><?=htmlspecialchars($staff['approved_at'])?></span><?php if (!empty($staff['approver_name'])): ?> · <?=htmlspecialchars($staff['approver_name'])?><?php endif; ?>
            <?php else: ?>
              <?=t($t,'not_applicable','N/A')?>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" class="md-inline-form" action="<?=htmlspecialchars(url_for('admin/pending_accounts.php'), ENT_QUOTES, 'UTF-8')?>">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$staff['id']?>">
              <input type="date" name="next_assessment_date" value="<?=htmlspecialchars($staff['next_assessment_date'] ?? '')?>">
              <button class="md-button md-primary" type="submit" name="action" value="set-date"><?=t($t,'save','Save')?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
