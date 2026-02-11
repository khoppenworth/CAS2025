# Dummy data seeding behavior

The `scripts/seed_dummy_data_from_questionnaires.php` script is intended for demo/training environments.

## What it does

- Selects questionnaires that have at least one active item, no active `likert` items, and at least one auto-gradable `choice` item marked `requires_correct = 1`.
- Ensures a fixed set of `dummy_*` users exists (one supervisor and several staff users).
- Creates/updates the current half-year performance period (`YYYY H1` or `YYYY H2`).
- Deletes prior dummy assignments and responses for `dummy_*` users, then recreates assignments and one response per dummy staff per selected questionnaire.
- Seeds response-item answers and computes a score (graded for `requires_correct` questions; randomized fallback otherwise).

## Scope notes

- It does **not** seed every questionnaire in the system.
- It only seeds questionnaires matching the eligibility filter above.
- Seeded rows are standard `questionnaire_response` records, so they appear in analytics queries unless additional filtering is added.

## Cleanup

Use `dummy_data_cleanup.sql` to remove seeded dummy-user submissions when needed.
