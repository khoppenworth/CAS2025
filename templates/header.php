<?php
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/../lib/help.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$role = $user['role'] ?? ($_SESSION['user']['role'] ?? null);
$reviewEnabled = (int)($cfg['review_enabled'] ?? 1) === 1;
$logoUrl = site_logo_url($cfg);
$logoPathSmall = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
$siteTitle = htmlspecialchars($cfg['site_name'] ?? 'My Performance');
$siteLogoAlt = htmlspecialchars($cfg['site_name'] ?? 'Logo', ENT_QUOTES, 'UTF-8');
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';
$brandStyle = site_brand_style($cfg);
$drawerKey = $drawerKey ?? null;
$scriptName = ltrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$navKeyMap = [
    'my_performance.php' => 'workspace.my_performance',
    'submit_assessment.php' => 'workspace.submit_assessment',
    'admin/supervisor_review.php' => 'team.review_queue',
    'admin/pending_accounts.php' => 'team.pending_accounts',
    'admin/questionnaire_assignments.php' => 'team.assignments',
    'admin/dashboard.php' => 'admin.dashboard',
    'admin/users.php' => 'admin.users',
    'admin/questionnaire_manage.php' => 'admin.manage_questionnaires',
    'admin/work_function_defaults.php' => 'admin.work_function_defaults',
    'admin/analytics.php' => 'team.analytics',
    'admin/export.php' => 'admin.export',
    'admin/branding.php' => 'admin.branding',
    'admin/settings.php' => 'admin.settings',
    'swagger.php' => 'admin.api_docs',
];
if ($drawerKey === null && $scriptName !== '') {
    $drawerKey = $navKeyMap[$scriptName] ?? null;
}
$helpKey = $pageHelpKey ?? $drawerKey ?? 'global.default';
$helpContent = get_page_help($helpKey, $t);
$clientStrings = [
    'offline_banner_offline' => t($t, 'offline_banner_offline', 'You are offline. Recent data will stay available until you reconnect.'),
    'offline_banner_online' => t($t, 'offline_banner_online', 'Back online. Syncing the latest updates now.'),
    'offline_banner_dismiss' => t($t, 'offline_banner_dismiss', 'Dismiss'),
    'offline_banner_dismiss_aria' => t($t, 'offline_banner_dismiss_aria', 'Dismiss offline status message'),
];
$isActiveNav = static function (string ...$keys) use ($drawerKey): bool {
    if ($drawerKey === null) {
        return false;
    }
    foreach ($keys as $key) {
        if ($drawerKey === $key) {
            return true;
        }
    }
    return false;
};
$topNavLinkAttributes = static function (string ...$keys) use ($isActiveNav): string {
    $class = 'md-topnav-link' . ($isActiveNav(...$keys) ? ' active' : '');
    $aria = $isActiveNav(...$keys) ? ' aria-current="page"' : '';
    return sprintf('class="%s"%s', $class, $aria);
};
$localeFlags = [
    'en' => '🇬🇧',
    'fr' => '🇫🇷',
    'am' => '🇪🇹',
];
$localeFlagIcons = [
    'en' => 'assets/images/flags/flag-en.svg',
    'fr' => 'assets/images/flags/flag-fr.svg',
    'am' => 'assets/images/flags/flag-am.svg',
];
$currentLocale = $locale ?? $defaultLocale;
$localeCount = count($availableLocales);
$localeIndex = $localeCount > 0 ? array_search($currentLocale, $availableLocales, true) : false;
$nextLocale = $localeCount > 0
    ? $availableLocales[(($localeIndex === false ? 0 : $localeIndex) + 1) % $localeCount]
    : $currentLocale;
$currentLocaleFlag = $localeFlags[$currentLocale] ?? '🌐';
$currentLocaleFlagIcon = $localeFlagIcons[$currentLocale] ?? null;
?>
<?php if ($brandStyle !== ''): ?>
<style id="md-brand-style"><?=htmlspecialchars($brandStyle, ENT_QUOTES, 'UTF-8')?></style>
<?php endif; ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  window.APP_DEFAULT_LOCALE = <?=json_encode($defaultLocale, JSON_THROW_ON_ERROR)?>;
  window.APP_AVAILABLE_LOCALES = <?=json_encode($availableLocales, JSON_THROW_ON_ERROR)?>;
  window.APP_USER = <?=json_encode([
      'username' => $user['username'] ?? null,
      'full_name' => $user['full_name'] ?? null,
      'role' => $role,
  ], JSON_THROW_ON_ERROR)?>;
  window.APP_STRINGS = <?=json_encode($clientStrings, JSON_THROW_ON_ERROR)?>;
