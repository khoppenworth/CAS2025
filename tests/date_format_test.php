<?php
declare(strict_types=1);

require_once __DIR__ . '/../i18n.php';
require_once __DIR__ . '/../lib/date_format.php';

$_SESSION = [];
$_SESSION['enabled_locales'] = ['en', 'fr'];
$_SESSION['lang'] = 'en';
putenv('APP_TIMEZONE=UTC');

$dateOnly = app_parse_display_datetime('2026-06-02', new DateTimeZone('Pacific/Honolulu'));
if (!$dateOnly || $dateOnly->format('Y-m-d') !== '2026-06-02') {
    fwrite(STDERR, "Date-only values should remain on the same calendar day.\n");
    exit(1);
}

$machine = app_format_machine_datetime('2026-06-02 15:30:00');
if ($machine === '' || strpos($machine, '2026-06-02T15:30:00') !== 0) {
    fwrite(STDERR, "Machine datetime should use ISO/Atom output.\n");
    exit(1);
}

$displayDate = app_format_display_date('2026-06-02', 'en', ['timezone' => 'UTC']);
if ($displayDate === '' || $displayDate === '—') {
    fwrite(STDERR, "Display date should produce a localized fallback.\n");
    exit(1);
}

$displayDateTime = app_format_display_datetime('2026-06-02 15:30:00', 'en', ['timezone' => 'UTC'], 'medium', 'short', true);
if (strpos($displayDateTime, 'UTC') === false && strpos($displayDateTime, 'GMT') === false) {
    fwrite(STDERR, "Display datetime should include a timezone label when requested.\n");
    exit(1);
}

echo "Date formatting tests passed.\n";
