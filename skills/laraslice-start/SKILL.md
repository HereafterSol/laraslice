---
name: laraslice-start
description: Start a NEW LaraSlice project — greenfield, from zero, from an idea or MVP, or from a brief. Covers installing and setting up LaraSlice, scaffolding the first slice, the pre-build dossier (PRD, TRD, app flow, design brief, schema, plan), writing the Mind first, and the Studio and AI doors. Use when the user is starting, creating, planning or setting up a fresh project or asks how to begin with LaraSlice. Load the laraslice core skill first; use laraslice-port instead for an existing system.
---

# LaraSlice — Starting a New Project

**Load first:** the `laraslice` core skill (the trilogy,
the fields story, the concepts map). This skill is the
greenfield door only: dossier → Mind → first slice → grow.

## Words we use (plain — read this first)

- **Mind** — one file (`system.yaml`) that says what the
  system is FOR: its purpose, its people, their journeys.
- **Body** — one file per feature (`*.ui.yaml`) that says
  what its pages LOOK like and contain.
- **Heart** — one file per feature (`slice.yaml`) that the
  framework actually RUNS. If it is not in the Heart, it does
  not happen.
- **Soul** — the design identity (colours, radius, density):
  how the whole system feels. Change it in one place, it
  changes everywhere.
- **Slice** — one feature, end to end (its data, pages,
  permissions, navigation) in one folder.
- **Gate** — a check with a plain name (Structure, Data,
  Furniture, Tasks, Visual) that must pass before work is
  called done.
- **Drift** — when the files disagree with each other or
  with the running system. `slice:drift` finds it.

## 24. Starting a new project — two doors, one system

A new LaraSlice project starts from a fresh Laravel application
(there is no separate scaffolder binary): `composer create-project
laravel/laravel`, then `composer require hereafter/laraslice` and
`php artisan slice:install`. From there, two doors lead to the
same system — the trilogy files are identical either way, and
teams mix doors freely.

**The Studio door (developer-led).**

1. Open the Studio (`/laraslice/wizard`) and start in **Plan**:
   write the Mind first — purpose, actors and their clubs,
   journeys, operations. A non-technical owner can read and
   sign this file; that is what it is for.
2. **Create** mode scaffolds the first slice (or a whole domain
   suite) from presets, blueprints, or the brief; **Design**
   mode shapes its Body — columns, filters, tabs — with live
   preview.
3. Run the app. The Standard UI layer (§5.5/§21) renders the
   basics correctly by default; declare, don't hand-build.
4. Grow slice by slice; **Operate** mode watches health,
   backups, and access as the system matures.

**The AI door (skill-led).**

1. Give the assistant this skill (`npx skills add
   HereafterSol/laraslice`, or the copy shipped in `skills/`)
   and, for live inspection, connect MCP (`laraslice:mcp`).
2. Brief the AI with the Mind, not with tickets: ask it to
   draft `system.yaml` (or run `slice:plan:draft` from a
   written brief) and review the draft like an owner's
   sign-off — the AI never promotes its own draft silently.
3. The AI scaffolds slices by the same declarations a human
   writes in Design mode, generates, and verifies against the
   running app (§18's evidence rules apply from the first
   slice, not just when porting).
4. Every AI change lands as reviewable YAML + generated code —
   diff it the way you would a colleague's PR.

**Which door when.** Solo freelancer: either; the AI door is
faster for breadth, the Studio door for feel. Big-team SDLC:
Plan mode is the contract surface (BRD → Mind → sign-off),
both doors build against it. Brief-first and from-zero are
first-class starts — a project does NOT begin with porting;
porting (§22) is simply the door for systems that already
exist.

**One complete slice, worked (Books).** Everything a first
slice needs, in the only file that holds it — the Heart
(`app/Slices/Library/Catalogue/Books/slice.yaml`). Read this
once and you know the shape of every slice; `slice:make Books`
scaffolds the same shape, and the fields story (§2) explains
why this file — not the generated PHP — is where fields live.

