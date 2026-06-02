<?php

declare(strict_types=1);

function app_locale_for_intl(?string $locale = null): string
{
    $candidate = $locale;
    if ($candidate === null || trim($candidate) === '') {
        $candidate = $_SESSION['lang'] ?? null;
    }
    if (($candidate === null || trim((string)$candidate) === '') && function_exists('ensure_locale')) {
        $candidate = ensure_locale();
    }

    $candidate = strtolower(str_replace('-', '_', trim((string)($candidate ?: 'en'))));
    $map = [
        'en' => 'en_US',
        'fr' => 'fr_FR',
        'am' => 'am_ET',
    ];

    return $map[$candidate] ?? $candidate;
}

function app_display_timezone($cfg = null): DateTimeZone
{
    $candidates = [];
    if (is_array($cfg)) {
        foreach (['timezone', 'time_zone', 'site_timezone'] as $key) {
            if (!empty($cfg[$key])) {
                $candidates[] = (string)$cfg[$key];
            }
        }
    }

    foreach (['APP_TIMEZONE', 'SITE_TIMEZONE', 'TZ'] as $envKey) {
        $envValue = getenv($envKey);
        if (is_string($envValue) && trim($envValue) !== '') {
            $candidates[] = $envValue;
        }
    }

    $phpTimezone = date_default_timezone_get();
    if (is_string($phpTimezone) && trim($phpTimezone) !== '') {
        $candidates[] = $phpTimezone;
    }
    $candidates[] = 'UTC';

    foreach ($candidates as $candidate) {
        try {
            return new DateTimeZone(trim((string)$candidate));
        } catch (Throwable $e) {
            continue;
        }
    }

    return new DateTimeZone('UTC');
}

function app_date_style_constant(string $style): int
{
    if (!class_exists('IntlDateFormatter')) {
        return 0;
    }

    return match (strtolower($style)) {
        'none' => IntlDateFormatter::NONE,
        'short' => IntlDateFormatter::SHORT,
        'long' => IntlDateFormatter::LONG,
        'full' => IntlDateFormatter::FULL,
        default => IntlDateFormatter::MEDIUM,
    };
}

function app_parse_display_datetime($value, ?DateTimeZone $timezone = null): ?DateTimeImmutable
{
    if ($value instanceof DateTimeImmutable) {
        return $timezone ? $value->setTimezone($timezone) : $value;
    }
    if ($value instanceof DateTimeInterface) {
        $dt = DateTimeImmutable::createFromInterface($value);
        return $timezone ? $dt->setTimezone($timezone) : $dt;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $timezone = $timezone ?: app_display_timezone();
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }

    try {
        $dt = new DateTimeImmutable($raw, $timezone);
        return $dt->setTimezone($timezone);
    } catch (Throwable $e) {
        return null;
    }
}

function app_format_display_date($value, ?string $locale = null, $cfg = null, string $dateStyle = 'medium', string $fallback = '—'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    $timezone = app_display_timezone($cfg);
    $dt = app_parse_display_datetime($value, $timezone);
    if (!$dt) {
        return (string)$value;
    }

    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter(
            app_locale_for_intl($locale),
            app_date_style_constant($dateStyle),
            IntlDateFormatter::NONE,
            $timezone->getName()
        );
        if ($formatter instanceof IntlDateFormatter) {
            $formatted = $formatter->format($dt);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }
    }

    return $dt->format('M j, Y');
}

function app_format_display_datetime($value, ?string $locale = null, $cfg = null, string $dateStyle = 'medium', string $timeStyle = 'short', bool $includeTimezone = false, string $fallback = '—'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    $timezone = app_display_timezone($cfg);
    $dt = app_parse_display_datetime($value, $timezone);
    if (!$dt) {
        return (string)$value;
    }

    $formatted = '';
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter(
            app_locale_for_intl($locale),
            app_date_style_constant($dateStyle),
            app_date_style_constant($timeStyle),
            $timezone->getName()
        );
        if ($formatter instanceof IntlDateFormatter) {
            $result = $formatter->format($dt);
            if (is_string($result) && $result !== '') {
                $formatted = $result;
            }
        }
    }

    if ($formatted === '') {
        $formatted = $dt->format('M j, Y g:i a');
    }

    if ($includeTimezone) {
        $formatted .= ' ' . $dt->format('T');
    }

    return $formatted;
}

function app_format_machine_date($value, string $fallback = ''): string
{
    $dt = app_parse_display_datetime($value, app_display_timezone());
    return $dt ? $dt->format('Y-m-d') : $fallback;
}

function app_format_machine_datetime($value, string $fallback = ''): string
{
    $dt = app_parse_display_datetime($value, app_display_timezone());
    return $dt ? $dt->format(DateTimeInterface::ATOM) : $fallback;
}
