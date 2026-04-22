# Password Validation Implementation

This project enforces password requirements through a shared server-side policy in `lib/security.php` and applies that policy consistently in profile updates and administrator user management.

## 1) Shared policy definition

- `password_policy_pattern()` defines a single regex used across the app.
- Policy rules:
  - minimum 8 characters
  - at least one number **or** symbol
- `password_meets_policy()` wraps the regex check and returns a strict boolean result.

## 2) Where the policy is enforced

### Profile password set/reset (`profile.php`)

When a signed-in user updates their profile:

- If `must_reset_password` is set, a non-empty password is required before they can continue.
- If a password is provided, it must pass `password_meets_policy()`.
- On success, the password is hashed with `password_hash(..., PASSWORD_DEFAULT)` and `must_reset_password` is cleared.

### Admin user creation and password resets (`admin/users.php`)

When administrators create or update users:

- Create flow requires username + password and validates password via `password_meets_policy()`.
- Reset flow validates new passwords (when provided) with the same policy.
- Passwords are persisted only as hashes via `password_hash(..., PASSWORD_DEFAULT)`.
- The admin flows set `must_reset_password = 1` when appropriate to force users through a first-login/next-login password change workflow.

## 3) Login behavior related to validation

`login.php` and `admin/login.php` verify credentials using `password_verify()` against stored hashes.

If `must_reset_password` is enabled for an authenticated user, both login flows redirect to `profile.php?force_password_reset=1`, where the policy enforcement occurs.

## 4) User-facing policy message

The same message key (`password_policy_invalid`) is used so users see a consistent explanation of requirements:

> "Password must be at least 8 characters and include at least one number or symbol."

Translations are maintained in `lang/en.json`, `lang/fr.json`, and `lang/am.json`.
