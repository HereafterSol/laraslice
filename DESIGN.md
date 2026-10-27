---
name: BlatUI (LaraSlice default kit)
colors:
  background: "oklch(1 0 0)"
  foreground: "oklch(0.145 0 0)"
  card: "oklch(1 0 0)"
  primary: "oklch(0.205 0 0)"
  primary-foreground: "oklch(0.985 0 0)"
  secondary: "oklch(0.97 0 0)"
  muted: "oklch(0.97 0 0)"
  muted-foreground: "oklch(0.49 0 0)"
  accent: "oklch(0.97 0 0)"
  destructive: "oklch(0.505 0.225 27.325)"
  success: "oklch(0.455 0.125 150)"
  warning: "oklch(0.48 0.11 65)"
  info: "oklch(0.475 0.16 250)"
  border: "oklch(0.922 0 0)"
  input: "oklch(0.922 0 0)"
  ring: "oklch(0.708 0 0)"
typography:
  sans: "system sans stack (kit default)"
  heading: "same family as sans, semibold"
  mono: "ui-monospace stack (codes, numbers in exports)"
rounded:
  base: "0.625rem"
spacing:
  scale: "Tailwind 4px base scale — values are chosen from the scale, never arbitrary"
density:
  compact: "list rows ~40-49px, card padding 16px, band padding 10px vertical"
  comfortable: "list rows ~48-56px, card padding 24px, band padding 12-16px vertical"
---

## Overview

A quiet, neutral, data-first enterprise kit. Near-black primary
on white, hairline borders instead of shadows, colour reserved
for meaning (status, action, feedback). Pages should read like
a well-kept operations console: dense where work happens,
calm everywhere else. If a screen looks decorated, it is wrong;
if it looks inevitable, it is right.

## Colors

Neutrals carry the interface: {colors.background},
{colors.card}, {colors.muted} surfaces; {colors.foreground}
text; {colors.border} hairlines separate regions (not cards
inside cards). {colors.primary} is for the ONE primary action
per region and active states — never decoration. Semantic
tones (success/warning/info/destructive) appear ONLY as status
signals: badges, progress thresholds, alerts. Every text tone
is tuned to clear WCAG AA 4.5:1 on white and on its own /10
tint; do not introduce a tone that has not been checked.

## Typography

One sans family. Headings semibold, body regular, small muted
labels for metadata. Data values never muted-foreground when
they are the content of a cell. Numbers and codes in exports
use the mono stack. No display fonts, no letter-spacing tricks.

## Layout

Page composition comes from the Body v4 region vocabulary
(breadcrumb, title, facets, toolbar, actions, grid,
pagination) — this file governs values, never structure.
Regions separate with hairlines and whitespace from the
spacing scale. Density is a declared page property (compact |
comfortable); this kit supplies the pixels behind each name
(front matter). Never hard-code a pixel value in a template
that the density name should own.

## Elevation & Depth

Flat by default. Elevation exists for popovers, dropdowns,
sheets and dialogs only (the shadow scale in the kit CSS).
Content regions never float on shadows.

## Shapes

One radius: {rounded.base} for cards, inputs, buttons and
menus. Pills only for badges and avatars' circles. Mixed
radius systems are a defect.

## Components

The kit's components live in the component library; the
machine-readable inventory is `docs/COMPONENT-CATALOG.yaml`
(389 components with declared props and a curated
page_structure map). Check the catalog before creating ANY
component — the library almost certainly has it. Which
component renders which declared region/intent is fixed by
`docs/KIT-CONTRACT.md`; a kit is complete when it implements
every contract row.

## Do's and Don'ts

- Do use colour only where it means something.
- Do keep the primary action singular per region and placed
  where the page declaration puts it.
- Do prefer hairlines over boxes, whitespace over chrome.
- Don't invent a component that exists in the catalog.
- Don't add badges, pills or banners a page didn't declare.
- Don't pick a spacing, radius or tone value outside the
  tokens above because it "looks better" in one screenshot —
  change the token, everywhere, or not at all.
