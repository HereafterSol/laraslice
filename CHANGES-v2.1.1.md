# v2.1.1 — The Evaluator Patch

Released 2026-11-10

A patch release driven by evidence, not features: the
v2.1.0 flagship was handed to two independent evaluators
working cold — one installing and driving the shipped demo,
the other porting a production application from scratch
with the shipped skill — and the owner then ran the demo
himself and reported it felt slow and unlike the original.
Every finding that survived verification against the code
was fixed at the framework or packaging layer; the rest are
named below with their dispositions. The flagship demo's
packaging pass is completed in this release, so the demo
now carries the same directory behaviour as a fresh port.
Full framework suite: **981 tests, 3,793 assertions**
(v2.1.0 shipped at 953 / 3,709).

**Packaging:** as v2.1.0 — the flagship demo artefact
(`flagship-erp-v2.1.1.zip`) vendors the framework inside
the demo (`packages/laraslice`, pinned by a regenerated
lock, now also carrying the optional Blaze integration)
and installs offline.

---

## 1. Performance & directory experience (owner-tested)

The flagship's record hubs and directories were measured
against the original software on the same machine: hubs
were up to 27× heavier. Four changes, all declaration- or
component-level, none per-page:

- **Lazy hub tabs.** A slice may declare
  `ui.show.lazy_tabs: true`; non-landing relation tabs then
  ship as counts and load their fragment on first view
  (deep-linkable via `?tab=`). On the flagship's employee
  hub this cut the initial payload from 3,073,525 to
  506,009 bytes (−84%) — the bulk had been the permissions
  matrix, not the relation tables. Chart and audit tabs
  stay eager by design.
- **In-place directory updates.** Inline edits, workflow
  transitions, and quick filters now swap the directory
  region in place via a fragment round-trip
  (`?ls_fragment=directory`) instead of reloading the page;
  any failure falls back to ordinary navigation. The
  filters drawer's Apply button uses the same path and
  closes the drawer on success.
- **Quick-filter vocabularies resolve fully.** A filter's
  options now resolve with a declared precedence
  (`options_from` → declared `options` → the field's own
  declared options), so selects the porter left unbound
  render their real values; the porter also derives
  people facets against the table people actually live in.
- **Blaze at leaf scope.** The compiler is adopted only
  where it is provably byte-identical — slot-free leaf
  components (icon, spinner, separator) are memoized — and
  the framework registers a no-op `blaze` directive when
  the package is absent, so the components render with or
  without it. Directory-scope compilation was tested and
  **rejected**: it silently dropped rendered output. The
  demo's production posture (OPcache, and Octane —
  verified working against the flagship, with worker
  sizing notes) is documented in its INSTALL.md.

## 2. Regeneration completes the fields it stages

`slice:regen-artefacts` staged a slice's business objects
from the top-level field map only, so columns declared
solely in the root model's field list never reached the
staged objects — and the loss detector then held the
slice's Contracts back indefinitely. Staging now completes
the field set from the root model's column list (with its
labels, types, options, and constraints). Adoption stays a
deliberate per-slice act; the affected slices are simply
unblocked.

## 3. Cold-port robustness (independent evaluation)

- **Mind drafting no longer crashes on porter-shaped
  declarations.** Drafting a Body from a slice whose fields
  declare their relation as a map — the shape the porter
  emits — died with an "array to string conversion" error,
  and the Mind was never written. The reference is now
  extracted from the map (slice / model / table), with a
  column-stem fallback; the sibling casts on the same path
  are guarded the same way.
- **Migration row-size preflight.** A port's migration run
  could halt midway on MySQL's row-size limit (error 1118),
  leaving a half-migrated schema. Before anything migrates,
  every `CREATE` about to run is estimated (charset-weighted
  worst case); any table over the InnoDB limit stops the
  run up front, naming the table, its estimated size, and
  the remedy. Calibrated against a production port's 218
  migration files with zero false positives.
- **`slice:links` reports collisions precisely.** Two
  slices owning the same table as their *primary* table is
  still a failure. A table merely modelled by several
  slices — the ordinary parent/child composition — is now
  reported as information, naming the primary owner,
  instead of fifteen indistinguishable failures burying
  the real ones.
- **Packaging completeness.** The release archive asserts
  the skeleton directories a cold install needs
  (`bootstrap/cache`, storage framework dirs) — their
  absence in v2.1.0 halted `composer install` on a cold
  Windows extract. The flagship package also gains the
  demo-side wiring this cycle's framework work assumes:
  quick-filter hooks on every directory, current-generation
  record pages for the slices whose declared tabs need
  them, and lazy tabs enabled on its two heaviest hubs.

## 4. Verified, and named remainders

- Two independent evaluations of v2.1.0 are now fully
  dispositioned: every code-level finding is either fixed
  above or named here. The cold port's fidelity scorecard
  (94.4, "not shippable" on a raw port) is unchanged by
  this patch: the distance from a raw port to shippable is
  the closure programme (reconciliation, absorption,
  settings wiring), which a future release binds into the
  port skill's happy path.
- The demo fleet is 164 slices; the reference port carries
  174. The ten additional slices are settings and lookup
  slices added to the reference port after the demo's
  dataset was frozen; growing the demo fleet is a dataset
  decision, not part of this patch.
- A benchmark-gate command (scorecard + scripted journeys
  + evidence in one invocation) remains a named candidate;
  today those gates run as separate commands and scripts.
