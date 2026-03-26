<?php
declare(strict_types=1);

/**
 * Apply security headers aligned with ISO/IEC 27001 control objectives
 * and OWASP application security guidelines.
 */
function apply_security_headers(bool $isDebugMode = false): void
{
    if (headers_sent()) {
        return;
    }

    $nonce = csp_nonce();

    $scriptSrc = [
        "'self'",
        sprintf("'nonce-%s'", $nonce),
        'https://cdn.jsdelivr.net',
        'https://translate.google.com',
        'https://translate.googleapis.com',
    ];

    if ($isDebugMode) {
        // Allow eval in debug mode so that developer tools continue working.
        $scriptSrc[] = "'unsafe-eval'";
    }

    $styleSrc = [
        "'self'",
        "'unsafe-inline'", // legacy support for third-party stylesheets.
        'https://translate.googleapis.com',
        'https://cdn.jsdelivr.net',
    ];

    $directives = [
        'default-src' => "'self'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'self'",
        'connect-src' => "'self'",
        'img-src' => "'self' data: https:",
        'script-src' => implode(' ', $scriptSrc),
        'style-src' => implode(' ', $styleSrc),
        'font-src' => "'self' data:",
        'object-src' => "'none'",
        'frame-src' => "'self' https://translate.google.com https://translate.googleapis.com https://translate.googleusercontent.com",
    ];

    $csp = [];
    foreach ($directives as $name => $value) {
        $csp[] = $name . ' ' . $value;
    }

    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

/**
 * Retrieve a nonce for inline scripts so they comply with the CSP header.
 */
function csp_nonce(): string
{
    static $nonce;
    if ($nonce === null) {
        $nonce = bin2hex(random_bytes(16));
    }

    return $nonce;
}

/**
 * Shared password policy pattern.
 * - At least 8 characters
 * - Includes at least one number or symbol
 */
function password_policy_pattern(): string
{
    return '/^(?=.{8,}$)(?=.*[\d\W_]).+$/';
}

/**
 * Validate whether a password complies with the shared policy.
 */
function password_meets_policy(string $password): bool
{
    return preg_match(password_policy_pattern(), $password) === 1;
}
