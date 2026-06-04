<?php
require_once __DIR__ . '/../lib/questionnaire_submission.php';

$post = [
    'item_cas10_q5' => 'B. Absence of audit-ready records',
    'item_safe_code' => 'Safe answer',
    'item_multi_dot' => ['A', 'B'],
];

if (questionnaire_post_item_value($post, 'cas10.q5') !== 'B. Absence of audit-ready records') {
    fwrite(STDERR, "Dotted linkId did not resolve to PHP-normalized POST key.\n");
    exit(1);
}

if (!questionnaire_post_item_exists($post, 'cas10.q5')) {
    fwrite(STDERR, "Dotted linkId existence check failed.\n");
    exit(1);
}

if (questionnaire_post_item_value($post, 'safe_code') !== 'Safe answer') {
    fwrite(STDERR, "Safe linkId lookup failed.\n");
    exit(1);
}

$multi = questionnaire_post_item_value($post, 'multi.dot');
if (!is_array($multi) || $multi !== ['A', 'B']) {
    fwrite(STDERR, "Dotted multi-value linkId lookup failed.\n");
    exit(1);
}

if (questionnaire_submission_normalize_link_id('item_cas10.q5[]') !== 'cas10_q5') {
    fwrite(STDERR, "Condition linkId normalization failed.\n");
    exit(1);
}


if (questionnaire_post_item_form_key('cas10.q5') !== questionnaire_post_item_form_key('cas10_q5')) {
    fwrite(STDERR, "Equivalent dotted/underscored question codes did not expose the same PHP form key.\n");
    exit(1);
}

if (!questionnaire_link_id_is_safe('cas10_q5') || !questionnaire_link_id_is_safe('cas10-q5')) {
    fwrite(STDERR, "Safe question code validation rejected an allowed code.\n");
    exit(1);
}

foreach (['cas10.q5', 'cas10 q5', 'cas10[q5]'] as $unsafe) {
    if (questionnaire_link_id_is_safe($unsafe)) {
        fwrite(STDERR, "Unsafe question code validation accepted {$unsafe}.\n");
        exit(1);
    }
}

echo "Questionnaire submission helper tests passed.\n";
