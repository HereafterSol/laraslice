<?php

declare(strict_types=1);

namespace LaraSlice\Core\Metadata;

/**
 * Proposal #2's executable form, as data: turns a slice's
 * schema contract into one form spec per declared operation.
 *
 * An operation is invocable from the form only when its shape
 * is derivable from the schema alone — the resource patterns:
 *
 *   GET     collection uri            → read (no inputs)
 *   GET     uri with one {param}      → read_one (id input)
 *   POST    collection uri            → create (schema fields)
 *   PUT|PATCH uri with one {param}    → update (id + fields)
 *   DELETE  uri with one {param}      → delete (id input)
 *
 * Everything else — custom actions (POST /{id}/approve), form
 * pages (the app's own create/edit screens), multi-parameter
 * routes — is INSPECT-only: listed with its name and URI, and
 * the reason it is not invoked from here. The form never
 * guesses a payload the schema cannot describe.
 *
 * Field inputs come from the schema's field entries (the
 * describe core): type → input kind, required → required,
 * declared enum values → a select. An enum above the served
 * cap arrives as `enum_truncated` and renders as a text input
 * with the count stated — never a partial select presented
 * as the whole truth.
 */
final class ExplorerForm
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>>
     */
    public static function operations(array $schema): array
    {
        $forms = [];
        foreach ((array) ($schema['operations'] ?? []) as $operation) {
            if (is_array($operation)) {
                $forms[] = self::forOperation($operation, $schema);
            }
        }

        return $forms;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function forOperation(array $operation, array $schema): array
    {
        $method = strtoupper((string) ($operation['method'] ?? 'GET'));
        $uri = (string) ($operation['uri'] ?? '');
        $name = (string) ($operation['name'] ?? '');
        preg_match_all('/\{(\w+)\}/', $uri, $matches);
        $params = $matches[1];

        $form = [
            'operation' => $operation,
            'kind' => 'inspect',
            'invocable' => false,
            'id_param' => null,
            'fields' => [],
            'note' => null,
        ];

        // The app's own form pages render HTML, not data; the
        // explorer's generated form replaces them here.
        $suffix = str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name;
        if ($method === 'GET' && in_array($suffix, ['create', 'edit'], true)) {
            $form['note'] = 'Renders the app\'s own form page (HTML). Use the create/update forms here instead.';

            return $form;
        }

        if ($method === 'GET' && $params === []) {
            $form['kind'] = 'read';
            $form['invocable'] = true;
        } elseif ($method === 'GET' && count($params) === 1) {
            $form['kind'] = 'read_one';
            $form['invocable'] = true;
            $form['id_param'] = $params[0];
        } elseif ($method === 'POST' && $params === []) {
            $form['kind'] = 'create';
            $form['invocable'] = true;
            $form['fields'] = self::fieldInputs($schema);
        } elseif (in_array($method, ['PUT', 'PATCH'], true) && count($params) === 1) {
            $form['kind'] = 'update';
            $form['invocable'] = true;
            $form['id_param'] = $params[0];
            $form['fields'] = self::fieldInputs($schema);
        } elseif ($method === 'DELETE' && count($params) === 1) {
            $form['kind'] = 'delete';
            $form['invocable'] = true;
            $form['id_param'] = $params[0];
        } else {
            $form['note'] = 'Custom operation: its payload is not derivable from the slice schema, so it is inspect-only here.';
        }

        return $form;
    }

    /**
     * The create/update inputs, generated from the schema's
     * field entries.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>>
     */
    public static function fieldInputs(array $schema): array
    {
        $inputs = [];
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            if (is_array($field) && ! empty($field['name'])) {
                $inputs[] = self::fieldInput($field);
            }
        }

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    public static function fieldInput(array $field): array
    {
        $type = strtolower((string) ($field['type'] ?? 'string'));
        $input = [
            'name' => (string) $field['name'],
            'label' => (string) ($field['label'] ?? $field['name']),
            'input' => 'text',
            'required' => ! empty($field['required']),
            'options' => null,
            'note' => null,
        ];

        if (isset($field['enum']) && is_array($field['enum']) && $field['enum'] !== []) {
            $input['input'] = 'select';
            $input['options'] = array_map(
                fn ($value): array => ['value' => (string) $value, 'label' => (string) $value],
                array_values($field['enum'])
            );
        } elseif (! empty($field['enum_truncated'])) {
            $count = (int) ($field['enum_count'] ?? 0);
            $input['note'] = "{$count} declared values exceed the served cap of 50 — type the exact declared value.";
        } elseif (in_array($type, ['boolean', 'bool', 'toggle', 'checkbox'], true)) {
            $input['input'] = 'select';
            $input['options'] = [
                ['value' => '1', 'label' => 'Yes'],
                ['value' => '0', 'label' => 'No'],
            ];
        } elseif (in_array($type, ['integer', 'int', 'bigint', 'smallint', 'tinyint', 'foreign_id'], true)) {
            $input['input'] = 'number';
        } elseif (in_array($type, ['decimal', 'float', 'double', 'money', 'number'], true)) {
            $input['input'] = 'number';
        } elseif ($type === 'date') {
            $input['input'] = 'date';
        } elseif (in_array($type, ['datetime', 'timestamp', 'datetimetz'], true)) {
            $input['input'] = 'datetime-local';
        } elseif ($type === 'time') {
            $input['input'] = 'time';
        } elseif ($type === 'email') {
            $input['input'] = 'email';
        } elseif (in_array($type, ['text', 'json', 'array', 'object'], true)) {
            $input['input'] = 'textarea';
            if ($type !== 'text') {
                $input['note'] = 'JSON value.';
            }
        }

        if (! empty($field['relation'])) {
            $relationNote = 'References the '.(string) $field['relation'].' table.';
            $input['note'] = $input['note'] === null ? $relationNote : $input['note'].' '.$relationNote;
        }

        return $input;
    }
}
