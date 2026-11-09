# v2.1.0 — The AI Surface, Completed

Released 2026-11-09

This release completes the runtime programme the foundation
was pointing at: the declarations now serve themselves to
every consumer — developer, Studio, MCP client, or a small
local model — through one projection and one discovery
ladder, and AI-initiated writes execute only behind a
signed, single-use confirmation. It was driven by a full
functional audit of the flagship demo, a screen-by-screen
comparison against a production application running the
original software, a day-2 operations drill, and the
four-phase runtime programme itself. Every phase passed an
independent zero-context review before it was accepted. The
full framework suite passes: **953 tests, 3,709 assertions**
(v2.0.2 shipped at 700 / 2,557).

**Packaging:** the flagship demo artefact for this release
(`flagship-erp-v2.1.0.zip`) vendors the framework inside the
demo (`packages/laraslice`, pinned by a regenerated lock), so
it is self-contained and installable offline. v2.0.2's
package instead resolved the framework from the public
repository at install time.

---

## 1. New commands

- **`slice:regen-artefacts`** — re-syncs a slice's generated
  service, model, record views, and (new in this cycle) its
  three Contracts from the declaration. A name-keyed loss
  detector blocks any regeneration that would drop
  hand-written code.
- **`slice:absorb`** — the reverse path: reads hand edits in
  generated files back into the declaration as proposals,
  classified declarable · vocabulary-gap · hand-owned. New
  declaration vocabulary landed with it: `hasManyThrough`
  relations and field-level `cast:`.
- **`slice:alter`** — additive-only declaration→database
  alters. Generates one guarded migration for new columns;
  refuses — by name — type, length, and nullability changes
  and unsafe NOT NULL additions. Undeclared database columns
  are reported, never dropped.
- **`slice:corpus`** — runs the cross-runtime conformance
  corpus (declaration set + shared assertions + fidelity
  table) against this runtime's metadata projection.

## 2. The AI surface (runtime programme, four phases)

- **Runtime metadata projection.** Schema and discovery are
  served from the declarations at runtime: a compact fleet
  index (one row per slice) and a per-slice describe (fields,
  relations, operations, auth), cached by file content hash
  with ETag/304 support, pagination, domain filtering, and a
  live row-count overlay that is never cached. UI detail is
  served only on request.
- **One discovery ladder.** MCP tools, the Copilot's provider
  path, and HTTP all climb index → describe → call. Payload
  budgets are per capability tier (`ai.ladder`: frontier /
  free / webgpu / on_device; an unknown tier falls back to
  `free`). The Copilot's provider path no longer assembles a
  whole-fleet context dump per call.
- **Schema contract + Explorer.** A versioned per-slice
  contract (`laraslice/slice-schema` v1, with a JSON-Schema
  rendering) and an Explorer page that generates invocable
  forms for the operations the schema can derive (read,
  read-one, create, update, delete); everything else is
  inspect-only with the reason stated. Validation failures
  return per-issue errors (`{field, problem, expected}`)
  shaped for self-repair.
- **Two-phase confirmation for AI writes.** No AI-initiated
  write executes in one step. A write returns a proposal — a
  validated approval spec from a typed component catalog
  (operation · field · warning · note · expiry) — plus a
  signed token bound to the surface, the tool/operation, the
  exact arguments, and the proposing user. Execution verifies
  signature → binding → user → expiry → argument hash, then
  consumes the token (single-use; a failed verification does
  not consume it). Default lifetime 300s
  (`ai.confirmation.ttl_seconds`). An application with no
  APP_KEY configured fails closed: nothing is issued and
  nothing verifies. Coverage: the six MCP write tools and
  every Explorer write. **Named boundary:** direct HTTP with
  a human session is ordinary UI use and is not gated;
  tokens issued on the user-less stdio channel are bound to
  the channel, not to a person.

## 3. Write-path behaviour — read this section before approving

These are deliberate behaviour changes for existing consumers:

- **Strict binding.** Slice write paths (generated store,
  update, and inline update alike) reject type-invalid input
  with a **422 carrying per-issue errors** instead of
  coercing it or failing obscurely. Text into a foreign key
  is the canonical case. One exemption: a blank string on a
  blank-allowed field still means "clear this field".
