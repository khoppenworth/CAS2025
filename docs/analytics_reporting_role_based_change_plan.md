# Analytics Reporting Change Plan (Role-Based)

## 1) Current-state analysis from CAS codebase

The current analytics implementation already provides:

- Aggregate response metrics and score averages from `questionnaire_response`.
- Work-role (`work_function`) and department-level rollups.
- Heatmap-style charts for questionnaire and work-role performance.
- PDF export support and analytics download endpoints.

However, the current implementation is not yet aligned to the full reporting template requested (Executive Summary + role-hierarchical views + benchmark-driven gap prioritization + lock/finalization workflow). In particular, role taxonomy used by users (`admin`, `supervisor`, `staff`) differs from template roles (Director, Manager, Team Leader, Staff, Hub level), and analytics classification bands are not centrally defined as a governance object.

## 2) Target reporting model (mapped to requested template)

Implement reporting as **three layers**:

1. **Global Organizational Layer**
   - Executive Summary, organization averages, top strengths, top gaps.
   - Competency level bands (system-controlled and immutable by non-admin users).

2. **Dimensional Drill-down Layer**
   - Department/directorate analysis.
   - Role-based analysis (Director, Manager, Team Leader, Staff, Hub).
   - Work-role analysis (existing `work_function`) retained as a cross-cut dimension.

3. **Entity Layer**
   - Individual profile cards.
   - Benchmark comparison against required target (default 80%, configurable).
   - Action recommendation and follow-up tracking.

## 3) Required data-model and schema updates

### 3.1 Role taxonomy harmonization

Add normalized fields to map user access role from business reporting role:

- `users.access_role` (existing semantics: admin/supervisor/staff).
- `users.business_role` (new: director, manager, team_leader, staff, hub).
- `users.directorate` (new optional text or FK table).

This avoids breaking authorization while enabling the requested role-based reporting.

### 3.2 Competency-level configuration table

Create `competency_level_band`:

- `name` (Not Proficient, Basic, Intermediate, Advanced, Expert)
- `min_pct`, `max_pct`
- `rank_order`
- `is_system_default`

Rules:

- Only admin may edit.
- Every report snapshot stores the band definitions used at generation time.

### 3.3 Benchmark and gap policies

Create `competency_benchmark_policy`:

- `scope_type` (organization/department/business_role/work_function/competency)
- `scope_id` (nullable)
- `required_pct`
- `effective_from`, `effective_to`

Gap formula should support both policies already described in the template:

- `gap = 100 - actual`
- `gap = required - actual`

## 4) Reporting computation changes

### 4.1 Snapshot architecture

Introduce `analytics_report_snapshot_v2` and detail tables:

- Store immutable, timestamped report runs.
- Include assessment period, participant counts, score aggregates, and generated insights.
- Lock snapshot after finalization to satisfy “Lock report after submission”.

### 4.2 Auto-generated sections (template conformance)

Generate and persist sections 1–13 from the requested template:

- Executive summary with top 3 strengths/gaps.
- Methodology block (constant text + mapping metadata).
- Participant overview by role, department, gender.
- Org dashboard and department/role tables with level and gap.
- Critical gaps ranking (threshold <60 and below benchmark).
- Benchmark comparison matrices.
- Strategic recommendations with deterministic rules:
  - `<60`: training
  - `60–75`: coaching
  - `>85`: mentorship candidate

### 4.3 Duplicate prevention alignment

Reinforce duplicate-submission protections in report eligibility logic:

- Exclude duplicate/invalid submissions from analytics aggregates.
- Add report diagnostics section listing excluded rows count for auditability.

## 5) Role-based access and visibility matrix

Define explicit scope filters by viewer role:

- **Admin**: full organization + all drill-down filters.
- **Supervisor/Directorate lead**: only assigned directorate/department and subordinate business roles.
- **Manager/Team Leader**: own teams/work roles + anonymized peer comparison.
- **Staff**: individual profile + department average benchmark only.

Apply this matrix consistently to:

- On-screen analytics views.
- PDF/Excel exports.
- API endpoints used for dashboard queries.

## 6) UI/UX and export changes

### 6.1 Filter panel

Extend analytics filters to include:

- Role (business_role)
- Work Role (`work_function`)
- Directorate
- Department
- Individual
- Assessment period

### 6.2 Visualizations

Add/upgrade:

- Department heatmap (score and gap shading).
- Role comparison bars.
- Critical-gap priority table with traffic-light severity.
- Progress tracking trend for follow-up periods.

### 6.3 Export

- PDF and Excel must include filter metadata and snapshot ID.
- Export should reflect the same row-level access restrictions as UI.

## 7) Implementation phases

### Phase 1 — Foundations (1 sprint)

- Add schema fields/tables (`business_role`, directorate, level bands, benchmark policy).
- Add migration + seed defaults.
- Add server-side classification helper service.

### Phase 2 — Computation + snapshot engine (1 sprint)

- Build report snapshot v2 generator.
- Add gap policy engine and top-N rankings.
- Add lock/finalize workflow + audit trail.

### Phase 3 — UI + exports (1 sprint)

- Add filter controls and new report sections.
- Add department/role charts and critical gap panel.
- Update PDF/Excel renderers.

### Phase 4 — hardening + rollout (1 sprint)

- Role-scope security tests.
- Data quality checks (null score handling, duplicate suppression).
- UAT signoff with sample template outputs.

## 8) Testing strategy

1. **Unit tests**
   - Level-band classification boundaries.
   - Gap calculation modes.
   - Recommendation logic thresholds.

2. **Integration tests**
   - Snapshot generation with seeded data.
   - Role-filter enforcement per access role.
   - Export parity (UI totals == PDF/Excel totals).

3. **Regression tests**
   - Existing analytics heatmaps continue to render.
   - Legacy admin analytics endpoints remain backward-compatible.

4. **UAT acceptance tests**
   - Validate each requested section (1–13) against expected sample output.

## 9) Risks and mitigations

- **Role vocabulary mismatch**: keep access role vs business role separate.
- **Historical comparability drift**: persist level bands and benchmarks per snapshot.
- **Performance under multi-filter queries**: add covering indexes for period/department/business_role/work_function.
- **Governance drift**: enforce admin-only control over level/benchmark settings.

## 10) Deliverables checklist

- [ ] DB migration scripts.
- [ ] Snapshot v2 backend service.
- [ ] Role-scope authorization matrix implementation.
- [ ] Updated analytics UI with required filters/charts.
- [ ] PDF/Excel export updates.
- [ ] Automated tests + UAT evidence package.

