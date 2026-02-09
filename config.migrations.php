<?php
declare(strict_types=1);

if (!function_exists('branding_logo_relative_dir')) {
    function branding_logo_relative_dir(): string
    {
        return 'assets/uploads/branding';
    }
}

if (!function_exists('branding_logo_directory')) {
    function branding_logo_directory(): string
    {
        return base_path(branding_logo_relative_dir());
    }
}

if (!function_exists('ensure_branding_logo_directory')) {
    function ensure_branding_logo_directory(): bool
    {
        $dir = branding_logo_directory();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
        }

        return is_writable($dir);
    }
}

if (!function_exists('normalize_landing_background_path')) {
    function normalize_landing_background_path($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', ltrim($trimmed, '/'));
        $relativeDir = branding_logo_relative_dir();
        $expectedPrefix = $relativeDir . '/';
        if (strpos($normalized, $expectedPrefix) !== 0) {
            return null;
        }

        $filename = basename($normalized);
        if ($filename === '' || preg_match('/[^A-Za-z0-9._-]/', $filename)) {
            return null;
        }

        return $relativeDir . '/' . $filename;
    }
}

if (!function_exists('landing_background_full_path')) {
    function landing_background_full_path(?string $path): ?string
    {
        $normalized = normalize_landing_background_path($path);
        if ($normalized === null) {
            return null;
        }

        return base_path($normalized);
    }
}

if (!function_exists('site_landing_background_path')) {
    function site_landing_background_path(array $cfg): ?string
    {
        $normalized = normalize_landing_background_path($cfg['landing_background_path'] ?? null);
        if ($normalized === null) {
            return null;
        }

        $fullPath = base_path($normalized);
        if (!is_file($fullPath)) {
            return null;
        }

        return $normalized;
    }
}

if (!function_exists('site_landing_background_url')) {
    function site_landing_background_url(array $cfg): string
    {
        $path = site_landing_background_path($cfg);
        if ($path !== null) {
            return asset_url($path);
        }

        return '';
    }
}
