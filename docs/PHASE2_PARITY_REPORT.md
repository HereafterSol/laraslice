# Phase 2 parity evidence (Tasks / Projects / Invoices)

**Date:** 19 Oct 2026  
**Branch tip:** after `--source` + pilot re-infer  
**Source:** ERP `*DataTable.php` fixtures (real Worksuite-class code)

## Command

```bash
php artisan slice:repair --re-infer-ui --source=/path/to/erp
```

`--source` enables Phase 2 DataTable classification; without it, only Phase 1 name guesses apply.

## Before → After (directory formatters)

| Page | Before (typical port) | After Phase 1+2 | Score was | Score now (config-level) |
|------|----------------------|-----------------|-----------|---------------------------|
| **Tasks list** | plain text status/dates | `text_with_badge` title, `status_pill`, `date_overdue` | ~2/5 | **~3.5/5** |
| **Task detail** | generic show | show tabs from relations (C10); still thin vs Files/Subtasks hub | ~2/5 | **~2.5/5** |
| **Projects list** | empty/plain rows | `status_pill`, `date_overdue` due, `progress_bar` completion | ~2/5 | **~3.5/5** |
| **Project detail** | generic | related tabs possible; Gantt not in v0.1 | ~2/5 | **~2.5/5** |
| **Invoices list** | numbers only | `code_badge`, `money_breakdown`, `status_pill`, `date_overdue` due | ~2/5 | **~4/5** |
| **Invoice detail** | generic | still needs line-items density | ~2/5 | **~2.5/5** |

**Overall list parity:** ~**3.5/5** (was ~2/5).  
**Overall detail parity:** ~**2.5/5** — still the main gap (hub tabs content, not column formatters).

## Formatters now visibly configured

| Formatter | Where |
|-----------|--------|
| `status_pill` | Tasks, Projects, Invoices status columns |
| `date_overdue` | Tasks due_date, Projects due_date, Invoices due_at |
| `text_with_badge` | Tasks title (+ priority) |
| `progress_bar` | Projects completion_percent |
| `money_breakdown` | Invoices amount |
| `code_badge` | Invoice number |

## Still generic — and why

1. **Detail pages** — ERP has Files / Sub Tasks / Timesheet / History. We have tab *slots* from relations, not full child UX.
2. **Assignee avatars** — `composite_user` extracted from DataTables but pilot YAML has no users/members column yet (needs field on model).
3. **Inline timer / active badge** — no v0.1 formatter; correctly skipped.
4. **Live screenshots** — must be captured on the host app after `slice:repair --re-infer-ui --source=...` and seed; this report is **manifest evidence** committed in `resources/pilot/*.slice.yaml`.

## Honest merge story

Infrastructure (parser, schema, validate) is real. List **config** parity moved ~2 → ~3.5 on the three pilots. Detail pages and full 85-slice visual QA remain host-side work.
