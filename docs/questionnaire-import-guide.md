# Questionnaire Import Guide

This guide explains how administrators can import questionnaires using the EPSS HR Assessment builder. The importer accepts FHIR Questionnaire resources in XML (recommended) or JSON, including single Questionnaire resources or Bundles containing Questionnaire entries.

## Prerequisites

- Administrator access to the HR Assessment platform.
- A questionnaire definition file that matches the structure in `docs/questionnaire-template.xml` (downloadable from the import card).
- Optional: start from the Excel planning sheet (`scripts/download_questionnaire_template.php`) to capture sections, items, and work-function assignments before generating the XML payload.

## Preparing Your XML or JSON File

1. Use the template as a starting point and update the `<title>` and `<description>` values (status is imported as a draft regardless of the XML value).
2. Add question `<item>` entries directly under the root to create items without a section, or add section-level `<item>` blocks with `type="group"`; set `<text>` for the section title and `<description>` for optional helper text.
3. Within each section, create question `<item>` entries with:
   - A unique `<linkId>` for stable identification.
   - `<text>` containing the question prompt.
   - `<type>` set to `likert`, `choice`, `text`, `textarea`, or `boolean` (other FHIR types import as free-text). `text` creates a short free-text input, while `textarea` creates a longer response field.
   - `<required value="true">` when a response is mandatory.
   - `<repeats value="true">` to allow multiple selections for `choice` items.
   - `<answerOption>` values for `likert` or `choice` items, using either `<valueString>` or `<valueCoding>` with `display` or `code`.
4. Use `<type value="display">` for headings or instructional text you do not want stored; these entries are skipped during import.
5. Validate that the XML or JSON is well-formed before uploading. Work functions are applied to all available cadres by default and can be refined after import in the builder.

## Using the Excel Planning Template

The downloadable Excel workbook contains a single sheet with the following columns:

- **Questionnaire Title** and **Questionnaire Description** — applied to the root questionnaire record.
- **Section Title** and **Section Description** — create or update named sections. Leave blank to assign items to the questionnaire root.
- **Item LinkId**, **Item Text**, **Item Type**, **Allow Multiple**, and **Weight (%)** — define question prompts and scoring metadata.
- **Options (semicolon separated)** — supply response options for `likert` or `choice` items.
- **Work Functions (semicolon separated)** — list the cadres that should receive the questionnaire (e.g., `general_service;hrm`).

Each row represents a single questionnaire item. Duplicate the questionnaire and section details across rows as needed. After the structure is reviewed, translate the entries into the XML format described above so the importer can create the questionnaire and its related sections automatically.

## Import Steps

1. Sign in as an administrator and navigate to **Manage Questionnaires** from the drawer.
2. Scroll to the **Questionnaire Import** card.
3. Click **Choose File** and select your prepared XML or JSON file.
4. Press **Import** to upload the file. The system will parse sections, items, and options.
5. After a successful import you will see a confirmation banner, and the imported questionnaire will open automatically in the builder tabs for review.

## Post-Import Checklist

- Verify that the correct work functions are assigned; all available work functions are applied by default and can be refined in the builder.
- Review each section and question for accuracy. Likert items without explicit options receive the default five-point scale.
- Use the **Save Changes** button to persist edits or **Publish** to make the questionnaire available to end users.
- Download the latest template or this guide at any time from the import card for future reference.

For troubleshooting or additional support, contact the system maintainer or refer to the project README.
