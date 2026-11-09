<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use LaraSlice\Support\FieldInference;

/**
 * OBS-B2 — one producer form for a Heart's `ui` block.
 *
 * The Manage editor's round trip (panel payload + SliceModifier's
 * preview/save merge) is a fixed point for Hearts whose ui block
 * carries the editor's resting structure: directory columns with
 * `key`/`type`, the directory's standard blocks present, and a
 * forms block that declares neither `mode` nor `presentation`
 * (the port form — ported Hearts preview a one-setting change in
 * ~132 chars). The blueprint applier used to install the design
 * factory's compact ui verbatim (columns without `key`/`type`,
 * no summary_cards/bulk_actions/views/default_view/inline_edit,
 * forms.presentation as a {create, edit} map), so a Studio-born
 * slice's FIRST Manage save previewed a ~5.5K normalization diff
 * around the one real change.
 *
 * This canonicalizer is applied at the two producer doors that
 * write NEW Hearts — SliceGenerator::generate() and
 * BlueprintApplier::normalizeManifest() — so a Heart is born in
 * the same form the Manage save path would produce from it.
 * Existing Hearts are never rewritten by it, and it is a no-op
 * on port-form ui (everything it fills is already declared
 * there; declared values are never altered — it only fills
 * absent (or reader-equivalent empty) resting keys, completes column identity keys in the
 * port key order, and drops a forms mode/presentation that sits
 * at the resting default, which no runtime reader consumes:
 * form rendering reads ui.presentation + forms.sections, and
 * forms.mode readers expect the string form).
 *
 * Idempotent by construction: canonicalize(canonicalize(ui))
 * === canonicalize(ui).
 */
final class HeartUiCanonicalizer
{
    /**
     * The Manage panel's own default bulk actions, in the
     * panel's order and entry shape — what a first Manage save
     * writes into a Heart that declares none.
     */
    private const DEFAULT_BULK_ACTIONS = [
        ['handle' => 'delete', 'label' => 'Delete', 'type' => 'native'],
        ['handle' => 'export', 'label' => 'Export', 'type' => 'native'],
    ];

    /** The design factory's resting forms presentation map. */
    private const RESTING_FORMS_PRESENTATION_MAP = ['create' => 'drawer', 'edit' => 'drawer'];

    /**
     * @param  array<string, mixed>  $ui
     * @param  list<array<string, mixed>>  $fields  Field declarations (name|handle, type, label) for inference.
     * @return array<string, mixed>
     */
    public static function canonicalize(array $ui, array $fields = []): array
    {
        $fieldInfo = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $handle = (string) ($field['name'] ?? $field['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            $fieldInfo[$handle] = [
                'type' => (string) ($field['type'] ?? 'string'),
                'label' => isset($field['label']) ? (string) $field['label'] : null,
            ];
        }

        if (isset($ui['directory']) && is_array($ui['directory'])) {
            $ui['directory'] = self::canonicalizeDirectory($ui['directory'], $fieldInfo);
        }
        if (isset($ui['forms']) && is_array($ui['forms'])) {
            $ui['forms'] = self::canonicalizeForms($ui['forms']);
        }

        return $ui;
    }

