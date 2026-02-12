<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/upgrade.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$fetchCount = static function (PDO $pdo, string $sql): int {
    try {
        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch() : null;
        return (int)($row['c'] ?? 0);
    } catch (PDOException $e) {
        error_log('Admin dashboard metric failed: ' . $e->getMessage());
        return 0;
    }
};

$fetchScalar = static function (PDO $pdo, string $sql, string $column = 'v'): ?string {
    try {
        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch() : null;
        if (!$row) {
            return null;
        }
        if (array_key_exists($column, $row)) {
            return $row[$column] !== null ? (string)$row[$column] : null;
        }
        $values = array_values($row);
        return isset($values[0]) && $values[0] !== null ? (string)$values[0] : null;
    } catch (PDOException $e) {
        error_log('Admin dashboard scalar metric failed: ' . $e->getMessage());
        return null;
    }
};

$runSqlScript = static function (PDO $pdo, string $path): void {
    if (!is_file($path)) {
        throw new RuntimeException('Migration script not found: ' . $path);
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read migration script: ' . $path);
    }
    $withoutBlockComments = preg_replace('/\/\*.*?\*\//s', '', $contents);
    $cleanSql = preg_replace('/^\s*--.*$/m', '', $withoutBlockComments ?? $contents);
    $statements = array_filter(array_map(static function ($statement) {
        return trim($statement);
    }, explode(';', (string)$cleanSql)), static function ($statement) {
        return $statement !== '';
    });

    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (upgrade_should_ignore_sql_error($e, $statement)) {
                $preview = trim(preg_replace('/\s+/', ' ', $statement));
                if (strlen($preview) > 120) {
                    $preview = substr($preview, 0, 117) . '...';
                }
                error_log('Ignored SQL warning from migration script ' . $path . ' while running "' . $preview . '": ' . $e->getMessage());
                continue;
            }
            throw $e;
        }
    }
};


$currentVersion = upgrade_current_version();
$currentReleaseInfo = upgrade_current_release_info();
$currentVersionTag = null;
$currentVersionLabel = null;
$currentVersionUrl = null;
$currentVersionInstalledAt = null;
if ($currentReleaseInfo !== null) {
    $tagValue = trim((string)($currentReleaseInfo['tag'] ?? ''));
    if ($tagValue !== '') {
        $currentVersionTag = $tagValue;
    }

    $labelValue = trim((string)($currentReleaseInfo['label'] ?? ''));
    if ($labelValue !== '') {
        $currentVersionLabel = $labelValue;
    }

    $currentVersionUrl = isset($currentReleaseInfo['url']) ? (string)$currentReleaseInfo['url'] : null;
    $currentVersionInstalledAt = isset($currentReleaseInfo['installed_at'])
        ? (string)$currentReleaseInfo['installed_at']
        : null;
}

$normalizedCurrentVersion = trim((string)$currentVersion);
if ($currentVersionTag === null && $normalizedCurrentVersion !== '') {
    $currentVersionTag = $normalizedCurrentVersion;
}

