# Department / Team / Work Role / Assignment UX Improvement Plan

## Problem Summary
Current administration requires managing four related entities in separate actions:
1. Department catalog
2. Team catalog
3. Work role catalog
4. Questionnaire assignment defaults by department

This creates extra navigation and increases the risk of inconsistent data entry (e.g., team added without immediate default assignment review).

## Proposed User-Friendly Flow

### 1) “Organization Setup Wizard” (single guided flow)
Create one guided screen (or modal stepper) with 4 steps:
- Step 1: Add/edit departments
- Step 2: Add/edit teams (scoped to selected department)
- Step 3: Add/edit work roles
- Step 4: Map default assignments (department + optional role overrides)

### 2) Terminology cleanup in UI
Use consistent labels everywhere:
- `Department`
- `Team`
- `Work Role` (replace technical term `work_function` in UI)
- `Default Assignment`

### 3) Smart defaults
- Auto-create a “General Team” when a new department is created.
- Optional “Clone from existing department” for assignment defaults.
- Auto-suggest work role based on account role (`admin/supervisor/staff`) with override.

### 4) Inline validation and dependency checks
- Prevent archiving a department that still has active teams/assigned users without a guided reassignment prompt.
- Show “impacted users” count before archive/rename actions.
- Validate uniqueness as user types (department/team/work-role labels).

### 5) Bulk operations
- CSV import for department-team-role hierarchy.
- Multi-select assignment updates (apply one questionnaire set to multiple departments).
- “Preview changes” before save.

## Minimum Viable Increment (low risk)
1. Add a read-only “Impact summary” panel to the current admin page.
2. Add “Clone assignments from department X” action.
3. Replace user-facing “Work Function” strings with “Work Role” while preserving existing DB column names.

## Suggested Implementation Order
1. UI terminology normalization.
2. Clone defaults feature.
3. Impact summary widgets.
4. Wizard (optional, phase 2).