    /**
     * @param  array<string, mixed>  $directory
     * @param  array<string, array{type: string, label: ?string}>  $fieldInfo
     * @return array<string, mixed>
     */
    private static function canonicalizeDirectory(array $directory, array $fieldInfo): array
    {
        if (isset($directory['columns']) && is_array($directory['columns'])) {
            foreach ($directory['columns'] as $index => $column) {
                if (is_array($column)) {
                    $directory['columns'][$index] = self::canonicalizeColumn($column, $fieldInfo);
                }
            }
        }

        // Fill the resting blocks the Manage payload always
        // carries, so a first save has nothing to materialize.
        // Present keys are never touched; fills append in a
        // stable order.
        if (! array_key_exists('sidebar_filters', $directory)) {
            $directory['sidebar_filters'] = [];
        }
        if (! array_key_exists('summary_cards', $directory)) {
            $directory['summary_cards'] = [];
        }
        if (! array_key_exists('views', $directory) || $directory['views'] === []) {
            // An empty views list is reader-equivalent to absent
            // (the directory falls back to ['table']), so it is
            // not a fixed point either — the panel emits its
            // hydrated-or-default list on every save.
            $views = ['table'];
            foreach ($fieldInfo as $handle => $info) {
                if (FieldInference::analyze($handle, $info['type'])['is_status']) {
                    $views[] = 'board';
                    break;
                }
            }
            $directory['views'] = $views;
        }
        if (! array_key_exists('default_view', $directory) || $directory['default_view'] === '' || $directory['default_view'] === null) {
            $directory['default_view'] = 'table';
        }
        if (! array_key_exists('inline_edit', $directory)) {
            $directory['inline_edit'] = [];
        }
        if (! array_key_exists('bulk_actions', $directory) || $directory['bulk_actions'] === []) {
            // An empty bulk_actions list is reader-equivalent to
            // absent (the generated directory reads it only for
            // workflow transitions; the native export/delete
            // affordances are supplied by the directory itself),
            // and the Manage panel's component default overwrites
            // it on first save — so the canonical form declares
            // the panel's defaults, in the panel's shape.
            $directory['bulk_actions'] = self::DEFAULT_BULK_ACTIONS;
        }

        return $directory;
    }

    /**
     * Complete a column's identity keys in the port key order
     * (handle, key, label, formatter, type, sortable, then the
     * declared extras verbatim). Declared values always win.
     *
     * @param  array<string, mixed>  $column
     * @param  array<string, array{type: string, label: ?string}>  $fieldInfo
     * @return array<string, mixed>
     */
    private static function canonicalizeColumn(array $column, array $fieldInfo): array
    {
        $handle = (string) ($column['handle'] ?? $column['key'] ?? '');
        if ($handle === '') {
            return $column;
        }
        $info = $fieldInfo[$handle] ?? null;

        $formatter = $column['formatter'] ?? $column['type'] ?? null;
        if (! is_string($formatter) || $formatter === '') {
            $formatter = $info !== null
                ? FieldInference::analyze($handle, $info['type'])['formatter']
                : 'text';
        }

        $out = [
            'handle' => $handle,
            'key' => isset($column['key']) && is_string($column['key']) && $column['key'] !== '' ? $column['key'] : $handle,
            'label' => $column['label'] ?? ($info['label'] ?? Str::headline($handle)),
            'formatter' => $formatter,
            'type' => isset($column['type']) && is_string($column['type']) && $column['type'] !== '' ? $column['type'] : $formatter,
        ];
        if (array_key_exists('sortable', $column)) {
            $out['sortable'] = $column['sortable'];
        }
        foreach ($column as $key => $value) {
            if (! array_key_exists($key, $out)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The port form declares neither forms.mode nor
     * forms.presentation: both sit at the editor's resting
     * default ('drawer'), and the Manage merge prunes exactly
     * those resting values instead of writing them. A forms
     * block whose mode/presentation are both at rest is
     * normalized to the port form; any non-resting declaration
     * is the author's and is preserved verbatim.
     *
     * @param  array<string, mixed>  $forms
     * @return array<string, mixed>
     */
    private static function canonicalizeForms(array $forms): array
    {
        $mode = $forms['mode'] ?? null;
        $presentation = $forms['presentation'] ?? null;

        $modeResting = $mode === null || $mode === 'drawer';
        $presentationResting = $presentation === null
            || $presentation === 'drawer'
            || $presentation === self::RESTING_FORMS_PRESENTATION_MAP;

        if ($modeResting && $presentationResting) {
            unset($forms['mode'], $forms['presentation']);
        }

        return $forms;
    }
}