$currentVersionName = $currentVersionLabel ?? $currentVersionTag ?? ($normalizedCurrentVersion !== '' ? $normalizedCurrentVersion : null);
$upgradeRepo = upgrade_effective_source($cfg);
$upgradeDefaults = [
    'current_version' => $currentVersionName,
    'current_version_tag' => $currentVersionTag,
    'current_version_url' => $currentVersionUrl,
    'current_version_installed_at' => $currentVersionInstalledAt,
    'available_version' => null,
    'available_version_label' => null,
    'available_version_url' => null,
    'last_check' => null,
    'backup_ready' => false,
    'last_backup_at' => null,
    'last_backup_path' => null,
    'upgrade_repo' => $upgradeRepo,
];
$upgradeState = array_replace($upgradeDefaults, $_SESSION['admin_upgrade_state'] ?? []);
$releaseState = [
    'current_version' => $currentVersionName,
    'current_version_tag' => $currentVersionTag,
    'current_version_url' => $currentVersionUrl,
    'current_version_installed_at' => $currentVersionInstalledAt,
];
foreach ($releaseState as $stateKey => $stateValue) {
    if ($stateValue !== null && $stateValue !== '') {
        if (!array_key_exists($stateKey, $upgradeState) || $upgradeState[$stateKey] !== $stateValue) {
            $upgradeState[$stateKey] = $stateValue;
        }
    } elseif (array_key_exists($stateKey, $upgradeState) && $upgradeState[$stateKey] !== $stateValue) {
        $upgradeState[$stateKey] = $stateValue;
    }
}
$upgradeState['upgrade_repo'] = upgrade_normalize_source((string)($upgradeState['upgrade_repo'] ?? $upgradeRepo));
if ($upgradeState['upgrade_repo'] === '') {
    $upgradeState['upgrade_repo'] = $upgradeRepo;
}
$flashMessage = $_SESSION['admin_dashboard_flash'] ?? '';
$flashType = $_SESSION['admin_dashboard_flash_type'] ?? 'info';
unset($_SESSION['admin_dashboard_flash'], $_SESSION['admin_dashboard_flash_type']);
$upgradePopup = $_SESSION['admin_upgrade_popup'] ?? null;
unset($_SESSION['admin_upgrade_popup']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $flashType = 'info';

    switch ($action) {
        case 'update_upgrade_repo':
            $rawRepo = (string)($_POST['upgrade_repo'] ?? '');
            $normalizedRepo = upgrade_normalize_source($rawRepo);
            if ($normalizedRepo === '') {
                try {
                    upgrade_save_source($pdo, null);
                    $cfg = get_site_config($pdo);
                    $upgradeRepo = upgrade_effective_source($cfg);
                    $upgradeState['upgrade_repo'] = $upgradeRepo;
                    $upgradeState['available_version'] = null;
                    $upgradeState['available_version_label'] = null;
                    $upgradeState['available_version_url'] = null;
                    $flashMessage = t($t, 'upgrade_repo_cleared', 'Release source cleared. The default repository will be used.');
                    $flashType = 'success';
                } catch (Throwable $saveError) {
                    error_log('Admin upgrade repo save failed: ' . $saveError->getMessage());
                    $flashMessage = t($t, 'upgrade_repo_save_failed', 'Unable to save the upgrade source. Please try again.');
                    $flashType = 'error';
                }
                break;
            }
            if (!upgrade_is_valid_source($normalizedRepo)) {
                $flashMessage = t($t, 'upgrade_repo_invalid', 'Enter a valid release source such as owner/repository or a Git URL.');
                $flashType = 'error';
                break;
            }
            try {
                upgrade_save_source($pdo, $normalizedRepo);
                $cfg = get_site_config($pdo);
                $upgradeRepo = upgrade_effective_source($cfg);
                $upgradeState['upgrade_repo'] = $normalizedRepo;
                $upgradeState['available_version'] = null;
                $upgradeState['available_version_label'] = null;
                $upgradeState['available_version_url'] = null;
                $flashMessage = t($t, 'upgrade_repo_saved', 'Upgrade source saved.');
                $flashType = 'success';
            } catch (Throwable $saveError) {
                error_log('Admin upgrade repo save failed: ' . $saveError->getMessage());
                $flashMessage = t($t, 'upgrade_repo_save_failed', 'Unable to save the upgrade source. Please try again.');
                $flashType = 'error';
            }
            break;

        case 'check_upgrade':
            $upgradeState['last_check'] = time();
            try {
                $token = trim((string)($cfg['github_token'] ?? getenv('GITHUB_TOKEN') ?? ''));
                $release = upgrade_fetch_latest_release($upgradeState['upgrade_repo'] ?? $upgradeRepo, $token !== '' ? $token : null);
                if ($release === null) {
                    $flashMessage = t($t, 'upgrade_repo_invalid', 'Enter a valid release source such as owner/repository or a Git URL.');
                    $flashType = 'error';
                } else {
                    $availableVersion = $release['tag'];
                    $availableLabel = $release['name'];
                    $availableVersionDisplay = $availableVersion !== '' ? $availableVersion : $availableLabel;
                    $availableUrl = $release['url'];
                    $installedTagForComparison = $upgradeState['current_version_tag'] ?? $currentVersionTag;
                    $installedLabelForComparison = $upgradeState['current_version'] ?? $currentVersionLabel;
                    if (upgrade_release_is_newer(
                        $availableVersion,
                        $installedTagForComparison,
                        $installedLabelForComparison
                    )) {
                        $upgradeState['available_version'] = $availableVersion;
                        $upgradeState['available_version_label'] = $availableLabel;
                        $upgradeState['available_version_url'] = $availableUrl;
                        $upgradeState['backup_ready'] = false;
                        $flashMessage = sprintf(
                            t($t, 'upgrade_available', 'Version %s is available for installation.'),
                            $availableVersionDisplay
                        );
                        $flashType = 'success';
                    } else {
                        $upgradeState['available_version'] = null;
                        $upgradeState['available_version_label'] = $availableLabel;
                        $upgradeState['available_version_url'] = $availableUrl;
                        $flashMessage = t($t, 'upgrade_latest', 'You are already on the latest version.');
                        $flashType = 'success';
                    }
                }
            } catch (Throwable $upgradeCheckError) {
                error_log('Admin upgrade check failed: ' . $upgradeCheckError->getMessage());
                $flashMessage = t($t, 'upgrade_check_failed', 'Unable to reach GitHub to verify the latest release. Please try again later.');
                $flashType = 'error';
            }
            break;

        case 'download_backups':
            try {
                $backup = upgrade_create_manual_backup($pdo);
                $upgradeState['backup_ready'] = true;
                $upgradeState['last_backup_at'] = time();
                $upgradeState['last_backup_path'] = $backup['relative_path'];

                $_SESSION['admin_upgrade_state'] = $upgradeState;
                $_SESSION['admin_dashboard_flash'] = t($t, 'backup_ready_message', 'System backup archive created successfully.');
                $_SESSION['admin_dashboard_flash_type'] = 'success';

                upgrade_stream_download($backup['absolute_path'], $backup['filename'], $backup['size']);
                exit;
            } catch (Throwable $backupError) {
                error_log('Admin backup failed: ' . $backupError->getMessage());
                $flashMessage = t($t, 'backup_failed', 'Unable to generate the backup archive.');
                $flashType = 'error';
            }
            break;

        case 'install_upgrade':
            $repoForUpgrade = upgrade_normalize_source($upgradeState['upgrade_repo'] ?? $upgradeRepo);
            if ($repoForUpgrade === '' || !upgrade_is_valid_source($repoForUpgrade)) {
                $flashMessage = t($t, 'upgrade_repo_invalid', 'Enter a valid release source such as owner/repository or a Git URL.');
                $flashType = 'error';
                break;
            }
            $repoArg = upgrade_repository_argument($repoForUpgrade);
            if ($repoArg === '') {
                $flashMessage = t($t, 'upgrade_repo_invalid', 'Enter a valid release source such as owner/repository or a Git URL.');
                $flashType = 'error';
                break;
            }
            $arguments = ['--action=upgrade', '--repo=' . $repoArg, '--latest-release'];
            $commandForLog = array_merge([PHP_BINARY ?: 'php', base_path('scripts/system_upgrade.php')], $arguments);
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            try {
                $result = upgrade_run_cli($arguments);
                $_SESSION['admin_upgrade_log'] = [
                    'type' => 'upgrade',
                    'timestamp' => time(),
                    'command' => upgrade_format_command($result['command']),
                    'stdout' => trim((string)$result['stdout']),
                    'stderr' => trim((string)$result['stderr']),
                    'exit_code' => $result['exit_code'],
                ];
                if ((int)$result['exit_code'] === 0) {
                    $newVersionTag = $upgradeState['available_version'] ?? null;
                    $newVersionLabel = $upgradeState['available_version_label'] ?? $newVersionTag;
                    $newVersionUrl = $upgradeState['available_version_url'] ?? null;
                    if (!empty($newVersionLabel)) {
                        $upgradeState['current_version'] = $newVersionLabel;
                    }
                    $upgradeState['current_version_tag'] = $newVersionTag;
                    $upgradeState['current_version_url'] = $newVersionUrl;
                    $upgradeState['current_version_installed_at'] = date(DATE_ATOM);
                    $upgradeState['available_version'] = null;
                    $upgradeState['available_version_label'] = null;
                    $upgradeState['available_version_url'] = null;
                    $upgradeState['backup_ready'] = false;
                    try {
                        upgrade_store_installed_release([
                            'tag' => $newVersionTag,
                            'name' => $newVersionLabel,
                            'repo' => $repoForUpgrade,
                            'url' => $newVersionUrl,
                            'installed_at' => $upgradeState['current_version_installed_at'],
                        ]);
                    } catch (Throwable $versionStoreError) {
                        error_log('Installed release metadata save failed: ' . $versionStoreError->getMessage());
                    }
                    $flashMessage = t($t, 'upgrade_command_success', 'Upgrade command completed successfully. Review the logs for details.');
                    $flashType = 'success';
                } else {
                    $flashMessage = t($t, 'upgrade_command_failed', 'The upgrade command returned a non-zero exit code. Review the log for details.');
                    $flashType = 'error';
                    $_SESSION['admin_upgrade_popup'] = [
                        'message' => $flashMessage,
                    ];
                }
            } catch (Throwable $upgradeError) {
                error_log('Admin upgrade failed: ' . $upgradeError->getMessage());
                $_SESSION['admin_upgrade_log'] = [
                    'type' => 'upgrade',
                    'timestamp' => time(),
                    'command' => upgrade_format_command($commandForLog),
                    'stdout' => '',
                    'stderr' => $upgradeError->getMessage(),
                    'exit_code' => -1,
                ];
                $flashMessage = t(
                    $t,
                    'upgrade_failed',
                    'The upgrade could not be completed. Review the logs and database permissions, then try again.'
                );
                $flashType = 'error';
                $_SESSION['admin_upgrade_popup'] = [
                    'message' => $flashMessage,
                ];
            }
            break;

        case 'restore_backup':
            $backupId = trim((string)($_POST['backup_id'] ?? ''));
            $restoreDb = isset($_POST['restore_db']);
            if ($backupId === '') {
                $flashMessage = t($t, 'restore_backup_invalid', 'Select a backup before attempting a restore.');
                $flashType = 'error';
                break;
            }
            $availableBackups = upgrade_list_backups();
            $selectedBackup = null;
            foreach ($availableBackups as $candidate) {
                $candidateId = (string)($candidate['id'] ?? ($candidate['timestamp'] ?? ''));
                if ($candidateId === $backupId) {
                    $selectedBackup = $candidate;
                    break;
                }
            }
            if ($selectedBackup === null) {
                $flashMessage = t($t, 'restore_backup_missing', 'The selected backup metadata could not be found on the server.');
                $flashType = 'error';
                break;
            }
            $arguments = ['--action=downgrade', '--backup-id=' . $backupId];
            if ($restoreDb) {
                $arguments[] = '--restore-db';
            }
            $restoreCommandForLog = array_merge([PHP_BINARY ?: 'php', base_path('scripts/system_upgrade.php')], $arguments);
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            try {
                $result = upgrade_run_cli($arguments);
                $_SESSION['admin_upgrade_log'] = [
                    'type' => 'restore',
                    'timestamp' => time(),
                    'command' => upgrade_format_command($result['command']),
                    'stdout' => trim((string)$result['stdout']),
                    'stderr' => trim((string)$result['stderr']),
                    'exit_code' => $result['exit_code'],
                ];
                if ((int)$result['exit_code'] === 0) {
                    $upgradeState['available_version'] = null;
                    $upgradeState['available_version_label'] = null;
                    $upgradeState['available_version_url'] = null;
                    $flashMessage = t($t, 'restore_backup_success', 'Backup restoration completed successfully.');
                    $flashType = 'success';
                } else {
                    $flashMessage = t($t, 'restore_backup_failed', 'The restore command failed. Review the log for details.');
                    $flashType = 'error';
                    $_SESSION['admin_upgrade_popup'] = [
                        'message' => $flashMessage,
                    ];
                }
            } catch (Throwable $restoreError) {
                error_log('Admin restore failed: ' . $restoreError->getMessage());
                $_SESSION['admin_upgrade_log'] = [
                    'type' => 'restore',
                    'timestamp' => time(),
                    'command' => upgrade_format_command($restoreCommandForLog),
                    'stdout' => '',
                    'stderr' => $restoreError->getMessage(),
                    'exit_code' => -1,
                ];
                $flashMessage = t($t, 'restore_backup_failed', 'The restore command failed. Review the log for details.');
                $flashType = 'error';
                $_SESSION['admin_upgrade_popup'] = [
                    'message' => $flashMessage,
                ];
            }
            break;

        default:
            $flashMessage = t($t, 'unknown_action', 'Unknown dashboard action.');
            $flashType = 'error';
            break;
    }

    $_SESSION['admin_upgrade_state'] = $upgradeState;
    $_SESSION['admin_dashboard_flash'] = $flashMessage;
    $_SESSION['admin_dashboard_flash_type'] = $flashType;
    header('Location: ' . url_for('admin/dashboard.php'));
    exit;
}

