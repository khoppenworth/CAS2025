<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
header('Location: ' . url_for('admin/upgrade.php'));
exit;
