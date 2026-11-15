<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * Record conversions (source-logic vocabulary): the
 * portable form of the source controllers' mapped-doc
 * conversions (e.g. Frappe's make_opportunity /
 * make_customer on a Lead). Declared in the Heart at
 * the top level:
 *
 *   conversions:
 *     - target: Opportunities
 *       label: Opportunity            # optional menu label
 *       field_map: {name: party_name, email_id: contact_email}
 *       field_no_map: [disabled]
 *       set:
 *         opportunity_from: Leads
 *         customer_type:
 *           when: {field: company_name, not_empty: true}
 *           then: Company
 *           else: Individual
 *
 * Semantics follow the source: same-name fields copy
 * across (minus field_no_map and system columns),
 * field_map then overrides source->target, and `set`
 * assigns literals or condition-driven values evaluated
 * against the SOURCE record with the FieldCondition
 * grammar. Empty source values never copy — the target
 * form's own defaults must be free to breathe. A copied
 * value the target field's declared options cannot
 * represent never copies either (the two systems'
 * same-named status fields rarely share an option
 * list); `set` literals stay unguarded — they are the
 * declaration author's explicit intent, and validation
 * surfaces a bad one loudly. The
 * conversion only PRE-FILLS the target's create form;
 * the user reviews and saves through the normal store,
 * so every target behaviour (computed, autoname,
 * mandatory rules) still applies.
 */
class RecordConverter
{
    private const SYSTEM_FIELDS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** @return array<int, array{target:string,label:string,field_map:array,field_no_map:array,set:array,children:array,pre_steps:array,source_status:?string}> */
    public static function conversionsOf(array $manifest): array
    {
        $out = [];
        foreach ((array) ($manifest['conversions'] ?? []) as $entry) {
            if (! is_array($entry) || empty($entry['target']) || ! is_string($entry['target'])) {
                continue;
            }
            $out[] = [
                'target' => $entry['target'],
                'label' => (string) ($entry['label'] ?? Str::singular($entry['target'])),
                'field_map' => is_array($entry['field_map'] ?? null) ? $entry['field_map'] : [],
                'field_no_map' => array_values(array_map('strval', (array) ($entry['field_no_map'] ?? []))),
                'set' => is_array($entry['set'] ?? null) ? $entry['set'] : [],
                // D-073: child-table rows carried into the target
                // (a Prospect's leads row, a Contact's email/phone/
                // link rows) — one row per handle, same scalar
                // machinery against the child's field definitions.
                'children' => self::normalizeChildren($entry['children'] ?? null),
                // D-073: pre-steps the convert dialog offers before
                // the target form opens (Desk's "Create Prospect"
                // checkbox): intermediate records created first.
                'pre_steps' => self::normalizePreSteps($entry['pre_steps'] ?? null),
                // D-073: the source record's status once a record
                // created through this conversion is SAVED (Desk
                // flips the Lead to "Opportunity"/"Converted").
                'source_status' => isset($entry['source_status']) && is_string($entry['source_status']) && $entry['source_status'] !== ''
                    ? $entry['source_status'] : null,
            ];
        }

        return $out;
    }

    /**
     * A handle's declaration is one row map ({field_map, set}), a
     * {rows: [...]} map, or a bare list of row maps — the source
     * sometimes appends several rows to one child table (a
     * Contact's phone AND mobile rows).
     *
     * @return array<string, array{rows: array<int, array{field_map:array,set:array}>}>
     */
    private static function normalizeChildren(mixed $children): array
    {
        $out = [];
        foreach ((array) ($children ?? []) as $handle => $decl) {
            if (! is_string($handle) || ! is_array($decl)) {
                continue;
            }
            $rowDecls = [];
            if (array_is_list($decl)) {
                $rowDecls = $decl;
            } elseif (is_array($decl['rows'] ?? null)) {
                $rowDecls = $decl['rows'];
            } else {
                $rowDecls = [$decl];
            }
            $rows = [];
            foreach ($rowDecls as $rowDecl) {
                if (! is_array($rowDecl)) {
                    continue;
                }
                $rows[] = [
                    'field_map' => is_array($rowDecl['field_map'] ?? null) ? $rowDecl['field_map'] : [],
                    'set' => is_array($rowDecl['set'] ?? null) ? $rowDecl['set'] : [],
                ];
            }
            if ($rows !== []) {
                $out[$handle] = ['rows' => $rows];
            }
        }

        return $out;
    }

