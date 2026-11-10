# v2.1.2 — The Complete Fleet

Released 2026-11-10

A demo-completeness patch. The framework is unchanged from
v2.1.1 (suite 981 tests, 3,793 assertions); what changes is
the flagship demo package: its fleet grows from **164 to
174 slices**, reaching parity with the reference port the
release notes have cited all along.

The ten added slices are the settings and lookup surfaces
that were ported after the demo's dataset was frozen:
ticket types and channels, knowledge-base categories,
lead sources and statuses, passport details, company
addresses, and the Custom Fields family (groups, fields,
link settings). Their tables and rows were already present
in the demo's bundled dataset — this release adds the
slices themselves (declarations, controllers, views), so
every one of them now opens, lists its real rows, and
works like any other part of the demo. The metadata index
total for the demo is now **174**, verified on a cold
install of this exact package.

Also fixed: the demo's INSTALL.md named the wrong
framework version in its version paragraph (the package
label and lock were correct; the text now matches them).
