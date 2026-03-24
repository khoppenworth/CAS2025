# UAT Execution Report — March 24, 2026

Environment: `https://epss.systemsdelight.com`  
Accounts used:
- Admin: `admin`
- Respondent: `staff`

## Scope
- Questionnaire Builder workflow integrity (controls, save/reload behavior, key actions).
- User data-entry workflow integrity (respondent login, assessment load, answer entry, draft persistence path).

## Method
- Browser automation (Playwright, headless Chromium) executed from the test environment.
- Live execution against production-like environment with explicit permission to modify **Demo Form**.

## Builder checks (Admin)

### Passed
- Admin authentication to dashboard succeeds.
- Builder page loads and renders the workspace.
- Existing form selection works (`Demo Form` can be opened).
- Section-level include-in-scoring checkbox can be toggled in UI state before save.
- Save action succeeds and returns success feedback (`Questionnaires saved`).
- Major workspace controls are present and actionable:
  - Preview questionnaire
  - Export questionnaire
  - Publish
  - Delete questionnaire
  - Delete questionnaire + responses
- Preview opens successfully.

### Failed / Defect reproduced
- **Include in scoring does not persist after save + reload**.
  - Repro:
    1. Open `Demo Form`.
    2. Uncheck section `Include in scoring`.
    3. Save draft.
    4. Reload page and reopen `Demo Form`.
  - Expected: unchecked state persists.
  - Actual: checkbox returns checked (`true`).
  - Additional evidence: save payload contains `"include_in_scoring": false` for the section, but subsequent fetch behavior still reflects `true` state after reload.

## Respondent checks (Staff)

### Passed
- Respondent login works via `/login.php` using `staff / staff123`.
- Redirect to `submit_assessment.php` works.
- Assessment form loads with interactive inputs.
- Data entry works (text/radio/select interactions).
- Save flow works and returns feedback:
  - `Draft saved. You can return to this questionnaire from the same assessment year to continue editing.`
  - URL includes save marker: `saved=draft`.

## Findings summary
- Builder is generally functional for navigation and core actions.
- The reported bug is reproducible on live environment: **section include-in-scoring toggle is not durable across reload**.
- Respondent side is operational for login, data entry, and draft persistence.

## Recommended follow-up
1. Add an explicit capability check in builder UI so section-scoring toggle is only shown when backend storage supports it.
2. Add server-side telemetry around section-scoring persistence path to identify whether persistence is skipped due schema/capability mismatch.
3. Add an automated regression test that verifies:
   - payload submits `include_in_scoring=false`, and
   - a fresh fetch returns the same value for the same section.
