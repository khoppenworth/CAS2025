<?php
require_once __DIR__ . '/config.php';
$requested = $_GET['lang'] ?? 'en';
$lang = resolve_locale($requested);
$_SESSION['lang'] = $lang;
ensure_locale();
if (!empty($_SESSION['user']['id'])) {
    $stmt = $pdo->prepare('UPDATE users SET language = ? WHERE id = ?');
    $stmt->execute([$lang, $_SESSION['user']['id']]);
    refresh_current_user($pdo);
}
$redirect = cleanRedirect($_SERVER['HTTP_REFERER'] ?? '', url_for('submit_assessment.php'));
header('Location: ' . $redirect);
exit;
?>
