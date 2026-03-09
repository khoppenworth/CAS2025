# SAP ERP Integration Mapping Template

This template helps you map HRassess API data to SAP ERP target structures for an outbound integration.

Use this alongside the platform API docs (`/swagger.php` and `docs/openapi.json`) when implementing middleware
that transmits submitted assessments to SAP.

---

## 1) Integration scope and assumptions

- **Source system**: HRassess application (FHIR-compatible endpoints).
- **Primary source endpoint**: `POST/GET /fhir/QuestionnaireResponse.php`.
- **Integration style**: Outbound push from middleware to SAP (preferred) with retry support.
- **Trigger**: `QuestionnaireResponse.status = submitted`.
- **Transport to SAP**: SAP Integration Suite (CPI) recommended; alternatives include OData/IDoc/BAPI wrappers.

---

## 2) Canonical payload (internal integration model)

Define a canonical model in middleware before SAP mapping:

```json
{
  "message_id": "uuid",
  "source_response_id": "12345",
  "employee_external_id": "E100245",
  "employee_email": "employee@company.com",
  "questionnaire_id": "17",
  "questionnaire_title": "Annual Performance Assessment",
  "performance_period_id": "8",
  "performance_period_label": "2025-H1",
  "submitted_at": "2026-01-20T09:15:00Z",
  "reviewed_at": null,
  "status": "submitted",
  "score_percent": 86.25,
  "review_comment": "Strong delivery and teamwork.",
  "answers": [
    {
      "link_id": "Q1",
      "question_text": "Team collaboration",
      "value": 5,
      "value_type": "integer"
    }
  ]
}
```

> Keep canonical fields stable; only adapters should change per SAP endpoint type.

---

## 3) Field mapping template (fill this table)

| # | Source path (HRassess/API) | Example | Transform rule | SAP target object | SAP target field | Required | Validation | Default/Fallback | Notes |
|---|-----------------------------|---------|----------------|-------------------|------------------|----------|------------|------------------|-------|
| 1 | `QuestionnaireResponse.id` | `12345` | Stringify | `ZHR_ASSESS_HDR` | `SOURCE_RESP_ID` | Yes | Not null, unique per source | Reject message | Idempotency key candidate |
| 2 | `user_id` + user master lookup | `204` | Map to employee number | `ZHR_ASSESS_HDR` | `PERNR` | Yes | Must exist in SAP HR master | Route to error queue | Maintain crosswalk table |
| 3 | `questionnaire` | `17` | Stringify | `ZHR_ASSESS_HDR` | `TEMPLATE_ID` | Yes | Numeric | Reject if missing | |
| 4 | `performance_period_id` / `performance_period` | `8` / `2025-H1` | Normalize to SAP period format | `ZHR_ASSESS_HDR` | `PERF_PERIOD` | Yes | Matches allowed periods | Derive from submitted_at | |
| 5 | `status` | `submitted` | Enum map (`submitted`->`FINAL`) | `ZHR_ASSESS_HDR` | `STATUS` | Yes | In allowed set | Set `PENDING` only for retries | |
| 6 | `score_percent` (if available) | `86.25` | Decimal(5,2) | `ZHR_ASSESS_HDR` | `TOTAL_SCORE` | No | 0-100 | Null | |
| 7 | `review_comment` | `Strong delivery...` | Trim + sanitize | `ZHR_ASSESS_HDR` | `REVIEW_NOTE` | No | Max length check | Truncate with audit log | |
| 8 | `item[*].linkId` | `Q1` | Direct | `ZHR_ASSESS_ITEM` | `QUESTION_CODE` | Yes | Not null | Reject line item | |
| 9 | `item[*].answer[*]` | `5` | Normalize by data type | `ZHR_ASSESS_ITEM` | `ANSWER_VALUE` | Yes | Type + range rules | Null + warning (if optional q) | Build per question type |
|10 | Middleware generated timestamp | `2026-01-20T09:16:04Z` | UTC to SAP format | `ZHR_ASSESS_HDR` | `INTG_SENT_TS` | Yes | Valid timestamp | Current UTC | Auditability |

---

## 4) Transformation rules checklist

### Identity and reference mapping

- [ ] Define `user_id -> SAP PERNR` strategy:
  - Option A: direct employee number stored in HRassess user profile.
  - Option B: lookup by immutable corporate ID.
  - Option C: lookup by email (last resort; less stable).
- [ ] Maintain a **reference crosswalk table** in middleware DB.
- [ ] Reject or quarantine records when employee mapping is ambiguous.

### Question/answer normalization

- [ ] Create `linkId -> SAP competency/criteria code` lookup.
- [ ] Convert FHIR answer value types (`valueString`, `valueInteger`, `valueBoolean`, etc.) to target SAP type.
- [ ] Define score conversion policy (e.g., Likert 1-5 -> percentage).
- [ ] Preserve original value in raw payload archive for audit.

### Period and date handling

- [ ] Normalize time zone to UTC in transit.
- [ ] Convert to SAP date/time format (`YYYYMMDD`, `HHMMSS`, etc.) in adapter layer.
- [ ] Validate performance period exists in SAP customizing.

---

## 5) Reliability design template

### Idempotency

- **Idempotency key**: `source_system + source_response_id + status`.
- Keep outbound message log with unique constraint on this key.

### Retry policy

- Retryable errors: HTTP 429/5xx, network timeout, transient SAP interface issues.
- Non-retryable errors: schema violation, missing mandatory employee mapping.
- Suggested backoff: 1m, 5m, 15m, 60m, then dead-letter queue.

### Dead-letter handling

- Store:
  - source payload,
  - transformed payload,
  - SAP error response,
  - attempt count,
  - last failure timestamp.
- Provide an admin replay action after correction.

---

## 6) Security and compliance template

- [ ] Use TLS 1.2+ end-to-end.
- [ ] Store SAP/API secrets in secret manager (not in code).
- [ ] Enforce least-privilege technical user in SAP.
- [ ] Mask PII in logs.
- [ ] Define data retention for raw payload archives.
- [ ] Capture audit trail: who/when/what was sent.

---

## 7) Test matrix template

| Test case | Input | Expected output | Result |
|-----------|-------|-----------------|--------|
| Happy path single response | Valid submitted response + mapped employee | SAP accepts header + items | |
| Missing employee mapping | Unknown `user_id` | Message to error queue, no SAP write | |
| Duplicate delivery | Same idempotency key resent | No duplicate SAP record | |
| Invalid answer type | Non-numeric score to numeric field | Validation failure before SAP call | |
| SAP timeout | Simulated 504 | Retries then DLQ | |
| Partial item failure | One invalid line item | Whole transaction rollback or explicit compensation | |

---

## 8) Example implementation sequence

1. Build middleware extractor for new `submitted` QuestionnaireResponse records.
2. Implement canonical model + transformation unit tests.
3. Implement SAP adapter (CPI/OData/IDoc endpoint).
4. Add idempotency store and retry scheduler.
5. Add monitoring dashboard (success rate, failures by reason, lag).
6. Run pilot for one business unit before full rollout.

---

## 9) Handover checklist

- [ ] Signed mapping sheet approved by HR + SAP functional owner.
- [ ] Interface contract versioned and stored in repo.
- [ ] Runbook for failures/replay documented.
- [ ] Cutover plan with rollback criteria.
