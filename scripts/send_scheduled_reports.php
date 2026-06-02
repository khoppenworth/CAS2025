<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This utility must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_report.php';

$cfg = get_site_config($pdo);
$now = new DateTimeImmutable('now');

try {
    $stmt = $pdo->prepare('SELECT * FROM analytics_report_schedule WHERE active = 1 AND next_run_at <= ? ORDER BY next_run_at ASC');
    $stmt->execute([$now->format('Y-m-d H:i:s')]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, 'Unable to fetch scheduled reports: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$schedules) {
    fwrite(STDOUT, "No scheduled reports due at this time.\n");
    exit(0);
}

foreach ($schedules as $schedule) {
    $scheduleId = (int)($schedule['id'] ?? 0);
    $recipients = analytics_report_parse_recipients((string)($schedule['recipients'] ?? ''));
    $frequency = (string)($schedule['frequency'] ?? 'weekly');
    $includeDetails = !empty($schedule['include_details']);
    $questionnaireId = isset($schedule['questionnaire_id']) ? (int)$schedule['questionnaire_id'] : null;
    $nextRunSource = isset($schedule['next_run_at']) ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$schedule['next_run_at']) : false;
    $nextRunBase = $nextRunSource instanceof DateTimeImmutable ? $nextRunSource : $now;
    $nextRun = analytics_report_next_run($frequency, $nextRunBase);
    while ($nextRun <= $now) {
        $nextRun = analytics_report_next_run($frequency, $nextRun);
    }

    if (!$recipients) {
        fwrite(STDERR, sprintf("Schedule %d skipped (no valid recipients).\n", $scheduleId));
        $update = $pdo->prepare('UPDATE analytics_report_schedule SET next_run_at = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$nextRun->format('Y-m-d H:i:s'), $scheduleId]);
        continue;
    }

    try {
        $snapshot = analytics_report_snapshot($pdo, $questionnaireId ?: null, $includeDetails);
        $pdfData = analytics_report_render_pdf($snapshot, $cfg);
        /** @var DateTimeImmutable $generatedAt */
        $generatedAt = $snapshot['generated_at'];
        $filename = analytics_report_filename($snapshot['selected_questionnaire_id'], $generatedAt);
        $siteName = trim((string)($cfg['site_name'] ?? 'HR Assessment'));
        $subject = ($siteName !== '' ? $siteName : 'HR Assessment') . ' analytics report - ' . app_format_display_date($generatedAt, $locale ?? null, $cfg);
        $bodyLines = [
            'Hello,',
            '',
            'Attached is the scheduled analytics report generated on ' . app_format_display_datetime($generatedAt, $locale ?? null, $cfg, 'medium', 'short', true) . '.',
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

        $sent = send_notification_email($cfg, $recipients, $subject, implode("\n", $bodyLines), $attachments);
        if ($sent) {
            $update = $pdo->prepare('UPDATE analytics_report_schedule SET last_run_at = ?, next_run_at = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([$now->format('Y-m-d H:i:s'), $nextRun->format('Y-m-d H:i:s'), $scheduleId]);
            fwrite(STDOUT, sprintf("Schedule %d sent to %s.\n", $scheduleId, implode(', ', $recipients)));
        } else {
            fwrite(STDERR, sprintf("Failed to send schedule %d.\n", $scheduleId));
            $update = $pdo->prepare('UPDATE analytics_report_schedule SET next_run_at = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([$nextRun->format('Y-m-d H:i:s'), $scheduleId]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("Error processing schedule %d: %s\n", $scheduleId, $e->getMessage()));
        $update = $pdo->prepare('UPDATE analytics_report_schedule SET next_run_at = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$nextRun->format('Y-m-d H:i:s'), $scheduleId]);
    }
}

exit(0);
