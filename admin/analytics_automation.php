<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_report.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$reportMessage = '';
$reportError = '';

if (isset($_SESSION['analytics_automation_flash']) && is_array($_SESSION['analytics_automation_flash'])) {
    $flash = $_SESSION['analytics_automation_flash'];
    unset($_SESSION['analytics_automation_flash']);
    $reportMessage = (string)($flash['message'] ?? '');
    $reportError = (string)($flash['error'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'send-report') {
        $recipientInput = trim((string)($_POST['report_recipients'] ?? ''));
        $selectedQuestionnaire = (int)($_POST['report_questionnaire_id'] ?? 0);
        $includeDetails = !empty($_POST['report_include_details']);
        $recipients = analytics_report_parse_recipients($recipientInput);

        if (!$recipients) {
            $reportError = t($t, 'analytics_report_recipients_required', 'Please provide at least one valid email address.');
        } else {
            $targetQuestionnaire = $selectedQuestionnaire > 0 ? $selectedQuestionnaire : null;
            try {
                $snapshot = analytics_report_snapshot($pdo, $targetQuestionnaire, $includeDetails);
                $pdfData = analytics_report_render_pdf($snapshot, $cfg);
                /** @var DateTimeImmutable $generatedAt */
                $generatedAt = $snapshot['generated_at'];
                $filename = analytics_report_filename($snapshot['selected_questionnaire_id'], $generatedAt);
                $siteName = trim((string)($cfg['site_name'] ?? 'HR Assessment'));
                $subject = ($siteName !== '' ? $siteName : 'HR Assessment') . ' analytics report - ' . $generatedAt->format('Y-m-d');
                $bodyLines = [
                    'Hello,',
                    '',
                    'Please find the attached analytics report generated on ' . $generatedAt->format('Y-m-d H:i') . '.',
                ];
                if ($includeDetails && !empty($snapshot['selected_questionnaire_title'])) {
                    $bodyLines[] = 'Questionnaire focus: ' . $snapshot['selected_questionnaire_title'];
                }
                $bodyLines[] = '';
                $bodyLines[] = 'Regards,';
                $bodyLines[] = $siteName !== '' ? $siteName : 'HR Assessment';
                $attachments = [[
                    'filename' => $filename,
                    'content' => $pdfData,
                    'content_type' => 'application/pdf',
                ]];

                if (send_notification_email($cfg, $recipients, $subject, implode("\n", $bodyLines), $attachments)) {
                    $reportMessage = t($t, 'analytics_report_sent', 'Analytics report emailed successfully.');
                } else {
                    $reportError = t($t, 'analytics_report_send_failed', 'Unable to send the analytics report email.');
                }
            } catch (Throwable $e) {
                error_log('analytics report send failed: ' . $e->getMessage());
                $reportError = t($t, 'analytics_report_send_failed', 'Unable to send the analytics report email.');
            }
        }
    } elseif ($action === 'create-schedule') {
        $recipientInput = trim((string)($_POST['schedule_recipients'] ?? ''));
        $frequency = strtolower(trim((string)($_POST['schedule_frequency'] ?? 'weekly')));
        if (!in_array($frequency, analytics_report_allowed_frequencies(), true)) {
            $frequency = 'weekly';
        }
        $includeDetails = !empty($_POST['schedule_include_details']);
        $questionnaireSelection = (int)($_POST['schedule_questionnaire_id'] ?? 0);
        $recipients = analytics_report_parse_recipients($recipientInput);

        if (!$recipients) {
            $reportError = t($t, 'analytics_report_recipients_required', 'Please provide at least one valid email address.');
        } else {
            $startInput = trim((string)($_POST['schedule_start_at'] ?? ''));
            $startAt = $startInput === '' ? new DateTimeImmutable('now') : (DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startInput) ?: null);
            if (!$startAt) {
                $reportError = t($t, 'analytics_schedule_start_invalid', 'Please provide a valid start date and time.');
            } else {
                $targetQuestionnaire = $questionnaireSelection > 0 ? $questionnaireSelection : null;
                $recipientsStored = implode(', ', $recipients);
                $createdBy = $_SESSION['user']['id'] ?? null;

                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO analytics_report_schedule (recipients, frequency, next_run_at, last_run_at, created_by, questionnaire_id, include_details, active, created_at, updated_at) '
                        . 'VALUES (?, ?, ?, NULL, ?, ?, ?, 1, NOW(), NOW())'
                    );
                    $stmt->execute([
                        $recipientsStored,
                        $frequency,
                        $startAt->format('Y-m-d H:i:s'),
                        $createdBy,
                        $targetQuestionnaire,
                        $includeDetails ? 1 : 0,
                    ]);
                    $reportMessage = t($t, 'analytics_schedule_created', 'Report schedule created successfully.');
                } catch (PDOException $e) {
                    error_log('analytics schedule create failed: ' . $e->getMessage());
                    $reportError = t($t, 'analytics_schedule_create_failed', 'Unable to save the schedule. Please try again.');
                }
            }
        }
    } elseif ($action === 'toggle-schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            try {
                $rowStmt = $pdo->prepare('SELECT active FROM analytics_report_schedule WHERE id = ?');
                $rowStmt->execute([$scheduleId]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $newStatus = ((int)($row['active'] ?? 0) === 1) ? 0 : 1;
                    $update = $pdo->prepare('UPDATE analytics_report_schedule SET active = ?, updated_at = NOW() WHERE id = ?');
                    $update->execute([$newStatus, $scheduleId]);
                    $reportMessage = $newStatus
                        ? t($t, 'analytics_schedule_enabled', 'Schedule enabled.')
                        : t($t, 'analytics_schedule_paused', 'Schedule paused.');
                } else {
                    $reportError = t($t, 'analytics_schedule_missing', 'Schedule not found.');
                }
            } catch (PDOException $e) {
                error_log('analytics schedule toggle failed: ' . $e->getMessage());
                $reportError = t($t, 'analytics_schedule_update_failed', 'Unable to update the schedule.');
            }
        } else {
            $reportError = t($t, 'analytics_schedule_missing', 'Schedule not found.');
        }
    } elseif ($action === 'delete-schedule') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM analytics_report_schedule WHERE id = ?');
                $stmt->execute([$scheduleId]);
                $reportMessage = t($t, 'analytics_schedule_deleted', 'Schedule removed.');
            } catch (PDOException $e) {
                error_log('analytics schedule delete failed: ' . $e->getMessage());
                $reportError = t($t, 'analytics_schedule_update_failed', 'Unable to update the schedule.');
            }
        } else {
            $reportError = t($t, 'analytics_schedule_missing', 'Schedule not found.');
        }
    }

    $_SESSION['analytics_automation_flash'] = ['message' => $reportMessage, 'error' => $reportError];
    header('Location: ' . url_for('admin/analytics_automation.php'));
    exit;
}

