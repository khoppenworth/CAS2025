# Department/Team/Work-Role Migration (Existing Databases)

Use this when upgrading an existing environment that already has users and questionnaires.

## Script

Run:

```sql
SOURCE migration_department_model.sql;
```

## What it migrates

- Creates/updates:
  - `department_catalog`
  - `department_team_catalog` (with `department_slug`)
  - `questionnaire_department`
- Copies questionnaire defaults from `questionnaire_work_function` to `questionnaire_department`.
- Converts `users.department` to department slug values.
- Creates department-linked team rows and converts `users.cadre` to team slug values.
- Collapses `users.work_function` to role values:
  - `expert`
  - `director_manager`
- Keeps only those two active entries in `work_function_catalog`.

## Notes

- The script is idempotent and can be re-run.
- Team slug format is `department_slug__team_slug` to avoid collisions across departments.
- For users without a department, the migration assigns `general_service`.