$_SESSION['admin_upgrade_state'] = $upgradeState;

$upgradeLog = $_SESSION['admin_upgrade_log'] ?? null;
unset($_SESSION['admin_upgrade_log']);

$upgradeBackups = upgrade_list_backups();


$users = $fetchCount($pdo, 'SELECT COUNT(*) c FROM users');
$activeUsers = $fetchCount($pdo, "SELECT COUNT(*) c FROM users WHERE account_status='active'");
$pendingUsers = $fetchCount($pdo, "SELECT COUNT(*) c FROM users WHERE account_status='pending'");
$disabledUsers = $fetchCount($pdo, "SELECT COUNT(*) c FROM users WHERE account_status='disabled'");
$totalQuestionnaires = $fetchCount($pdo, 'SELECT COUNT(*) c FROM questionnaire');
$totalResponses = $fetchCount($pdo, 'SELECT COUNT(*) c FROM questionnaire_response');
$assessmentsLast30 = $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$draftResponses = $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE status='draft'");
$submittedResponses = $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE status='submitted'");
$approvedResponses = $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE status='approved'");
$avgScoreRaw = $fetchScalar($pdo, 'SELECT AVG(score) v FROM questionnaire_response WHERE score IS NOT NULL');
$maxScoreRaw = $fetchScalar($pdo, 'SELECT MAX(score) v FROM questionnaire_response WHERE score IS NOT NULL');
$minScoreRaw = $fetchScalar($pdo, 'SELECT MIN(score) v FROM questionnaire_response WHERE score IS NOT NULL');
$latestSubmissionRaw = $fetchScalar($pdo, 'SELECT MAX(created_at) v FROM questionnaire_response');

