<?php

declare(strict_types=1);

function assert_contains_profile_gender(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . $needle);
    }
}

$root = dirname(__DIR__);
$config = file_get_contents($root . '/config.php');
$profile = file_get_contents($root . '/profile.php');
$integrity = file_get_contents($root . '/scripts/check_database_integrity.php');

if ($config === false || $profile === false || $integrity === false) {
    throw new RuntimeException('Unable to read files for profile gender resilience checks.');
}

assert_contains_profile_gender($config, "'gender' => \"ALTER TABLE users ADD COLUMN gender", 'User schema bootstrap must add the gender column for upgraded databases.');
assert_contains_profile_gender($config, "'date_of_birth' => 'ALTER TABLE users ADD COLUMN date_of_birth", 'User schema bootstrap must add the date_of_birth column for upgraded databases.');
assert_contains_profile_gender($integrity, "'gender' =>", 'Database integrity checks must include the users.gender column.');
assert_contains_profile_gender($integrity, "'date_of_birth' =>", 'Database integrity checks must include the users.date_of_birth column.');
assert_contains_profile_gender($profile, 'profile_normalize_gender_options($cfg', 'Profile should use the safe gender normalization wrapper.');
assert_contains_profile_gender($profile, 'profile_gender_option_labels($t)', 'Profile should use the safe gender label wrapper.');

define('APP_BOOTSTRAPPED', true);
require_once $root . '/config.php';
$options = normalize_gender_options('["female","invalid","prefer_not_say"]');
if ($options !== ['female', 'prefer_not_say']) {
    throw new RuntimeException('Global gender option normalization did not filter invalid options as expected.');
}

$labels = gender_option_labels([]);
foreach (['female', 'male', 'other', 'prefer_not_say'] as $key) {
    if (!isset($labels[$key])) {
        throw new RuntimeException('Missing gender label: ' . $key);
    }
}

echo "Profile gender resilience tests passed.\n";
