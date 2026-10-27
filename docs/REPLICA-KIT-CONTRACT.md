# Replica Kit Contract — kit #3 (Tier-5 P3, 24 Oct 2026)

A third kit that renders kit #1's markup with the SOURCE
application's visual identity, built entirely from the
captured design spec (`porting/ui-spec/source.design.yaml`,
Tier-5 P1). Purpose: close the Tier-5 visual deviations the
conformance gate records — a port that is structurally
identical must also be able to LOOK like its source when
the port's owner chooses the replica kit.

## What the replica kit is

- Same BlatUI components, same markup as kit #1 (Gate-1
  structure verified IDENTICAL on Employees list + detail,
  `probes/replica_verify.py`). The kit is a token layer
  plus a component-style layer:
  `demo public/css/replica-kit.css`, scoped to
  `[data-kit="replica"]`, loaded by the demo layout when
  `?kit=replica` (spike-grade selection, like kit #2 —
  productized kit selection is a separate decision).
- Token layer (CSS variables the Tailwind @theme inline
  chain resolves at runtime): `--primary` =
  rgb(29, 130, 245), `--font-sans` = "Helvetica Neue"
  (single family, so the computed value matches the
  source's exactly), `--radius` = 6px (rounded-md resolves
  to 4px), the full `--sidebar*` set (bg rgb(23, 31, 41),
  foreground rgb(163, 179, 199), accent/border white 8%),
  and `--sidebar-width` = 240px declared `!important` at
  the sidebar-provider slot (the provider carries the
  variable inline at 256px; the important redeclaration is
  the contained override).
- Component-style layer (kit #1's own hooks, no new
  markup): primary actions `:is(a, button).bg-primary` →
  39px / r4 / 400 / 14px; `thead th` → 13px / 500 /
  rgb(153, 165, 181); cards + the table wrapper → r4 +
  the captured shadow rgb(210, 217, 228) 0 0 4px; status
  pills in table cells → transparent fill + a 6px dot in
  the pill's own text colour (the source's dot-pill
  language).

## Measured result (24 Oct 2026, Playwright + Firefox)

- Employees list, replica vs source spec: primary,
  sidebar bg/width, font, button height/radius/weight,
  table-header size/colour/weight — ALL equal
  (`probes/replica_verify.py`, `replica_tokens_match_source`).
- Gate-1 structure identical to kit #1 on list + detail.
- Full visual gate (`slice:conformance --visual`, port
  capture under `?kit=replica`, comparable-state rule for
  view-toggled pages): **12/12 pages visually conforming**
  — every visual candidate closes; the 12 register entries
  recorded against the default kit read stale under this
  capture and stay OPEN in the register until the product
  owner rules on the default kit (see the register's
  measurement-basis note in porting/deviations.yaml).

## The honest boundary

This kit does NOT synthesize markup. Row composition
(the source's taller, two-line project/invoice rows),
the detail pages' chart widgets, and the source's
overlay-styled create pages remain markup-level
differences a stylesheet cannot close; they belong to
the JEV synthesis layer (still unbuilt — SKILL §17
status note) and to the widget-renderer work (backlog
A6/D2/D3). The replica kit closes the IDENTITY layer —
colour, typography, shell, and component geometry —
which is what the visual gate asserts (§19B).

## Addendum (26 Oct 2026) — from token kit to layout kit

The closing note above is superseded in one respect: the
composition differences a stylesheet could not close are now
addressed by standard-layer primitives, not per-page work —
the replica shell variant (flat module navigation, brand +
user header), strip filters fused into the one directory
band (`filterbar-extra`), the declared hub composition
(`ui.show.composition: overview`), and the portal dashboard
composition (dual-figure counters, `table`/`calendar`
widgets, declared order). The kit's contract is unchanged —
tokens only — but it no longer carries the visual gate alone:
Gate V (see `docs/REPLICA-PROTOCOL.md`) reads composition
from paired screenshots, and the layout primitives above are
what a port declares to converge.
