# Porting reference — non-Laravel sources & YAML-only manifests

The closing sections of the porting skill: porting legacy PHP / SQL-dump sources and the YAML-only manifest mode. Verbatim; the core SKILL.md routes here.

## Porting non-Laravel sources (legacy PHP / SQL dumps)

`slice:port` / Porting Studio accept **any project tree**, not only Laravel.

### What is analyzed

| Source signal | Used when |
|---------------|-----------|
| Live DB via `--source-*` | **Preferred** — real tables + row counts |
| `database/migrations` | Laravel projects |
| `*.sql` dumps under `install/`, `sql/`, `schema/`, … | Legacy app installers, mysqldump exports |
| Target app DB | **Never by default** (was a bug). Opt-in: `LARASLICE_PORT_ALLOW_APP_DB_FALLBACK=true` |

### Required for non-Laravel legacy apps

Point at the **legacy database** (or ship SQL schema files in the tree):

```bash
# Path to legacy codebase
php artisan slice:port /path/to/legacy-app \
  --domain=ERP \
  --source-host=127.0.0.1 \
  --source-port=3306 \
  --source-database=legacy_erp \
  --source-username=root \
  --source-password=secret \
  --dry-run

# Or named connection in config/database.php
php artisan slice:port /path/to/dolibarr \
  --source-connection=dolibarr_legacy \
  --dry-run
```

If you only have the code tree and **no** DB and **no** `CREATE TABLE` SQL dumps, the command **fails with a clear error** instead of reading LaraSlice’s own SQLite/MySQL.

### GitHub URL (Porting Studio)

1. Shallow clone (`git clone --depth 1`)
2. Static analysis (migrations / SQL dumps / optional live DB)
3. Draft `slice.yaml` via Phase 1+2 inference

No LLM required for the draft. AI helps later in Studio refinement.

### Env

```env
LARASLICE_PORT_SOURCE_CONNECTION=   # optional default connection name
LARASLICE_PORT_ALLOW_APP_DB_FALLBACK=false  # keep false in real ports
```

### Table prefix stripping

Default prefixes stripped from slice names: `llx_`, `wp_` (config `laraslice.porting.strip_table_prefixes`).

```bash
php artisan slice:port /path/to/dolibarr --strip-prefix=llx_ --dry-run --no-interaction
# llx_categorie_warehouse → CategorieWarehouse
```

`--dry-run` and `--no-interaction` default `--mode=system-base` and do not prompt.

### Target table prefixing (coexisting ports)

`--table-prefix=ws_` (config `laraslice.porting.table_prefix`, env `LARASLICE_PORT_TABLE_PREFIX`) makes every ported TARGET table land as `{prefix}{table}` — migrations, model `$table`, manifest tables, repeater declarations, and the ETL writes — so a second port of the same (or a colliding) source coexists with tables an earlier port or the host app already owns. Slice and model names stay clean. Framework auth/merge tables (`users`, `roles`, `permissions`, `permission_role`, `role_user`) are NEVER prefixed: they always merge into the host's own tables, and the identity remap rewrites user references inside the prefixed tables. Default off; unprefixed ports behave exactly as before. Applies to source-driven ports (`slice:port` inspect → scaffold → ETL); plan/blueprint files name their tables explicitly.

### Product examples (this branch)

| File | Use |
|------|-----|
| `blueprints/examples/kitchen-sink.slice.yaml` | All v0.1 formatters — primary UI QA |
| `blueprints/examples/suites/crm-*.slice.yaml` | CRM suite |
| `blueprints/examples/suites/hr-*.slice.yaml` | HR suite |
| `blueprints/examples/suites/shop-*.slice.yaml` | E-commerce suite |

```bash
php artisan slice:validate blueprints/examples/kitchen-sink.slice.yaml
```


## YAML-only manifests (Phase 4)

Manifests are `slice.yaml` only. JSON-only slices fail to load with a hint:

```bash
php artisan slice:migrate-json-to-yaml --dry-run
php artisan slice:migrate-json-to-yaml
php artisan slice:migrate-json-to-yaml --delete
```

Studio Save shows a YAML diff and writes only after confirm. CLI equivalent:

```bash
php artisan slice:diff Tasks
```
