# Kit Contract — kit #2 (token-swapped spike), P4 proof (24 Oct 2026)

A second kit, built ONLY from the token layer, to prove the kit seam
is real: the same Body/Heart declarations render through different
tokens with Gate 1 (structure) unchanged.

## What kit #2 is

- Same BlatUI components, same markup. Only the DESIGN tokens change,
  via `[data-kit="kit2"]` overrides loaded after the kit #1 bundle
  (demo: `public/css/kit2-spike.css`, gated on `?kit=kit2` in
  `resources/views/layouts/app.blade.php` — spike scaffolding, not a
  product feature; remove both together when retired).
- Tokens changed (everything else inherited from kit #1):
  `--primary: oklch(0.50 0.19 255)` (was `oklch(0.205 0 0)`),
  `--primary-foreground` unchanged white, `--ring` + `--sidebar-primary`
  + `--sidebar-ring` follow the new primary, `--radius: 1rem`
  (was `0.625rem`). The Tailwind v4 `@theme inline` chain resolves
  utilities through these vars at runtime, so the override propagates
  (rounded-md = radius − 2px: 8px → 14px, measured).

## Contract-table subset (regions exercised by Employees list/detail)

| Region | kit #2 rendering | Guaranteed semantics (same as kit #1) |
|---|---|---|
| breadcrumb | x-ui.breadcrumb set | nav[aria-label=breadcrumb], current page aria-current |
| title | shell header heading | single h1 per page |
| header_actions | x-ui.button / button-group | buttons in declared order |
| facets | x-ui.filter-tabs / native select | GET form semantics; active state visible |
| toolbar | data-table toolbar slot | search; drawer trigger button |
| actions | directory actions band (belowToolbar slot) | primary first; order = declared order |
| grid | x-ui.data-table | table semantics; first column = identifier |
| pagination | data-table footer | "Showing X to Y of Z"; prev/numbered/next |
| identity | show-page header card | avatar + title + subtitle composition |
| highlights | detail field rows (DirectoryUi) | label/value pairs |
| sections | x-ui.card + split-line rows | grouped label/value rows |
| tabs | x-ui.tabs set + related-tab-table | tablist semantics; each tab = full list contract |

## Measured result (24 Oct 2026, Playwright + Firefox, `probes/kit2_verify.py`)

- Employees list (`/hr/employees`): Gate 1 structure IDENTICAL —
  h1 "Employees", breadcrumb "Dashboard HR Employees", all main
  buttons in order, table headers (Select · Employee ID · Name ·
  Email · User Role · Status · ACTION), 11 rows, pagination.
- Employees detail (`/hr/employees/2`): Gate 1 structure IDENTICAL —
  h1 "Marjolaine Altenwerth", 11 tab sections in order (Projects ·
  Tasks · Leaves · Leaves Quota · Timesheet · Documents · Emergency
  Contacts · Ticket · Attendance · Appreciation · Shifts).
- Tokens rendered: primary element bg `oklch(0.205 0 0)` → 
  `oklch(0.5 0.19 255)`; radius `8px` → `14px`.
- Screenshots: `laraslice-work/screenshots/kit2-list-{kit1,kit2}.png`,
  `kit2-detail-{kit1,kit2}.png`.

## What this does NOT prove (stated plainly)

Kit #2 reuses kit #1's component markup. A kit that renders
DIFFERENT markup (a Material/Ant-style kit) behind the same Body
still needs the JEV synthesis layer (Body → kit contract →
components) — decided 23 Oct, not yet built. The spike proves the
token layer is swappable and Gate 1 is kit-independent; it does
not claim markup swappability.

## Side finding (fixed the same session)

The detail half of this spike was blocked by a real defect: the
employee hub took 61.9s and 500'd on the standard 30s server.
Cause: `SliceManager::discover()` re-parsed all 140 manifests
(~0.92s) on EVERY call, and the hub's tab enrichment calls it
~60 times per render. Fix (framework-level, this tree):
`discover(bool $force = false)` memoizes per instance;
`clearCache()` resets the flag (every toggle/destroy/sync path
already calls it after writing, so mutation flows still rescan);
`DirectoryUi::manifestPath()`/`readManifest()` memoized per slice
name. Result: hub 61.9s → 12.4s, 200 on the standard server.
Residual 12s (queries + tab enrichment + Blade) is a separate
performance item, not chased here.
