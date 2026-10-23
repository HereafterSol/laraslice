# Mind · Body · Heart — the three files of a Slice system

Every Slice system is described by three coordinated files. Only
one of them runs.

| File | Scope | Question it answers | Runtime? |
| --- | --- | --- | --- |
| **Mind** — `system.yaml` | one per system | WHY does this system exist, WHO uses it, and HOW does work flow through it? | No — intent |
| **Body** — `porting/ui-spec/<slice>.ui.yaml` | one per slice | WHAT does each page look like and do? | No — evidence + decisions |
| **Heart** — `slice.yaml` | one per slice | WHAT runs? (data, fields, relations, UI declarations, workflows, settings) | **Yes — the only runtime truth** |

A non-technical founder can read the Mind and understand the whole
system. A developer (or an AI) reads Mind → Body → Heart and can
rebuild or extend the system on any Slice runtime (LaraSlice today;
the Mind and Body are language-agnostic by construction, and the
Heart declares intent each runtime maps to its own stack and
design system).

**The Soul is not a file.** It is the experience identity — the
chosen design system, density, and refinement layer (JEV) —
declared once in the Mind (`design:`) and realized by the runtime's
component mapping.

## The Mind (`system.yaml`) — schema

```yaml
system: {name, purpose, owner}
design: {system: blatui-tailwind, density: compact, refinement: jev}
actors:            # every kind of user, human or external
  - {name: Employee, kind: internal, description: "..."}
roles:             # permission CLUBS + the override model
  model: role-clubs-with-user-overrides
  clubs:
    - {name: Admin, permissions: [tasks.*, leaves.approve]}
  overrides: per-user grants/revocations on top of the club
journeys:          # the work, end to end, in plain language
  - {name: Apply for leave, actor: Employee, steps: [...], outcome: "..."}
navigation:        # the menu tree users actually see
  - {label: Work, children: [Projects, Tasks, Time Logs]}
routes: {style: resource, prefixing: module}
flows:             # internal + external, incl. approvals and timers
  - {name: Leave approval, kind: internal, summary: "..."}
integrations:      # APIs in/out, webhooks, mail, storage
  - {name: SMTP mail, kind: outbound, purpose: notifications}
templates:         # email + print inventory and who owns them
  - {name: Leave approved email, kind: email, slice: Leaves}
storage: {uploads: local-disk, policy: "..."}
pipelines:         # import / export / zip / sync, declared not coded ad hoc
  - {name: Tasks export, kind: export, format: csv}
operations:        # the Operate quadrant, declared
  backups: {schedule: daily, retention_days: 30, includes: [db, uploads]}
  sync: {files: none, db: none}
  audit: {enabled: true}
```

Rules:

1. The Mind **describes and decides**; it never executes and never
   holds code or JS logic. Behavior expectations are written as
   plain-language outcomes ("a second timer start is refused").
2. Roles are **clubs of permissions**; individual people may hold
   **overrides** (grants or revocations) on top of their club —
   both layers are declared in the Mind, the slices declare the
   permissions they need in their Hearts, and the runtime enforces
   the union.
3. The Mind and the Body are **checked against the Heart**, never
   the other way round: extraction generates their drafts when
   porting, audits keep them honest, and drift is reported — the
   Heart wins every conflict until a human edits it deliberately.
4. For a new project the order reverses: Mind first (from an idea
   or a BRD), Body derived from the Mind, Hearts generated from
   both. Porting and planning are the same three files at
   different moments of a system's life.

## The Body (`porting/ui-spec/<slice>.ui.yaml`) — schema v2

One Body per slice. It is the reviewable evidence of what each
page of the slice looks like and does, and what each club may do
with it. Bodies are written in the source system's words (its UI
nouns, its labels), never in storage words. Every item carries a
`decision` (`undecided` at extraction, then `keep`, `drop`,
`rename`, or `add`); audits check the Heart and the running app
against the decided Body.