```yaml
schema_version: 2
name: Books
handle: book
description: 'The library catalogue: books and their authors.'
app: Library
module: Catalogue
domain: Catalogue
navigation:
  group: Catalogue
  label: Books
  url: /library/catalogue/books
  icon: package
  order: 10
models:
  -
    handle: book
    table: books
    root: true
    fields:
      - {handle: title, label: Title, type: string, required: true}
      - {handle: isbn, label: ISBN, type: string, required: false}
      - {handle: author_id, label: Author, type: integer, required: true}
      - {handle: published_year, label: Published, type: integer, required: false}
      - {handle: status, label: Status, type: select, required: true, options: {available: Available, on_loan: 'On loan', lost: Lost}}
    relations:
      -
        name: author
        type: belongsTo
        model: author
        foreign_key: author_id
permissions:
  - book.view
  - book.create
  - book.edit
  - book.delete
ui:
  directory:
    columns:
      - {handle: title, label: Title}
      - {handle: author_id, label: Author}
      - {handle: status, label: Status}
```

From this one file the framework generates the model, the
migration, the directory (three columns above), the form
(every declared field), and the four permissions. A second
slice (Authors, Members, Loans) is the same shape again —
that repetition is the point.

## 25. The SDLC on LaraSlice — teams, software houses, AI

The lifecycle is the product a software house sells; the
framework gives every phase a surface and makes the handoffs
files, not folklore (`docs/SDLC.md` is the long form).

- **Discovery → the Mind.** Brief/BRD becomes `system.yaml` in
  Plan mode; the owner signs the Mind — that sign-off is the
  requirements gate. Drafts (yours or `slice:plan:draft`'s)
  are marked and never self-promoted.
- **Architecture/design → the Body.** Domains are bounded
  contexts teams can own; Bodies are reviewable without
  running the app; architecture tests fail the build on
  boundary violations.
- **Build → slices.** Create/Design, CLI, or the AI door
  (§24) — identical declarations, interchangeable work. The
  Standard UI layer is what keeps many hands' output one
  product.
- **Verify → gates.** `slice:drift` zero-errors, per-domain
  real-usage runs (§18), the composition probe; for ports,
  the five Replica gates (§22) with `REPLICA-STATUS.md` as
  the quotable record.
- **Release → the boundary.** Demo + changes file + docs/
  README/landing/changelog updated + owner approval, then
  merge/tag/publish. An AI prepares releases; it does not
  ship them.
- **Operate → Operate mode.** Health, backups per the Mind's
  policy, audit trail, access review, operator manuals from
  the Mind. Maintenance loop: Plan edit → impact →
  diff-save → `slice:repair` (fleet-wide, idempotent) →
  Operate verify.

**AI's seat:** a team member with defined permissions —
drafts in discovery, builds by declaration, verifies with
evidence, never approves its own deviations or releases.
This skill is the shared protocol: the same document onboards
a new hire and an AI assistant, which is why the lifecycle
holds at software-house scale.

## 28. The pre-build dossier — six documents, in plain words

Starting a NEW system (not a port)? Do not start by writing
code or prompting an AI cold. First fill six slots — together
they are the project's dossier. Each slot is one LaraSlice
artefact; most teams already know these documents by their
industry names:

| # | Everyone calls it | In LaraSlice it is | Done when |
| --- | --- | --- | --- |
| 1 | **PRD** — what are we building and why | The **Mind** brief: purpose, actors, journeys, success metrics (`system.yaml`) | A non-technical owner can read it and sign it |
| 2 | **TRD** — the technical choices | One page from `docs/templates/TRD.md` (hosting, database, integrations, the choices LaraSlice leaves open) | Every open choice has a named decision |
| 3 | **App Flow** — the screens, in order | Generated: `slice:flow` renders journeys + navigation from the Mind and the slices — never hand-drawn | The artefact exists and matches the Mind |
| 4 | **UI/UX brief** — how it looks and feels | The **Soul**: design identity declared in the Mind + the standard patterns (§21) | One design identity, named once |
| 5 | **Backend schema** — the data | **Heart** drafts (`slice.yaml` per slice), from the Mind's modules | Every module has a slice draft |
| 6 | **Implementation plan** — the order of work | The gate roadmap: build order + which gates (§22, plain names) prove each stage | Stages named, each with its proof |

Rules: the dossier is drafted BEFORE the first build command;
documents propose, declarations dispose (§27) — when a slot
and the running system disagree, the declaration wins and the
slot is updated. On a port, the porter drafts slots 1, 3 and 5
automatically (`slice:plan:draft`, `slice:flow`); the owner
completes the rest in Plan mode.
