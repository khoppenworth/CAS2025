<?php
require_once __DIR__ . '/enterprise_runtime.php';
function base_path(string $path = ''): string {
    return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function asset_url(string $path): string {
    $base = rtrim(BASE_URL, '/');
    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

function url_for(string $path = ''): string {
    $trimmed = ltrim($path, '/');
    $base = rtrim(BASE_URL, '/');
    if ($trimmed === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $trimmed;
}

function cleanRedirect(?string $target, ?string $default = null): string {
    $fallback = $default;
    if ($fallback === null || trim($fallback) === '') {
        $fallback = url_for('');
    }

    $target = trim((string)($target ?? ''));
    if ($target === '') {
        return $fallback;
    }

    $target = str_replace(["\r", "\n"], '', $target);
    $parsed = @parse_url($target);
    if ($parsed === false) {
        return $fallback;
    }

    $scheme = $parsed['scheme'] ?? '';
    if ($scheme !== '' && !in_array(strtolower($scheme), ['http', 'https'], true)) {
        return $fallback;
    }

    $base = rtrim(BASE_URL, '/');
    $baseForParse = $base === '' ? '/' : $base;
    $baseParts = @parse_url($baseForParse);
    $allowedHost = $baseParts['host'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    $allowedPort = $baseParts['port'] ?? (isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : null);

    if (isset($parsed['host'])) {
        if ($allowedHost === '' || strcasecmp($parsed['host'], $allowedHost) !== 0) {
            return $fallback;
        }
        if (isset($parsed['port']) && $allowedPort !== null && (int)$parsed['port'] !== $allowedPort) {
            return $fallback;
        }
        if ($scheme === '') {
            return $fallback;
        }
    } elseif ($scheme !== '') {
        return $fallback;
    }

    $path = $parsed['path'] ?? '';
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    $cleanPath = '/' . implode('/', $segments);
    if ($path === '' || $path === '/') {
        $cleanPath = '/';
    }

    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

    $basePath = $baseParts['path'] ?? '';
    if ($basePath !== '' && $basePath !== '/') {
        if ($basePath[0] !== '/') {
            $basePath = '/' . $basePath;
        }
        $normalizedBase = rtrim($basePath, '/');
        if ($normalizedBase !== '' && strpos($cleanPath, $normalizedBase) !== 0) {
            if ($cleanPath === '/') {
                $cleanPath = $normalizedBase . '/';
            } else {
                $cleanPath = $normalizedBase . $cleanPath;
            }
        }
    }

    $result = $cleanPath . $query . $fragment;
    if ($result === '' || $result[0] !== '/') {
        return $fallback;
    }

    return $result;
}
?>
