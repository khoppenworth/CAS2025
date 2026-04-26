# Dummy data seeding behavior

The `scripts/seed_dummy_data_from_questionnaires.php` script is intended for demo/training environments.

## How to run

From the project root:

```bash
php ./scripts/seed_dummy_data_from_questionnaires.php
# Optional overrides:
# php ./scripts/seed_dummy_data_from_questionnaires.php --statuses=draft,published --start-year=2020 --end-year=2025
```

Before running, ensure your `.env` has valid `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` values so `config.php` can open a database connection.

## What it does

- Defaults to seeding both `draft` and `published` questionnaires (override with `--statuses=<comma-separated-statuses>`).
- Selects all questionnaires in the selected statuses that have at least one active item.
- Ensures a fixed set of `demo_*` users exists (one supervisor and several staff users).
- Creates/updates annual performance periods for the selected year range (default `2020`–`2025`, override with `--start-year` and `--end-year`).
- Deletes prior demo assignments and responses for `demo_*` users, then recreates assignments and one response per demo staff per selected questionnaire.
- Seeds response-item answers and computes a score (graded for `requires_correct` questions; randomized fallback otherwise), with assignment/response timestamps spread across the selected year range.

## Scope notes

- It seeds all questionnaires in the selected statuses that have active items.
- Seeded rows are standard `questionnaire_response` records, so they appear in analytics queries unless additional filtering is added.

## Cleanup

Use `dummy_data_cleanup.sql` to remove seeded demo-user submissions when needed. The cleanup script removes both `demo_*` and `dummy_*` users and their related records, and does not delete questionnaire definitions.


## Admin toggle

Administrators can now enable or disable the full demo dataset directly from **Admin → Settings**:

- **Enable Demo Dataset** executes `dummy_data.sql` (fictive demo users plus generated assignments/responses for existing draft/published questionnaires; no questionnaire creation/deletion).
- **Disable Demo Dataset** executes `dummy_data_cleanup.sql` (removes demo/dummy records without touching questionnaire definitions).
