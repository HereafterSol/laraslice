# v2.0.2 — The Convergence Release

Released 2026-11-02

This release was driven by one demanding exercise: a from-scratch port of a
production application, built entirely in a clean sandbox from the shipped
skill and protocol, then converged surface by surface against the running
original — directories, hubs, forms, and scripted user journeys — until its
benchmark scope passed every Replica Protocol gate, including full task
journeys for both an administrator and an employee (24 of 24 checks). Every
fix below was found by that convergence work or by the candidate programme it
completes. The port then went through a full interaction audit — every module
driven live on both sides, filters, exports, and edit round-trips compared —
and grew its last missing workspaces (messaging, reports, payroll, and the
settings hub) before this release was cut. The full framework suite passes:
**700 tests, 2557 assertions**.

## Studio UX polish — one system, honest states (3 Nov 2026)

A full audit of the Studio (six panels, desktop and small
screens, screenshots and DOM measurements) drove one
consistency pass:

- **One button system.** Primary actions use the design
  system's primary token (Port's Analyze, Plan's Preview
  diff, Manage's Open Slice UI, the porting modal's
  Analyze); indigo is an accent again, and decorative
  colour came off buttons like Design's Load Ported Suite
  (now a neutral outline). Capability chips are neutral;
  rose marks only the destructive capability.
- **Honest empty and sample states.** The default UI
  plan's KPI ribbon no longer invents figures ("120
  invoices, +12% this month") — placeholder stats read as
  samples until the author sets them. The generation
  card reads "No generation plan yet" until a plan
  exists. Manage opens with a loading state instead of
  claiming "no slices match", and an unselected Manage
  explains itself instead of sitting blank. The porting
  presets no longer prefill a developer machine's local
  path as the source default.
- **Small screens work.** Manage's slice list steps
  aside once a slice is chosen (detail first, with a way
  back) instead of burying the editor under the full
  list. Operate no longer scrolls the page sideways —
  long identifiers wrap, and text never sets a card's
  width. Design's field table keeps a minimum width so
  field names and types stay readable inside its
  scroller, and its row controls meet a sane size floor.
- **The Copilot stops covering the work.** Its collapsed
  state is now a compact round button on every screen
  size (it was a wide bar floating over form fields);
  its status tells the truth — the header pill reads
  BUILT-IN, and the status dot greys, when no AI provider
  is connected. Create opens with its own first question
  (the porting banner moved out), and its first-step form
  labels are sentence case like the rest of the Studio.

## Design panel — the markup cut (3 Nov 2026)

- **The Design fragment sheds ~45%.** A fresh panel now
  serves ~199 KB where it served ~361 KB (with a slice
  loaded, ~244 KB where it served ~406 KB), with no
  behaviour change. Two moves did it. First, the catalogs
  the panel used to render over and over on the server —
  the installed-slice picker, the relation targets, the
  database-table list — now ship once as compact data and
  render on the client. Second, the largest on-demand
  surface (the porting modal, ~101 KB) loads lazily the
  first time it is opened instead of riding in every panel.

## Working as a team — a shared library, and a portability gate (3 Nov 2026)

- **Blueprints can be shelved in a team library.**
  Design gains a Team Library: save the current blueprint
  and it is written as a file under `porting/blueprints/`
  in the app, so the whole team shares it through Git —
  load it back from the Start-from picker on any machine,
  remove it like any file. The library is tracked by
  Declaration History, so shelving and changing a shared
  blueprint shows up in the project's record like any
  other declaration change.
- **A portability audit keeps the declarations
  runtime-neutral.** The Mind, Bodies and Hearts are the
  shared truth for every runtime, present and future;
  `php artisan slice:portability` scans them for this
  runtime leaking in — PHP class names named where a
  concept belongs, template filenames, framework helper
  calls, the CLI's name — and reports each one with its
  file, line and a plain-words fix. It exits non-zero on
  findings, so CI can hold the line, and Operate's
  quality card shows the result. A Heart's `namespace:`
  line — its declared home in this runtime — counts as a
  binding, not leakage: every runtime declares its own.

## Working as a team — who changed what, and what Git sees (2 Nov 2026, night)

