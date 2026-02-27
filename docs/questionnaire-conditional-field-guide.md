# Questionnaire Conditional Field Configuration Guide

This guide explains how to configure a questionnaire item so it only appears when a condition is met.

## What a conditional field needs

Each dependent question uses three condition properties:

- `condition_source_linkid`: the **Link ID** of the source question to watch.
- `condition_operator`: how to compare (`equals`, `not_equals`, or `contains`).
- `condition_value`: the expected answer text.

If `condition_source_linkid` is empty, the question is always shown.

## How to configure it in Questionnaire Builder

1. Create the source question and set its **Link ID** (example: `q_department`).
2. Create the dependent question you want to show conditionally.
3. In the dependent question row:
   - In **Condition source** enter `q_department`.
   - In **Condition** choose the operator (for example `equals`).
   - In **Expected answer** enter the value exactly as users will answer (for example `Sales`).
4. Save and publish the questionnaire.
5. Open the assessment form and verify:
   - the dependent field is hidden when the condition is not met,
   - and appears immediately when the condition is met.

## Example

### Source question
- **Text**: `Which department are you in?`
- **Type**: `choice`
- **Link ID**: `q_department`
- **Options**: `Sales`, `Engineering`, `HR`

### Conditional question
- **Text**: `Which region do you cover?`
- **Type**: `string`
- **Condition source**: `q_department`
- **Condition operator**: `equals`
- **Condition value**: `Sales`

Result: `Which region do you cover?` is only visible when the user selects `Sales`.

## Matching behavior details

- Matching is case-insensitive.
- `equals` checks for an exact value match.
- `not_equals` shows the field when the selected value is different from the expected value.
- `contains` checks if the answer includes the expected text.
- Multi-select source questions are supported (any selected value can satisfy the condition).

## Common mistakes to avoid

- Using the question label instead of the source **Link ID**.
- Typos in `condition_value` for choice options.
- Adding spaces before/after the source link ID.
- Expecting condition values to work against options that are never submitted (for example disabled options).
