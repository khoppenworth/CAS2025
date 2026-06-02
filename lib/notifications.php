<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/path.php';
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/date_format.php';

function notification_resolve_template(array $cfg, string $key, array $variables): array
{
    $templates = normalize_email_templates($cfg['email_templates'] ?? []);
    $defaults = default_email_templates();
    $template = $templates[$key] ?? ($defaults[$key] ?? ['subject' => '', 'html' => '']);
    $subjectTemplate = (string)($template['subject'] ?? '');
    $htmlTemplate = (string)($template['html'] ?? '');

    $plainReplacements = [];
    $htmlReplacements = [];
    foreach ($variables as $name => $value) {
        $token = '{{' . $name . '}}';
        if (is_array($value)) {
            $textValue = isset($value['text']) ? (string)$value['text'] : '';
            $htmlValue = isset($value['html']) ? (string)$value['html'] : $textValue;
            $plainReplacements[$token] = $textValue;
            $htmlReplacements[$token] = $htmlValue;
        } else {
            $textValue = (string)$value;
            $plainReplacements[$token] = $textValue;
            $htmlReplacements[$token] = htmlspecialchars($textValue, ENT_QUOTES, 'UTF-8');
        }
    }

    $subject = strtr($subjectTemplate, $plainReplacements);
    $html = strtr($htmlTemplate, $htmlReplacements);
    $text = mail_html_to_text($html);

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}

function notify_supervisors_of_pending_user(PDO $pdo, array $cfg, array $user): void
{
    $recipients = [];
    $stmt = $pdo->query("SELECT email FROM users WHERE email IS NOT NULL AND email <> '' AND role IN ('supervisor','admin') AND account_status='active'");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '') {
                $recipients[] = $email;
            }
        }
    }
    if (!$recipients) {
        return;
    }

    $display = trim((string)($user['full_name'] ?? ''));
    if ($display === '') {
        $display = trim((string)($user['email'] ?? $user['username'] ?? 'New user'));
    }

    $profileUrl = url_for('admin/pending_accounts.php');
    $template = notification_resolve_template($cfg, 'pending_user', [
        'user_display' => $display,
        'user_email' => (string)($user['email'] ?? 'not provided'),
        'submitted_at' => app_format_display_datetime(new DateTimeImmutable('now'), null, $cfg, 'medium', 'short', true),
        'pending_accounts_url' => $profileUrl,
    ]);

    send_notification_email($cfg, $recipients, $template['subject'], [
        'text' => $template['text'],
        'html' => $template['html'],
    ]);
}

function notify_user_account_approved(array $cfg, array $user, ?string $nextAssessmentDate): void
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        return;
    }
    $loginUrl = url_for('login.php');
    $name = trim((string)($user['full_name'] ?? $user['username'] ?? 'team member'));
    $nextAssessmentBlock = '';
    $nextAssessmentText = '';
    if ($nextAssessmentDate) {
        $nextAssessmentDisplay = app_format_display_date($nextAssessmentDate, null, $cfg, 'long');
        $nextAssessmentBlock = '<p>Your next assessment has been scheduled for ' . htmlspecialchars($nextAssessmentDisplay, ENT_QUOTES, 'UTF-8') . '.</p>';
        $nextAssessmentText = 'Your next assessment has been scheduled for: ' . $nextAssessmentDisplay;
    }

    $template = notification_resolve_template($cfg, 'account_approved', [
        'user_name' => $name,
        'login_url' => $loginUrl,
        'next_assessment_block' => [
            'html' => $nextAssessmentBlock,
            'text' => $nextAssessmentText,
        ],
    ]);

    send_notification_email($cfg, [$email], $template['subject'], [
        'text' => $template['text'],
        'html' => $template['html'],
    ]);
}

function notify_user_next_assessment(array $cfg, array $user, string $nextAssessmentDate): void
{
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') {
        return;
    }
    $template = notification_resolve_template($cfg, 'next_assessment', [
        'user_name' => trim((string)($user['full_name'] ?? $user['username'] ?? 'team member')),
        'next_assessment_date' => $nextAssessmentDate,
        'portal_url' => url_for('login.php'),
    ]);

    send_notification_email($cfg, [$email], $template['subject'], [
        'text' => $template['text'],
        'html' => $template['html'],
    ]);
}

function notify_questionnaire_assignment_update(array $cfg, array $staff, array $assignedTitles, ?array $assigner = null): void
{
    $recipients = [];
    $staffEmail = trim((string)($staff['email'] ?? ''));
    if ($staffEmail !== '') {
        $recipients[] = $staffEmail;
    }
    $assignerEmail = '';
    if ($assigner) {
        $assignerEmail = trim((string)($assigner['email'] ?? ''));
        if ($assignerEmail !== '' && strcasecmp($assignerEmail, $staffEmail) !== 0) {
            $recipients[] = $assignerEmail;
        }
    }
    if (!$recipients) {
        return;
    }

    $staffName = trim((string)($staff['full_name'] ?? $staff['username'] ?? 'team member'));
    $assignerName = $assigner ? trim((string)($assigner['full_name'] ?? $assigner['username'] ?? '')) : '';

    if ($assignedTitles) {
        $summaryHtml = '<p>The following questionnaires are now assigned to you:</p><ul>';
        $summaryTextLines = ['The following questionnaires are now assigned to you:'];
        foreach ($assignedTitles as $title) {
            $summaryHtml .= '<li>' . htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') . '</li>';
            $summaryTextLines[] = ' - ' . (string)$title;
        }
        $summaryHtml .= '</ul>';
        $summaryText = implode("\n", $summaryTextLines);
    } else {
        $summaryHtml = '<p>All previously assigned questionnaires have been removed from your profile.</p>';
        $summaryText = 'All previously assigned questionnaires have been removed from your profile.';
    }

    $nextAssessment = trim((string)($staff['next_assessment_date'] ?? ''));
    $nextAssessmentBlock = '';
    $nextAssessmentText = '';
    if ($nextAssessment !== '') {
        $nextAssessmentBlock = '<p>Your next assessment date: ' . htmlspecialchars($nextAssessment, ENT_QUOTES, 'UTF-8') . '.</p>';
        $nextAssessmentText = 'Your next assessment date: ' . $nextAssessment;
    }

    $assignerBlock = '';
    $assignerText = '';
    if ($assignerName !== '') {
        $assignerBlock = '<p>Assignments updated by: ' . htmlspecialchars($assignerName, ENT_QUOTES, 'UTF-8') . '.</p>';
        $assignerText = 'Assignments updated by: ' . $assignerName;
    }

    $template = notification_resolve_template($cfg, 'assignment_update', [
        'user_name' => $staffName !== '' ? $staffName : 'team member',
        'assignment_summary' => [
            'html' => $summaryHtml,
            'text' => $summaryText,
        ],
        'next_assessment_block' => [
            'html' => $nextAssessmentBlock,
            'text' => $nextAssessmentText,
        ],
        'assigner_block' => [
            'html' => $assignerBlock,
            'text' => $assignerText,
        ],
        'dashboard_url' => url_for('dashboard.php'),
    ]);

    send_notification_email($cfg, $recipients, $template['subject'], [
        'text' => $template['text'],
        'html' => $template['html'],
    ]);
}