- **Declaration History now records the person, not just
  the tool.** Entries already named the recording command
  (slice:cache, slice:sync); they now also carry who was
  behind it — the signed-in user's name and email in the
  Studio, the OS account on the CLI. Older entries simply
  show the tool, as before. Manage's per-slice history,
  the Operate quality card, and `slice:history` all show
  the person when one is recorded.
- **Operate shows the Git state of the declarations.**
  A read-only card answers: is this project under Git,
  which branch, and which Mind/Body/Heart files carry
  uncommitted changes (non-declaration files never count).
  Where Git or the repository is absent, the card says so
  plainly and points at Declaration History, which keeps
  the record either way. The same facts are on the CLI:
  `php artisan slice:operate git`.

## Port analysis — feedback and retention (2 Nov 2026, night)

- **Analyze source no longer runs silent.** A fast
  pre-flight counts the source first (a configured source
  connection, migrations, or SQL dumps — the same priority
  the analysis itself uses), so a bad path fails in a
  second with the reason instead of after a minute of
  silence, and the panel states the real scope ("227 tables
  from migrations") before the wait begins.
- **While analysis runs, the panel shows live state:**
  the scope, an elapsed timer, what the analysis is doing,
  and a Cancel button. No invented percentages — the
  numbers shown are the numbers known.
- **The result is retained.** A completed analysis is kept
  on the server; leaving Port and returning restores the
  reviewed plan with a "Restored your last analysis"
  banner, instead of a blank panel and a lost analysis.
  Retention is working state — Execute remains the only
  path from plan to files, exactly as before.

## Plan shows its impact (2 Nov 2026, night)

- **The Plan editor now answers "what does this Mind
  touch?"** An Impact card under the form lists every
  journey with its surface coverage — how many of its steps
  link to a built slice (by the App Flow rule: a step links
  when its text names a slice's navigation label) and which
  slices they are. A journey with no linked step is flagged
  "No surface yet — intent only" instead of passing silently.
- **The impact is computed for the form, not just the saved
  Mind.** While editing, the card reflects the journeys as
  typed: new journeys carry a NEW marker, and a line at the
  foot names what saving would remove. It refreshes on load,
  on Preview diff, and after Save — so the consequence of a
  Mind edit is visible before it is committed, next to the
  diff itself.
- One source of truth: the page map behind the impact is
  the same code slice:flow renders its App Flow artefact
  from, so the Studio and the CLI never disagree.
- **Plan is interactive, not just a form.** A journey
  builder (name, actor dropdown fed by the Mind's own
  actors, a step list, an outcome) composes the journey
  line for non-technical users — no pipe syntax to learn —
  while developers keep the raw field; both write the same
  form. The Impact card refreshes live as journeys are
  typed, so coverage feedback lands while editing, not
  after. Duplicate journey names are refused in place with
  the reason.
- **Plan now edits the whole Mind — actors included.**
  Actors were the one section Plan displayed but could not
  change; they are a form field now ("Name | internal or
  external | What they do"), with the framework's usual
  safeguard: a save that never mentions the field leaves
  the cast untouched. The journey builder's actor list
  reads from the form itself, so an actor added a moment
  ago is immediately available to build journeys for.

## Same-name slices can no longer be edited by accident (2 Nov 2026, night)

- **Manage mutations are path-exact.** Two installed slices
  may share a name (Settings does: the framework core slice
  and an app slice). Editing by name alone resolved through
  filesystem guessing, so the wrong twin could be changed.
  The Studio's mutation endpoints (fields, relationships,
  child tables, navigation, studio config, manifest sync,
  rollback) now accept the slice's exact path — which the
  Studio list already carries — and pin the edit to it.
- **Ambiguity is refused, not guessed.** A mutation that
  names an ambiguous slice without a path fails with the
  reason and the remedy; a path that matches no discovered
  slice of that name is refused; the core package slice is
  refused even with its exact path, as before. Declaration
  history filtering is path-aware too, so twins no longer
  mix histories. Unambiguous slices behave exactly as before.

## Copilot, answered like a product (2 Nov 2026, night)

- **The built-in responder was rebuilt.** It used to answer
  every question with the same inventory dump (on a real app,
  every slice name in one line). It is now intent-routed and
  answer-first: slice inventory is counts by domain; a named
  domain lists its slices; a named slice gets its registry
  facts; health is live counts. Every grounded answer labels
  its source ("Source: slice registry (live)" / "Source:
  database (live counts)"), and the fallback is a short,
  honest explanation of what built-in mode can do — verified
  live in the browser against the real chat endpoint (health
  went from a screen of text to five lines).
- **Propose-mode for the Mind.** The Plan editor gains
  "Propose with AI": describe a journey and the connected
  provider proposes additions as Plan-form lines. Proposals
  are validated against the real Mind — the actor must
  already exist and the journey must be new — and nothing is
  saved by the proposal itself: the lines land in the form,
  and the existing diff-before-save flow is the approval.
  Verified against the real Gemini free tier.
- The copilot bubble's footer no longer claims a provider
  that is not connected; it names the actual mode.

## The brief now seeds the Mind too (2 Nov 2026, night)

- **Drafting is trilogy-aware.** The brief-to-blueprint call
  now returns a second block: a Mind seed — the system's
  purpose, its actors and its main journeys, in the brief's
  own terms. The kickoff card shows the seed and offers one
  action, Add as the app's Mind, which writes
  porting/system.yaml for the Plan editor to complete.
- **An existing Mind is never overwritten.** Applying a seed
  is refused (with the reason stated) when a Mind already
  exists, the same rule the porter's plan draft keeps: a
  Mind is edited in Plan, not replaced by a draft. Sections
  a brief cannot know (roles, operations) start empty
  rather than invented.
- Verified against the real Gemini free tier: blueprint
  valid, seed faithful (Staff internal, Patient external;
  journeys Book an appointment and Filter appointments),
  and the apply guard exercised both ways in tests.

## First real drafting verification (2 Nov 2026, night)

- **The brief-to-blueprint pipeline is now proven against a
  real provider end to end.** With a Google Gemini free-tier
  key connected, the shipped drafting prompt and a plain
  clinic brief produced a blueprint that passed the same
  BlueprintValidator the Studio applies — two models, a
  declared belongsTo relation, no hand edits.
- The first real draft failed validation for one precise
  reason: the model declared a foreign key but no relation,
  leaving the referenced model unreachable. The drafting
  prompt now requires a relations entry for every foreign
  key, and the re-run passed. Real models found what stubbed
  tests could not.
- The validation failure message now quotes the first real
  error line instead of the generic "Blueprint is invalid:"
  header, which carried no information.
- Gemini's Flash-Lite models joined the provider catalogue
  and the Settings -> AI picker: on the free tier the
  flagship Flash model can be capacity-saturated (HTTP 503)
  while Flash-Lite answers immediately, and the free daily
  allowance lives on the Lite models.

## Real-provider verification pass (2 Nov 2026, night)

- **Verified against the live OpenCode Zen API** with a real
  connected account. The framework's OpenCode model catalogue
  had quietly rotted: the default model and six of the eight
  models offered in Settings -> AI no longer exist on Zen
  (the live list was read and compared ID by ID). The
  catalogue, the settings dropdown, the default and the
  free-tier fallback now carry the verified current models,
  with the free default moved to a single shared constant so
  the lists cannot drift apart again.
- **Provider failures now say why.** The AI HTTP client
  recorded the provider's status and message only in the log
  and returned a bare null, so the Copilot and Studio
  drafting could only report "the provider did not answer" —
  a quota limit, a rejected key and an outage looked
  identical. The client now keeps the last error in plain
  words ("HTTP 429: Free usage limit reached."), the engine
  exposes it, and Studio drafting quotes it in its failure
  message. A successful call clears it.
- The live completion check itself is currently quota-bound:
  the connected account's free tier returned 429
  FreeUsageLimitError, which is exactly the failure this pass
  learned to surface. The drafting pipeline is verified up to
  the provider boundary and by stubbed-provider tests.

## BRD -> drafted blueprint (2 Nov 2026, night)

- **The kickoff brief can now draft.** The Studio Home /
  Design kickoff banner gains "Draft blueprint from this
  brief": the connected AI provider drafts a blueprint,
  and the reply is extracted, parsed and passed through
  the same validator as any hand-written blueprint
  before it lands — as an unsaved draft — in the Design
  editor for review. Nothing is generated or written by
  drafting.
- **Honest when it cannot.** With no provider connected,
  or a reply that does not validate, the Studio says
  exactly that and points to Settings -> AI. The Home
  card's promise ("AI drafts a blueprint from it") is
  now kept wherever a provider is configured.

## Design panel diet (2 Nov 2026, night)

- **Presets are data now, not payload.** The built-in
  example suite (~155 KB of blueprint data) no longer
  ships inside every Design panel load; it lives in
  `blueprints/studio-presets.json` and is fetched once,
  lazily, when a preset is actually picked
  (`GET /laraslice/studio/blueprint/presets`). The Design
  panel drops from ~685 KB to ~543 KB, and every preset
  path (picker, deep link, ported-suite fallback) behaves
  exactly as before.
- **The YAML parser is a shared asset too.** js-yaml
  (~39 KB) no longer rides inline in the panel either; it
  ships as `resources/assets/js-yaml.min.js`, served
  cacheable (`GET /laraslice/studio/blueprint/js-yaml`)
  and lazy-loaded once per page. The panel's parse and
  YAML-generation paths re-run themselves when the parser
  lands, so nothing waits and nothing is lost. The panel
  drops from ~511 KB to ~473 KB, and repeat loads pay
  nothing for the parser at all.
- **The editor's own code is an asset too.** The Design
  component factory (~113 KB of JavaScript) no longer
  rides inside the panel fragment either; it ships as
  `resources/assets/design-factory.js`, served cacheable
  (`GET /laraslice/studio/blueprint/design-factory`). The
  Studio shell prefetches it and guarantees it exists
  before the panel initializes — the fragment now carries
  markup and the slice's own data only, at ~360 KB, down
  from ~685 KB when this diet began.

## Design pass 3 (2 Nov 2026, late night)

From a measured deep-dive of the Design mode:

- **Section quick-nav.** Chips (Definition / Access /
  Models & Fields / Relations / Workflow / Roles /
  UI Plan) jump straight to a section; a chip appears
  only when its section exists in the current blueprint.
- **Field filter.** Each model's field table gains a
  filter (name or label) with a live match count —
  finding one field among forty no longer means
  scrolling and eyeballing.
- **Start-here checklist.** A new blueprint opens with a
  four-step plain-language path (name → model → fields →
  validate) that ticks live and retires itself when the
  basics are in place.

## Studio UX pass 2 (2 Nov 2026, late night)

- **Manage is tabbed.** A sticky section bar (Structure /
  Data & Relations / Pages / Access / Activity) replaces
  the endless scroll through every editor at once.
- **The trust layer sits beside the slice.** The Activity
  section shows the slice's own declaration history —
  every recorded Mind/Body/Heart change — next to its
  version history and audit trail.
- **Create flows into Manage.** A successful generate
  offers "Open in Manage": the new slice, selected in the
  editor of record, one click from its success card.
- **Design drafts persist.** Save a blueprint draft
  server-side and resume it later from a banner — design
  work no longer evaporates on navigation. Drafts are
  working state, never declarations.
- **The YAML rail sticks** in split view, so the
  declaration stays visible while the visual editor
  scrolls.

## Studio improvement pass (2 Nov 2026, night)

From the Studio DX audit (a hands-on review of every mode):

- **One formatter vocabulary.** The directory-column
  formatters now live in a single registry owned by the
  runtime renderer; the blueprint validator and the
  Studio's column-type picker read the same list. The
  validator no longer rejects the plain `date` formatter
  that working slices declare.
- **One slice counter.** The Studio header, Manage and
  Operate all count slices by discovery; the Studio slice
  list is discovery-based and path-exact, so slices that
  share a name across domains are all listed.
- **Design honesty.** Switching slices stays inside the
  Studio shell (no full-page reload); edits raise an
  "Unsaved draft" badge; an installed slice carries a
  plain signpost — Design drafts, Manage is the editor of
  record — with a one-click jump.
- **Create gate.** Generating a slice with no fields asks
  first, in the styled confirm: an empty shell is a
  deliberate choice, never a silent default.
- **Operate shows the trust layer.** A Quality card
  reports conformance evidence, declaration drift and
  declaration history from the same services the CLI
  gates use.
- **CLI parity.** `slice:operate manual` generates the
  operator manual from the terminal (it was Studio-only),
  so scripts and AI agents hold the same Operate facts
  as the UI.

## Conformance evidence, declaration history, sync discipline (2 Nov 2026, evening)

The post-port pass closed with the measurement and
provenance layer:

- **Conformance reads port evidence.** Journey gates and
  audit runners record per-check results in
  `porting/evidence/<suite>.json`; `slice:conformance`
  measures the port by that evidence when no anatomy is
  recorded (structure still reports NOT MEASURED — the two
  are never blended), and a failing recorded check or a
  malformed evidence file fails the gate.
- **Declaration history.** Every change to the Mind, the
  Bodies and the Hearts is ledgered automatically —
  content-addressed snapshots plus an append-only index
  under `porting/history/`, recorded by slice:cache and
  slice:sync, listed and diffed by `slice:history`.
- **slice:sync hardened.** One run at a time (sync lock);
  `--dry-run` is pure — no migrations, no workflow syncs,
  no manifest writes, no history; applied migrations are
  verified against the migrations table before they are
  claimed; production requires `--force`; a
  non-interactive run with pending migrations fails with
  the instruction instead of silently skipping them.
- **Wizard repair.** The Studio wizard's init() lost the
  tail of its body when the demo preset's auto-apply was
  removed in the post-port pass, orphaning statements
  outside the method — a script syntax error that left
  the Create/Manage wizard inert. Repaired, behaviour-
  verified in a browser, and pinned by a regression test.

## Post-port experience pass (2 Nov 2026)

A second audit — of the ported system as its developers, Studio users
and AI agents meet it — drove one more fix round before this release:

- **The Studio opens on your system.** The Design panel's installed-
  slice scan is recursive (real installs nest slices under suite and
  area folders; a two-level scan found nothing and the picker never
  rendered), the Create wizard opens blank (it auto-applied a demo
  preset on every install), and panel URLs visited directly redirect
  into the Studio shell instead of rendering a naked fragment.
- **Conformance never fabricates a pass.** With zero recorded pages
  the gate reports NOT MEASURED (percent null) and fails on any app
  that has slices installed — a "100%" computed over nothing is
  worse than no number.
- **Schema cache never caches a missing table.** A table probed
  before it exists (install, migration, a database rebuilt under a
  running server) becomes visible the moment it is created. The
  stuck negative silently suppressed audit-trail writes for the
  whole life of one ported server.
- **Blank repeater rows are the form's empty state, not data.**
  Saving a record no longer inserts the repeater's untouched
  template row — a junk child at best, and a failed save wherever
  the child table has a NOT NULL column the form does not carry.
- **Feature-module switches are behaviour.** A slice may declare
  `feature_module`; where the host app carries the legacy
  module_settings table, a module deactivated for a user type
  leaves that type's navigation and refuses its pages.
- **Structure audit reads markup, not comments.** Blade comments
  are stripped before scanning, so a contract comment that
  mentions a control is no longer flagged as the control.
- **Operate health carries timings** (database round-trip and
  slice discovery), and the porter's reference-data carry now
  includes the settings singletons and lookup tables a sweep
  found empty on the ported side.

## Porter

- **Role grants are carried.** The data porter now translates legacy
  permission names onto framework permission slugs (legacy nouns name
  entities; entities are slices — keyed on navigation label, slice name,
  title, and root table, with a token-vote fallback) and carries
  role→permission grants on auth import. Scope is carried faithfully, not
  binarily: legacy scopes resolve **by name** (added · owned · both · all ·
  none) into a typed grant pivot — an earlier binary read, keyed on scope
  ids, silently widened some roles and dropped others, and the audit caught
  it. Untranslatable permissions are reported by name, never guessed.
  Previously the porter imported users and roles but no grants, leaving
  every non-admin surface locked.
- **Schema closure covers auth tables' descriptive columns.** Closure no
  longer skips auth tables wholesale: non-sensitive columns (profile fields,
  display names, last-login) close like any other table under a hard
  blocklist — credentials, tokens, MFA material, payment identifiers, and
  permission payloads never cross. Values pair by natural key (users by
  email, roles/permissions by name), because the auth merge reassigns ids.
- **Foreign keys resolve by evidence, not by column stem.** Generated
  belongsTo relations whose target model name differs from the FK stem no
  longer throw on traversal: the generator resolves targets through an
  evidence chain (record-prefixed table → conventional table → fused form →
  plural-prefixed → unique suffix → same-table siblings → people-are-users),
  and every candidate must actually resolve to a class before it is emitted.
  The same chain runs as a `slice:repair` pass over already-generated models:
  in the convergence port, 205 of 262 belongsTo lookups now resolve, with the
  remainder reported in named buckets (missing models, genuinely ambiguous
  targets, misderived relations) instead of being guessed.

## Hub composition (Body)

- **Identity pattern.** A hub can declare `ui.show.identity` — name,
  subtitle, meta line, and an About field — rendered as the standard
  identity + About cards at the head of the overview, with bare paths
  delegating to the record's linked user.
- **Widget relation fallback.** Declared widgets no longer require a
  relation method on the generated model: aggregates, lists, and charts
  resolve through user-scope columns, back-references, and pivot joins,
  in that order — and declared empty states render instead of vanishing.
- **`pairs` widget type.** Labelled text pairs (a reporting card, a contacts
  card): rows resolve dotted paths like detail fields; missing values render
  as an em dash and the card renders even when every row is empty.
- **Current-period scopes.** Stats measures accept a reserved `_period`
  filter (e.g. current month on a date column) — the source semantics for
  attendance-style figures, which are period counts, not all-time totals.
- **Measure defaults.** A stats measure may declare `default:` — an unset
  numeric field reads as the default (budgets read 0, not a missing row).
- This release also completes the candidate's hub-composition trio and
  porter pair: hub tabs compose from declared anatomy, and the porter
  derives directory landing order and navigation from source evidence.

## Forms

- **Edit forms no longer destroy data they cannot render.** A string column
  that merely ends in `_id` (an employee code) is no longer rendered as an
  empty relationship combobox — the combobox branch requires FK shape — and
  date/datetime inputs emit values in their own format (a raw timestamp
  rendered an empty input whose next save wrote NULL). A scripted
  field-survival check now guards the class.

## Shell

- **The AI Copilot pill no longer covers form actions.** It renders below
  sticky action bars, so Save buttons stay clickable with the pill present.
- Directory tab ribbons collapse overflow into a More menu; directory
  landing order is a declared contract, not filesystem luck.

## Simpler on-ramps (the six-document review)

- **The pre-build dossier (SKILL §28).** A new project now starts
  from six named slots everyone already knows — PRD, TRD, App
  Flow, UI/UX brief, backend schema, implementation plan — each
  mapped to its LaraSlice artefact. The TRD ships as a one-page
  template (`docs/templates/TRD.md`) covering only the choices
  LaraSlice leaves open.
- **Success metrics are a Mind slot.** `success_metrics`
  (name + target) drafts with the Mind, round-trips through the
  Studio Plan form like journeys do, and is documented in the
  Mind schema.
- **The App Flow is generated.** `slice:flow` renders journey
  lanes (from the Mind) and the navigation map (from the slices'
  own declarations) as a standalone HTML page or a Mermaid
  diagram — founders think in screens; nobody hand-draws it.
- **The five gates have plain names.** Structure, Data,
  Furniture, Tasks, Visual — said first everywhere, with the
  S·D·F·T·V letters kept only as shortcuts (SKILL §22), and
  status files lead with a plain-words scorecard.
- **The skill is now a family, arranged to the skills.sh
  standard.** One folder per skill, each with routing
  frontmatter (name + when-to-use description), worked examples
  and references inside: `laraslice` (the core — 2,999-line
  monolith slimmed to ~740 lines, its multi-table architecture
  moved to `references/`), `laraslice-start` (greenfield, with
  the Books slice and a complete library Mind as example
  files), and `laraslice-port` (the porting protocol).
  `skill:publish` now publishes the whole family into
  `.agents/skills/`. Nothing was deleted — every section of the
  monolith is accounted for in exactly one home.
- **The SDLC tail gets its own skill — and its missing CLI.**
  `laraslice-operate` joins the family: customize, optimize,
  improve, operate, and upgrade a live system, every job
  mapped to its CLI, Studio, and AI surfaces. The audit
  behind it found Operate-mode facts (health, backups) were
  Studio-only; the new `slice:operate` command closes that —
  health checks (non-zero exit on failure, cron-friendly),
  the backup list, and running a backup that honours the
  Mind's declared includes, all from the terminal.
- **The on-ramp, audited and fixed.** An independent fresh-start
  audit (a newcomer given only the README + skill) found the
  greenfield path buried and self-contradictory; the fixes ship
  here: a START-HERE door router at the skill's head, one
  canonical "Mind first" Quick Start in the README (release
  essays moved below it), a complete worked Books `slice.yaml`
  in §24, and one declared fields story — the Heart is the only
  home for fields; `slice:make --field` / `slice:field` are pens
  that write it, the PHP Schema class is a printout generated
  from it (§2). A phantom command (`slice:generate`) was removed
  from the docs — it never existed.

## Working with spec-driven toolkits

- SKILL §27 names the shared six-step path (constitution · specify · plan ·
  tasks · build · converge) for teams arriving from a spec-driven toolkit:
  documents propose, declarations dispose, and converge re-runs the gates
  rather than accepting a self-reported status.

## Permission scopes are runtime behaviour, not just carried data

- **A scope layer for grants.** Grants carry one of five scopes (added ·
  owned · both · all · none; legacy grants without a scope read as "all").
  Directory lists and record pages narrow by the viewer's scope: owned
  records resolve through the slice's declared `access.own_scope` (a field,
  or a pivot clause for membership-shaped ownership), added records through
  the record's creator, and a user's own hub record opens without a view
  grant. The widest scope wins when a role holds several.

## Design kits (Soul)

- **The rendering kit is a declared choice.** The starter layout can emit a
  named kit stylesheet alongside the house design system: the Mind declares
  `design.kit`, a `?kit=` parameter overrides it for a session, and
  `slice:install` publishes the kits. A source-faithful port declares the
  replica kit and renders in the original's skin while its declarations —
  Mind, Body, Heart — stay exactly the same; platform and Studio routes
  always render in the house kit.

## Directories that finish the job (the interaction layer)

- **Imports.** A directory may declare `ui.directory.import` (a column map);
  the import writes only declared columns, and the declaration is the
  allowlist.
- **Server-side XLSX export.** The directory Export action streams a real
  spreadsheet from the same filtered, scope-narrowed criteria as the list
  itself (the criteria builder is shared, not duplicated), with foreign-key
  values resolved to their display labels.
- **Record actions, declared.** `ui.actions` gives a record row menu real
  verbs: `link` and `copy` with URL templates, `send` (mail the record's
  link to its client), and `method` (a named method on the slice's own
  service — the home for operations like converting a lead or generating a
  payslip).
- **Public record links + print.** A slice may declare `ui.public_link`; the
  record's stored token serves an unauthenticated read page, and every
  record page answers `?print=1` with a print surface.
- **One delete dialog.** Destructive actions confirm through a single styled
  dialog with the source's wording, everywhere — row menus, bulk delete,
  and legacy confirm forms alike. Directory row-menu delete, previously a
  silent no-op in generated views, now submits a real delete.
- **Declaration hygiene.** A save-path guard skips repeater definitions
  whose foreign key does not exist (they 500'd record creation), and
  `slice:drift --structure` audits declarations for the class, so the next
  occurrence is caught by the gate, not by a user.

## Reports and conversations

- **Report group labels resolve through the model.** When a report groups
  by a foreign key whose options live outside the conventional tables, the
  label falls back to the model's own relation — grouped reports name
  their groups instead of printing raw ids.
- **Flat conversations.** The conversation pattern gains a flat mode for
  pair-shaped message tables (one row per message, grouped by the other
  party): opening a thread marks incoming messages seen, and sending
  fills the table's legacy mirror columns when it has them.

## Agents as first-class users (MCP + gates)

- **Dual-era MCP.** The MCP server negotiates both the current stateless
  protocol and the earlier stateful-era versions, from the request body or
  the protocol header.
- **MCP resources.** `resources/list` / `resources/read` serve the skill
  index, the gate definitions, the docs index, the slice index, and the
  command index — an agent can read the framework's own map before it
  acts.
- **Gates speak JSON.** `slice:drift --format=json` and
  `slice:conformance --format=json` emit structured results whose every
  finding carries a concrete fix hint, so an agent (or CI) can act on a
  gate without scraping prose.

## Settings hub conventions

- **Bound toggles keep the table's own convention.** Settings sections
  bound to existing tables write toggle values in the domain the table
  already uses — yes/no stays yes/no, active/deactive stays
  active/deactive, enabled/disabled stays enabled/disabled, integers stay
  integers — on singleton forms and on keyed-rows sections alike. One
  convention helper, four call sites, pinned by tests.