</script>
<header class="md-appbar md-elev-2">
  <button class="md-appbar-toggle" aria-label="Toggle navigation" data-drawer-toggle aria-controls="app-topnav" aria-expanded="false">
    <span></span>
    <span></span>
    <span></span>
  </button>
  <div class="md-appbar-title">
    <img src="<?=$logoPathSmall?>" alt="<?=$siteLogoAlt?>" class="md-appbar-logo" loading="lazy">
    <span><?=$siteTitle?></span>
  </div>
  <div class="md-appbar-actions">
    <a
      href="<?=htmlspecialchars(url_for('set_lang.php?lang=' . $nextLocale), ENT_QUOTES, 'UTF-8')?>"
      class="md-appbar-link md-appbar-language"
      aria-label="<?=htmlspecialchars(t($t, 'language_switch', 'Switch language'), ENT_QUOTES, 'UTF-8')?>"
      title="<?=htmlspecialchars(t($t, 'language_switch', 'Switch language'), ENT_QUOTES, 'UTF-8')?>"
    >
      <?php if ($currentLocaleFlagIcon): ?>
        <img
          src="<?=htmlspecialchars(url_for($currentLocaleFlagIcon), ENT_QUOTES, 'UTF-8')?>"
          alt=""
          class="md-appbar-flag"
          aria-hidden="true"
        >
      <?php else: ?>
        <span aria-hidden="true"><?=$currentLocaleFlag?></span>
      <?php endif; ?>
    </a>
    <a href="<?=htmlspecialchars(url_for('logout.php'), ENT_QUOTES, 'UTF-8')?>" class="md-appbar-link">
      <?=t($t, 'logout', 'Logout')?>
    </a>
  </div>
