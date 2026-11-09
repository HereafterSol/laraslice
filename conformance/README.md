# Cross-runtime conformance corpus

Proposal #7 of the ServiceStack R&D programme (8 Nov 2026):
the infrastructure a second LaraSlice runtime will one day
be judged by. This directory is the **pattern**, proven
against the Laravel runtime only. The cross-runtime claim
awaits a second runtime executing the same corpus through
its own projector and producing the same fidelity table.

## What lives here

```
conformance/
  README.md           this file
  fidelity-table.md   the runner's output artefact (regenerate, never hand-edit)
  corpus/
    <entry>/
      slice.yaml      the declaration under test (a Heart)
      Routes/         optional route files (operation facts come from these)
      expected.json   the facts a runtime must project from the Heart
```

## The contract a runtime must satisfy

For every entry, the runner (`LaraSlice\Core\Conformance\ConformanceRunner`,
CLI: `php artisan slice:corpus`) projects the Heart through
the runtime's own metadata surfaces — on Laravel, the
Phase A/B `MetadataProjector`: `describe()`, `schema()`,
`index()` — and checks:

1. **Fact fidelity.** Every fact pinned in `expected.json`
   matches the projection: primary table, field count and
   per-field type/required/enum/relation, the relation
   name+kind set, table/operation/permission counts,
   tenancy, and update semantics (Proposal #6). Facts pin a
   **subset** by design — the load-bearing facts per entry —
   not byte equality of the whole projection; the subset is
   chosen per entry and stated in its `provenance`.
2. **Structure.** `describe` carries the contract keys
   (`id, name, table, fields, tables, relations,
   operations, auth, update`); `schema` carries the
   `laraslice/slice-schema` v1 header and a draft-07
   `json_schema`, and is declaration-only (no live row
   counts anywhere in it).
3. **Canonical stability.** A fresh parse + a fresh
   projector — the second constructed with the shared
   declaration cache off, so it genuinely rebuilds —
   produce byte-identical schema JSON: the projection is
   a pure function of the declaration.
4. **Ladder reproducibility.** The index (rung one) states
   the same field/relation/table counts as describe
   (rung two) for the entry.

`expected.json` **placement facts are not pinned** (domain,
app, canonical id): those are the host application's
taxonomy, derived from where the slice lives, not what it
declares. Declaration facts are what a runtime must carry
across.

## The entries

| Entry | Kind | What it pins |
|---|---|---|
| `fleet-tasks` | real fleet Heart (ported ERP, Work) | 29 fields, the 8-relation set incl. a belongsToMany, status enum, tenancy on |
| `fleet-projects` | real fleet Heart (ported ERP, Work) | multi-table slice (5 tables), 27 fields, the 18-relation set, 5-value status enum |
| `fleet-users` | framework Heart | map-shaped permissions ({slug, name}), no models list → no table/relations, 45 operations |
| `synthetic-relations` | synthetic | every relation kind: belongsTo, hasMany, hasOne, belongsToMany, morphMany, hasManyThrough; tenancy off |
| `synthetic-enum-cap` | synthetic | the enum cap boundary: 50 options served whole, 51 truncated visibly (enum_count + enum_truncated); tenancy on |
| `synthetic-update-replace` | synthetic | declared `update: replace`; the two-shape field merge (map adds a field, model list wins conflicts) |

Fleet entries are **copies**, taken 2026-11-09 from the
live slices named in their `provenance`, so the corpus is
stable even as the fleet evolves. A fleet Heart changing
under its corpus copy does not move the corpus; refreshing
a copy is a deliberate act that re-pins its facts.

## Running it

```
php artisan slice:corpus                    # verdict per entry; exit 1 on any failure
php artisan slice:corpus --write-table      # also regenerate fidelity-table.md
php artisan slice:corpus --dir=<path>       # run against another corpus copy
```

The framework test suite (`ConformanceCorpusTest`) runs
the corpus on every build, asserts the committed
`fidelity-table.md` is byte-identical to a fresh run's
table (drift is a failure), and proves the suite can fail
by corrupting a copy.

## For the second runtime

A new runtime implements exactly one seam — its own
projector behind the same four checks — and runs this
corpus unchanged. Its fidelity table stands beside this
one; the diff between the two tables IS the cross-runtime
fidelity claim, entry by entry, fact by fact. Nothing in
the corpus is Laravel-shaped except the Hearts' YAML
surface syntax, which is the declaration language every
runtime shares.
