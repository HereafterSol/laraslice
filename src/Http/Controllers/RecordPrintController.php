<?php

namespace LaraSlice\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Security\Access;
use LaraSlice\Support\RecordActions;
use LaraSlice\Support\SliceRecordOptions;

/**
 * The built-in Print record action (D-083): a clean,
 * printable view of one record for EVERY slice — Desk's
 * form menu carries Print on every doctype, so the port's
 * does too. GET /laraslice/record-print/{prefix}/{id}.
 *
 * The rendering mirrors the source's print semantics:
 * fields flagged hidden do not print; option values print
 * as their labels; links print as the linked record's
 * identity; child tables print as tables. Values come
 * from the slice service's form object (repeater rows and
 * multiselect selections attached), never from a second
 * read path.
 */
final class RecordPrintController
{
    public function __invoke(Request $request, string $prefix, string $id)
    {
        $resolved = RecordActions::sliceForRoutePrefix($prefix);
        abort_if($resolved === null, 404);
        $user = $request->user();
        abort_if($user === null, 403);
        $base = RecordActions::permissionBase($resolved['raw'], $prefix);
        $plural = Str::plural($base);
        abort_unless(
            Access::allows($user, ["{$base}.view", "{$plural}.view", "{$base}.*", "{$plural}.*"]),
            403
        );
        $service = self::serviceFor($resolved['manifest']);
        abort_if($service === null, 404);
        $form = $service->getItemById($id);
        abort_if($form === null, 404);

        $fields = SliceRecordOptions::manifestFieldsOf($resolved['raw']);
        $sections = [];
        foreach ($fields as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            // Desk print semantics: hidden fields do not print.
            if (! empty($definition['hidden']) || (string) ($definition['type'] ?? '') === 'password') {
                continue;
            }
            $section = (string) ($definition['section'] ?? 'Details');
            $sections[$section] ??= [];
            $sections[$section][] = [
                'label' => (string) ($definition['label'] ?? Str::headline($name)),
                'type' => (string) ($definition['type'] ?? 'string'),
                'display' => $this->displayValue($fields, $name, $definition, $form->{$name} ?? null, $form),
                'rows' => (string) ($definition['type'] ?? '') === 'repeater'
                    ? $this->repeaterRows($definition, $form->{$name} ?? null)
                    : null,
            ];
        }

        $identity = SliceRecordOptions::forSlice($resolved['manifest']->name)[$id] ?? '#'.$id;

        return response()->view('laraslice::documents.record', [
            'title' => Str::headline(Str::singular($resolved['manifest']->name)).' — '.$identity,
            'sliceLabel' => Str::headline($resolved['manifest']->name),
            'identity' => $identity,
            'sections' => $sections,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $definition
     */
    private function displayValue(array $fields, string $name, array $definition, mixed $value, object $form): string
    {
        $type = (string) ($definition['type'] ?? 'string');
        if ($type === 'repeater') {
            return '';
        }
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }
        if ($type === 'multiselect') {
            $target = isset($definition['target']) && is_string($definition['target']) ? $definition['target'] : null;
            $labels = $target !== null ? SliceRecordOptions::forSlice($target) : [];
            $parts = [];
            foreach ((array) $value as $selected) {
                $parts[] = $labels[$selected] ?? '#'.$selected;
            }

            return implode(', ', $parts);
        }
        if ($type === 'boolean' || $type === 'checkbox') {
            return $value ? 'Yes' : 'No';
        }
        // Linked record: print the target's identity, not the id.
        $targetSlice = $this->linkTargetSlice($fields, $name, $definition, $form);
        if ($targetSlice !== null && is_numeric($value)) {
            $labels = SliceRecordOptions::forSlice($targetSlice);

            return $labels[$value] ?? '#'.$value;
        }
        // Declared options print as labels (map or list form).
        $options = $definition['options'] ?? null;
        if (is_array($options) && $options !== []) {
            foreach ($options as $key => $option) {
                if (is_array($option)) {
                    if ((string) ($option['value'] ?? '') === (string) $value) {
                        return (string) ($option['label'] ?? $value);
                    }
                } elseif ((string) $key === (string) $value && is_string($option)) {
                    return $option;
                } elseif (is_int($key) && (string) $option === (string) $value) {
                    return (string) $option;
                }
            }
        }
        if (is_array($value)) {
            return implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }

        return (string) $value;
    }

    /**
     * The slice a link field points at: a dynamic
     * slice_records:<field> source takes the target from
     * that sibling field's VALUE (the port's Dynamic Link
     * shape); a plain relation resolves by name.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $definition
     */
    private function linkTargetSlice(array $fields, string $name, array $definition, object $form): ?string
    {
        $source = $definition['options_from'] ?? null;
        if (is_string($source) && str_starts_with($source, 'slice_records:')) {
            $viaField = substr($source, strlen('slice_records:'));
            $target = $form->{$viaField} ?? null;
            if (is_string($target) && $target !== '') {
                $resolved = SliceRecordOptions::resolveSliceName($target);
                return $resolved !== '' ? $resolved : $target;
            }

            return null;
        }
        if (isset($definition['relation']) || isset($definition['target'])) {
            return SliceRecordOptions::viaTargetSlice($fields, $name);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{columns: list<string>, rows: list<list<string>>}|null
     */
    private function repeaterRows(array $definition, mixed $value): ?array
    {
        $subfields = is_array($definition['repeater_fields'] ?? null) ? $definition['repeater_fields'] : [];
        if ($subfields === [] || ! is_array($value)) {
            return null;
        }
        $columns = [];
        $handles = [];
        foreach ($subfields as $sub) {
            if (! is_array($sub) || empty($sub['handle'])) {
                continue;
            }
            if (! empty($sub['hidden'])) {
                continue;
            }
            $handles[] = (string) $sub['handle'];
            $columns[] = (string) ($sub['label'] ?? Str::headline((string) $sub['handle']));
        }
        $rows = [];
        foreach ($value as $row) {
            $row = is_object($row) ? get_object_vars($row) : (array) $row;
            $cells = [];
            foreach ($handles as $handle) {
                $cell = $row[$handle] ?? '';
                $cells[] = is_scalar($cell) ? (string) $cell : '';
            }
            $rows[] = $cells;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    private static function serviceFor(SliceManifest $manifest): ?BaseSliceService
    {
        $namespace = $manifest->getNamespace();
        $singular = Str::studly(Str::singular($manifest->name));
        $candidates = ["{$namespace}\\Services\\{$singular}SliceService"];
        $servicesPath = $manifest->getServicesPath();
        if ($servicesPath !== null) {
            foreach (glob($servicesPath.'/*SliceService.php') ?: [] as $file) {
                $candidates[] = $namespace.'\\Services\\'.basename($file, '.php');
            }
        }
        foreach (array_unique($candidates) as $class) {
            if (class_exists($class)) {
                $service = app($class);
                if ($service instanceof BaseSliceService) {
                    return $service;
                }
            }
        }

        return null;
    }
}
