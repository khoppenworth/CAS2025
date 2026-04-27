<?php

declare(strict_types=1);

function app_smtp_config(array $cfg): array
{
    $enabled = (int)($cfg['smtp_enabled'] ?? 0) === 1;
    $host = trim((string)($cfg['smtp_host'] ?? ''));
    $portRaw = (int)($cfg['smtp_port'] ?? 0);
    if ($portRaw <= 0) {
        $portRaw = 587;
    }
    $encryption = strtolower(trim((string)($cfg['smtp_encryption'] ?? 'none')));
    if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
        $encryption = 'none';
    }
    $fromEmail = trim((string)($cfg['smtp_from_email'] ?? ''));
    if ($fromEmail === '' && isset($cfg['footer_email'])) {
        $fromEmail = trim((string)$cfg['footer_email']);
    }
    $fromName = trim((string)($cfg['smtp_from_name'] ?? ''));
    if ($fromName === '' && isset($cfg['site_name'])) {
        $fromName = trim((string)$cfg['site_name']);
    }

    return [
        'enabled' => $enabled,
        'host' => $host,
        'port' => $portRaw,
        'username' => (string)($cfg['smtp_username'] ?? ''),
        'password' => (string)($cfg['smtp_password'] ?? ''),
        'encryption' => $encryption,
        'timeout' => (int)($cfg['smtp_timeout'] ?? 20),
        'from_email' => $fromEmail,
        'from_name' => $fromName,
    ];
}

function mail_html_to_text(string $html): string
{
    $normalized = preg_replace([
        '/<\s*br\s*\/?\s*>/i',
        '/<\s*\/(p|div)\s*>/i',
        '/<\s*li\s*>/i',
        '/<\s*\/li\s*>/i',
        '/<\s*\/h[1-6]\s*>/i',
    ], [
        "\n",
        "\n\n",
        ' - ',
        "\n",
        "\n\n",
    ], $html);
    if ($normalized === null) {
        $normalized = $html;
    }
    $stripped = strip_tags($normalized);
    $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = preg_split('/\r\n|\r|\n/', $decoded) ?: [];
    $lines = array_map(static fn($line) => rtrim((string)$line), $lines);
    return trim(implode("\n", $lines));
}

function send_notification_email(array $cfg, $recipients, $subject, $body, array $attachments = []): bool
{
    $smtp = app_smtp_config($cfg);
    if (!$smtp['enabled']) {
        return false;
    }
    if ($smtp['host'] === '' || $smtp['from_email'] === '') {
        error_log('SMTP configuration incomplete – host or from_email missing.');
        return false;
    }

    $list = [];
    if (is_string($recipients)) {
        $list = array_filter(array_map('trim', explode(',', $recipients)));
    } elseif (is_array($recipients)) {
        foreach ($recipients as $recipient) {
            if (!is_string($recipient)) {
                continue;
            }
            $addr = trim($recipient);
            if ($addr !== '') {
                $list[] = $addr;
            }
        }
    }
    $list = array_values(array_unique($list));
    if (!$list) {
        error_log('No recipients provided for notification email.');
        return false;
    }

    $bodyText = '';
    $bodyHtml = null;
    if (is_array($body)) {
        $bodyText = isset($body['text']) ? (string)$body['text'] : '';
        $bodyHtml = isset($body['html']) && $body['html'] !== '' ? (string)$body['html'] : null;
    } else {
        $bodyText = (string)$body;
    }
    if (($bodyHtml !== null && trim($bodyHtml) !== '') && trim($bodyText) === '') {
        $bodyText = mail_html_to_text($bodyHtml);
    }

    $subjectLine = trim((string)$subject);
    if ($subjectLine === '') {
        $subjectLine = 'Notification';
    }

    try {
        return smtp_send($smtp, $list, $subjectLine, ['text' => $bodyText, 'html' => $bodyHtml], $attachments);
    } catch (Throwable $e) {
        error_log('SMTP send failed: ' . $e->getMessage());
        return false;
    }
}

