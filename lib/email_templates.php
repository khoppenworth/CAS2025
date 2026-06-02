<?php

declare(strict_types=1);

/**
 * Return the canonical registry describing every email template the application supports.
 *
 * Each registry entry contains the default subject and HTML body along with translation
 * metadata that other parts of the application can use to build user-facing descriptions.
 *
 * @return array<string, array{
 *     defaults: array{subject: string, html: string},
 *     title: array{key: string, fallback: string},
 *     description: array{key: string, fallback: string},
 *     placeholders: array<string, array{key: string, fallback: string}>
 * }>
 */
function email_template_registry(): array
{
    static $registry = null;
    if ($registry !== null) {
        return $registry;
    }

    $registry = [
        'pending_user' => [
            'defaults' => [
                'subject' => 'Approval needed: {{user_display}}',
                'html' => <<<'HTML'
<p>A new single sign-on user requires approval before accessing the HR Assessment portal.</p>
<ul>
  <li>Name: {{user_display}}</li>
  <li>Email: {{user_email}}</li>
  <li>Submitted on: {{submitted_at}}</li>
</ul>
<p><a href="{{pending_accounts_url}}">Review pending accounts</a></p>
HTML,
            ],
            'title' => [
                'key' => 'email_template_pending_user_title',
                'fallback' => 'Pending account notification (to supervisors)',
            ],
            'description' => [
                'key' => 'email_template_pending_user_desc',
                'fallback' => 'Sent to supervisors and admins when a new single sign-on account requires approval.',
            ],
            'placeholders' => [
                'user_display' => [
                    'key' => 'email_template_pending_user_placeholder_display',
                    'fallback' => 'Display name of the pending user',
                ],
                'user_email' => [
                    'key' => 'email_template_pending_user_placeholder_email',
                    'fallback' => 'Email address of the pending user',
                ],
                'submitted_at' => [
                    'key' => 'email_template_pending_user_placeholder_submitted',
                    'fallback' => 'Timestamp when the request was submitted',
                ],
                'pending_accounts_url' => [
                    'key' => 'email_template_pending_user_placeholder_url',
                    'fallback' => 'Link to the user approval page',
                ],
            ],
        ],
        'account_approved' => [
            'defaults' => [
                'subject' => 'Your HR Assessment access has been approved',
                'html' => <<<'HTML'
<p>Hello {{user_name}},</p>
<p>Your supervisor has approved your access to the HR Assessment portal. You can now sign in and complete your assessments.</p>
{{next_assessment_block}}
<p><a href="{{login_url}}">Sign in to the portal</a></p>
<p>Thank you.</p>
HTML,
            ],
            'title' => [
                'key' => 'email_template_account_approved_title',
                'fallback' => 'Account approval notice (to staff)',
            ],
            'description' => [
                'key' => 'email_template_account_approved_desc',
                'fallback' => 'Sent to a staff member after their access request is approved.',
            ],
            'placeholders' => [
                'user_name' => [
                    'key' => 'email_template_account_approved_placeholder_name',
                    'fallback' => 'Name of the recipient',
                ],
                'login_url' => [
                    'key' => 'email_template_account_approved_placeholder_login',
                    'fallback' => 'Sign-in URL for the portal',
                ],
                'next_assessment_block' => [
                    'key' => 'email_template_account_approved_placeholder_next',
                    'fallback' => 'HTML paragraph shown when a next assessment date is available',
                ],
            ],
        ],
        'next_assessment' => [
            'defaults' => [
                'subject' => 'Upcoming assessment scheduled',
                'html' => <<<'HTML'
<p>Hello {{user_name}},</p>
<p>A supervisor has scheduled your next assessment for {{next_assessment_date}}.</p>
<p>Please log in to the HR Assessment portal to prepare and complete any required steps.</p>
<p><a href="{{portal_url}}">Open the HR Assessment portal</a></p>
<p>Thank you.</p>
HTML,
            ],
            'title' => [
                'key' => 'email_template_next_assessment_title',
                'fallback' => 'Upcoming assessment reminder (to staff)',
            ],
            'description' => [
                'key' => 'email_template_next_assessment_desc',
                'fallback' => 'Sent to staff when a supervisor schedules their next assessment.',
            ],
            'placeholders' => [
                'user_name' => [
                    'key' => 'email_template_next_assessment_placeholder_name',
                    'fallback' => 'Name of the recipient',
                ],
                'next_assessment_date' => [
                    'key' => 'email_template_next_assessment_placeholder_date',
                    'fallback' => 'Scheduled assessment date',
                ],
                'portal_url' => [
                    'key' => 'email_template_next_assessment_placeholder_portal',
                    'fallback' => 'Link to the HR Assessment portal',
                ],
            ],
        ],
        'assignment_update' => [
            'defaults' => [
                'subject' => 'Questionnaire assignments updated',
                'html' => <<<'HTML'
<p>Hello {{user_name}},</p>
{{assignment_summary}}
{{next_assessment_block}}
{{assigner_block}}
<p>You can review your questionnaires here: <a href="{{dashboard_url}}">{{dashboard_url}}</a></p>
<p>Thank you.</p>
HTML,
            ],
            'title' => [
                'key' => 'email_template_assignment_update_title',
                'fallback' => 'Questionnaire assignment update',
            ],
            'description' => [
                'key' => 'email_template_assignment_update_desc',
                'fallback' => 'Sent to staff (and optionally the assigning supervisor) when questionnaire assignments change.',
            ],
            'placeholders' => [
                'user_name' => [
                    'key' => 'email_template_assignment_update_placeholder_name',
                    'fallback' => 'Name of the recipient',
                ],
                'assignment_summary' => [
                    'key' => 'email_template_assignment_update_placeholder_summary',
                    'fallback' => 'HTML list describing assigned questionnaires',
                ],
                'next_assessment_block' => [
                    'key' => 'email_template_assignment_update_placeholder_next',
                    'fallback' => 'HTML paragraph shown when a next assessment date is set',
                ],
                'assigner_block' => [
                    'key' => 'email_template_assignment_update_placeholder_assigner',
                    'fallback' => 'HTML paragraph that names the supervisor who made the change',
                ],
                'dashboard_url' => [
                    'key' => 'email_template_assignment_update_placeholder_dashboard',
                    'fallback' => 'Link to the dashboard for reviewing questionnaires',
                ],
            ],
        ],
    ];

    return $registry;
}

