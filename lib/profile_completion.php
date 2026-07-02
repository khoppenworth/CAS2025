<?php
/**
 * Central profile-completion rules used by profile saving and workspace gates.
 *
 * These functions intentionally use a CAS-specific prefix instead of the older
 * user_profile_* names because config.php can be preserved during some live
 * upgrades. Calling these functions directly prevents stale config.php helper
 * implementations from continuing to block users after an upgrade.
 */

if (!function_exists('cas_profile_required_fields')) {
    function cas_profile_required_fields(): array
    {
        return [
            'full_name',
            'email',
            'gender',
            'phone',
            'department',
            'cadre',
            'profile_role',
            'job_grade',
            'education_level',
            'highest_degree_subject',
            'total_work_experience_band',
            'epss_work_experience_band',
            'work_function',
        ];
    }
}

if (!function_exists('cas_profile_missing_required_fields')) {
    function cas_profile_missing_required_fields(array $user): array
    {
        $missing = [];
        foreach (cas_profile_required_fields() as $field) {
            if (trim((string)($user[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}

if (!function_exists('cas_profile_is_complete')) {
    function cas_profile_is_complete(array $user): bool
    {
        return cas_profile_missing_required_fields($user) === [];
    }
}

if (!function_exists('cas_profile_redirect_target')) {
    function cas_profile_redirect_target(string $redirect): string
    {
        $parsedRedirect = @parse_url($redirect);
        $redirectPath = is_array($parsedRedirect) && isset($parsedRedirect['path']) ? (string)$parsedRedirect['path'] : $redirect;
        $defaultTarget = function_exists('url_for') ? url_for('profile.php') : ((defined('BASE_URL') ? (string)BASE_URL : '/') . 'profile.php');
        $isAbsolute = is_array($parsedRedirect) && isset($parsedRedirect['scheme']) && $parsedRedirect['scheme'] !== '';

        if ($isAbsolute) {
            return $redirect;
        }

        if (function_exists('cleanRedirect')) {
            return cleanRedirect($redirect, $defaultTarget);
        }

        if (function_exists('url_for')) {
            return url_for($redirect);
        }

        $base = defined('BASE_URL') ? (string)BASE_URL : '/';
        $normalizedBase = rtrim($base, '/');
        $normalizedPath = '/' . ltrim($redirectPath, '/');
        if ($redirectPath === '' || $redirectPath === '/') {
            $normalizedPath = '/';
        }

        $target = $normalizedBase === '' ? $normalizedPath : $normalizedBase . $normalizedPath;
        if (is_array($parsedRedirect)) {
            if (isset($parsedRedirect['query']) && $parsedRedirect['query'] !== '') {
                $target .= '?' . $parsedRedirect['query'];
            }
            if (isset($parsedRedirect['fragment']) && $parsedRedirect['fragment'] !== '') {
                $target .= '#' . $parsedRedirect['fragment'];
            }
        }

        return $target !== '' ? $target : $defaultTarget;
    }
}

if (!function_exists('cas_require_profile_completion')) {
    function cas_require_profile_completion(PDO $pdo, string $redirect = 'profile.php'): void
    {
        if (!isset($_SESSION['user']['id'])) {
            return;
        }

        $userId = (int)($_SESSION['user']['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $latestUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($latestUser)) {
                $_SESSION['user'] = $latestUser;
                $isComplete = cas_profile_is_complete($latestUser);
                $completedValue = $isComplete ? 1 : 0;
                if ((int)($latestUser['profile_completed'] ?? 0) !== $completedValue) {
                    $pdo->prepare('UPDATE users SET profile_completed = ? WHERE id = ?')->execute([$completedValue, $userId]);
                    $_SESSION['user']['profile_completed'] = $completedValue;
                }
                if ($isComplete) {
                    return;
                }
            }
        } catch (PDOException $e) {
            error_log('cas_require_profile_completion profile check failed: ' . $e->getMessage());
        }

        if (($_SESSION['user']['profile_completed'] ?? 0) == 1) {
            return;
        }

        $currentScripts = [
            basename((string)($_SERVER['SCRIPT_NAME'] ?? '')),
            basename((string)($_SERVER['PHP_SELF'] ?? '')),
        ];
        $parsedRedirect = @parse_url($redirect);
        $redirectPath = is_array($parsedRedirect) && isset($parsedRedirect['path']) ? (string)$parsedRedirect['path'] : $redirect;
        $redirectScript = basename($redirectPath);
        if ($redirectScript !== '' && in_array($redirectScript, $currentScripts, true)) {
            return;
        }

        header('Location: ' . cas_profile_redirect_target($redirect));
        exit;
    }
}
