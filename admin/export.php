<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$downloadRequested = isset($_GET['download']) && $_GET['download'] === '1';

if ($downloadRequested) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="responses.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'response_id',
        'username',
        'full_name',
        'email',
        'role',
        'work_function',
        'account_status',
        'questionnaire_id',
        'questionnaire_title',
        'status',
        'score_percent',
        'performance_period',
        'created_at',
        'reviewed_at',
        'reviewer_username',
        'reviewer_full_name',
        'review_comment',
    ]);
    $sql = "SELECT qr.id, u.username, u.full_name, u.email, u.role, u.work_function, u.account_status, qr.questionnaire_id, q.title AS questionnaire_title, qr.status, qr.score, pp.label AS period_label, qr.created_at, qr.reviewed_at, reviewer.username AS reviewer_username, reviewer.full_name AS reviewer_full_name, qr.review_comment FROM questionnaire_response qr JOIN users u ON u.id = qr.user_id LEFT JOIN questionnaire q ON q.id = qr.questionnaire_id LEFT JOIN users reviewer ON reviewer.id = qr.reviewed_by LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id ORDER BY qr.id DESC";
    foreach ($pdo->query($sql) as $row) {
        fputcsv($out, [
            $row['id'],
            $row['username'],
            $row['full_name'],
            $row['email'],
            $row['role'],
            $row['work_function'],
            $row['account_status'],
            $row['questionnaire_id'],
            $row['questionnaire_title'],
            $row['status'],
            $row['score'],
            $row['period_label'],
            $row['created_at'],
            $row['reviewed_at'],
            $row['reviewer_username'],
            $row['reviewer_full_name'],
            $row['review_comment'],
        ]);
    }
    fclose($out);
    exit;
}

$totalResponses = 0;
$latestSubmission = null;
try {
    $totalResponses = (int)$pdo->query('SELECT COUNT(*) FROM questionnaire_response')->fetchColumn();
    $latestSubmission = $pdo->query('SELECT MAX(created_at) FROM questionnaire_response')->fetchColumn();
} catch (PDOException $e) {
    error_log('export.php stats failed: ' . $e->getMessage());
}

$latestSubmissionDisplay = '—';
if ($latestSubmission) {
    try {
        $dt = new DateTime((string)$latestSubmission);
        $latestSubmissionDisplay = $dt->format('M j, Y g:i a');
    } catch (Throwable $ignored) {
        $latestSubmissionDisplay = (string)$latestSubmission;
    }
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t, 'export_data', 'Export Assessment Data'), ENT_QUOTES, 'UTF-8')?></title>
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
    <h2 class="md-card-title"><?=t($t, 'export_assessments_title', 'Export questionnaire responses')?></h2>
    <p><?=t($t, 'export_assessments_intro', 'Download all recorded assessment responses as a CSV file for offline analysis or archival.')?></p>
    <ul class="md-stat-list">
      <li class="md-stat-item"><span class="md-stat-label"><?=t($t, 'responses_available', 'Responses available')?>: </span><span class="md-stat-value"><?=$totalResponses?></span></li>
      <li class="md-stat-item"><span class="md-stat-label"><?=t($t, 'latest_submission', 'Latest submission')?>: </span><span class="md-stat-value"><?=htmlspecialchars($latestSubmissionDisplay, ENT_QUOTES, 'UTF-8')?></span></li>
    </ul>
    <div class="md-form-actions md-form-actions--center md-form-actions--stack">
      <a class="md-button md-primary md-elev-2" href="<?=htmlspecialchars(url_for('admin/export.php?download=1'), ENT_QUOTES, 'UTF-8')?>">
        <?=t($t, 'download_csv', 'Download CSV')?></a>
    </div>
    <p class="md-upgrade-meta"><?=t($t, 'export_notice', 'The export includes reviewer information, status changes, and performance period details for each response.')?></p>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'export_columns', 'Columns included in the export')?></h2>
    <table class="md-table">
      <thead>
        <tr>
          <th><?=t($t, 'column_name', 'Column')?></th>
          <th><?=t($t, 'column_description', 'Description')?></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $columns = [
            ['response_id', t($t, 'col_response_id', 'Unique identifier of the questionnaire response')],
            ['username', t($t, 'col_username', 'Username of the staff member')],
            ['full_name', t($t, 'col_full_name', 'Full name, if provided')],
            ['email', t($t, 'col_email', 'Email address on record')],
            ['role', t($t, 'col_role', 'User role at the time of submission')],
            ['work_function', t($t, 'col_work_function', 'Assigned work role')],
            ['account_status', t($t, 'col_account_status', 'Account status when the export was generated')],
            ['questionnaire_id', t($t, 'col_questionnaire_id', 'Identifier of the questionnaire template')],
            ['questionnaire_title', t($t, 'col_questionnaire_title', 'Title of the questionnaire')],
            ['status', t($t, 'col_status', 'Submission status (draft, submitted, approved, rejected)')],
            ['score_percent', t($t, 'col_score', 'Overall weighted score (percent)')],
            ['performance_period', t($t, 'col_period', 'Performance period label linked to the response')],
            ['created_at', t($t, 'col_created_at', 'Date and time the response was saved')],
            ['reviewed_at', t($t, 'col_reviewed_at', 'Date and time of the latest review, if any')],
            ['reviewer_username', t($t, 'col_reviewer_username', 'Username of the reviewer who provided feedback')],
            ['reviewer_full_name', t($t, 'col_reviewer_full_name', 'Full name of the reviewer, if available')],
            ['review_comment', t($t, 'col_review_comment', 'Supervisor or admin review comment')],
        ];
        foreach ($columns as [$column, $description]): ?>
        <tr>
          <td><code><?=htmlspecialchars($column, ENT_QUOTES, 'UTF-8')?></code></td>
          <td><?=htmlspecialchars($description, ENT_QUOTES, 'UTF-8')?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
