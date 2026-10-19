# YAML canonical source + UI richness (Phase 1)

**Date:** 19 Oct 2026  
**Branch:** `feat/workflow-bulk-actions`  
**Source:** GROK-HANDOFF-UI-RICHNESS.md

## Decision: dual-write, YAML preferred for GitOps

| Artifact | Role |
|----------|------|
| **`slice.yaml`** | Human/AI/Git source of truth for review and PRs |
| **`slice.json`** | Compiled runtime artifact (Studio + `ManifestRepository::read`) |
| **DB workflow** | Runtime mirror via `syncWorkflowFromManifest` / `slice:sync` |

Today Studio saves via `ManifestRepository::writeBoth` (JSON + YAML).  
`version_history` stays in the manifest (Git + ledger). A full Craft-style `slice:sync --apply|--rebuild|--check` state machine remains **backlog** (handoff §3).

We do **not** crown YAML-only yet while JSON is what discovery loads — dual-write closes the gap without a breaking cutover.

## Phase 1 — Formatter inference (shipped)

`buildUiFromInspectedFields()` now maps:

| Pattern | Formatter |
|---------|-----------|
| status / state / stage | `status_pill` + quick filter + board view |
| priority | `status_pill` + badge on title |
| due_date / deadline | `date_overdue` (rose if past) |
| progress / percent / completion | `progress_bar` |
| user_id / assignee_id | `composite_user` |
| total + paid_amount | `money_breakdown` |
| invoice_number / codes | `code_badge` |

Runtime: `DataTableRows` + `x-ui.data-table` treat `date_overdue` as HTML.

## Phase 2 / 3 (not started)

- Parse source `*DataTable.php` `editColumn` closures  
- AI screenshot + code → YAML diffs approved in Studio  

## Operator

```bash
# Re-scaffold or repair indexes after pull to pick up new formatters
php artisan slice:repair --publish-ui
# Or re-run port scaffold for affected slices
```
