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
