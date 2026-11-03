<?php

namespace LaraSlice\Support;

/**
 * Static check for a declaration defect the 1 Nov 2026
 * interaction audit found the hard way: a belongsTo LOOKUP
 * table (ticket_channels, lead_sources, contract_types…)
 * declared as a child REPEATER. The save path syncs a
 * repeater by its parent foreign key; a lookup table has
 * no such column, so every create/update on the slice
 * failed with "Unknown column '<fk>' in 'WHERE'" while
 * reads (which tolerate the miss) looked fine.
 *
 * The runtime now skips FK-less repeaters defensively
 * (BaseSliceService::syncRepeaters), but the declaration
 * is still wrong — this audit makes slice:drift flag it
 * at build time with the fix spelled out.
 */
class RepeaterDeclarationAudit
{
    /**
     * @param  list<array{slice: string, repeaters: list<array{handle: string, table: string, foreign_key: string}>}>  $slices
     * @param  callable(string $table, string $column): string  $columnStatus
     *         returns 'ok', 'missing_table' or 'missing_column'
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function audit(array $slices, callable $columnStatus): array
    {
        $findings = [];
        foreach ($slices as $slice) {
            $seen = [];
            foreach ($slice['repeaters'] ?? [] as $repeater) {
                $table = (string) ($repeater['table'] ?? '');
                $fk = (string) ($repeater['foreign_key'] ?? '');
                $handle = (string) ($repeater['handle'] ?? $table);
                if ($table === '' || $fk === '') {
                    continue;
                }
                $key = $table.'|'.$fk;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $status = $columnStatus($table, $fk);
                if ($status === 'missing_column') {
                    $findings[] = [
                        'kind' => 'repeater-foreign-key',
                        'severity' => 'error',
                        'message' => "slice {$slice['slice']}: repeater '{$handle}' declares foreign key '{$fk}' but table '{$table}' has no such column — a belongsTo lookup modelled as a child repeater; remove the relation and its form section (the runtime skips it, but the declaration is wrong).",
                    ];
                } elseif ($status === 'missing_table') {
                    $findings[] = [
                        'kind' => 'repeater-foreign-key',
                        'severity' => 'warning',
                        'message' => "slice {$slice['slice']}: repeater '{$handle}' points at table '{$table}', which does not exist in this database.",
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Extract repeater declarations from a parsed slice
     * manifest: the models' relations plus form-section
     * fields of type repeater.
     *
     * @return list<array{handle: string, table: string, foreign_key: string}>
     */
    public static function repeatersFromManifest(array $manifest): array
    {
        $repeaters = [];
        $relations = $manifest['relations'] ?? [];
        foreach ($manifest['models'] ?? [] as $model) {
            if (is_array($model)) {
                $relations = array_merge($relations, $model['relations'] ?? []);
            }
        }
        foreach ($relations as $relation) {
            if (! is_array($relation)) {
                continue;
            }
            $repeaters[] = [
                'handle' => (string) ($relation['name'] ?? ''),
                'table' => (string) ($relation['table'] ?? ''),
                'foreign_key' => (string) ($relation['foreign_key'] ?? ''),
            ];
        }
        $sections = $manifest['ui']['forms']['sections']
            ?? $manifest['ui']['form']['sections']
            ?? [];
        foreach ($sections as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! is_array($field) || ($field['type'] ?? null) !== 'repeater') {
                    continue;
                }
                $repeaters[] = [
                    'handle' => (string) ($field['handle'] ?? ''),
                    'table' => (string) ($field['table'] ?? ''),
                    'foreign_key' => (string) ($field['foreign_key'] ?? ''),
                ];
            }
        }

        return $repeaters;
    }
}
