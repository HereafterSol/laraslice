<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * Option lists for the dynamic field sources (Stage C-5).
 *
 * A field may declare `options_from: slices` (its value is
 * a slice NAME — Frappe's Link-to-DocType pattern, where
 * e.g. an Opportunity's "from" is the word Lead or
 * Customer, not a number) or `options_from:
 * slice_records:<field>` (a Dynamic Link: its options are
 * the records of whichever slice the named field selects).
 * Before these sources existed, the uniform Link→integer
 * rule rendered such fields as required number inputs and
 * the form could not be completed truthfully (Stage A
 * finding F5).
 */
class SliceRecordOptions
{
    /**
     * The slice-registry options for `options_from: slices`:
     * slice name => slice title, for every active slice.
     *
     * @return array<string, string>
     */
    public static function registryOptions(): array
    {
        try {
            $out = [];
            foreach (app(SliceManager::class)->getActiveSlices() as $name => $slice) {
                $manifest = $slice->toArray();
                $out[(string) $name] = (string) ($manifest['title'] ?? $manifest['label'] ?? $name);
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Extract a slice manifest's root field definitions
     * as a name => definition map, from either Heart
     * shape (a top-level `fields` map, or the root
     * model's `fields` list).
     *
     * @return array<string, array>
     */
    public static function manifestFieldsOf(array $raw): array
    {
        $out = [];
        if (isset($raw['fields']) && is_array($raw['fields'])) {
            foreach ($raw['fields'] as $key => $definition) {
                if (is_array($definition)) {
                    $out[(string) ($definition['name'] ?? $key)] = $definition;
                }
            }
        }
        if ($out === [] && isset($raw['models']) && is_array($raw['models'])) {
            foreach ($raw['models'] as $model) {
                if (! is_array($model) || empty($model['fields']) || ! is_array($model['fields'])) {
                    continue;
                }
                foreach ($model['fields'] as $key => $definition) {
                    if (is_array($definition)) {
                        $out[(string) ($definition['name'] ?? $definition['handle'] ?? $key)] = $definition;
                    }
                }
                if (($model['root'] ?? false) === true) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Resolve a caller-supplied name to a registered slice
     * name. Dynamic-link values in ported systems are the
     * source's entity names ("Customer", "lead"), not slice
     * names ("Customers", "Leads") — walk the same candidate
     * chain viaTargetSlice() uses so a Dynamic Link whose
     * controlling field stores entity names still resolves
     * its records (D-050: Contracts' Party, Email Campaigns'
     * Recipient).
     */
    public static function resolveSliceName(string $sliceName): string
    {
        try {
            $manager = app(SliceManager::class);
            if ($manager->getSlice($sliceName) !== null) {
                return $sliceName;
            }
            foreach ([\Illuminate\Support\Str::plural(\Illuminate\Support\Str::studly($sliceName)), \Illuminate\Support\Str::studly($sliceName)] as $candidate) {
                if ($manager->getSlice($candidate) !== null) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
        }

        return $sliceName;
    }

    /**
     * The primary table a slice stores its records in.
     */
    public static function tableForSlice(string $sliceName): ?string
    {
        try {
            $slice = app(SliceManager::class)->getSlice(self::resolveSliceName($sliceName));
            if ($slice === null) {
                return null;
            }
            $table = $slice->primaryTable();
            if ($table === null) {
                $tables = app(SliceManager::class)->getSliceTables($slice);
                $table = $tables[0] ?? null;
            }

            return $table !== null && Schema::hasTable($table) ? $table : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve which slice a link field points at, from
     * the current slice's manifest fields (Seat 1
     * fetch_from support): a declared string relation
     * names the entity; a *_id field names it by base.
     */
    public static function viaTargetSlice(array $manifestFields, string $viaField): ?string
    {
        $definition = $manifestFields[$viaField] ?? null;
        if (! is_array($definition)) {
            return null;
        }
        $entity = null;
        if (isset($definition['relation']) && is_string($definition['relation'])) {
            $entity = $definition['relation'];
        } elseif (str_ends_with($viaField, '_id')) {
            $entity = substr($viaField, 0, -3);
        } elseif (isset($definition['relation']) && is_array($definition['relation'])) {
            $entity = $definition['relation']['model'] ?? $definition['relation']['name'] ?? null;
        }
        if (! is_string($entity) || $entity === '') {
            return null;
        }
        foreach ([\Illuminate\Support\Str::plural(\Illuminate\Support\Str::studly($entity)), \Illuminate\Support\Str::studly($entity), $entity] as $candidate) {
            if (app(SliceManager::class)->getSlice($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * One field's value from one record of a slice —
     * the fetch_from read (Seat 1).
     */
    public static function recordValue(string $sliceName, mixed $id, string $field): mixed
    {
        try {
            $table = self::tableForSlice($sliceName);
            if ($table === null || ! in_array($field, Schema::getColumnListing($table), true)) {
                return null;
            }
            $row = DB::table($table)->where('id', $id)->first([$field]);

            return $row?->{$field};
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Record options for one slice: id => display label.
     * The label is the row's first non-empty identity value
     * — full_name / name / title / label / code / subject,
     * then the slice's first declared free-text field, then
     * any *_name column, then email, then a composed
     * first+last name, else "#id". (A single-column pick is
     * not enough: the
     * Stage C-5 verification found a slice whose title
     * column exists but sits empty on real rows while the
     * name columns carry the identity.)
     *
     * @return array<int, string>
     */
    public static function forSlice(string $sliceName, int $limit = 500): array
    {
        try {
            $slice = app(SliceManager::class)->getSlice(self::resolveSliceName($sliceName));
            if ($slice === null) {
                return [];
            }
            $table = $slice->primaryTable();
            if ($table === null) {
                // Some manifests (the package's own slices)
                // resolve their tables only through the
                // manager's table map.
                $tables = app(SliceManager::class)->getSliceTables($slice);
                $table = $tables[0] ?? null;
            }
            if ($table === null || ! Schema::hasTable($table)) {
                return [];
            }
            $columns = Schema::getColumnListing($table);
            // `email` is deliberately NOT a primary candidate:
            // a slice whose identity lives in a *_name column
            // (companies.company_name) but which also carries a
            // populated generic email column would otherwise be
            // labelled by email in every picker (D-041). Email
            // remains a late fallback below, after *_name.
            $candidates = array_values(array_intersect(
                ['full_name', 'name', 'title', 'label', 'code', 'subject'],
                $columns
            ));
            // The slice's own first declared free-text field
            // is its identity when no conventional column
            // exists: a ported slice whose records are named
            // by a stem column (opportunity_lost_reasons'
            // `lost_reason`) otherwise labels every record
            // "#id" in pickers. Select-shaped fields (they
            // carry options) and status are never identity.
            foreach (self::manifestFieldsOf($slice->raw) as $fieldName => $fieldDef) {
                if (! is_array($fieldDef) || ! in_array($fieldName, $columns, true)) {
                    continue;
                }
                if (in_array($fieldName, $candidates, true) || $fieldName === 'status') {
                    continue;
                }
                if (! empty($fieldDef['options'])) {
                    continue;
                }
                if (in_array((string) ($fieldDef['type'] ?? 'string'), ['string', 'text'], true)) {
                    $candidates[] = $fieldName;
                    break;
                }
            }
            $select = array_merge(['id'], $candidates);
            if (in_array('email', $columns, true)) {
                $select[] = 'email';
            }
            foreach ($columns as $column) {
                if (str_ends_with($column, '_name') || in_array($column, ['first_name', 'last_name'], true)) {
                    $select[] = $column;
                }
            }
            $select = array_values(array_unique($select));
            if (count($select) === 1 && ! in_array('id', $columns, true)) {
                return [];
            }

            $out = [];
            foreach (DB::table($table)->select($select)->limit($limit)->get() as $row) {
                $label = '';
                foreach ($candidates as $candidate) {
                    $value = $row->{$candidate} ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        $label = trim($value);
                        break;
                    }
                }
                if ($label === '') {
                    // Any *_name column with a value (a
                    // ported slice's identity often lives in
                    // lead_name / item_name / project_name).
                    foreach ($columns as $column) {
                        if (! str_ends_with($column, '_name')) {
                            continue;
                        }
                        $value = $row->{$column} ?? null;
                        if (is_string($value) && trim($value) !== '') {
                            $label = trim($value);
                            break;
                        }
                    }
                }
                if ($label === '' && isset($row->email) && is_string($row->email) && trim($row->email) !== '') {
                    // Late fallback only (D-041): an email can
                    // identify a record when nothing name-like
                    // exists, but it never outranks a *_name
                    // identity column.
                    $label = trim($row->email);
                }
                if ($label === '') {
                    $composed = trim(((string) ($row->first_name ?? '')).' '.((string) ($row->last_name ?? '')));
                    $label = $composed !== '' ? $composed : '#'.$row->id;
                }
                $out[(int) $row->id] = $label;
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
