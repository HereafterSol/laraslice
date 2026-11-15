# v2.2.0 — The Parity Release

Released 2026-11-15

A behaviour release. Since v2.1.2 the framework was driven
through a full reference port — **39 slices of a production
CRM** (leads, opportunities, customers, contracts,
campaigns, appointments and their settings) — and, for the
first time, graded not only on how its screens look but on
how its **flows behave when a real user runs them end to
end**: create, edit, convert, declare-lost, reopen, delete,
export, bulk actions, on both the reference system and the
port, with the database read back after every step. Every
divergence that programme found was fixed at the framework's
standard layer, never in the port. The suite grows from
981 tests / 3,793 assertions to **1,142 tests / 4,340
assertions**.

## Behaviour now travels with the declaration

- **Field behaviour is complete and shared.** The clause
  vocabulary (depends_on, mandatory_depends_on,
  read_only_depends_on, fetch_from, computed, autoname) is
  enforced by one trait, so an API save and a form save now
  behave identically — validation clauses, fetched values
  and naming series included.
- **Conditional derivations.** A computed field can now be
  declared as ordered cases over dates, sibling fields and
  child-table aggregates (`cases`, `date_add`), which is
  how real statuses actually work: a contract is Unsigned,
  then Active inside its dates, then Inactive; fulfilment
  follows its ticked rows and its deadline. A case the save
  cannot judge is skipped — the derivation never guesses.
- **Dialog effects persist in one save.** Declare-style
  dialogs (the set-as-lost pattern) write status, reasons
  and the detailed note together, stamp date fields on the
  rising edge (`stamp_when`), and clear every trace when
  the record leaves the state (`clear_when`). A mandatory
  collection refuses an empty save with the source's own
  wording (`mandatory_message`).
- **Scheduled effects are declared, not hand-built.** A
  Heart's `effects:` list — status sweeps aged by a
  settings value (`older_than_setting`, with the source's
  default when unset) and recompute hooks — runs through
  `slice:run-effects`, registered with the scheduler. A
  settings page value now has a consumer: opportunities in
  a replied state older than the configured days close
  themselves, exactly as the source's daily job does.

## Record and directory actions, completed

- **Duplicate and Print are built in.** Every record form
  carries both: Duplicate seeds the create form from the
  source record (everything except identity, audit and
  no-copy fields; child rows copied without their ids),
  Print renders the framework's printable view — hidden
  fields never print. Either can be suppressed or replaced
  per slice.
- **Stepped conversions.** Converting a record can run
  declared pre-steps first (create the prospect, create
  the contact — each default-checked in a dialog), carry
  child rows across, and flip the source record's status
  when the target saves.
- **Linked deletes are refused, everywhere.** The service
  layer counts inbound links and refuses with the count
  named, instead of orphaning the records that pointed at
  the deleted one. Bulk delete reports per-record outcomes
  — deleted, and skipped-because-linked — in one message.
- **Export tells the truth.** Directory export respects
  the active filter and writes option labels, not slugs;
  the filter builder itself now emits values for
  map-shaped option declarations (equality filters on
  select fields match again).
- **Bulk actions work end to end.** The data table's
  selection payload is resolved correctly for every
  directory (a regression had silently emptied it), with a
  framework test guarding the seat.

## Seats finished along the way

Table MultiSelect as a first-class field type; quick-entry
dialogs with companion records (a filled companion address
saves with its parent, a partial one is refused by name,
and the refusal is visible on the directory); workplace
Connections that include dynamic-link child-table parents;
form tabs; tree directories; header Create / Links /
Actions menus on every record; minted naming series for
records the source names by series. The benchmark gate
itself was rebuilt on a real HTML parser — it grades
visible text and control types, so a degraded picker can
no longer pass as its label.

## Notes

Framework only. The flagship demo package is unchanged in
this release and remains at its v2.1.2 state.
