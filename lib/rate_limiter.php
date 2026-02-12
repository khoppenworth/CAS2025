<?php
declare(strict_types=1);

/**
 * Lightweight IP-based rate limiter to slow abusive traffic and support DDoS resilience.
 */
function enforce_rate_limit(array $server, ?int $limit = null, ?int $intervalSeconds = null): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $limit = $limit ?? (int) (getenv('RATE_LIMIT_REQUESTS') ?: 240);
    $intervalSeconds = $intervalSeconds ?? (int) (getenv('RATE_LIMIT_WINDOW_SECONDS') ?: 60);

    if ($limit <= 0 || $intervalSeconds <= 0) {
        return;
    }

    $clientIp = rate_limit_client_ip($server);
    $sessionHint = rate_limit_session_hint($server);
    $userAgent = trim((string)($server['HTTP_USER_AGENT'] ?? ''));
    $requestUri = trim((string)($server['REQUEST_URI'] ?? ''));

    $storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hrassess-rate-limiter';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
        return;
    }

    $keySeed = implode('|', [$clientIp, $sessionHint, $userAgent, $requestUri]);
    $key = hash('sha256', $keySeed);
    $filePath = $storageDir . DIRECTORY_SEPARATOR . $key . '.json';

    $now = time();
    $windowStart = $now - $intervalSeconds;

    $handle = @fopen($filePath, 'c+');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        $contents = stream_get_contents($handle);
        $records = [];
        if ($contents !== false && $contents !== '') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $records = array_values(array_filter($decoded, static fn($ts) => is_int($ts) && $ts >= $windowStart));
            }
        }

        if (count($records) >= $limit) {
            send_rate_limited_response($intervalSeconds);
        }

        $records[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        $encoded = json_encode($records);
        if ($encoded !== false) {
            fwrite($handle, $encoded);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function rate_limit_client_ip(array $server): string
{
    $candidates = [
        (string)($server['HTTP_CF_CONNECTING_IP'] ?? ''),
        (string)($server['HTTP_X_REAL_IP'] ?? ''),
        (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string)($server['REMOTE_ADDR'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        $trimmed = trim($candidate);
        if ($trimmed === '') {
            continue;
        }

        if (strpos($trimmed, ',') !== false) {
            $parts = array_map('trim', explode(',', $trimmed));
            $trimmed = (string)($parts[0] ?? '');
        }

        if ($trimmed !== '') {
            return $trimmed;
        }
    }

    return 'unknown';
}

function rate_limit_session_hint(array $server): string
{
    $cookieHeader = (string)($server['HTTP_COOKIE'] ?? '');
    if ($cookieHeader === '') {
        return '';
    }

    foreach (explode(';', $cookieHeader) as $cookiePart) {
        $pair = explode('=', trim($cookiePart), 2);
        $key = trim((string)($pair[0] ?? ''));
        if ($key === 'PHPSESSID') {
            return trim((string)($pair[1] ?? ''));
        }
    }

    return '';
}

function send_rate_limited_response(int $intervalSeconds): void
{
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: ' . max(1, $intervalSeconds));
    echo 'Too many requests. Please try again later.';
    exit;
}
