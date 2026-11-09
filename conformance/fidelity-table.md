# Conformance fidelity table

Produced by the conformance runner (`php artisan slice:corpus --write-table`) projecting every corpus entry through the Laravel runtime's metadata projector (describe/schema/index). Expected facts are pinned per entry in `conformance/corpus/<entry>/expected.json`. This table proves the pattern against the Laravel runtime only; a second runtime earns its claim by producing the same table from the same corpus.

**Result: 6/6 entries conform.**

## fleet-projects — CONFORMS

| Fact | Expected | Projected | |
|---|---|---|---|
| table | `"projects"` | `"projects"` | ✓ |
| fields_count | `27` | `27` | ✓ |
| field 'client_id' present | `true` | `true` | ✓ |
| field 'client_id' type | `"integer"` | `"integer"` | ✓ |
| field 'client_id' required | `false` | `false` | ✓ |
| field 'project_name' present | `true` | `true` | ✓ |
| field 'project_name' type | `"string"` | `"string"` | ✓ |
| field 'project_name' required | `true` | `true` | ✓ |
| field 'status' present | `true` | `true` | ✓ |
| field 'status' type | `"string"` | `"string"` | ✓ |
| field 'status' required | `false` | `false` | ✓ |
| field 'status' enum | `["not started","in progress","on hold","finished","canceled"]` | `["not started","in progress","on hold","finished","canceled"]` | ✓ |
| relations (name+kind set) | `[["discussion","hasMany"],["expenses","hasMany"],["expenses_recurring","hasMany"],["files"…` | `[["discussion","hasMany"],["expenses","hasMany"],["expenses_recurring","hasMany"],["files"…` | ✓ |
| tables_count | `5` | `5` | ✓ |
| operations_count | `7` | `7` | ✓ |
| permissions_count | `4` | `4` | ✓ |
| multitenant | `true` | `true` | ✓ |
| tenant_key | `"company_id"` | `"company_id"` | ✓ |
| update.mode | `"patch"` | `"patch"` | ✓ |
| update.declared | `false` | `false` | ✓ |

All 18 contract invariants hold (structure, schema header, declaration-only schema, canonical stability, ladder reproducibility).

## fleet-tasks — CONFORMS

| Fact | Expected | Projected | |
|---|---|---|---|
| table | `"tasks"` | `"tasks"` | ✓ |
| fields_count | `29` | `29` | ✓ |
| field 'heading' present | `true` | `true` | ✓ |
| field 'heading' type | `"string"` | `"string"` | ✓ |
| field 'heading' required | `true` | `true` | ✓ |
| field 'project_id' present | `true` | `true` | ✓ |
| field 'project_id' type | `"integer"` | `"integer"` | ✓ |
| field 'project_id' required | `false` | `false` | ✓ |
| field 'status' present | `true` | `true` | ✓ |
| field 'status' type | `"string"` | `"string"` | ✓ |
| field 'status' required | `false` | `false` | ✓ |
| field 'status' enum | `["incomplete","completed"]` | `["incomplete","completed"]` | ✓ |
| relations (name+kind set) | `[["notes","hasMany"],["projectTimeLogs","hasMany"],["subTasks","hasMany"],["task","belongs…` | `[["notes","hasMany"],["projectTimeLogs","hasMany"],["subTasks","hasMany"],["task","belongs…` | ✓ |
| tables_count | `2` | `2` | ✓ |
| operations_count | `7` | `7` | ✓ |
| permissions_count | `4` | `4` | ✓ |
| multitenant | `true` | `true` | ✓ |
| tenant_key | `"company_id"` | `"company_id"` | ✓ |
| update.mode | `"patch"` | `"patch"` | ✓ |
| update.declared | `false` | `false` | ✓ |

All 18 contract invariants hold (structure, schema header, declaration-only schema, canonical stability, ladder reproducibility).

## fleet-users — CONFORMS

| Fact | Expected | Projected | |
|---|---|---|---|
| table | `null` | `null` | ✓ |
| fields_count | `4` | `4` | ✓ |
| field 'customised_permissions' present | `true` | `true` | ✓ |
| field 'customised_permissions' type | `"boolean"` | `"boolean"` | ✓ |
| field 'customised_permissions' required | `true` | `true` | ✓ |
| field 'mfa_channel' present | `true` | `true` | ✓ |
| field 'mfa_channel' type | `"string"` | `"string"` | ✓ |
| field 'mfa_channel' required | `true` | `true` | ✓ |
| relations_count | `0` | `0` | ✓ |
| tables_count | `0` | `0` | ✓ |
| operations_count | `45` | `45` | ✓ |
| permissions_count | `10` | `10` | ✓ |
| multitenant | `false` | `false` | ✓ |
| update.mode | `"patch"` | `"patch"` | ✓ |
| update.declared | `false` | `false` | ✓ |