/**
 * Return the built-in set of email templates used by the application.
 *
 * @return array<string, array{subject: string, html: string}>
 */
function default_email_templates(): array
{
    $defaults = [];
    foreach (email_template_registry() as $key => $definition) {
        $defaults[$key] = $definition['defaults'];
    }

    return $defaults;
}

/**
 * Normalize stored email template configuration values.
 *
 * @param array<string, array{subject?: string, html?: string}>|string $value
 * @return array<string, array{subject: string, html: string}>
 */
function normalize_email_templates($value): array
{
    $defaults = default_email_templates();
    $decoded = [];

    if (is_string($value) && $value !== '') {
        $maybe = json_decode($value, true);
        if (is_array($maybe)) {
            $decoded = $maybe;
        }
    } elseif (is_array($value)) {
        $decoded = $value;
    }

    $result = $defaults;
    foreach ($decoded as $key => $template) {
        if (!isset($defaults[$key]) || !is_array($template)) {
            continue;
        }

        $subject = isset($template['subject']) ? trim((string)$template['subject']) : '';
        $html = isset($template['html']) ? trim((string)$template['html']) : '';

        $result[$key] = [
            'subject' => $subject !== '' ? $subject : $defaults[$key]['subject'],
            'html' => $html !== '' ? $html : $defaults[$key]['html'],
        ];
    }

    return $result;
}

function encode_email_templates(array $templates): string
{
    $normalized = normalize_email_templates($templates);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '{}' : $json;
}
