<?php

declare(strict_types=1);

function get_page_help(string $key, array $t): array
{
    $map = [
        'admin.settings' => [
            'title' => t($t, 'help_settings_title', 'Settings overview'),
            'tips' => [
                t($t, 'help_settings_tip_reviews', 'Use the Reviews toggle to pause the supervisor workflow without editing permissions.'),
                t($t, 'help_settings_tip_notifications', 'Configure SMTP before enabling scheduled reports or approval notifications.'),
                t($t, 'help_settings_tip_branding', 'Update branding and language options so the interface matches your organisation.'),
            ],
        ],
        'team.assignments' => [
            'title' => t($t, 'help_assignments_title', 'Assign questionnaires'),
            'tips' => [
                t($t, 'help_assignments_tip_select', 'Use the multi-select list to assign one or many questionnaires at once.'),
                t($t, 'help_assignments_tip_search', 'Type a questionnaire name and press the first letter to jump to it quickly.'),
                t($t, 'help_assignments_tip_save', 'Changes are applied immediately after you save—no extra confirmation step is required.'),
            ],
        ],
        'team.analytics' => [
            'title' => t($t, 'help_analytics_title', 'Analytics insights'),
            'tips' => [
                t($t, 'help_analytics_tip_filters', 'Filter downloads or scheduled emails by questionnaire to focus on key programmes.'),
                t($t, 'help_analytics_tip_scores', 'Hover over the performance bars to compare average scores and response counts.'),
                t($t, 'help_analytics_tip_reports', 'Use the email scheduler to keep stakeholders updated without exporting data manually.'),
            ],
        ],
        'admin.dashboard' => [
            'title' => t($t, 'help_dashboard_title', 'Administration dashboard'),
            'tips' => [
                t($t, 'help_dashboard_tip_backups', 'Download a backup before upgrading or importing large datasets.'),
                t($t, 'help_dashboard_tip_tasks', 'Review system tasks for any failed upgrades or scheduled reports needing attention.'),
            ],
        ],
        'admin.course_mappings' => [
            'title' => t($t, 'help_course_mapping_title', 'Course mapping rules'),
            'tips' => [
                t($t, 'help_course_mapping_tip_bands', 'Create score bands per questionnaire so low, medium, and high results can map to different Moodle courses.'),
                t($t, 'help_course_mapping_tip_links', 'Use full Moodle course URLs so staff can launch learning content directly from their performance view.'),
            ],
        ],
        'workspace.my_performance' => [
            'title' => t($t, 'help_performance_title', 'Your performance workspace'),
            'tips' => [
                t($t, 'help_performance_tip_scores', 'Use the timeline chart to spot trends across performance periods.'),
                t($t, 'help_performance_tip_drafts', 'Resume saved drafts from the banner so no work is lost when you go offline.'),
            ],
        ],
    ];

    $default = [
        'title' => t($t, 'help_default_title', 'Need a hand?'),
        'tips' => [
            t($t, 'help_default_tip_navigation', 'Use the navigation menu to switch between workspace, team, and admin tools.'),
            t($t, 'help_default_tip_support', 'Visit the Settings page to update contact details and support information.'),
        ],
    ];

    $entry = $map[$key] ?? $default;
    $tips = array_values(array_filter($entry['tips'], static fn($tip) => is_string($tip) && trim($tip) !== ''));
    if ($tips === []) {
        $tips = $default['tips'];
    }

    return [
        'title' => is_string($entry['title']) && $entry['title'] !== '' ? $entry['title'] : $default['title'],
        'tips' => $tips,
    ];
}