```yaml
slice: leaves
labels: {singular: Leave, plural: Leaves}   # the UI's own nouns
pages:
  list:
    path_source: /account/leaves
    path_port: /erp/erp/hr/leaves
    toolbar: [{item: 'New Leave', decision: keep}]
    filters: [{item: status, decision: keep, placement: drawer}]
    columns:
      - {item: Employee, decision: keep, value: user.name}  # value = what must render non-empty
  form:                       # the create/edit surface — v2 addition
    presentation: drawer      # page | drawer | modal (create), page | drawer | modal (edit)
    sections:
      - {title: Leave, fields: [user_id, leave_type_id, leave_date, duration, reason]}
    fields:
      user_id:        {input: picker, relation: users, label: 'Choose Employee'}
      leave_type_id:  {input: select, relation: leave_types}
      leave_date:     {input: date}                 # date even if storage is datetime
      duration:       {input: select, options: [full, first_half, second_half]}
      reason:         {input: textarea, required: false}
      status:         {input: workflow-state, default: workflow-initial, editable: false}
    system_fields: [added_by, last_updated_by, approved_by, approved_at, event_id]
      # storage/audit columns that exist in the Heart but must
      # never render on a form; generation hides them by default
  detail:
    composition: [header-counters, tabs]
    tabs: [Profile, Activity]
access:                       # per club — checked against the Hearts + roles
  Super Admin:  {actions: [view, create, edit, delete, approve], scope: all}
  Admin:        {actions: [view, create, edit, approve], scope: all}
  Employee:     {actions: [view, create], scope: own}   # own = records whose user_id is the viewer
workflow: {state_field: status, initial: pending, on_create: apply-initial}
```

Rules:

1. **A Body is incomplete without `pages.form` and `access`.**
   A list-only Body is a draft, and audits must say so.
2. **System fields are declared, then hidden.** Any column the
   user should never type (`*_by` audit stamps, `approved_*`,
   `event_id`, timestamps) is listed under `system_fields`;
   form generation hides them by default, and the form audit
   fails if one renders.
3. **Defaults have sources.** A field's default is one of
   `workflow-initial`, a literal, `current-user`, or
   `today` — never an accident of an empty input. A workflow
   slice's state field defaults to the workflow's initial
   state, applied by the server on create.
4. **Relations are pickers.** A field whose value is another
   record declares `input: picker` (or `select`) with its
   `relation`; rendering a raw numeric ID input for a person
   or a named record is a defect the form audit flags.
5. **Access is part of the surface.** `scope: own` means the
   runtime filters the list (and guards detail routes) to the
   viewer's own records for that club. The drift audit
   compares `access` against the Hearts' declared permissions
   and the roles' actual grants — a club the Mind promises
   access and no role grants is drift, reported before release.
6. **Values are auditable.** A list column may declare
   `value:` (the relation path that must render). An audit
   that finds the column rendering empty for every row has
   found a defect, even when the header matches.

## Where each surface reads them

- **Dev Studio** — Plan mode reads/writes the Mind; Design mode
  works the Body; Create/Manage modes work the Hearts. The
  Operate console reads the Mind's `operations:` plus live
  runtime state (health, backups, sync, audit, security).
- **CLI** — `slice:port` extracts draft Mind + Bodies from a
  source system; the delta audit verifies Bodies against both
  applications; `slice:links` checks the Hearts.
- **Manuals and the AI API are generated outputs** — user manuals
  from the Mind's journeys + the Bodies; AI access is the CLI/MCP
  surface over the same three files. Never hand-maintain them.

## Plan mode (Studio)

The Studio's fifth mode, **Plan**, is where the Mind is read and
edited: actors, journeys, operations, and role overrides render
as plain-language cards, and purpose / role clubs / journeys /
operations edit as simple line-based forms. Saving is
diff-before-save (preview renders a unified diff of the YAML;
confirming writes `porting/system.yaml`). Sections the form
does not touch are preserved, except YAML comments — a Plan
save re-renders the file and drops comments, so keep prose in
values, not comments. `php artisan slice:plan:draft` drafts a
Mind plus a Body per slice from the discovered slices (R6);
drafts are marked [draft] and existing files are never
overwritten.

## Operate mode (Studio)

The sixth mode reports the running system against the Mind's
`operations:` declarations: health checks (database, slices,
storage, Mind presence, audit trail, runtime), backups — the
declared policy is shown and "Run backup now" produces real
artifacts honoring the declared includes — the recent audit
trail, and an access review (R5) listing every user's roles
with flags on system-wide roles, multiple roles, and missing
roles. "Generate manual" (R4) downloads an operator manual
assembled from the Mind and the slice inventory. Operate
never changes runtime behavior; it is the Maintain loop's
home (R3).