- **Declared update semantics.** Updates are `patch` by
  default (only provided fields change — this codifies prior
  behaviour, per the programme's goldfish review). A slice
  may declare `replace`, under which a partial body is an
  error naming the missing fields. The projection serves
  `update: {mode, declared}` per slice; an unrecognised
  declared value is reported as `declared: false` and the
  slice behaves as patch.
- **Permission re-sync totals.** A from-scratch rebuild now
  derives the grant state deterministically: 1,148 permission
  rows on the reference fleet (carried grants with a module:
  427). The same total was reproduced by an independent
  rebuild, so upgrades converge instead of drifting.
- **Config additions are optional.** `ai.ladder` and
  `ai.confirmation` have built-in defaults (free tier, 300s).
  An existing install with an older published config boots
  and behaves identically — verified in the prep QA by
  deleting both sections from a live install's config.

## 4. Porter

- **Fidelity scorecard as product.** A port run now emits its
  own scored fidelity artefact in the port matrix's
  vocabulary. The reference live run scored **80.4 — NOT
  SHIPPABLE**, correctly failing on unmapped pivot foreign
  keys; a deliberately degraded declaration set re-scored
  **60.9**. The scorecard can fail, which is the point.
- **Precision fixes.** Decimal length/precision is no longer
  dropped at any point of the port path (source
  `decimal(10,8)`-class columns arrive intact), and blueprint
  apply — CLI and Studio — reports success only after
  verifying the planned tables exist.

## 5. Studio

- The save-preview diff modal binds correctly; slice
  directories resolve at the framework's real nesting depth;
  a validate endpoint lets the generation engine itself judge
  a declaration (the Studio chip composes syntax + engine
  verdicts); the Design factory loads in the standalone
  Studio wrapper; declaration history reads the ledger's real
  keys.
- New slices are born in the canonical declaration shape, so
  a Studio-born slice's first preview diff is ~132 characters
  (previously ~5,200 of phantom changes).
- The Copilot routes slice questions to slice facts first,
  counts from one inventory source, and closes count/health
  answers with a named basis line.

## 6. Declared UI + settings

- **New declared tab types:** `permissions` (a unified grant
  matrix over both permission families, with a per-user
  typed-scope layer), `chart` (burndown), `calendar` and
  `thread` relation-tab render modes, a `stack` composition
  mode, and `ui.show.default_tab`.
- **Settings wiring.** One framework read path consumes the
  settings hub's bound tables — company identity, app name,
  currency formatting, theme tokens, storage — so a hub edit
  now visibly takes effect on the surfaces that display it.
- **Custom Fields runtime.** Declared field groups render on
  slice forms and record pages with faithful storage
  semantics (select stores the option index; checkbox joins
  with `', '`; required-only validation). Management slices
  included; new slices are generated with the hooks and
  existing fleets are healed by `slice:repair`.

## 7. The flagship demo

Found by a clean-room functional audit and a screen-by-screen
comparison against the original software — not by install
smoke:

- Role-composed dashboards with every link crawling clean;
  record hubs composed (tasks, invoices); the task board
  rebuilt with drag; messaging sends end-to-end.
- A 76-tab sweep's defects fixed, including 44 missing
  relation methods across eight hub models.
- Security pass: revoking a session now destroys the stored
  session (previously it deleted only the registry row);
  provider keys encrypted at rest; password policy and MFA
  verified intact.
- Document forms gained real line-item builders with shared
  arithmetic; account-creating flows (add employee, add
  client) create real accounts; reports menu, exports, and
  print verified.

## 8. Verification behind this list

- Framework suite 953 / 3,709, zero regressions across the
  cycle; every programme phase additionally passed an
  independent zero-context review ("goldfish") whose findings
  were resolved or named in the phase reports — including one
  fatal find (a shipped page that could not execute confirmed
  writes) fixed and then proven in a real browser.
- Scripted end-to-end journeys on the reference port: 24/24,
  re-run at prep close.
- The prep package was fresh-installed from its lock and
  smoke-tested beyond login: dashboard, directories, a real
  form create verified in the database, the Studio, and the
  metadata index — including completing the shipped MFA
  enrolment flow rather than bypassing it.
- Evaluator paths re-walked: fresh install via `slice:install`
  (first slice generated, migrated, served); an existing
  install with a stale published config (defaults hold); the
  MCP consumer surface (protocol instructions, fleet index,
  schema, and a gated write that executed nothing).

## 9. Named remainders (not in this release, not hidden)

- The Studio save path merges declarations differently from
  the preview path; they agree on canonical declarations
  today. Aligning them is a separate decision.
- Regeneration covers the Contracts, but the absorb reverse
  path still classifies only its original three artefact
  kinds; Contract classification is future work.
- Migrations that `slice:alter` records for manual recovery
  do not all replay cleanly (enum restoration is the known
  gap); the forward path is unaffected.
- The conformance corpus is a **pattern**: it proves the
  Laravel runtime against the shared assertions. Cross-runtime
  proof awaits a second runtime.
- Custom Fields do not yet appear as directory export
  columns, and the public lead/ticket form wiring is not
  connected.
- Two settings fields (`link_color`, `user_css`) have no
  consumer in the original software either and remain
  unmapped; two storage drivers (Google Drive, Dropbox) have
  no framework driver and stay inert by guard.
- A cache flush inside a confirmation token's 300-second
  life weakens replay protection for that window to
  signature + binding + expiry; the ledger deliberately lives
  in the cache store for this release.
