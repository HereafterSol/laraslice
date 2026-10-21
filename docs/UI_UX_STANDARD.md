# LaraSlice Standard UI/UX

The standard UI is the product's parity vehicle: when a slice uses
only standard patterns, its pages hold the same density, layout and
interactions as a mature ERP by default. Specialized screens are
declared as standard patterns in YAML — never one-off markup.

Distilled from a page-by-page study of a production ERP (the
reference standard). Full R&D: the ERP-100 UI/UX plan in the
project workspace.

## Density tokens (normative)

- Base font **14px** on app surfaces; page title **16px / 500**.
- Card titles ~17px / 600; counters up to 20px semibold.
- Buttons and inputs **~39px** tall; radius **4px** on cards and
  controls in app surfaces.
- Table rows **42–61px**; cell padding ~10px 12px (compact density
  is the generated default).
- Page side padding ~24px; grid gaps ~20px; content uses the full
  width — never a narrow centered column.
- Status colors are semantic and global: red = overdue /
  incomplete, amber = to do, blue = doing / in progress,
  green = completed / paid. People render as avatars/initial
  chips, never bare ids.

## The standard patterns

- **P1 List** — attached filter strip (not a floating panel),
  actions row with view switchers (list/board/calendar), ONE card
  holding the table and its pagination footer, two-line identity
  cells, inline-editable status, avatar stacks, single kebab
  row-actions menu.
- **P2 Detail hub** — tab bar directly under the topbar (the
  record's name is the title), overview = asymmetric card grid:
  declared stats, gauge, charts, related tabs with counts
  (`ui.show.tabs`, `ui.show.stats`).
- **P3 Form** — one card, in-card section headers, labels above
  inputs, quick-add beside selects, rich text for long text,
  in-card save bar (Save / Save & More / Cancel).
- **P4 Dashboard** — declared widgets in a masonry grid: profile
  card, dual counters, mini-tables, week strip, calendar;
  designed empty states, never blank panels.
- **P5 Report** — `ui.directory.report`: filter + grouped table
  with totals (G2). **P6 Document** — `ui.show.document`
  printable sheet (G2). **P7 Matrix** — `ui.directory.matrix`
  dense marker grid (G2).
- **P8 Views** — `ui.directory.views` switches list/board/
  calendar over the same data and filters.
- **P9 Settings hub** — every feature's settings live in ONE
  searchable hub: left rail + search + one form-card section per
  feature, declared via `settings:` in slice YAML and aggregated
  by the framework (S-UI 5).

## Components

Use BlatUI first (`docs/UI-COMPONENT-MAPPING.md`): `data-table`
(compact density), `stat`, `tabs`, `chart`, `kanban`, `gantt`,
`scheduler`, `repeater`, `rich-text-editor`, `avatar-group`,
`status-pill`, `filter-tabs`, `description-list`, `empty`,
`drawer`, `pagination`. Foundation components being added for
the standard: gauge, status-select, widget-card, week-strip,
filter-strip, save-bar, kebab-actions, section-card, tab-bar,
view-switcher.

## Rollout

Phases S-UI 1 (tokens & shell — DONE) → S-UI 2 (list — the
filter strip, kebab actions and the inline status select are
live: `ui.directory.inline_edit` declares editable fields, the
framework registers `{index}/{id}/inline`, values are guarded
against the field's declared options, and column options fall
back to the filter declarations incl. `distinct:`) → S-UI 3
(hub & form, incl. overlay single-column forms) → S-UI 4
(dashboard) → S-UI 5 (settings hub). Each phase: build once in the
foundation, regenerate demo slices (`slice:repair --re-infer-ui`
and template refresh), verify side-by-side against the
reference ERP. The Studio follows the same tokens.
