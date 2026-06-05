<?php

declare(strict_types=1);

function assert_contains(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . $needle);
    }
}

function assert_not_contains(string $haystack, string $needle, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nUnexpected: " . $needle);
    }
}

/**
 * @return list<string>
 */
function extract_settings_fields(string $settingsPhp): array
{
    if (!preg_match('/\$fields\s*=\s*\[(.*?)\n\s*\];/s', $settingsPhp, $matches)) {
        throw new RuntimeException('Unable to locate the Settings save field map.');
    }

    preg_match_all("/'([^']+)'\s*=>/", $matches[1], $fieldMatches);
    $fields = $fieldMatches[1] ?? [];
    if ($fields === []) {
        throw new RuntimeException('Settings save field map should not be empty.');
    }

    return array_values(array_unique($fields));
}

function assert_all_settings_fields_are_schema_backed(): void
{
    $root = dirname(__DIR__);
    $settingsPhp = file_get_contents($root . '/admin/settings.php');
    if ($settingsPhp === false) {
        throw new RuntimeException('Unable to read admin/settings.php');
    }
    $fields = extract_settings_fields($settingsPhp);

    $configPhp = file_get_contents($root . '/config.php');
    $integrityPhp = file_get_contents($root . '/scripts/check_database_integrity.php');
    if ($configPhp === false || $integrityPhp === false) {
        throw new RuntimeException('Unable to read configuration or integrity scripts.');
    }

    foreach ($fields as $field) {
        assert_contains($configPhp, "'" . $field . "' =>", 'config.php should bootstrap and default every Settings save column.');
        assert_contains($integrityPhp, "'" . $field . "' =>", 'Database integrity checks should cover every Settings save column.');
    }

    foreach (['init.sql', 'migration.sql', 'upgrade_to_v3.sql', 'reset_system.sql'] as $file) {
        $sql = file_get_contents($root . '/' . $file);
        if ($sql === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }

        foreach ($fields as $field) {
            assert_contains($sql, $field, $file . ' should include every Settings save column.');
        }
    }

    foreach (['migration.sql', 'upgrade_to_v3.sql'] as $file) {
        $sql = file_get_contents($root . '/' . $file);
        if ($sql === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }

        foreach ($fields as $field) {
            assert_contains($sql, "COLUMN_NAME = '" . $field . "'", $file . ' should backfill missing Settings save columns during upgrades.');
        }
    }
}


/**
 * @return list<string>
 */
function split_sql_csv(string $csv): array
{
    $items = [];
    $buffer = '';
    $inString = false;
    $length = strlen($csv);

    for ($i = 0; $i < $length; $i++) {
        $char = $csv[$i];
        if ($char === "'" && ($i === 0 || $csv[$i - 1] !== '\\')) {
            $inString = !$inString;
            $buffer .= $char;
            continue;
        }

        if ($char === ',' && !$inString) {
            $items[] = trim($buffer);
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    if (trim($buffer) !== '') {
        $items[] = trim($buffer);
    }

    return $items;
}

function assert_site_config_seed_column_value_counts_match(): void
{
    $root = dirname(__DIR__);
    foreach (['init.sql', 'migration.sql', 'upgrade_to_v3.sql', 'reset_system.sql'] as $file) {
        $sql = file_get_contents($root . '/' . $file);
        if ($sql === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }

        if (!preg_match('/(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+site_config\s*\((.*?)\)\s*VALUES\s*\((.*?)\)\s*;/is', $sql, $matches)) {
            throw new RuntimeException($file . ' should seed site_config.');
        }

        $columns = split_sql_csv($matches[1]);
        $values = split_sql_csv($matches[2]);
        if (count($columns) !== count($values)) {
            throw new RuntimeException(sprintf('%s site_config seed has %d columns but %d values.', $file, count($columns), count($values)));
        }
    }
}


function assert_settings_form_exposes_saved_fields(): void
{
    $settingsPhp = file_get_contents(dirname(__DIR__) . '/admin/settings.php');
    if ($settingsPhp === false) {
        throw new RuntimeException('Unable to read admin/settings.php');
    }

    foreach (extract_settings_fields($settingsPhp) as $field) {
        if ($field === 'email_templates') {
            assert_contains($settingsPhp, 'name="email_templates[', 'Settings form should expose email template fields.');
            continue;
        }

        assert_contains($settingsPhp, 'name="' . $field . '"', 'Settings form should expose every Settings save field.');
    }
}

function assert_settings_save_fails_safely_when_columns_are_missing(): void
{
    $settingsPhp = file_get_contents(dirname(__DIR__) . '/admin/settings.php');
    if ($settingsPhp === false) {
        throw new RuntimeException('Unable to read admin/settings.php');
    }

    assert_contains($settingsPhp, '$missingSettingColumns = [];', 'Settings save should collect missing schema columns.');
    assert_contains($settingsPhp, 'settings_columns_missing_notice', 'Settings save should show a localized missing-column error.');
    assert_not_contains($settingsPhp, 'if (isset($requiredToggleColumns[$column]))', 'Settings save should not only guard the three toggle fields.');
}

function run_settings_page_quality_tests(): void
{
    assert_all_settings_fields_are_schema_backed();
    assert_site_config_seed_column_value_counts_match();
    assert_settings_form_exposes_saved_fields();
    assert_settings_save_fails_safely_when_columns_are_missing();
}

run_settings_page_quality_tests();

echo "Settings page quality tests passed.\n";
