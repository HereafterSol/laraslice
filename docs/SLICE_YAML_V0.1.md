# Slice YAML v0.1 — Draft Schema Skeleton

**Date:** 19 Oct 2026
**Status:** DRAFT — extracted from real ERP slices (Tasks), not designed in abstract
**Spec version:** `0.1`

> This is the minimal YAML that describes a working slice. If it validates, it renders — on any framework.

---

## Minimal slice (generates working CRUD)

```yaml
spec_version: "0.1"

name: Tasks
handle: task
description: "Manages task records"

# ── Data ──────────────────────────────────────────────
models:
  - handle: task
    table: tasks
    root: true
    fields:
      - handle: title
        label: Title
        type: string        # string | text | integer | decimal | boolean | date | datetime | timestamp
        required: false
      - handle: status
        label: Status
        type: string
        required: false
      - handle: due_date
        label: Due Date
        type: date
        required: false

# ── Navigation ────────────────────────────────────────
navigation:
  group: Work              # sidebar section
  label: Tasks

# ── Tenancy (optional) ────────────────────────────────
multitenant: true
tenant_key: company_id
```

**That's it.** This alone generates: database table, list page, create/edit forms, show page, sidebar entry.

---

## Full slice (adds UI richness)

```yaml
spec_version: "0.1"
name: Tasks
# ... (minimal fields above) ...

# ── Relations (optional) ──────────────────────────────
models:
  - handle: task
    table: tasks
    root: true
    fields: [ ... ]
    relations:
      - name: comments
        type: hasMany          # hasMany | belongsTo | belongsToMany | hasOne
        model: comment
        foreign_key: task_id

# ── UI: directory (list page) ─────────────────────────
ui:
  directory:
    columns:
      - key: title
        label: Title
        formatter: text_with_badge   # see § Formatter vocabulary
        sortable: true
      - key: status
        label: Status
        formatter: status_pill
      - key: due_date
        label: Due Date
        formatter: date_overdue      # rose if past, amber if today

    searchable: [title, description]
    search_placeholder: "Search tasks..."

    quick_filters:                  # pill/tab filters above the list
      - key: status
        label: Status
        options_from: distinct:status

    sidebar_filters:                # filter panel
      - key: due_date
        label: Due Date
        type: date_range             # date_range | select | text

    views: [table, board, calendar]  # available layouts
    default_view: table

    bulk_actions:
      - handle: export
        label: Export Selected
      - handle: delete
        label: Delete Selected
        danger: true

    row_actions:
      display: dropdown
      items:
        - { handle: view, label: View, icon: eye }
        - { handle: edit, label: Edit, icon: pencil }
        - { handle: delete, label: Delete, icon: trash-2, danger: true }

# ── UI: forms ─────────────────────────────────────────
  forms:
    sections:
      - key: general
        title: General Information
        grid: 2
        fields:
          - { handle: title, span: 1 }
          - { handle: description, span: 2 }

# ── UI: show page ─────────────────────────────────────
  show:
    tabs:
      - { handle: overview, label: Overview, type: fields }
      - { handle: comments, label: Comments, type: relation }

# ── UI: dashboard widgets (optional) ──────────────────
  dashboard:
    enabled: true
    widgets:
      - { type: stat, handle: total, label: Total, query: count }

# ── Workflow (optional) ───────────────────────────────
workflow:
  states: [todo, doing, done]
  transitions:
    - { from: [todo], to: doing, label: Start }
    - { from: [doing], to: done, label: Complete }

# ── Access (optional) ─────────────────────────────────
roles: [admin, employee, client]
```

---

## Formatter vocabulary (closed list — v0.1)

The AI **selects** from this list. It never invents formatters.

| Formatter | Intent | Example |
|-----------|--------|---------|
| `text` | Plain text | Description |
| `text_with_badge` | Text + small badge from another field | Title + priority badge |
| `status_pill` | Colored pill for status/state | todo / doing / done |
| `date_time` | Formatted date | Jan 5, 2026 |
| `date_overdue` | Date, rose if past, amber if today | Due date |
| `money_breakdown` | Total / paid / unpaid breakdown | Invoice amounts |
| `progress_bar` | 0–100% bar | Project completion |
| `code_badge` | Monospace badge for codes | INV-2026-001 |
| `composite_user` | Avatar + name for person ref | Assigned to |
| `number` | Formatted number | Count |

**Rule:** if no formatter matches the intent, use `text`. Never emit HTML in YAML.

---

## Field types (closed list — v0.1)

`string` `text` `integer` `decimal` `boolean` `date` `datetime` `timestamp`

---

## What v0.1 deliberately excludes

- Custom HTML/templates in YAML
- Framework-specific directives
- Computed expressions / formulas
- Multi-document files (one slice per file)
- Sync protocol (separate spec)

These can come in v0.2+ — but only after v0.1 proves itself on two runtimes.

---

## Validation

Machine schema: **`schemas/slice-v0.1.json`** in this repo (published later at `schemas.sliceframework.dev/slice-v0.1.json`).

```bash
php artisan slice:validate path/to/slice.yaml
php artisan slice:validate --all   # exit 1 if any fail
```

**Contract:** if a YAML file validates against the schema, any compliant renderer MUST produce a working slice. No silent drops.

---

## Changelog

- **0.1** (19 Oct 2026): Initial draft. Extracted from LaraSlice ERP port (Tasks slice). Covers: models, fields, relations, navigation, tenancy, directory UI, forms, show tabs, dashboard, workflow, roles.
