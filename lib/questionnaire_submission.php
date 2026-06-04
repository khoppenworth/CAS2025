<?php
/**
 * Helpers for safely mapping questionnaire item linkIds to PHP form posts.
 */

/**
 * Determine whether a questionnaire linkId is safe to use as a future form key.
 */
function questionnaire_link_id_is_safe(string $linkId): bool
{
    return $linkId !== '' && preg_match('/\A[A-Za-z0-9_-]+\z/', $linkId) === 1;
}

/**
 * Return the way PHP normalizes request variable names for simple form fields.
 */
function questionnaire_php_request_key(string $name): string
{
    return str_replace(['.', ' '], '_', $name);
}


/**
 * Return the PHP-visible POST key for a questionnaire item linkId.
 */
function questionnaire_post_item_form_key(string $linkId): string
{
    return questionnaire_php_request_key('item_' . $linkId);
}

/**
 * Build possible POST keys for an item linkId, including legacy unsafe names.
 *
 * @return array<int, string>
 */
function questionnaire_post_item_keys(string $linkId): array
{
    $raw = 'item_' . $linkId;
    $keys = [$raw, questionnaire_post_item_form_key($linkId)];
    return array_values(array_unique(array_filter($keys, static fn($key) => $key !== '')));
}

/**
 * Read an item value from $_POST while tolerating legacy linkIds containing dots/spaces.
 *
 * @param array<string, mixed> $postData
 * @param mixed $default
 * @return mixed
 */
function questionnaire_post_item_value(array $postData, string $linkId, $default = null)
{
    foreach (questionnaire_post_item_keys($linkId) as $key) {
        if (array_key_exists($key, $postData)) {
            return $postData[$key];
        }
    }
    return $default;
}

/**
 * Check whether an item value exists in POST while tolerating legacy unsafe linkIds.
 *
 * @param array<string, mixed> $postData
 */
function questionnaire_post_item_exists(array $postData, string $linkId): bool
{
    foreach (questionnaire_post_item_keys($linkId) as $key) {
        if (array_key_exists($key, $postData)) {
            return true;
        }
    }
    return false;
}

/**
 * Normalize a linkId or submitted item field name for condition comparisons.
 */
function questionnaire_submission_normalize_link_id(string $value): string
{
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }
    if (str_starts_with(strtolower($normalized), 'item_')) {
        $normalized = substr($normalized, 5);
    }
    if (str_ends_with($normalized, '[]')) {
        $normalized = substr($normalized, 0, -2);
    }
    $normalized = questionnaire_php_request_key($normalized);
    return strtolower(trim($normalized));
}
