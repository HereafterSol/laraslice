# Kit Contract — BlatUI (kit #1), draft v1 (23 Oct 2026, P0)

The contract every kit implements. A kit is COMPLETE when
every row maps to a component with the stated semantics.
Bodies declare the left column; kits own the middle; the
right column is what the conformance gate asserts.

## Regions (Body v4 vocabulary)

| Region | BlatUI rendering | Guaranteed semantics |
|---|---|---|
| breadcrumb | x-ui.breadcrumb set | nav[aria-label=breadcrumb], current page aria-current |
| title | shell header heading | single h1 per page |
| header_actions | x-ui.button / button-group | buttons in declared order |
| facets | x-ui.filter-tabs (tabs kind) / native select (select kind) | GET form semantics; active state visible |
| toolbar | data-table toolbar slot | search input[type=search]; drawer trigger button |
| actions | directory actions band (belowToolbar slot) | primary first; order = declared order |
| grid | x-ui.data-table | table semantics; first column = identifier; sortable headers are buttons |
| pagination | data-table footer | "Showing X to Y of Z"; prev/numbered/next |
| identity | show-page header card | avatar + title + subtitle composition |
| highlights | detail field rows (DirectoryUi) | ≤7 declared fields, label/value pairs |
| sections | x-ui.card + split-line rows | grouped label/value rows |
| tabs | x-ui.tabs set + related-tab-table | tablist semantics; each tab = full list contract |
| related | related-tab-table | same as grid rows, scoped |
| activity | (unimplemented — contract gap, P1) | — |

## Intents

| Intent | Rendering | Notes |
|---|---|---|
| action:create | primary button → slice create route | label from declaration |
| action:import / export / invite / bulk / report / matrix | secondary buttons in actions band | export dispatches the table export event |
| filter:<field> | drawer control or facet, per declaration | options from field/lookup, never hardcoded |
| search | toolbar search input | server-side, declared fields |
| inline_edit:<field> | select in cell → inline endpoint | declaration is the allowlist; service may own the write |
| row_action:view/edit/delete | row buttons or overflow menu (<3 inline) | delete confirms |
| workflow:transition | status select in cell | options from the workflow declaration |

## Kinds (value formatting)

money · date · datetime · bool (badge) · status (tone badge) ·
progress_bar (rose <40 / amber 40–74 / emerald ≥75) · person
(avatar + name + subtitle) · relation (label via *_name or
lookup) · text (clamp in compact) · number (right-aligned).
Every kind renders '—' for empty, never a fabricated value.

## Density names

compact → row ~49px, p-4 cards, py-2.5 bands ·
comfortable → row ~56px, p-6 cards, py-3 bands. The kit owns
these numbers; Bodies own only the name.

## Escape hatch

A Body may name `component: <catalog-name>` for a registered
custom component. It must exist in COMPONENT-CATALOG.yaml
and be implemented by the active kit. Hatch usage is counted
in conformance reports; a rising count means the vocabulary
needs a new first-class intent.
