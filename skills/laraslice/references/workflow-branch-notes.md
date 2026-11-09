## Branch notes (`feat/workflow-bulk-actions`) — keep in sync with code

### Slice YAML v0.1
- Docs: `docs/SLICE_YAML_V0.1.md`, `docs/SLICE_YAML_STRATEGY.md`
- Machine schema: `schemas/slice-v0.1.json`
- `php artisan slice:validate {path|path.yaml}` or `--all` (CI exit 1)

**Closed formatters:** text, text_with_badge, status_pill, date_time, date_overdue, money_breakdown, progress_bar, code_badge, composite_user, number

**`type` vs `input`:** `type` = storage; `input` = form widget (`select`, `textarea`, …). Never use `type: select`.

### Phase 2 DataTable parser
`LaraSlice\Porting\DataTableUiParser` — static classification of source `*DataTable.php` into the formatter vocabulary.

```bash
php artisan slice:repair --re-infer-ui --source=/path/to/erp
```

### Runtime directory columns
`LaraSlice\Support\DirectoryUi::columnsForSlice($name, $fallback)` resolves `slice.json` from the **registered** slice path (Composer path-repo, vendor, or `app/Slices`). Index blades call this at runtime — do not rely on `__DIR__` or paths baked only at repair time.

```bash
php artisan slice:repair          # re-inject DirectoryUi loader; merge config gaps
php artisan slice:repair --publish-ui
php artisan view:clear
```

### Regenerating an existing slice's form
`php artisan slice:regen-form {Slice} [--path=DIR] [--write] [--keep-staging]`
rebuilds form/create/edit from the slice's Heart: field definitions are
rebuilt from the manifest, the slice is generated into a staging root, and
only the three form views are transplanted. Dry-run by default — the report
names any surface that would be **lost** (named inputs / `$…Options`
variables the Heart cannot re-derive, e.g. pivot multi-selects and hand
edits). `--write` backs the originals up under
`storage/app/laraslice/regen-backups/` before transplanting.

### Regenerating an existing slice's baked artefacts
`php artisan slice:regen-artefacts {Slice} [--path=DIR] [--write] [--force] [--keep-staging]`
re-syncs the artefacts that bake manifest data in at generation time and
are NOT covered by `slice:regen-form`: the service class (baked
`repeaterDefinitions()` / validation rules), the primary model
(`$fillable` / `$casts` / relation methods) and the show blade. Same
staging discipline — the slice is generated into a staging root (with the
Heart's declared relations and the slice's true base namespace + tiers
derived from its namespace, so staged classes land in the real
namespace) and only those three files are transplanted. Dry-run by
default — the report names anything that would be **lost** (hand-added
methods, `$fillable`/`$casts` entries, validation fields, repeater
handles, blade routes/includes the Heart cannot re-derive). `--write`
skips any artefact with detected losses unless `--force` is given, and
backs originals up under `storage/app/laraslice/regen-backups/` first.
A staged service/model whose namespace staging cannot reproduce is
refused outright (`namespace-mismatch`), even with `--force`. Use
`slice:repair` instead when a model's `modelClass('…')` targets merely
need surgical healing without trusting the Heart.

### Absorbing hand knowledge back into the Heart
`php artisan slice:absorb {Slice} [--path=DIR] [--all] [--write]` is the
reverse of `slice:regen-artefacts`: where regen names what the Heart
cannot re-derive, absorb classifies every such loss and — for the
declarable ones — writes the knowledge back into the Heart. Each loss is
classified exactly one way: **declarable** (a concrete Heart proposal is
printed: `models[].relations` entries — including the other-side key via
`local_key`/`owner_key` and pivot `parent_key`/`related_key`, Heart
fields for mass-assigned columns, the root model's `table:`,
`multitenant`), **vocabulary-gap** (real declaration-shaped knowledge
today's schema cannot express — a `hasOne`, a `hasManyThrough`, a
per-field cast — reported with the schema addition that would express
it), or **hand-owned** (real code: service logic, custom methods, empty
vestigial shells — never proposed, stays `--force`-gated on regen).
Dry-run by default; `--write` writes ONLY `slice.yaml` (timestamped
backup under `storage/app/laraslice/absorb-backups/` first), never a
generated artefact — run `slice:regen-artefacts` afterwards to re-sync
them loss-free. The framework's own core slices (`core: true` — Users,
Roles, Settings, Workflows) are hand-built by design and never absorbed.

### MFA local toggle
```env
LARASLICE_MFA_ENABLED=false   # default true — never disable in production
```
`slice:repair` merges missing keys (e.g. `auth.mfa_enabled`) into published `config/laraslice.php`.

---