</header>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  (function () {
    var globalConnectivity = (function (existing) {
      if (existing && typeof existing === 'object') {
        return existing;
      }

      var listeners = [];
      var storageKey = 'hrassess:connectivity:forcedOffline';
      var forcedOffline = false;

      try {
        var stored = window.localStorage.getItem(storageKey);
        forcedOffline = stored === '1';
      } catch (err) {
        forcedOffline = false;
      }

      var computeOnline = function () {
        return !forcedOffline && navigator.onLine;
      };

      var notify = function () {
        var state = { online: computeOnline(), forcedOffline: forcedOffline };
        listeners.slice().forEach(function (listener) {
          try {
            listener(state);
          } catch (err) {
            // Ignore listener errors to avoid breaking other handlers.
          }
        });
        try {
          document.dispatchEvent(new CustomEvent('app:connectivity-change', { detail: state }));
        } catch (err) {
          // Ignore dispatch errors if CustomEvent is unavailable.
        }
        return state;
      };

      var persistForcedState = function () {
        try {
          window.localStorage.setItem(storageKey, forcedOffline ? '1' : '0');
        } catch (err) {
          // Ignore persistence failures (private mode, quota, etc.).
        }
      };

      var handleBrowserChange = function () {
        notify();
      };

      window.addEventListener('online', handleBrowserChange);
      window.addEventListener('offline', handleBrowserChange);

      var api = {
        isOnline: function () {
          return computeOnline();
        },
        isForcedOffline: function () {
          return forcedOffline;
        },
        setForcedOffline: function (value) {
          var next = Boolean(value);
          if (next === forcedOffline) {
            notify();
            return;
          }
          forcedOffline = next;
          persistForcedState();
          notify();
        },
        toggleForcedOffline: function () {
          api.setForcedOffline(!forcedOffline);
        },
        subscribe: function (listener) {
          if (typeof listener !== 'function') {
            return function () {};
          }
          if (!listeners.includes(listener)) {
            listeners.push(listener);
          }
          try {
            listener({ online: computeOnline(), forcedOffline: forcedOffline });
          } catch (err) {
            // Ignore listener errors during initial sync.
          }
          return function () {
            listeners = listeners.filter(function (fn) { return fn !== listener; });
          };
        },
        getState: function () {
          return { online: computeOnline(), forcedOffline: forcedOffline };
        }
      };

      notify();

      return api;
    })(window.AppConnectivity);

    window.AppConnectivity = globalConnectivity;

    var onReady = function () {
      var indicator = document.querySelector('[data-status-indicator]');
      if (indicator) {
        var label = indicator.querySelector('.md-status-label');
        var onlineText = indicator.getAttribute('data-online-text') || 'Online';
        var offlineText = indicator.getAttribute('data-offline-text') || 'Offline';

        var applyState = function (state) {
          var isOnline = state && typeof state.online === 'boolean' ? state.online : globalConnectivity.isOnline();
          var forced = state && typeof state.forcedOffline === 'boolean' ? state.forcedOffline : globalConnectivity.isForcedOffline();
          indicator.classList.toggle('is-offline', !isOnline);
          indicator.setAttribute('data-status', isOnline ? 'online' : 'offline');
          indicator.setAttribute('aria-checked', isOnline ? 'true' : 'false');
          if (forced) {
            indicator.setAttribute('data-mode', 'manual');
          } else {
            indicator.removeAttribute('data-mode');
          }
          if (label) {
            label.textContent = isOnline ? onlineText : offlineText;
          }
        };

        if (globalConnectivity && typeof globalConnectivity.subscribe === 'function') {
          globalConnectivity.subscribe(applyState);
        } else {
          var updateStatus = function () {
            applyState({ online: navigator.onLine, forcedOffline: false });
          };
          window.addEventListener('online', updateStatus);
          window.addEventListener('offline', updateStatus);
          updateStatus();
        }

        indicator.addEventListener('click', function () {
          if (globalConnectivity && typeof globalConnectivity.toggleForcedOffline === 'function') {
            globalConnectivity.toggleForcedOffline();
          }
        });
      }

      var reloadButton = document.getElementById('appbar-reload-btn');
      if (reloadButton) {
        var performReload = function () {
          window.location.reload();
        };

        reloadButton.addEventListener('click', function () {
          reloadButton.disabled = true;
          reloadButton.classList.add('is-loading');

          var cleanupTasks = [];

          if ('caches' in window && typeof caches.keys === 'function') {
            cleanupTasks.push(
              caches.keys().then(function (keys) {
                return Promise.all(keys.map(function (key) {
                  return caches.delete(key);
                }));
              })
            );
          }

          if ('serviceWorker' in navigator && typeof navigator.serviceWorker.getRegistrations === 'function') {
            cleanupTasks.push(
              navigator.serviceWorker.getRegistrations().then(function (registrations) {
                return Promise.all(registrations.map(function (registration) {
                  return registration.unregister();
                }));
              })
            );
          }

          if (cleanupTasks.length > 0) {
            Promise.all(cleanupTasks)
              .catch(function () { /* ignore */ })
              .finally(performReload);
          } else {
            performReload();
          }
        });
      }
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', onReady);
    } else {
      onReady();
    }
  })();
</script>
<div id="google_translate_element" class="visually-hidden" aria-hidden="true"></div>
<div class="md-shell">
<nav
  id="app-topnav"
  class="md-topnav md-elev-2"
  data-topnav
  aria-label="<?=htmlspecialchars(t($t, 'primary_navigation', 'Primary navigation'), ENT_QUOTES, 'UTF-8')?>"
  tabindex="-1"
  aria-hidden="true"
