# YAML / JSON dual-write + UI richness

**Date:** 19 Oct 2026  
**Branch:** `feat/workflow-bulk-actions`  
**Sources:** GROK-HANDOFF-UI-RICHNESS.md, Muse review, YAML-ELIMINATION-SPEC.md

---

## Conflict rule (explicit — runtime)

**While dual-write is in effect:**

| Situation | Winner |
|-----------|--------|
| Runtime discovery / Studio load | **`slice.json`** (what `ManifestRepository::read` loads today) |
| Git PR review / AI edits | Prefer **`slice.yaml`** for human readability |
| Studio **Save config** | **Both** via `writeBoth` — keeps them aligned |
| Disagreement without Studio save | **JSON wins at runtime**; YAML alone will **not** affect the page until synced into JSON |

This is the failure mode Muse hit with the invoice YAML revert: editing YAML only does not change the running app.

**Operator fix when YAML was edited by hand:**

```bash
# Prefer: save from Slice Studio (writes both), or:
php artisan slice:sync   # follow your install's prefer-json / prefer-yaml flags if present
```

**After `feat/workflow-bulk-actions` merges**, full YAML-only migration follows **YAML-ELIMINATION-SPEC.md** (not this branch).

---

## Operator: upgrade existing slices to Phase 1 formatters

`--publish-ui` only publishes BlatUI stubs. It does **not** re-infer column formatters.

```bash
# Safe: skips slices that already have Studio version_history customizations
php artisan slice:repair --re-infer-ui

# Preview
php artisan slice:repair --re-infer-ui --dry-run

# Combined
php artisan slice:repair --remap-nav --re-infer-ui --publish-ui
```

Shipped by Muse (`b72820c`).

**Bugfix:** early `--re-infer-ui` read `$ui['columns']` instead of `$ui['directory']['columns']`, so re-infer wrote empty formatters. Fixed to merge full `directory` (columns, views, inline_edit, bulk_actions) + dashboard.

---

## Phase 1 formatters (shipped)

| Pattern | Formatter |
|---------|-----------|
| status / state / stage | `status_pill` + board + inline edit |
| priority | `status_pill` + title badge |
| due_date / deadline | `date_overdue` (escaped; rose if past) |
| progress / percent / completion | `progress_bar` |
| user_id / assignee_id | `composite_user` |
| total + paid_amount | `money_breakdown` |
| codes | `code_badge` |

`DataTableRows::dateOverdue()` escapes on parse failure (`htmlspecialchars`).

---

## YAML elimination (AFTER this branch merges)

See `YAML-ELIMINATION-SPEC.md` — 4 phases. **Do not implement on this branch.**

### Design answers (section 7)

1. **Comments in generated YAML?** — **Yes.** Section headers (`# Navigation`, `# UI directory`) help AI + humans. Keep short; no essays.
2. **Key order?** — **Logical**, not alphabetical: `name`, `title`, `version`, `description`, `fields`/`models`, `ui`, `navigation`, `workflow`, `version_history`.
3. **Line width?** — Symfony default (unlimited). Prefer readable nested blocks over hard wrap at 120.
4. **Multi-doc YAML?** — **No.** One document per `slice.yaml`.

---

## Phase 2 / 3 (backlog)

- Parse source `*DataTable.php` `editColumn` closures  
- AI screenshot + code → YAML diffs approved in Studio

## Phase 1 dual-read (YAML elimination) — shipped

`ManifestRepository::readFromSliceDir()`:

| Situation | Behavior |
|-----------|----------|
| YAML only | Load YAML |
| JSON only | Load JSON, write YAML twin, **keep JSON** |
| Both present | **YAML wins** |
| Malformed YAML + JSON | Warn, fall back to JSON (never fatal) |
| Malformed YAML, no JSON | Throw |

JSON is not deleted in Phase 1. Write-YAML-only and JSON removal are later phases.
