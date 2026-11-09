<?php

declare(strict_types=1);

namespace LaraSlice\Core\Ai;

/**
 * Proposal #4's approval form, as data (compilation §3.11 —
 * the json-render pattern): a confirmation is presented to a
 * human by rendering a SPEC from a small typed component
 * vocabulary, never by hand-building HTML per write type.
 * The spec is plain data: the Explorer page renders it in the
 * browser, an MCP client renders it in its own UI, and
 * `validate()` checks any spec — built here or supplied by a
 * caller — per-issue before anything renders it. An invalid
 * spec yields readable issues, never broken markup.
 *
 * Vocabulary (v1), deliberately small:
 *   operation  what will run (surface, name, slice/method/uri
 *              on the Explorer, destructive flag)
 *   field      one proposed argument: name, human label,
 *              display value (always a string by the time it
 *              is in a spec — see displayValue())
 *   warning    a coloured notice (level: info | destructive)
 *   note       a plain explanatory line
 *   expiry     when the proposal dies (expires_at + ttl)
 */
final class ApprovalSpec
{
    public const SPEC = 'laraslice/approval-spec';

    public const VERSION = 1;

    public const TYPES = ['operation', 'field', 'warning', 'note', 'expiry'];

    private const WARNING_LEVELS = ['info', 'destructive'];

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const WRITE_KINDS = ['create', 'update', 'delete'];

    /**
     * The spec for an MCP write-tool proposal.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function forMcpTool(string $tool, array $args, bool $destructive, int $expiresAt, int $ttlSeconds): array
    {
        $components = [[
            'type' => 'operation',
            'surface' => 'mcp',
            'name' => $tool,
            'label' => $tool,
            'destructive' => $destructive,
        ]];
        foreach ($args as $name => $value) {
            $components[] = [
                'type' => 'field',
                'name' => (string) $name,
                'label' => (string) $name,
                'value' => self::displayValue($value),
            ];
        }
        if ($destructive) {
            $components[] = [
                'type' => 'warning',
                'level' => 'destructive',
                'text' => 'This operation is destructive: it permanently removes or destroys data or code. Approving it cannot be undone by the confirmation flow.',
            ];
        }
        $components[] = [
            'type' => 'note',
            'text' => 'Nothing has been executed yet. The write runs only when the call is repeated with the confirmation token and exactly these arguments.',
        ];
        $components[] = self::expiryComponent($expiresAt, $ttlSeconds);

        return self::wrap($components);
    }

    /**
     * The spec for an Explorer write-operation proposal. Field
     * labels come from the schema-generated form inputs, so the
     * human reads the same labels the form showed.
     *
     * @param  array<string, mixed>  $operation  the projected operation (name/method/uri)
     * @param  array<int, array<string, mixed>>  $formFields  ExplorerForm field inputs (name/label)
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function forExplorerOperation(string $sliceTitle, array $operation, string $kind, array $formFields, array $args, int $expiresAt, int $ttlSeconds): array
    {
        $labels = [];
        foreach ($formFields as $input) {
            if (is_array($input) && ! empty($input['name'])) {
                $labels[(string) $input['name']] = (string) ($input['label'] ?? $input['name']);
            }
        }

        $components = [[
            'type' => 'operation',
            'surface' => 'explorer',
            'name' => (string) ($operation['name'] ?? ''),
            'label' => $sliceTitle.' — '.(string) ($operation['name'] ?? ''),
            'slice' => $sliceTitle,
            'method' => strtoupper((string) ($operation['method'] ?? '')),
            'uri' => (string) ($operation['uri'] ?? ''),
            'kind' => $kind,
            'destructive' => $kind === 'delete',
        ]];
        foreach ($args as $name => $value) {
            $components[] = [
                'type' => 'field',
                'name' => (string) $name,
                'label' => $labels[(string) $name] ?? (string) $name,
                'value' => self::displayValue($value),
            ];
        }
        if ($kind === 'delete') {
            $components[] = [
                'type' => 'warning',
                'level' => 'destructive',
                'text' => 'This deletes the record identified in the URI. Approving it cannot be undone by the confirmation flow.',
            ];
        }
        $components[] = [
            'type' => 'note',
            'text' => 'Nothing has been executed yet. The write runs only when you approve — with exactly these values.',
        ];
        $components[] = self::expiryComponent($expiresAt, $ttlSeconds);

        return self::wrap($components);
    }

    /**
     * Validate a spec against the vocabulary. Returns a list of
     * issues in the ladder's per-issue shape (field / problem /
     * expected); an empty list means the spec may be rendered.
     *
     * @param  array<string, mixed>  $spec
     * @return array<int, array{field: string, problem: string, expected: string}>
     */
    public static function validate(array $spec): array
    {
        $issues = [];
        $add = static function (string $field, string $problem, string $expected) use (&$issues): void {
            $issues[] = ['field' => $field, 'problem' => $problem, 'expected' => $expected];
        };

        if (($spec['spec'] ?? null) !== self::SPEC) {
            $add('spec', 'The spec header is missing or is not '.self::SPEC.'.', 'spec: "'.self::SPEC.'" with version '.self::VERSION.'.');
        }
        if (($spec['version'] ?? null) !== self::VERSION) {
            $add('version', 'The spec version is missing or unsupported.', 'version: '.self::VERSION.' (the only version this renderer speaks).');
        }

        $components = $spec['components'] ?? null;
        if (! is_array($components) || $components === [] || ! array_is_list($components)) {
            $add('components', 'The spec carries no component list.', 'A non-empty list of components from the vocabulary: '.implode(', ', self::TYPES).'.');

            return $issues;
        }

        foreach ($components as $i => $component) {
            $at = "components.{$i}";
            if (! is_array($component)) {
                $add($at, 'A component is not an object.', 'Each component is an object with a "type" from the vocabulary.');

                continue;
            }
            $type = $component['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                $add("{$at}.type", 'Unknown component type '.(is_string($type) ? "'{$type}'" : '(missing)').'.', 'One of: '.implode(', ', self::TYPES).'.');

                continue;
            }

            match ($type) {
                'operation' => self::validateOperation($component, $at, $add),
                'field' => self::validateField($component, $at, $add),
                'warning' => self::validateTextComponent($component, $at, $add, true),
                'note' => self::validateTextComponent($component, $at, $add, false),
                'expiry' => self::validateExpiry($component, $at, $add),
            };
        }

        return $issues;
    }

