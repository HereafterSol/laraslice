# The 100% Replica Protocol

How a port earns the word "replica". Porting a running system into
LaraSlice is gated, not vibes: five gates, in order, and a port's
status is its **lowest unverified gate**. Every port keeps one
`porting/REPLICA-STATUS.md` at the application root — per-gate results, the task matrix,
deviations, and a NOT-covered list — and replica status is quoted
only from that file, with its date. Copy the template from
[docs/templates/REPLICA-STATUS.md](templates/REPLICA-STATUS.md). See also
[ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md) (framework design vs port completion).

## Phase 0 — the composition map

Before any table is ported, name the cross-model surfaces: which
records are born from other records (an employee is an account plus
a details row plus a directory row), which pages compose several
models, which actions write across tables. A directory invented
per table — with no account behind it — is a defect found here,
not a design choice discovered later.

## Gate S — schema parity

Column-level diff between the source schema and the ported schema:
every source column accounted for (carried, renamed with a note, or
dropped with a reason). The porter skipping a column silently is
the most common way a port loses behavior it later "discovers".

## Gate D — data truth

ETL is verified by joins, not row counts: sample records are
followed across their relations in both systems and compared
value-for-value. Foreign keys copied verbatim while id spaces
shifted is a Gate D failure even when every count matches.

## Gate F — furniture

Page furniture parity: the labels, columns, counts, cards, and
placement a user actually sees, compared element by element on
paired pages — values, not just structure.

## Gate T — task conformance

The same journeys in both systems (create, edit, approve, invite,
import…), each asserted against the database afterwards. A journey
that renders is not a journey that works.

## Gate V — visual conformance

Composition read **by eye** from paired screenshots: shell,
chrome, region order, density, what leads and what follows. A
token probe (computed styles) is not a visual gate — tokens cannot
see region order. The layout kit exists because of this gate:
shell variant, single-band directory chrome, the declared hub
composition (`ui.show.composition: overview`), and the portal
dashboard composition are the standard-layer primitives a port
uses to converge — never per-page CSS.

## Doing a port — the guided path

Porting is deliberately mechanical. Whether a developer or an AI
assistant drives it, the steps are the same:

**For developers**

1. **Capture the anatomy** — `slice:port --anatomy` records every
   source page's interaction inventory as porting evidence. A
   non-Laravel source is pointed at its SQL dump or live
   database; the porter hard-fails rather than guess.
2. **Map the composition** (Phase 0) — the cross-model surfaces
   named before any table moves.
3. **Port** — `slice:port`, with `--permissions` for the source's
   role matrix and `--table-prefix` to coexist with an existing
   install. Name collisions resolve by twin namespacing and are
   reported.
4. **Move data, then prove it by joins** (Gate D) — never by the
   loader's own counts.
5. **Declare the surface** — filters, hub composition, dashboard
   widgets, the replica shell: all Heart/Body declarations, no
   per-page code.
6. **Repair and cache** — `slice:repair` retrofits the patterns
   fleet-wide; `slice:cache` compiles the registry.
7. **Walk the gates** S → D → F → T → V and record the results,
   residuals included, in the port's `REPLICA-STATUS.md`.

**For AI assistants**

The framework ships an AI skill that teaches this exact
protocol — the same gates, the same evidence rules, the same
status file. An AI porting with the skill works the developer
track with a stricter discipline: status quoted only from
`REPLICA-STATUS.md`, Gate V read from screenshots rather than
measurements, fixes made at the generator/standard layer so
every future port inherits them, and deviations recorded for
the owner's call — never self-approved. That symmetry is the
point: a port's quality does not depend on who (or what) drove
it.

## Claiming status

Say the gate, say the date, name the residuals. "Converged with
residuals" is a complete sentence; "pixel-perfect" is not a claim
this protocol makes.