    /** @return array<int, array{name:string,label:string,slice:string,default:bool,name_field:?string,name_from:?string,name_label:string,field_map:array,set:array,children:array}> */
    private static function normalizePreSteps(mixed $steps): array
    {
        $out = [];
        foreach ((array) ($steps ?? []) as $step) {
            if (! is_array($step) || empty($step['name']) || empty($step['slice'])
                || ! is_string($step['name']) || ! is_string($step['slice'])) {
                continue;
            }
            $out[] = [
                'name' => $step['name'],
                'label' => (string) ($step['label'] ?? Str::headline($step['name'])),
                'slice' => $step['slice'],
                'default' => (bool) ($step['default'] ?? true),
                'name_field' => isset($step['name_field']) && is_string($step['name_field']) ? $step['name_field'] : null,
                'name_from' => isset($step['name_from']) && is_string($step['name_from']) ? $step['name_from'] : null,
                'name_label' => (string) ($step['name_label'] ?? 'Name'),
                'field_map' => is_array($step['field_map'] ?? null) ? $step['field_map'] : [],
                'set' => is_array($step['set'] ?? null) ? $step['set'] : [],
                'children' => self::normalizeChildren($step['children'] ?? null),
            ];
        }

        return $out;
    }

    public static function find(array $manifest, string $target): ?array
    {
        foreach (self::conversionsOf($manifest) as $conversion) {
            if ($conversion['target'] === $target) {
                return $conversion;
            }
        }

        return null;
    }

    /** Declared conversions for a slice name, resolved live (Blade header menu). Never throws. */
    public static function forSlice(string $sliceName): array
    {
        try {
            $slice = app(SliceManager::class)->getSlice($sliceName);

            return $slice === null ? [] : self::conversionsOf($slice->raw);
        } catch (\Throwable) {
            return [];
        }
    }