function smtp_send(array $smtp, array $recipients, $subject, array $body, array $attachments = []): bool
{
    $subject = trim((string)$subject);
    if ($subject === '') {
        $subject = 'Notification';
    }

    $host = $smtp['host'];
    $port = (int)$smtp['port'];
    if ($port <= 0) {
        $port = ($smtp['encryption'] === 'ssl') ? 465 : 587;
    }
    $timeout = max(5, (int)$smtp['timeout']);

    $remoteHost = $host;
    if ($smtp['encryption'] === 'ssl') {
        if (strpos($host, 'ssl://') !== 0 && strpos($host, 'tls://') !== 0) {
            $remoteHost = 'ssl://' . $host;
        }
    }

    $stream = @stream_socket_client($remoteHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]));
    if (!$stream) {
        throw new RuntimeException('Unable to connect to SMTP server: ' . $errstr . ' (' . $errno . ')');
    }
    stream_set_timeout($stream, $timeout);

    $greeting = smtp_read_response($stream);
    if ($greeting[0] >= 400) {
        throw new RuntimeException('SMTP greeting failed: ' . $greeting[1]);
    }

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    smtp_expect($stream, 'EHLO ' . $ehloHost, [250], 'EHLO');

    if ($smtp['encryption'] === 'tls') {
        smtp_expect($stream, 'STARTTLS', [220], 'STARTTLS');
        if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Unable to start TLS encryption with SMTP server.');
        }
        smtp_expect($stream, 'EHLO ' . $ehloHost, [250], 'EHLO after STARTTLS');
    }

    if ($smtp['username'] !== '') {
        smtp_expect($stream, 'AUTH LOGIN', [235, 334], 'AUTH LOGIN (init)');
        $authResponse = smtp_read_last_response_code();
        if ($authResponse === 334) {
            smtp_expect($stream, base64_encode($smtp['username']), [334], 'SMTP username');
            smtp_expect($stream, base64_encode($smtp['password']), [235], 'SMTP password');
        }
    }

    smtp_expect($stream, 'MAIL FROM:<' . smtp_escape_address($smtp['from_email']) . '>', [250], 'MAIL FROM');
    foreach ($recipients as $recipient) {
        smtp_expect($stream, 'RCPT TO:<' . smtp_escape_address($recipient) . '>', [250, 251], 'RCPT TO');
    }

    smtp_expect($stream, 'DATA', [354], 'DATA');

    $headers = [];
    $fromName = $smtp['from_name'] !== '' ? $smtp['from_name'] : $smtp['from_email'];
    $encodedSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n") : $subject;
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . smtp_format_address($smtp['from_email'], $fromName);
    $headers[] = 'To: ' . implode(', ', array_map(static function ($email) {
        return smtp_format_address($email, '');
    }, $recipients));
    $headers[] = 'Subject: ' . $encodedSubject;
    $headers[] = 'Message-ID: <' . uniqid('', true) . '@' . ($ehloHost ?: 'localhost') . '>';

    $textBody = isset($body['text']) ? (string)$body['text'] : '';
    $htmlBody = isset($body['html']) ? (string)$body['html'] : '';
    $hasHtmlBody = trim($htmlBody) !== '';
    $hasTextBody = trim($textBody) !== '';
    if (!$hasTextBody && $hasHtmlBody) {
        $textBody = mail_html_to_text($htmlBody);
        $hasTextBody = trim($textBody) !== '';
    }
    if (!$hasTextBody && !$hasHtmlBody) {
        $hasTextBody = true;
    }
    $splitLines = static function (string $content): array {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        return explode("\n", $normalized);
    };
    $hasAttachments = !empty($attachments);
    $lines = $headers;
    if ($hasAttachments) {
        $boundary = '=_mixed_' . bin2hex(random_bytes(12));
        $altBoundary = '=_alt_' . bin2hex(random_bytes(12));
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $lines[] = '';
        $lines[] = 'This is a multi-part message in MIME format.';
        $lines[] = '';
        if ($hasHtmlBody) {
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
            $lines[] = '';
            if ($hasTextBody) {
                $lines[] = '--' . $altBoundary;
                $lines[] = 'Content-Type: text/plain; charset=UTF-8';
                $lines[] = 'Content-Transfer-Encoding: 8bit';
                $lines[] = '';
                foreach ($splitLines($textBody) as $bodyLine) {
                    $lines[] = $bodyLine;
                }
            }
            $lines[] = '--' . $altBoundary;
            $lines[] = 'Content-Type: text/html; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            foreach ($splitLines($htmlBody) as $bodyLine) {
                $lines[] = $bodyLine;
            }
            $lines[] = '';
            $lines[] = '--' . $altBoundary . '--';
        } else {
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            foreach ($splitLines($textBody) as $bodyLine) {
                $lines[] = $bodyLine;
            }
        }
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $filename = isset($attachment['filename']) ? (string)$attachment['filename'] : 'attachment.bin';
            $contentType = isset($attachment['content_type']) ? (string)$attachment['content_type'] : 'application/octet-stream';
            $content = isset($attachment['content']) ? (string)$attachment['content'] : '';
            if ($content === '') {
                continue;
            }
            $sanitizedFilename = smtp_sanitize_filename($filename);
            $lines[] = '';
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: ' . $contentType . '; name="' . $sanitizedFilename . '"';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = 'Content-Disposition: attachment; filename="' . $sanitizedFilename . '"';
            $lines[] = '';
            $encoded = rtrim(chunk_split(base64_encode($content)));
            foreach (explode("\n", $encoded) as $encodedLine) {
                $lines[] = $encodedLine;
            }
        }
        $lines[] = '';
        $lines[] = '--' . $boundary . '--';
    } else {
        if ($hasHtmlBody) {
            $boundary = '=_alt_' . bin2hex(random_bytes(12));
            $lines[] = 'MIME-Version: 1.0';
            $lines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $lines[] = '';
            if ($hasTextBody) {
                $lines[] = '--' . $boundary;
                $lines[] = 'Content-Type: text/plain; charset=UTF-8';
                $lines[] = 'Content-Transfer-Encoding: 8bit';
                $lines[] = '';
                foreach ($splitLines($textBody) as $bodyLine) {
                    $lines[] = $bodyLine;
                }
            }
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: text/html; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            foreach ($splitLines($htmlBody) as $bodyLine) {
                $lines[] = $bodyLine;
            }
            $lines[] = '';
            $lines[] = '--' . $boundary . '--';
        } else {
            $lines[] = 'MIME-Version: 1.0';
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            foreach ($splitLines($textBody) as $bodyLine) {
                $lines[] = $bodyLine;
            }
        }
    }

    foreach ($lines as $line) {
        $normalized = rtrim((string)$line, "\r\n");
        if ($normalized !== '' && $normalized[0] === '.') {
            $normalized = '.' . $normalized;
        }
        fwrite($stream, $normalized . "\r\n");
    }
    fwrite($stream, ".\r\n");

    $dataResult = smtp_read_response($stream);
    if ($dataResult[0] >= 400) {
        throw new RuntimeException('SMTP DATA failed: ' . $dataResult[1]);
    }

    smtp_expect($stream, 'QUIT', [221], 'QUIT');
    fclose($stream);
    return true;
}

