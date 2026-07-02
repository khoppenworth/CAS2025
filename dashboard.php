<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/profile_completion.php';
auth_required();
refresh_current_user($pdo);
cas_require_profile_completion($pdo);

$redirectTarget = url_for('submit_assessment.php');
header('Location: ' . $redirectTarget);
exit;