>
  <div class="md-topnav-mobile-header" data-topnav-mobile-header>
    <span class="md-topnav-mobile-title"><?=t($t, 'navigation_menu', 'Navigation')?></span>
    <button
      type="button"
      class="md-topnav-close"
      data-topnav-close
      aria-label="<?=htmlspecialchars(t($t, 'close_menu', 'Close menu'), ENT_QUOTES, 'UTF-8')?>"
    >
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <ul class="md-topnav-list">
    <?php
    $workspaceActive = $isActiveNav('workspace.my_performance', 'workspace.submit_assessment');
    ?>
    <li class="md-topnav-item<?=$workspaceActive ? ' is-active' : ''?>" data-topnav-item>
      <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
        <span class="md-topnav-label">
          <span class="md-topnav-title"><?=t($t, 'my_workspace', 'My Workspace')?></span>
          <span class="md-topnav-desc"><?=t($t, 'my_workspace_summary', 'Stay on top of your goals and tasks.')?></span>
        </span>
        <span class="md-topnav-chevron" aria-hidden="true"></span>
      </button>
      <ul class="md-topnav-submenu">
        <li>
          <a href="<?=htmlspecialchars(url_for('my_performance.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('workspace.my_performance')?>>
            <span class="md-topnav-link-content">
              <span class="md-topnav-link-title"><?=t($t, 'my_performance', 'My Performance')?></span>
              <span class="md-topnav-link-desc"><?=t($t, 'my_performance_summary', 'Track your objectives, reviews, and milestones.')?></span>
            </span>
            <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
          </a>
        </li>
        <li>
          <a href="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('workspace.submit_assessment')?>>
            <span class="md-topnav-link-content">
              <span class="md-topnav-link-title"><?=t($t, 'submit_assessment', 'Submit Assessment')?></span>
              <span class="md-topnav-link-desc"><?=t($t, 'submit_assessment_summary', 'Complete or update your latest assessment.')?></span>
            </span>
            <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
          </a>
        </li>
      </ul>
    </li>
    <?php if (in_array($role, ['admin', 'supervisor'], true)): ?>
      <?php
      $teamNavKeys = ['team.pending_accounts', 'team.analytics'];
      if ($reviewEnabled) {
          array_unshift($teamNavKeys, 'team.review_queue');
      }
      $teamActive = $teamNavKeys ? $isActiveNav(...$teamNavKeys) : false;
      ?>
      <li class="md-topnav-item<?=$teamActive ? ' is-active' : ''?>" data-topnav-item>
        <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
          <span class="md-topnav-label">
            <span class="md-topnav-title"><?=t($t, 'team_navigation', 'Team & Reviews')?></span>
            <span class="md-topnav-desc"><?=t($t, 'team_navigation_summary', 'Support your team with reviews and approvals.')?></span>
          </span>
          <span class="md-topnav-chevron" aria-hidden="true"></span>
        </button>
        <ul class="md-topnav-submenu">
          <?php if ($reviewEnabled): ?>
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/supervisor_review.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('team.review_queue')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'review_queue', 'Review Queue')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'review_queue_summary', 'Review submissions that need your feedback.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
          <?php endif; ?>
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/pending_accounts.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('team.pending_accounts')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'pending_accounts', 'Pending Approvals')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'pending_accounts_summary', 'Approve or reject incoming access requests.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('team.analytics')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'analytics', 'Analytics')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'analytics_summary', 'Discover trends with interactive analytics.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
        </ul>
      </li>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
      <?php
      $adminActive = $isActiveNav('admin.users', 'admin.manage_questionnaires', 'admin.work_function_defaults', 'admin.branding');
      $systemActive = $isActiveNav('admin.dashboard', 'admin.export', 'admin.settings');
      ?>
      <li class="md-topnav-item<?=$adminActive ? ' is-active' : ''?>" data-topnav-item>
        <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
          <span class="md-topnav-label">
            <span class="md-topnav-title"><?=t($t, 'admin_navigation', 'Administration')?></span>
            <span class="md-topnav-desc"><?=t($t, 'admin_navigation_summary', 'Manage users, questionnaires, and branding.')?></span>
          </span>
          <span class="md-topnav-chevron" aria-hidden="true"></span>
        </button>
        <ul class="md-topnav-submenu">
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.users')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'manage_users', 'Manage Users')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'manage_users_summary', 'Invite, edit, or deactivate accounts and roles.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/questionnaire_manage.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.manage_questionnaires')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'manage_questionnaires', 'Manage Questionnaires')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'manage_questionnaires_summary', 'Build, organize, and publish assessment questionnaires.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.work_function_defaults')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'work_function_defaults_title', 'Work Function Defaults')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'work_function_defaults_summary', 'Set default work function options for new assessments.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/branding.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.branding')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'branding', 'Branding & Landing')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'branding_summary', 'Customize logos, colors, and the landing page.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
        </ul>
      </li>
      <li class="md-topnav-item<?=$systemActive ? ' is-active' : ''?>" data-topnav-item>
        <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
          <span class="md-topnav-label">
            <span class="md-topnav-title"><?=t($t, 'system_navigation', 'System')?></span>
            <span class="md-topnav-desc"><?=t($t, 'system_navigation_summary', 'Configure authentication, exports, and platform upgrades.')?></span>
          </span>
          <span class="md-topnav-chevron" aria-hidden="true"></span>
        </button>
        <ul class="md-topnav-submenu">
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/dashboard.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.dashboard')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'admin_dashboard', 'System Information')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'admin_dashboard_summary', 'Monitor release status, backups, and usage metrics.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/export.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.export')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'export_data', 'Export Data')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'export_data_summary', 'Download assessment data for reporting or analysis.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a href="<?=htmlspecialchars(url_for('admin/settings.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.settings')?>>
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t, 'settings', 'Settings')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'settings_summary', 'Adjust platform settings and integrations.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a href="<?=htmlspecialchars(url_for('swagger.php'), ENT_QUOTES, 'UTF-8')?>" class="md-topnav-link md-topnav-link--external" target="_blank" rel="noopener">
              <span class="md-topnav-link-content">
                <span class="md-topnav-link-title"><?=t($t,'api_documentation','API Documentation')?></span>
                <span class="md-topnav-link-desc"><?=t($t, 'api_documentation_summary', 'Browse the interactive API reference.')?></span>
              </span>
              <span class="md-topnav-link-icon" aria-hidden="true">↗</span>
            </a>
          </li>
        </ul>
      </li>
    <?php endif; ?>
    <li class="md-topnav-item" data-topnav-item>
      <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
        <span class="md-topnav-label">
          <span class="md-topnav-title"><?=t($t, 'account_tools', 'Account & Tools')?></span>
          <span class="md-topnav-desc"><?=t($t, 'account_tools_summary', 'Quick actions, profile, and preferences.')?></span>
        </span>
        <span class="md-topnav-chevron" aria-hidden="true"></span>
      </button>
      <ul class="md-topnav-submenu">
        <li>
          <button
            type="button"
            class="md-topnav-link"
            id="appbar-install-btn"
            hidden
            aria-hidden="true"
          >
            <span class="md-topnav-link-content">
              <span class="md-topnav-link-title"><?=htmlspecialchars(t($t, 'install_app', 'Install App'), ENT_QUOTES, 'UTF-8')?></span>
              <span class="md-topnav-link-desc"><?=t($t, 'install_app_summary', 'Add this app to your device for quick access.')?></span>
            </span>
            <span class="md-topnav-link-icon" aria-hidden="true">↓</span>
          </button>
        </li>
        <li>
          <button
            type="button"
            class="md-topnav-link"
            data-status-indicator
            data-online-text="<?=htmlspecialchars(t($t, 'status_online', 'Online'), ENT_QUOTES, 'UTF-8')?>"
            data-offline-text="<?=htmlspecialchars(t($t, 'status_offline', 'Offline'), ENT_QUOTES, 'UTF-8')?>"
            role="switch"
            aria-live="polite"
            aria-atomic="true"
            aria-checked="true"
            data-status="online"
            title="<?=htmlspecialchars(t($t, 'toggle_offline_mode', 'Toggle offline mode'), ENT_QUOTES, 'UTF-8')?>"
          >
            <span class="md-topnav-link-content">
              <span class="md-topnav-link-title"><?=t($t, 'connectivity_status', 'Connectivity')?></span>
              <span class="md-topnav-link-desc md-status-label"><?=htmlspecialchars(t($t, 'status_online', 'Online'), ENT_QUOTES, 'UTF-8')?></span>
            </span>
            <span class="md-topnav-link-icon md-status-dot" aria-hidden="true"></span>
          </button>
        </li>
        <li>
          <a href="<?=htmlspecialchars(url_for('profile.php'), ENT_QUOTES, 'UTF-8')?>" class="md-topnav-link">
            <span class="md-topnav-link-content">
              <span class="md-topnav-link-title"><?=htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Profile')?></span>
              <span class="md-topnav-link-desc"><?=t($t, 'profile_summary', 'Update your profile details and settings.')?></span>
            </span>
            <span class="md-topnav-link-icon" aria-hidden="true">&rsaquo;</span>
          </a>
        </li>
      </ul>
    </li>
  </ul>
</nav>
<div class="md-topnav-backdrop" data-topnav-backdrop aria-hidden="true" hidden></div>
<div class="md-help-overlay" data-help-overlay hidden>
  <div class="md-help-dialog" role="dialog" aria-modal="true" aria-labelledby="help-dialog-title">
    <div class="md-help-dialog__header">
      <h2 id="help-dialog-title"><?=htmlspecialchars($helpContent['title'] ?? t($t, 'help_default_title', 'Need a hand?'), ENT_QUOTES, 'UTF-8')?></h2>
      <button type="button" class="md-help-close" data-help-close aria-label="<?=htmlspecialchars(t($t, 'help_close', 'Close'), ENT_QUOTES, 'UTF-8')?>">&times;</button>
    </div>
    <div class="md-help-dialog__body">
      <ul class="md-help-list">
        <?php foreach (($helpContent['tips'] ?? []) as $tip): ?>
          <li><?=htmlspecialchars($tip, ENT_QUOTES, 'UTF-8')?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<main class="md-main">
