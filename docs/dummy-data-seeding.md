# Demo data seeding behavior

CAS includes a synthetic dataset for demonstrations, training, screenshots, and analytics QA. Demo records are identifiable by `demo_*` / `dummy_*` usernames and are designed to be removable without touching real questionnaire definitions or EPSS location master data.

## Recommended method: Admin → Settings

Administrators can enable or disable the full demo dataset from **Admin → Settings**.

### Enable Demo Dataset

Enabling the dataset executes `dummy_data.sql`. The seed is idempotent: it first removes prior demo/dummy records and then rebuilds the synthetic dataset.

The current full seed:

- Creates one synthetic supervisor and **80 synthetic staff**.
- Uses non-routable `example.invalid` email addresses.
- Populates complete profile fields including gender, job grade, education, experience bands, profile/business role, department, team and work role.
- Distributes staff across **all active records in `epss_location`**, including EPSS HQ and all active hubs. With the standard 20-location EPSS master, this produces four synthetic staff per physical location.
- Reads the existing department and team catalogues rather than inventing a separate demo organizational hierarchy.
- Assigns synthetic staff to every existing draft/published questionnaire that has active items.
- Creates synthetic assessments for **2024–2028**, so the current 2026–2028 trend dashboard has multiple populated periods while earlier years remain available for demonstration.
- Intentionally leaves a small share of staff/questionnaire/year combinations without an assessment so completion/coverage is not unrealistically 100%.
- Creates both submitted and approved responses with varied scores across performance bands and an upward multi-year trend.
- Populates `questionnaire_response_item` with valid synthetic answers for Likert, boolean, single/multiple choice, and text-like questions. This is required for capacity-area, section-gap and heatmap analytics that calculate from individual answers rather than response-header scores alone.
- Does **not** create, delete, rename, move or edit `epss_location` records.

Because location analytics use the live EPSS location master, an active location without verified coordinates can still appear in location comparison tables but will remain unmapped until its coordinates are administratively verified. The demo seed does not invent coordinates to fill that gap.

### Disable Demo Dataset

Disabling the dataset executes `dummy_data_cleanup.sql`. It removes demo/dummy assignments, responses, response items, training recommendations, analytics snapshots/schedules and demo users while preserving questionnaire definitions and location master data. Metadata foreign-key references such as reviewer/assigner/snapshot creator pointers are cleared before demo users are removed.

## Expected analytics coverage

After enabling the demo dataset, the following views should have synthetic data where the corresponding questionnaire structure exists:

- Analytics Overview KPIs and annual trends.
- Directorate/department and work-role comparisons.
- Questionnaire performance comparisons.
- Capacity Areas & Gaps and section-level scoring.
- Department/capacity heatmaps.
- Questionnaire Analysis and granular response exports.
- Locations & GIS comparison metrics for all active work locations.
- Gender and other demographic distributions used by reporting views.

A questionnaire with no scorable sections/items can still have overall synthetic response scores, but a section-specific chart naturally depends on that questionnaire actually defining scorable sections/items.

## Regression check

Run:

```bash
php tests/demo_dataset_sql_test.php
```

The contract test verifies that the full seed still includes granular response items, work-location coverage, 2026–2028 trend periods, synthetic staff identifiers, and safeguards that keep the EPSS location master read-only.

## Optional legacy CLI seeder

The repository also contains `scripts/seed_dummy_data_from_questionnaires.php` for command-line development use:

```bash
php ./scripts/seed_dummy_data_from_questionnaires.php
# Optional overrides:
# php ./scripts/seed_dummy_data_from_questionnaires.php --statuses=draft,published --start-year=2020 --end-year=2025
```

That script is a smaller developer-oriented seed and is not the recommended source for the presentation-ready full analytics dataset. Use **Admin → Settings → Enable Demo Dataset** for dashboard demonstrations.

Before using the CLI script, ensure `.env` contains valid `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` values so `config.php` can open the database connection.
