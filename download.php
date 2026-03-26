<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/secure_links.php';

auth_required(['staff', 'supervisor', 'admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
$resolved = secure_links_resolve_token($pdo, $token);
if ($resolved === null) {
    http_response_code(404);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$tokenUserId = isset($resolved['user_id']) ? (int)$resolved['user_id'] : 0;
if ($tokenUserId > 0 && $tokenUserId !== $currentUserId) {
    http_response_code(403);
    exit;
}

$resourceType = trim((string)($resolved['resource_type'] ?? ''));
$payload = $resolved['payload'] ?? [];
$payloadUserId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
if ($payloadUserId > 0 && $payloadUserId !== $currentUserId) {
    http_response_code(403);
    exit;
}

if ((int)($resolved['single_use'] ?? 0) === 1) {
    secure_links_mark_used($pdo, (int)$resolved['id']);
}

$GLOBALS['secure_link_context'] = [
    'resource_type' => $resourceType,
    'token_id' => (int)$resolved['id'],
    'payload' => $payload,
];

if ($resourceType === 'performance_pdf') {
    require __DIR__ . '/my_performance_download.php';
    exit;
}

if ($resourceType === 'admin_export_csv') {
    if (($currentUser['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit;
    }

    $_GET['download'] = '1';
    require __DIR__ . '/admin/export.php';
    exit;
}

if ($resourceType === 'analytics_report_pdf') {
    $role = (string)($currentUser['role'] ?? '');
    if (!in_array($role, ['admin', 'supervisor'], true)) {
        http_response_code(403);
        exit;
    }

    require __DIR__ . '/admin/analytics_download.php';
    exit;
}

http_response_code(404);
exit;
?>