All 18 contract invariants hold (structure, schema header, declaration-only schema, canonical stability, ladder reproducibility).

## synthetic-enum-cap — CONFORMS

| Fact | Expected | Projected | |
|---|---|---|---|
| table | `"corpus_enums"` | `"corpus_enums"` | ✓ |
| fields_count | `4` | `4` | ✓ |
| field 'at_cap' present | `true` | `true` | ✓ |
| field 'at_cap' type | `"string"` | `"string"` | ✓ |
| field 'at_cap' required | `false` | `false` | ✓ |
| field 'at_cap' enum_count | `50` | `50` | ✓ |
| field 'at_cap' enum_truncated | `false` | `false` | ✓ |
| field 'company_id' present | `true` | `true` | ✓ |
| field 'company_id' type | `"integer"` | `"integer"` | ✓ |
| field 'company_id' required | `true` | `true` | ✓ |
| field 'over_cap' present | `true` | `true` | ✓ |
| field 'over_cap' type | `"string"` | `"string"` | ✓ |
| field 'over_cap' required | `false` | `false` | ✓ |
| field 'over_cap' enum_count | `51` | `51` | ✓ |
| field 'over_cap' enum_truncated | `true` | `true` | ✓ |
| relations_count | `0` | `0` | ✓ |
| tables_count | `1` | `1` | ✓ |
| operations_count | `0` | `0` | ✓ |
| permissions_count | `2` | `2` | ✓ |
| multitenant | `true` | `true` | ✓ |
| tenant_key | `"company_id"` | `"company_id"` | ✓ |
| update.mode | `"patch"` | `"patch"` | ✓ |
| update.declared | `false` | `false` | ✓ |

All 18 contract invariants hold (structure, schema header, declaration-only schema, canonical stability, ladder reproducibility).

## synthetic-relations — CONFORMS

| Fact | Expected | Projected | |
|---|---|---|---|
| table | `"corpus_widgets"` | `"corpus_widgets"` | ✓ |
| fields_count | `3` | `3` | ✓ |
| field 'name' present | `true` | `true` | ✓ |
| field 'name' type | `"string"` | `"string"` | ✓ |
| field 'name' required | `true` | `true` | ✓ |
| field 'owner_id' present | `true` | `true` | ✓ |
| field 'owner_id' type | `"integer"` | `"integer"` | ✓ |
| field 'owner_id' required | `false` | `false` | ✓ |
| field 'owner_id' relation | `"corpus_owner"` | `"corpus_owner"` | ✓ |
| relations (name+kind set) | `[["attachments","morphMany"],["entries","hasMany"],["owner","belongsTo"],["ownerEntries","…` | `[["attachments","morphMany"],["entries","hasMany"],["owner","belongsTo"],["ownerEntries","…` | ✓ |
| tables_count | `2` | `2` | ✓ |
| operations_count | `0` | `0` | ✓ |
| permissions_count | `4` | `4` | ✓ |
| multitenant | `false` | `false` | ✓ |
| update.mode | `"patch"` | `"patch"` | ✓ |
| update.declared | `false` | `false` | ✓ |

All 18 contract invariants hold (structure, schema header, declaration-only schema, canonical stability, ladder reproducibility).

## synthetic-update-replace — CONFORMS

| Fact | Expected | Projected | |
|---|---|---|---|
| table | `"corpus_replaceables"` | `"corpus_replaceables"` | ✓ |
| fields_count | `4` | `4` | ✓ |
| field 'legacy_code' present | `true` | `true` | ✓ |
| field 'legacy_code' type | `"string"` | `"string"` | ✓ |
| field 'legacy_code' required | `false` | `false` | ✓ |
| field 'name' present | `true` | `true` | ✓ |
| field 'name' type | `"string"` | `"string"` | ✓ |
| field 'name' required | `true` | `true` | ✓ |
| field 'quantity' present | `true` | `true` | ✓ |
| field 'quantity' type | `"integer"` | `"integer"` | ✓ |
| field 'quantity' required | `true` | `true` | ✓ |
| relations_count | `0` | `0` | ✓ |
| tables_count | `1` | `1` | ✓ |
| operations_count | `0` | `0` | ✓ |
| permissions_count | `4` | `4` | ✓ |
| multitenant | `false` | `false` | ✓ |
| update.mode | `"replace"` | `"replace"` | ✓ |
| update.declared | `true` | `true` | ✓ |

All 18 contract invariants hold (structure, schema header, declaration-only schema, canonical stability, ladder reproducibility).