$avgScoreDisplay = $avgScoreRaw !== null ? number_format((float)$avgScoreRaw, 1) . '%' : '—';
$maxScoreDisplay = $maxScoreRaw !== null ? number_format((float)$maxScoreRaw, 0) . '%' : '—';
$minScoreDisplay = $minScoreRaw !== null ? number_format((float)$minScoreRaw, 0) . '%' : '—';
$latestSubmissionDisplay = '—';
if ($latestSubmissionRaw) {
    try {
        $dt = new DateTime($latestSubmissionRaw);
        $latestSubmissionDisplay = $dt->format('M j, Y g:i a');
    } catch (Exception $e) {
        $latestSubmissionDisplay = $latestSubmissionRaw;
    }
}

$availableVersion = $upgradeState['available_version'] ?? null;
$availableVersionLabel = $upgradeState['available_version_label'] ?? null;
$availableVersionUrl = $upgradeState['available_version_url'] ?? null;
$availableVersionDisplay = $availableVersion ?? $availableVersionLabel;
$availableVersionSecondary = null;
if ($availableVersion && $availableVersionLabel && $availableVersionLabel !== $availableVersionDisplay) {
    $availableVersionSecondary = $availableVersionLabel;
}

$currentVersionTag = $upgradeState['current_version_tag'] ?? $currentVersionTag;
$currentVersionName = $upgradeState['current_version'] ?? $currentVersionName;
$currentVersionUrl = $upgradeState['current_version_url'] ?? $currentVersionUrl;
$currentVersionInstalledAt = $upgradeState['current_version_installed_at'] ?? $currentVersionInstalledAt;
$currentVersionDisplay = $currentVersionTag ?? $currentVersionName ?? '—';
$currentVersionSecondary = null;
if ($currentVersionName && $currentVersionName !== $currentVersionDisplay) {
    $currentVersionSecondary = $currentVersionName;
}
$currentVersionInstalledAtDisplay = null;
if ($currentVersionInstalledAt) {
    $parsedInstalledAt = strtotime($currentVersionInstalledAt);
    $currentVersionInstalledAtDisplay = $parsedInstalledAt
        ? date('M j, Y g:i a', $parsedInstalledAt)
        : $currentVersionInstalledAt;
}
$backupReady = !empty($upgradeState['backup_ready']);
$lastCheckDisplay = !empty($upgradeState['last_check']) ? date('M j, Y g:i a', (int)$upgradeState['last_check']) : null;
$lastBackupDisplay = !empty($upgradeState['last_backup_at']) ? date('M j, Y g:i a', (int)$upgradeState['last_backup_at']) : null;
$upgradeRepoDisplay = $upgradeState['upgrade_repo'] ?? $upgradeRepo;
$upgradeRepoLink = '';
if (is_string($upgradeRepoDisplay) && preg_match('#^https?://#i', (string)$upgradeRepoDisplay)) {
    $upgradeRepoLink = (string)$upgradeRepoDisplay;
} else {
    $repoSlugForLink = upgrade_extract_slug((string)$upgradeRepoDisplay);
    if ($repoSlugForLink !== null) {
        $upgradeRepoLink = 'https://github.com/' . $repoSlugForLink;
    }
}
$selectedBackupId = '';
$formattedBackups = [];
foreach ($upgradeBackups as $index => $backupMeta) {
    $backupId = (string)($backupMeta['id'] ?? ($backupMeta['timestamp'] ?? ''));
    if ($selectedBackupId === '' && $index === 0) {
        $selectedBackupId = $backupId;
    }

    $timestamp = (string)($backupMeta['timestamp'] ?? '');
    $displayTime = $timestamp;
    if ($timestamp !== '') {
        $parsed = strtotime($timestamp);
        if ($parsed !== false) {
            $displayTime = date('M j, Y g:i a', $parsed);
        }
    }

    $status = (string)($backupMeta['status'] ?? '');
    $statusLabel = $status !== '' ? ucfirst(strtolower($status)) : '';
    $statusClass = $status !== '' && strtolower($status) === 'success' ? 'success' : ($status !== '' ? 'warning' : '');
    $versionLabel = (string)($backupMeta['version_label'] ?? '');
    $ref = (string)($backupMeta['ref'] ?? '');
    $releaseLabel = $versionLabel !== '' ? $versionLabel : $ref;

    $optionParts = array_filter([
        $displayTime,
        $releaseLabel,
        $statusLabel,
    ], static function ($value) {
        return $value !== '';
    });

    $formatted = [
        'id' => $backupId,
        'timestamp' => $timestamp,
        'display_time' => $displayTime,
        'status' => $status,
        'status_label' => $statusLabel,
        'status_class' => $statusClass,
        'ref' => $ref,
        'version_label' => $versionLabel,
        'release_label' => $releaseLabel,
        'release_url' => isset($backupMeta['release_url']) ? (string)$backupMeta['release_url'] : null,
        'option_label' => $optionParts !== [] ? implode(' · ', $optionParts) : $displayTime,
    ];

    $formattedBackups[] = $formatted;
}
if ($selectedBackupId === '' && $formattedBackups !== []) {
    $selectedBackupId = $formattedBackups[0]['id'];
}
$pageHelpKey = 'admin.dashboard';
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'admin_dashboard','System Upgrade'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <header class="md-page-header">
    <div class="md-page-header__content">
      <h1 class="md-page-title"><?=t($t,'admin_dashboard','System Upgrade')?></h1>
      <p class="md-page-subtitle"><?=t($t,'system_information_intro','Monitor release status, backups, and key usage metrics from a single view.')?></p>
    </div>
  </header>
  <?php if ($flashMessage): ?>
    <?php
      $alertClass = '';
      if ($flashType === 'success') {
          $alertClass = ' success';
      } elseif ($flashType === 'error') {
          $alertClass = ' error';
      } elseif ($flashType === 'warning') {
          $alertClass = ' warning';
      }
    ?>
    <div class="md-alert<?=$alertClass?>"><?=htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8')?></div>
  <?php endif; ?>
  <?php if (is_array($upgradePopup) && !empty($upgradePopup['message'])): ?>
    <div class="md-upgrade-popup" role="alertdialog" aria-live="assertive" aria-label="<?=htmlspecialchars(t($t, 'upgrade_failed', 'Upgrade failed'), ENT_QUOTES, 'UTF-8')?>">
      <div class="md-upgrade-popup__backdrop"></div>
      <div class="md-upgrade-popup__dialog">
        <div class="md-upgrade-popup__header">
          <span class="md-upgrade-popup__title"><?=htmlspecialchars(t($t, 'upgrade_failed', 'Upgrade failed'), ENT_QUOTES, 'UTF-8')?></span>
          <button type="button" class="md-upgrade-popup__close" data-upgrade-popup-close aria-label="<?=htmlspecialchars(t($t, 'close', 'Close'), ENT_QUOTES, 'UTF-8')?>">×</button>
        </div>
        <p class="md-upgrade-popup__message"><?=htmlspecialchars((string)$upgradePopup['message'], ENT_QUOTES, 'UTF-8')?></p>
      </div>
    </div>
  <?php endif; ?>

  <div class="md-upgrade-progress" data-upgrade-progress hidden aria-hidden="true">
    <div class="md-upgrade-progress__backdrop" aria-hidden="true"></div>
    <div class="md-upgrade-progress__dialog" role="status" aria-live="assertive">
      <div class="md-upgrade-progress__spinner" aria-hidden="true"></div>
      <p class="md-upgrade-progress__message" data-upgrade-progress-message>
        <?=t($t, 'upgrade_progress_default', 'Applying update. This may take a few moments...')?>
      </p>
    </div>
  </div>

    <section class="md-upgrade-surface">
    <div class="md-upgrade-overview-grid">
      <article class="md-card md-upgrade-card md-upgrade-card--accent">
        <h3 class="md-upgrade-heading"><?=t($t,'upgrade_overview_current','Current release')?></h3>
        <div class="md-upgrade-emphasis">
          <?php if ($currentVersionUrl): ?>
            <a href="<?=htmlspecialchars($currentVersionUrl, ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener" class="md-upgrade-link">
              <?=htmlspecialchars((string)$currentVersionDisplay, ENT_QUOTES, 'UTF-8')?>
            </a>
          <?php else: ?>
            <span><?=htmlspecialchars((string)$currentVersionDisplay, ENT_QUOTES, 'UTF-8')?></span>
          <?php endif; ?>
          <?php if ($currentVersionSecondary): ?>
            <span class="md-upgrade-chip">
              <?=htmlspecialchars((string)$currentVersionSecondary, ENT_QUOTES, 'UTF-8')?>
            </span>
          <?php endif; ?>
        </div>
        <?php if ($currentVersionInstalledAtDisplay): ?>
          <p class="md-upgrade-meta"><?=htmlspecialchars(sprintf(t($t,'upgrade_installed_at','Installed on %s'), $currentVersionInstalledAtDisplay), ENT_QUOTES, 'UTF-8')?></p>
        <?php endif; ?>
        <?php if ($upgradeRepoDisplay): ?>
          <p class="md-upgrade-meta">
            <?=t($t,'release_source','Release source')?>:
            <?php if ($upgradeRepoLink): ?>
              <a href="<?=htmlspecialchars($upgradeRepoLink, ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener" class="md-upgrade-link">
                <?=htmlspecialchars((string)$upgradeRepoDisplay, ENT_QUOTES, 'UTF-8')?>
              </a>
            <?php else: ?>
              <?=htmlspecialchars((string)$upgradeRepoDisplay, ENT_QUOTES, 'UTF-8')?>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </article>
      <article class="md-card md-upgrade-card">
        <h3 class="md-upgrade-heading"><?=t($t,'upgrade_overview_status','Update status')?></h3>
        <?php if ($availableVersion): ?>
          <p class="md-upgrade-meta md-upgrade-meta--strong">
            <?=htmlspecialchars(sprintf(t($t,'upgrade_available','Version %s is available for installation.'), $availableVersionDisplay), ENT_QUOTES, 'UTF-8')?>
          </p>
          <?php if ($availableVersionSecondary): ?>
            <p class="md-upgrade-meta">
              <?=t($t,'upgrade_available_label','Release name')?>:
              <?=htmlspecialchars($availableVersionSecondary, ENT_QUOTES, 'UTF-8')?>
            </p>
          <?php endif; ?>
          <?php if ($availableVersionUrl): ?>
            <p class="md-upgrade-meta">
              <a href="<?=htmlspecialchars($availableVersionUrl, ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener" class="md-upgrade-link">
                <?=t($t,'view_release_notes','View release notes')?>
              </a>
            </p>
          <?php endif; ?>
        <?php else: ?>
          <p class="md-upgrade-meta md-upgrade-meta--strong"><?=t($t,'upgrade_latest','You are already on the latest version.')?></p>
          <?php if ($availableVersionLabel && $availableVersionUrl): ?>
            <p class="md-upgrade-meta">
              <?=t($t,'latest_release_label','Latest release')?>:
              <a href="<?=htmlspecialchars($availableVersionUrl, ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener" class="md-upgrade-link">
                <?=htmlspecialchars($availableVersionLabel, ENT_QUOTES, 'UTF-8')?>
              </a>
            </p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($lastCheckDisplay): ?>
          <p class="md-upgrade-meta"><?=t($t,'last_checked','Last checked:')?> <?=htmlspecialchars($lastCheckDisplay, ENT_QUOTES, 'UTF-8')?></p>
        <?php endif; ?>
        <div class="md-upgrade-inline-actions">
          <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="check_upgrade">
            <button type="submit" class="md-button md-elev-1 md-upgrade-action-button"><?=t($t,'check_for_upgrade','Check for Upgrade')?></button>
          </form>
          <form method="post" data-upgrade-progress-trigger data-upgrade-progress-message="<?=htmlspecialchars(t($t, 'upgrade_progress_install', 'Installing update. Please keep this page open until the process completes.'), ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="install_upgrade">
            <button type="submit" class="md-button md-primary md-elev-2 md-upgrade-action-button" <?=($upgradeRepoDisplay === '' ? 'disabled' : '')?>><?=t($t,'install_upgrade','Install Upgrade')?></button>
          </form>
        </div>
        <p class="md-upgrade-meta"><?=t($t,'upgrade_hint_script','The upgrade script automatically creates backups before applying changes. Download manual backups whenever you need an extra copy.')?></p>
      </article>
      <article class="md-card md-upgrade-card">
        <h3 class="md-upgrade-heading"><?=t($t,'upgrade_overview_backup','Backups')?></h3>
        <p class="md-upgrade-meta md-upgrade-meta--strong">
          <?=$backupReady ? t($t,'backup_ready','Backup ready') : t($t,'backup_required','Backup required')?>
        </p>
        <?php if ($lastBackupDisplay): ?>
          <p class="md-upgrade-meta">
            <?=t($t,'last_backup','Last backup:')?> <?=htmlspecialchars($lastBackupDisplay, ENT_QUOTES, 'UTF-8')?>
          </p>
        <?php endif; ?>
        <p class="md-upgrade-meta"><?=t($t,'backup_hint','Keep regular snapshots before installing updates.')?></p>
      </article>
    </div>

    <div class="md-upgrade-layout">
      <article class="md-card md-upgrade-card">
        <h3 class="md-upgrade-heading"><?=t($t,'upgrade_repo_label','Release source')?></h3>
        <p class="md-upgrade-meta"><?=t($t,'upgrade_repo_hint','Specify the GitHub repository slug or a full HTTPS Git URL used to check for releases (for example, https://github.com/khoppenworth/HRassessv300).')?></p>
        <form method="post" class="md-upgrade-form">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="action" value="update_upgrade_repo">
          <label class="md-upgrade-field">
            <span class="md-upgrade-field-label"><?=t($t,'upgrade_repo_label','Release source')?></span>
            <input type="text" name="upgrade_repo" value="<?=htmlspecialchars((string)$upgradeRepoDisplay, ENT_QUOTES, 'UTF-8')?>" placeholder="https://github.com/owner/repository" class="md-upgrade-input" inputmode="url" spellcheck="false" autocapitalize="none" autocomplete="off">
          </label>
          <button type="submit" class="md-button md-primary md-elev-1 md-upgrade-submit"><?=t($t,'save_upgrade_source','Save source')?></button>
        </form>
      </article>

      <article class="md-card md-upgrade-card">
        <h3 class="md-upgrade-heading"><?=t($t,'upgrade_log_heading','Recent upgrade activity')?></h3>
        <?php if ($upgradeLog): ?>
          <?php if (!empty($upgradeLog['timestamp'])): ?>
            <p class="md-upgrade-meta"><?=t($t,'upgrade_log_timestamp','Run at:')?> <?=htmlspecialchars(date('M j, Y g:i a', (int)$upgradeLog['timestamp']), ENT_QUOTES, 'UTF-8')?></p>
          <?php endif; ?>
          <?php if (!empty($upgradeLog['command'])): ?>
            <p class="md-upgrade-meta"><strong><?=t($t,'upgrade_command_label','Executed command')?>:</strong> <code><?=htmlspecialchars($upgradeLog['command'], ENT_QUOTES, 'UTF-8')?></code></p>
          <?php endif; ?>
          <p class="md-upgrade-meta"><strong><?=t($t,'upgrade_exit_code_label','Exit code')?>:</strong> <span class="md-upgrade-chip <?=(int)($upgradeLog['exit_code'] ?? 1) === 0 ? 'success' : 'error'?>"><?=htmlspecialchars((string)($upgradeLog['exit_code'] ?? '—'), ENT_QUOTES, 'UTF-8')?></span></p>
          <?php if (!empty($upgradeLog['stdout'])): ?>
            <div class="md-upgrade-log-block">
              <strong><?=t($t,'upgrade_stdout_label','Output')?>:</strong>
              <pre class="md-code-block"><?=htmlspecialchars($upgradeLog['stdout'], ENT_QUOTES, 'UTF-8')?></pre>
            </div>
          <?php endif; ?>
          <?php if (!empty($upgradeLog['stderr'])): ?>
            <div class="md-upgrade-log-block">
              <strong><?=t($t,'upgrade_stderr_label','Errors')?>:</strong>
              <pre class="md-code-block"><?=htmlspecialchars($upgradeLog['stderr'], ENT_QUOTES, 'UTF-8')?></pre>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p class="md-upgrade-meta"><?=t($t,'no_upgrade_activity','No upgrade commands have been executed yet.')?></p>
        <?php endif; ?>
      </article>

      <article class="md-card md-upgrade-card">
        <h3 class="md-upgrade-heading"><?=t($t,'upgrade_recent_backups','Upgrade backups')?></h3>
        <?php if ($formattedBackups): ?>
          <?php $downloadBackupFormId = 'download-backup-form'; ?>
          <form method="post" class="md-upgrade-restore-form" id="restore-backup-form">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="restore_backup">
            <label class="md-upgrade-field">
              <span class="md-upgrade-field-label"><?=t($t,'select_backup_label','Backup to restore')?></span>
              <select name="backup_id" class="md-upgrade-select" data-upgrade-backup-select>
                <?php foreach ($formattedBackups as $backupMeta): ?>
                  <option value="<?=htmlspecialchars($backupMeta['id'], ENT_QUOTES, 'UTF-8')?>" <?=$backupMeta['id'] === $selectedBackupId ? 'selected' : ''?>>
                    <?=htmlspecialchars($backupMeta['option_label'], ENT_QUOTES, 'UTF-8')?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <p class="md-upgrade-meta"><?=t($t,'select_backup_hint','Choose a snapshot to restore or download.')?></p>
            <label class="md-upgrade-checkbox">
              <input type="checkbox" name="restore_db" value="1">
              <span><?=t($t,'restore_database','Restore database')?></span>
            </label>
            <p class="md-upgrade-meta"><?=t($t,'restore_database_hint','Also restore the database from the selected backup.')?></p>
            <div class="md-upgrade-backup-toolbar">
              <button type="submit" class="md-button md-outline md-elev-1 md-upgrade-action-button"><?=t($t,'restore_backup','Restore backup')?></button>
              <button type="submit" form="<?=htmlspecialchars($downloadBackupFormId, ENT_QUOTES, 'UTF-8')?>" class="md-button md-elev-1 md-upgrade-action-button">
                <?=t($t,'download_backup','Download backup')?>
              </button>
            </div>
          </form>
          <form method="post" id="<?=htmlspecialchars($downloadBackupFormId, ENT_QUOTES, 'UTF-8')?>" class="md-upgrade-hidden-form">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="download_backups">
          </form>
        <?php else: ?>
          <p class="md-upgrade-meta"><?=t($t,'no_backups_available','No upgrade backups are available yet.')?></p>
        <?php endif; ?>
      </article>
    </div>
  </section>
</section>
<script>
  document.addEventListener('click', function (event) {
    const closeButton = event.target.closest('[data-upgrade-popup-close]');
    if (!closeButton) {
      return;
    }
    const popup = closeButton.closest('.md-upgrade-popup');
    if (popup) {
      popup.remove();
    }
  });
</script>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>