function smtp_expect($stream, string $command, array $expectedCodes, string $context): void
{
    fwrite($stream, $command . "\r\n");
    $response = smtp_read_response($stream);
    $code = $response[0];
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException($context . ' failed with code ' . $code . ': ' . $response[1]);
    }
    smtp_store_last_response_code($code);
}

function smtp_read_response($stream): array
{
    $response = '';
    while (($line = fgets($stream, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4) {
            break;
        }
        if ($line[3] === ' ') {
            break;
        }
    }
    $code = (int)substr($response, 0, 3);
    return [$code, trim($response)];
}

function smtp_escape_address(string $address): string
{
    return str_replace(['\r', '\n'], '', $address);
}

function smtp_format_address(string $email, string $name): string
{
    $email = smtp_escape_address($email);
    $cleanName = trim(str_replace(["\r", "\n"], '', $name));
    if ($cleanName === '') {
        return '<' . $email . '>';
    }
    $encoded = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($cleanName, 'UTF-8', 'B', "\r\n") : addslashes($cleanName);
    return $encoded . ' <' . $email . '>';
}

function smtp_sanitize_filename(string $filename): string
{
    $clean = trim(str_replace(['"', '\\'], '', str_replace(["\r", "\n"], '', $filename)));
    return $clean !== '' ? $clean : 'attachment.bin';
}

function smtp_store_last_response_code(int $code): void
{
    $GLOBALS['__smtp_last_code'] = $code;
}

function smtp_read_last_response_code(): int
{
    return (int)($GLOBALS['__smtp_last_code'] ?? 0);
}