$questionnaires = [];
try {
    $qStmt = $pdo->query('SELECT id, title FROM questionnaire ORDER BY title ASC');
    $questionnaires = $qStmt ? ($qStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (PDOException $e) {
    error_log('analytics automation questionnaire fetch failed: ' . $e->getMessage());
}

try {
    $scheduleStmt = $pdo->query(
        'SELECT s.*, q.title AS questionnaire_title, u.full_name AS creator_name '
        . 'FROM analytics_report_schedule s '
        . 'LEFT JOIN questionnaire q ON q.id = s.questionnaire_id '
        . 'LEFT JOIN users u ON u.id = s.created_by '
        . 'ORDER BY s.next_run_at ASC'
    );
    $reportSchedules = $scheduleStmt ? $scheduleStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    error_log('analytics schedule fetch failed: ' . $e->getMessage());
    $reportSchedules = [];
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t, 'analytics_automation', 'Analytics automation'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_automation', 'Analytics automation')?></h2>
    <p>
      <a class="md-button" href="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'back_to_analytics', 'Back to analytics')?></a>
      <a class="md-button" href="<?=htmlspecialchars(url_for('admin/analytics_looker.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'open_looker_resources', 'Open Looker resources')?></a>
    </p>
  </div>

  <?php if ($reportMessage): ?>
    <div class="md-alert success"><?=htmlspecialchars($reportMessage, ENT_QUOTES, 'UTF-8')?></div>
  <?php endif; ?>
  <?php if ($reportError): ?>
    <div class="md-alert error"><?=htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8')?></div>
  <?php endif; ?>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_email_report', 'Email analytics report')?></h2>
    <form method="post" class="md-form-grid md-report-grid" action="<?=htmlspecialchars(url_for('admin/analytics_automation.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="send-report">
      <label class="md-field">
        <span><?=t($t, 'recipients', 'Recipients')?></span>
        <textarea name="report_recipients" placeholder="name@example.com, other@example.com" required></textarea>
      </label>
      <label class="md-field">
        <span><?=t($t, 'questionnaire', 'Questionnaire')?></span>
        <select name="report_questionnaire_id">
          <option value="0"><?=t($t, 'all_questionnaires', 'All questionnaires')?></option>
          <?php foreach ($questionnaires as $row): ?>
            <option value="<?=$row['id']?>"><?=htmlspecialchars($row['title'] ?? t($t, 'questionnaire', 'Questionnaire'), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-checkbox">
        <input type="checkbox" name="report_include_details" value="1">
        <span><?=t($t, 'include_detailed_breakdown', 'Include detailed questionnaire breakdown')?></span>
      </label>
      <div class="md-inline-actions">
        <button class="md-button md-primary" type="submit"><?=t($t, 'send_report_now', 'Send report')?></button>
      </div>
    </form>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_schedules', 'Scheduled analytics reports')?></h2>
    <form method="post" class="md-form-grid md-report-grid" action="<?=htmlspecialchars(url_for('admin/analytics_automation.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="create-schedule">
      <label class="md-field">
        <span><?=t($t, 'recipients', 'Recipients')?></span>
        <textarea name="schedule_recipients" placeholder="name@example.com, other@example.com" required></textarea>
      </label>
      <label class="md-field">
        <span><?=t($t, 'frequency', 'Frequency')?></span>
        <select name="schedule_frequency">
          <?php foreach (analytics_report_allowed_frequencies() as $freq): ?>
            <option value="<?=$freq?>"><?=htmlspecialchars(t($t, 'frequency_' . $freq, analytics_report_frequency_label($freq)), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-field">
        <span><?=t($t, 'start_time', 'First delivery (local time)')?></span>
        <input type="datetime-local" name="schedule_start_at">
      </label>
      <label class="md-field">
        <span><?=t($t, 'questionnaire', 'Questionnaire')?></span>
        <select name="schedule_questionnaire_id">
          <option value="0"><?=t($t, 'all_questionnaires', 'All questionnaires')?></option>
          <?php foreach ($questionnaires as $row): ?>
            <option value="<?=$row['id']?>"><?=htmlspecialchars($row['title'] ?? t($t, 'questionnaire', 'Questionnaire'), ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="md-checkbox">
        <input type="checkbox" name="schedule_include_details" value="1">
        <span><?=t($t, 'include_detailed_breakdown', 'Include detailed questionnaire breakdown')?></span>
      </label>
      <div class="md-inline-actions">
        <button class="md-button md-primary" type="submit"><?=t($t, 'create_schedule', 'Create schedule')?></button>
      </div>
    </form>

    <?php if ($reportSchedules): ?>
      <div class="md-table-responsive">
        <table class="md-table">
          <thead>
            <tr>
              <th><?=t($t, 'recipients', 'Recipients')?></th>
              <th><?=t($t, 'frequency', 'Frequency')?></th>
              <th><?=t($t, 'questionnaire', 'Questionnaire')?></th>
              <th><?=t($t, 'details', 'Detailed')?></th>
              <th><?=t($t, 'next_run', 'Next run')?></th>
              <th><?=t($t, 'last_run', 'Last run')?></th>
              <th><?=t($t, 'status', 'Status')?></th>
              <th><?=t($t, 'actions', 'Actions')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reportSchedules as $schedule): ?>
              <?php
                $isActive = (int)($schedule['active'] ?? 0) === 1;
                $frequencyKey = (string)($schedule['frequency'] ?? '');
                $frequencyLabel = t($t, 'frequency_' . $frequencyKey, analytics_report_frequency_label($frequencyKey));
                $questionnaireLabel = $schedule['questionnaire_id'] ? ($schedule['questionnaire_title'] ?? t($t, 'questionnaire', 'Questionnaire')) : t($t, 'all_questionnaires', 'All questionnaires');
              ?>
              <tr>
                <td><?=htmlspecialchars($schedule['recipients'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($frequencyLabel, ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($questionnaireLabel, ENT_QUOTES, 'UTF-8')?></td>
                <td><?=!empty($schedule['include_details']) ? t($t, 'yes', 'Yes') : t($t, 'no', 'No')?></td>
                <td><?=htmlspecialchars($schedule['next_run_at'] ?? '-', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($schedule['last_run_at'] ?? '-', ENT_QUOTES, 'UTF-8')?></td>
                <td><?= $isActive ? t($t, 'active', 'Active') : t($t, 'paused', 'Paused') ?></td>
                <td>
                  <div class="md-inline-actions">
                    <form method="post" action="<?=htmlspecialchars(url_for('admin/analytics_automation.php'), ENT_QUOTES, 'UTF-8')?>" class="md-inline-form">
                      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                      <input type="hidden" name="action" value="toggle-schedule">
                      <input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                      <button type="submit" class="md-button md-outline"><?= $isActive ? t($t, 'pause', 'Pause') : t($t, 'enable', 'Enable') ?></button>
                    </form>
                    <form method="post" action="<?=htmlspecialchars(url_for('admin/analytics_automation.php'), ENT_QUOTES, 'UTF-8')?>" class="md-inline-form" onsubmit="return confirm('<?=htmlspecialchars(t($t, 'confirm_delete_schedule', 'Remove this schedule?'), ENT_QUOTES, 'UTF-8')?>');">
                      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                      <input type="hidden" name="action" value="delete-schedule">
                      <input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>">
                      <button type="submit" class="md-button md-danger"><?=t($t, 'delete', 'Delete')?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="md-upgrade-meta"><?=t($t, 'no_schedules_configured', 'No report schedules have been configured yet.')?></p>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