    /** @param array<string, mixed> $component */
    private static function validateOperation(array $component, string $at, \Closure $add): void
    {
        $surface = $component['surface'] ?? null;
        if (! in_array($surface, ['mcp', 'explorer'], true)) {
            $add("{$at}.surface", 'The operation surface is missing or unknown.', '"mcp" or "explorer".');
        }
        if (! is_string($component['name'] ?? null) || ($component['name'] ?? '') === '') {
            $add("{$at}.name", 'The operation name is missing.', 'The tool name (MCP) or route name (Explorer) being confirmed.');
        }
        if (! is_bool($component['destructive'] ?? null)) {
            $add("{$at}.destructive", 'The destructive flag is missing or not a boolean.', 'true or false — renderers style destructive operations differently.');
        }
        if ($surface === 'explorer') {
            if (! in_array($component['method'] ?? null, self::WRITE_METHODS, true)) {
                $add("{$at}.method", 'The operation method is not a write method.', 'One of: '.implode(', ', self::WRITE_METHODS).'.');
            }
            if (! is_string($component['uri'] ?? null) || ! str_starts_with((string) ($component['uri'] ?? ''), '/')) {
                $add("{$at}.uri", 'The operation URI is missing or not an absolute path.', 'The concrete URI the write will be dispatched to, starting with "/".');
            }
            if (! in_array($component['kind'] ?? null, self::WRITE_KINDS, true)) {
                $add("{$at}.kind", 'The operation kind is not a write kind.', 'One of: '.implode(', ', self::WRITE_KINDS).'.');
            }
        }
    }

    /** @param array<string, mixed> $component */
    private static function validateField(array $component, string $at, \Closure $add): void
    {
        if (! is_string($component['name'] ?? null) || ($component['name'] ?? '') === '') {
            $add("{$at}.name", 'The field name is missing.', 'The argument name exactly as it will be sent.');
        }
        if (! is_string($component['label'] ?? null) || ($component['label'] ?? '') === '') {
            $add("{$at}.label", 'The field label is missing.', 'A human-readable label for the argument.');
        }
        $value = $component['value'] ?? null;
        if (! is_scalar($value) && $value !== null) {
            $add("{$at}.value", 'The field value is not displayable.', 'A scalar display value (builders stringify with ApprovalSpec::displayValue()).');
        }
    }

    /** @param array<string, mixed> $component */
    private static function validateTextComponent(array $component, string $at, \Closure $add, bool $warning): void
    {
        if ($warning && ! in_array($component['level'] ?? null, self::WARNING_LEVELS, true)) {
            $add("{$at}.level", 'The warning level is missing or unknown.', 'One of: '.implode(', ', self::WARNING_LEVELS).'.');
        }
        if (! is_string($component['text'] ?? null) || ($component['text'] ?? '') === '') {
            $add("{$at}.text", 'The text is missing.', 'A non-empty human-readable sentence.');
        }
    }

    /** @param array<string, mixed> $component */
    private static function validateExpiry(array $component, string $at, \Closure $add): void
    {
        if (! is_string($component['expires_at'] ?? null) || ($component['expires_at'] ?? '') === '') {
            $add("{$at}.expires_at", 'The expiry timestamp is missing.', 'An ISO-8601 timestamp for when the proposal expires.');
        }
        if (! is_int($component['ttl_seconds'] ?? null) || ($component['ttl_seconds'] ?? -1) < 0) {
            $add("{$at}.ttl_seconds", 'The TTL is missing or not a non-negative integer.', 'The proposal lifetime in seconds (server-side configuration).');
        }
    }

    /**
     * The one display conversion for spec values: a spec never
     * carries a raw array/object — builders stringify here, so
     * every renderer can treat `value` as printable text.
     */
    public static function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '(empty)',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /** @return array<string, mixed> */
    private static function expiryComponent(int $expiresAt, int $ttlSeconds): array
    {
        return [
            'type' => 'expiry',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private static function wrap(array $components): array
    {
        return ['spec' => self::SPEC, 'version' => self::VERSION, 'components' => $components];
    }
}
