# Date Field & Formatting Review

## Current state

### Database-level date/datetime fields
- `users.date_of_birth` and `users.next_assessment_date` are typed as `DATE` in schema and migrations, which is good for date-only business values. 
- Most audit/system fields (`created_at`, `updated_at`, `first_login_at`, `reviewed_at`) are `DATETIME`/`TIMESTAMP` and represent server-side event time.

### Server-side display formatting
- Several places format dates with fixed PHP patterns like `Y-m-d`, `Y-m-d H:i`, or `F j, Y g:i a`.
- This creates mixed presentation styles and ties output to server decisions instead of end-user locale/timezone.

### Client-side formatting
- There is already at least one client-side formatter using `Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })`, which automatically picks locale-friendly formatting from the browser.

## Can the system pick client-side data format?

Yes — for **display formatting**, the frontend can and should pick client-side format using browser locale and timezone.

Recommended split of responsibilities:
- **Backend transport/storage format (canonical):**
  - Date only: `YYYY-MM-DD`
  - Date-time: ISO 8601 with timezone (prefer UTC source, e.g. `2026-05-18T14:30:00Z`)
- **Frontend display format:**
  - Use `Intl.DateTimeFormat` with `undefined` locale (or user-selected locale)
  - Let the browser format according to user regional settings.

## Best approach

1. **Standardize outbound date payloads**
   - Keep DB as-is for typed fields.
   - Normalize API/template-provided timestamps to ISO 8601 where possible.

2. **Introduce shared formatting helpers**
   - JS utility: `formatDate(value, { dateStyle, timeStyle, timeZone })`
   - PHP utility: for non-JS surfaces (PDF/email/export), define one shared formatter per output type so patterns are centralized.

3. **Apply by surface**
   - **Interactive UI:** client-side `Intl` formatting.
   - **CSV exports:** machine-friendly fixed format (ISO-like), clearly documented.
   - **Emails/PDFs:** explicit stable format (and, if relevant, include timezone label).

4. **Timezone policy**
   - Store datetimes in UTC (or treat DB values as UTC consistently).
   - Convert only for display.

5. **Validation policy**
   - Keep `<input type="date">` fields bound to `YYYY-MM-DD`.
   - Avoid locale-formatted strings for data submission; locale formatting is display-only.

## Practical migration plan

- Step 1: Identify all direct `date()`/`format()` presentation calls and classify by output surface (UI, export, PDF, email).
- Step 2: Replace UI-facing embedded formatted strings with raw ISO values + client-side formatter.
- Step 3: Consolidate non-UI formats in one PHP helper per surface.
- Step 4: Add regression tests for parsing/formatting around timezone boundaries.

## Summary recommendation

Use a **canonical wire format + localized client rendering** architecture:
- Canonical data in server responses (`YYYY-MM-DD` for date-only, ISO 8601 for datetime)
- Localized display in browser via `Intl.DateTimeFormat`
- Explicit fixed formats only for exports and generated documents.

This gives consistency, locale correctness, and fewer date bugs.
