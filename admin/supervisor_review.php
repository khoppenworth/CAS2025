<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin','supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$role = $user['role'] ?? ($_SESSION['user']['role'] ?? null);
$department = trim((string)($user['department'] ?? ''));
$reviewEnabled = (int)($cfg['review_enabled'] ?? 1) === 1;

if ($reviewEnabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'submitted';
    if ($id > 0 && in_array($status, ['approved', 'rejected'], true)) {
        if ($role === 'supervisor') {
            if ($department === '') {
                $id = 0;
            } else {
                $accessStmt = $pdo->prepare(
                    'SELECT 1 FROM questionnaire_response qr '
                    . 'JOIN users u ON u.id = qr.user_id '
                    . 'WHERE qr.id = ? AND u.department = ? LIMIT 1'
                );
                $accessStmt->execute([$id, $department]);
                if (!$accessStmt->fetchColumn()) {
                    $id = 0;
                }
            }
        }

        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE questionnaire_response SET status=?, reviewed_by=?, reviewed_at=NOW(), review_comment=? WHERE id=?');
            $stmt->execute([$status, $_SESSION['user']['id'], $_POST['review_comment'] ?? null, $id]);
        }
    }
}
$rows = [];
if ($reviewEnabled) {
    if ($role === 'supervisor') {
        if ($department !== '') {
            $query = $pdo->prepare(
                "SELECT qr.*, u.username, q.title "
                . "FROM questionnaire_response qr "
                . "JOIN users u ON u.id = qr.user_id "
                . "JOIN questionnaire q ON q.id = qr.questionnaire_id "
                . "WHERE qr.status = 'submitted' AND u.department = ? "
                . "ORDER BY qr.created_at ASC"
            );
            $query->execute([$department]);
            $rows = $query->fetchAll();
        }
    } else {
        $query = $pdo->query("SELECT qr.*, u.username, q.title FROM questionnaire_response qr JOIN users u ON u.id=qr.user_id JOIN questionnaire q ON q.id=qr.questionnaire_id WHERE qr.status='submitted' ORDER BY qr.created_at ASC");
        $rows = $query ? $query->fetchAll() : [];
    }
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'review_queue','Review Queue'), ENT_QUOTES, 'UTF-8')?></title>
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
  <h2 class="md-card-title"><?=t($t,'pending_submissions','Pending Submissions')?></h2>
  <?php if (!$reviewEnabled): ?>
    <div class="md-alert info">
      <p><?=t($t,'review_disabled_notice','Supervisor reviews are currently disabled in Settings.')?></p>
      <?php if ($role === 'admin'): ?>
        <p><a class="md-link" href="<?=htmlspecialchars(url_for('admin/settings.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t,'review_disabled_settings_link','Update review settings')?></a></p>
      <?php endif; ?>
    </div>
  <?php elseif (!$rows): ?>
    <p class="md-muted"><?=t($t,'no_pending_reviews','There are no submissions awaiting review right now.')?></p>
  <?php else: ?>
  <table class="md-table">
    <thead><tr><th><?=t($t,'id','ID')?></th><th><?=t($t,'user','User')?></th><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'score','Score (%)')?></th><th><?=t($t,'view','View')?></th><th><?=t($t,'action','Action')?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?=$r['id']?></td>
      <td><?=htmlspecialchars($r['username'])?></td>
      <td><?=htmlspecialchars($r['title'])?></td>
      <td><?= is_null($r['score']) ? '-' : (int)$r['score']?></td>
      <td><a class="md-button" href="<?=htmlspecialchars(url_for('admin/view_submission.php?id=' . $r['id']), ENT_QUOTES, 'UTF-8')?>"><?=t($t,'open','Open')?></a></td>
      <td>
        <form method="post" class="md-inline-form" action="<?=htmlspecialchars(url_for('admin/supervisor_review.php'), ENT_QUOTES, 'UTF-8')?>">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="id" value="<?=$r['id']?>">
          <select name="status">
            <option value="approved"><?=t($t,'approve','Approve')?></option>
            <option value="rejected"><?=t($t,'reject','Reject')?></option>
          </select>
          <input name="review_comment" placeholder="<?=htmlspecialchars(t($t,'comment','Comment'), ENT_QUOTES, 'UTF-8')?>">
          <button class="md-button md-elev-1"><?=t($t,'apply','Apply')?></button>
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
