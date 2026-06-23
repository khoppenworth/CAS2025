<?php

declare(strict_types=1);

function assert_contains_ui(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . $needle);
    }
}

$page = file_get_contents(dirname(__DIR__) . '/admin/work_function_defaults.php');
if ($page === false) {
    throw new RuntimeException('Unable to read admin/work_function_defaults.php');
}

assert_contains_ui($page, 'overflow-wrap: anywhere;', 'Catalog and assignment fields should wrap long values instead of overlapping.');
assert_contains_ui($page, 'table-layout: fixed;', 'Assignment tables should keep columns stable while content wraps.');
assert_contains_ui($page, '.md-list-col code { font-size: .85rem; white-space: normal; }', 'Long slugs should wrap in catalog rows.');
assert_contains_ui($page, 'form.classList.add(\'is-saving\');', 'Team forms should expose a saving state on submit.');
assert_contains_ui($page, 'savingMessage.textContent = \'Saving…\';', 'Team forms should show a saving message while submitting.');
assert_contains_ui($page, 'button.disabled = true;', 'Team save buttons should be disabled after submit to prevent duplicate saves.');
assert_contains_ui($page, 'input.value = activePaneId || \'departments\';', 'Saving should preserve the active Teams tab after redirects.');

$teamDefaultsPosition = strpos($page, 'id="team-defaults"');
$teamSaveModePosition = strpos($page, 'name="mode" value="team_assignments_save"');
if ($teamDefaultsPosition === false || $teamSaveModePosition === false || $teamSaveModePosition < $teamDefaultsPosition) {
    throw new RuntimeException('Team Defaults should post using the team assignments save mode inside the Teams defaults pane.');
}

echo "Work function defaults UI checks passed.\n";
