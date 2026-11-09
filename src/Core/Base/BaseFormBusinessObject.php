<?php

namespace LaraSlice\Core\Base;

use LaraSlice\Core\Contracts\IBusinessObject;

/**
 * Subclasses keep a compatible constructor; the static factories rely on new static().
 *
 * @phpstan-consistent-constructor
 */
abstract class BaseFormBusinessObject implements IBusinessObject
{
    public string|int|null $id = null;

    /** Properties set from request data by fromArray(); null when the object was built by hand. */
    private ?array $providedFields = null;

    /**
     * Values fromArray() could not bind to their property's
     * declared type (Proposal #6 strict binding). Recorded on
     * every bind; the base slice controllers reject the write
     * when the list is non-empty instead of letting the value
     * vanish silently.
     *
     * @var array<int, array{field: string, problem: string, expected: string}>
     */
    private array $bindingIssues = [];

    public function toArray(): array
    {
        $data = get_object_vars($this);
        unset($data['providedFields'], $data['bindingIssues']);

        return $data;
    }

    /**
     * Binding failures recorded by fromArray(): one issue per
     * provided value that did not fit its declared type, in
     * the shared {field, problem, expected} shape. Empty when
     * every provided value bound cleanly (or the object was
     * built by hand, in which case no binding took place).
     *
     * @return array<int, array{field: string, problem: string, expected: string}>
     */
    public function bindingIssues(): array
    {
        return $this->bindingIssues;
    }

    /**
     * Property names that the request actually supplied, or null when unknown.
     *
     * @return array<int, string>|null
     */
    public function providedFields(): ?array
    {
        return $this->providedFields;
    }

    public function provided(string $property): bool
    {
        return $this->providedFields === null || in_array($property, $this->providedFields, true);
    }

    /**
     * Record a property as supplied by the current request even when its
     * value is empty — e.g. a repeater whose rows were all removed submits
     * no array at all, and the service still needs to know the (now empty)
     * collection was provided so it can sync deletions.
     */
    public function markProvided(string $property): void
    {
        if ($this->providedFields !== null && ! in_array($property, $this->providedFields, true)) {
            $this->providedFields[] = $property;
        }
    }

    public static function fromArray(array $data): static
    {
        $instance = new static;
        $instance->providedFields = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            // Support camelCase and snake_case
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $property = property_exists($instance, $key) ? $key : (property_exists($instance, $camel) ? $camel : null);

            if ($property !== null && static::coerce($instance, $property, $value, $coerced)) {
                $instance->{$property} = $coerced;
                $instance->providedFields[] = $property;
            } elseif ($property !== null && $value !== null && $value !== '') {
                // The value was provided but does not fit the
                // declared type. Blank values are form semantics
                // (an untouched optional input), never an issue;
                // anything else is recorded so the write can be
                // rejected with the reason instead of the value
                // being silently dropped (the silent-coercion
                // wart, Proposal #6).
                $instance->bindingIssues[] = static::bindingIssue($instance, $property, $value);
            }
        }

        // Seamless bridge between name and title for quick-add drawers & API contracts.
        // Only SCALAR candidates move: the bridge is an alias
        // for a declared string field, not a cast — a
        // non-scalar under the alias key is skipped like an
        // unknown key, never stringified into "Array"
        // (goldfish round). A bridged value counts as
        // provided, so replace-completeness sees it.
        if (property_exists($instance, 'title') && (empty($instance->title) || ! isset($data['title']))) {
            $candidate = $data['name'] ?? $data['label'] ?? null;
            if (is_scalar($candidate) && ! empty($candidate)) {
                $instance->title = (string) $candidate;
                $instance->providedFields[] = 'title';
            }
        }
        if (property_exists($instance, 'name') && (empty($instance->name) || ! isset($data['name']))) {
            $candidate = $data['title'] ?? $data['label'] ?? null;
            if (is_scalar($candidate) && ! empty($candidate)) {
                $instance->name = (string) $candidate;
                $instance->providedFields[] = 'name';
            }
        }

        return $instance;
    }

    /**
     * Convert a request value to the property's declared type. Returns false when it cannot be
     * converted, in which case the property keeps its default and validation reports the problem.
     */
    protected static function coerce(object $instance, string $property, mixed $value, mixed &$coerced): bool
    {
        $type = (new \ReflectionProperty($instance, $property))->getType();
        if (! $type instanceof \ReflectionNamedType || $type->getName() === 'mixed') {
            $coerced = $value;

            return true;
        }

        if ($value === null) {
            if ($type->allowsNull()) {
                $coerced = null;

                return true;
            }
            // Empty form inputs arrive as null; treat them as empty values
            $coerced = match ($type->getName()) {
                'string' => '',
                'bool' => false,
                'array' => [],
                default => null,
            };

            return $coerced !== null;
        }

        $coerced = match ($type->getName()) {
            'string' => is_scalar($value) ? (string) $value : null,
            'int' => is_numeric($value) && (int) $value == $value ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            // A hidden empty input (e.g. "no roles selected") arrives as ''
            'array' => is_array($value) ? $value : ($value === '' ? [] : null),
            default => $value,
        };

        return $coerced !== null;
    }

    /**
     * Build the per-issue error for one unbindable value: what
     * was given (rendered readably, truncated), and what the
     * declared type expects — including the representations
     * that DO bind, so the caller can self-repair.
     *
     * @return array{field: string, problem: string, expected: string}
     */
    protected static function bindingIssue(object $instance, string $property, mixed $value): array
    {
        $type = (new \ReflectionProperty($instance, $property))->getType();
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';

        $given = match (true) {
            is_string($value) => "'".(mb_strlen($value) > 60 ? mb_substr($value, 0, 60).'…' : $value)."'",
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => 'an array of '.count($value).' item(s)',
            is_object($value) => 'an object ('.get_class($value).')',
            default => gettype($value),
        };

        $expected = match ($typeName) {
            'int' => 'An integer value (e.g. 42). Integers and integer strings are accepted.',
            'float' => 'A numeric value (e.g. 12.50). Numbers and numeric strings are accepted.',
            'bool' => 'A boolean value: true/false, 1/0, yes/no or on/off.',
            'string' => 'A single text value (not an array or object).',
            'array' => 'A list of values (an array).',
            default => "A value matching the declared type ({$typeName}).",
        };

        return [
            'field' => $property,
            'problem' => "The value {$given} cannot be used for '{$property}'.",
            'expected' => $expected,
        ];
    }
}