    /** The target slice's web route name prefix, read from its own Routes file (SliceManager's pattern). */
    public static function targetRoutePrefix(string $targetSlice): ?string
    {
        try {
            $slice = app(SliceManager::class)->getSlice($targetSlice);
            $routesFile = $slice?->getRoutesPath() ? $slice->getRoutesPath().'/web.php' : null;
            if ($routesFile && is_file($routesFile)
                && preg_match("/->name\('([^']+)'\)/", (string) file_get_contents($routesFile), $m)) {
                return $m[1];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Build the pre-fill payload for the target's create
     * form. $sourceValues is keyed by source field name
     * (plus the pseudo-field 'name' for the record's
     * identity); $targetFields is the target's manifest
     * field map (name => definition).
     */
    public static function payload(array $conversion, array $sourceValues, array $targetFields): array
    {
        $noMap = array_flip(array_merge(self::SYSTEM_FIELDS, $conversion['field_no_map']));
        $out = [];

        foreach ($targetFields as $name => $definition) {
            if (isset($noMap[$name]) || ! array_key_exists($name, $sourceValues)) {
                continue;
            }
            $value = $sourceValues[$name];
            if ($value === null || $value === '' || ! self::representable($definition, $value)) {
                continue;
            }
            $out[$name] = self::coerce($definition, $value);
        }

        foreach ($conversion['field_map'] as $sourceField => $targetField) {
            if (! isset($targetFields[$targetField]) || isset($noMap[$targetField])) {
                continue;
            }
            $value = $sourceValues[$sourceField] ?? null;
            if ($value === null || $value === '' || ! self::representable($targetFields[$targetField], $value)) {
                continue;
            }
            $out[$targetField] = self::coerce($targetFields[$targetField], $value);
        }

        foreach ($conversion['set'] as $targetField => $rule) {
            if (! isset($targetFields[$targetField]) || isset($noMap[$targetField])) {
                continue;
            }
            if (is_array($rule) && array_key_exists('when', $rule)) {
                try {
                    $groups = FieldCondition::normalizeGroups($rule['when'], (string) $targetField);
                    $matches = FieldCondition::evaluateGroups($groups, $sourceValues);
                } catch (\Throwable) {
                    $matches = false;
                }
                if ($matches && array_key_exists('then', $rule)) {
                    $out[$targetField] = $rule['then'];
                } elseif (! $matches && array_key_exists('else', $rule)) {
                    $out[$targetField] = $rule['else'];
                }
            } elseif (is_array($rule) && ! empty($rule['today'])) {
                // The source conversions' set-missing-values
                // step stamps the transaction date with the
                // day of the conversion.
                $out[$targetField] = date('Y-m-d');
            } elseif (is_array($rule) && ! empty($rule['now'])) {
                $out[$targetField] = date('Y-m-d H:i:s');
            } elseif (is_scalar($rule)) {
                $out[$targetField] = $rule;
            }
        }

        // Child-table rows (D-073): built by the SAME scalar
        // machinery against the child's own field definitions
        // (its repeater_fields), so option representability and
        // set rules behave identically. A row whose values all
        // come out empty is omitted.
        foreach (($conversion['children'] ?? []) as $handle => $childDecl) {
            $childDef = $targetFields[$handle] ?? null;
            if (! is_array($childDef) || ($childDef['type'] ?? null) !== 'repeater') {
                continue;
            }
            $childFields = [];
            foreach ((array) ($childDef['repeater_fields'] ?? []) as $rf) {
                if (is_array($rf) && isset($rf['handle'])) {
                    $childFields[(string) $rf['handle']] = $rf;
                }
            }
            if ($childFields === []) {
                continue;
            }
            $rows = [];
            foreach ($childDecl['rows'] as $rowDecl) {
                // A row with no SOURCE-derived value is dropped
                // even when its `set` map would fill flags: the
                // source appends such rows only when the source
                // data exists (a Contact's phone row exists only
                // when the Lead has a phone).
                $sourced = self::payload([
                    'target' => $conversion['target'],
                    'label' => '',
                    'field_map' => $rowDecl['field_map'],
                    'field_no_map' => [],
                    'set' => [],
                    'children' => [],
                    'pre_steps' => [],
                    'source_status' => null,
                ], $sourceValues, $childFields);
                if (array_filter($sourced, static fn ($v) => $v !== null && $v !== '') === []) {
                    continue;
                }
                $rows[] = self::payload([
                    'target' => $conversion['target'],
                    'label' => '',
                    'field_map' => $rowDecl['field_map'],
                    'field_no_map' => [],
                    'set' => $rowDecl['set'],
                    'children' => [],
                    'pre_steps' => [],
                    'source_status' => null,
                ], $sourceValues, $childFields);
            }
            if ($rows !== []) {
                $out[$handle] = $rows;
            }
        }

        return $out;
    }

    /**
     * Type coercion across the map: an integer id landing in a
     * string/text field is stringified (the source's link names
     * ARE strings — a Contact's link_name holds the Lead's name,
     * which in this runtime is its registry id as a string).
     */
    private static function coerce(mixed $definition, mixed $value): mixed
    {
        $type = is_array($definition) ? ($definition['type'] ?? null) : null;
        if (($type === 'string' || $type === 'text') && is_int($value)) {
            return (string) $value;
        }

        return $value;
    }

    /** A value is representable when the target field declares no static option list, or the value is one of its keys. */
    private static function representable(mixed $definition, mixed $value): bool
    {
        $options = is_array($definition) ? ($definition['options'] ?? null) : null;
        if (! is_array($options) || $options === [] || ! is_scalar($value)) {
            return true;
        }

        return array_key_exists((string) $value, $options) || array_key_exists($value, $options);
    }
}
