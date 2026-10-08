<?php

namespace LaraSlice\Core\Base;

use LaraSlice\Core\Contracts\IBusinessObject;

abstract class BaseFormBusinessObject implements IBusinessObject
{
    public string|int|null $id = null;

    /** Properties set from request data by fromArray(); null when the object was built by hand. */
    private ?array $providedFields = null;

    public function toArray(): array
    {
        $data = get_object_vars($this);
        unset($data['providedFields']);

        return $data;
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

    public static function fromArray(array $data): static
    {
        $instance = new static();
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
            }
        }

        // Seamless bridge between name and title for quick-add drawers & API contracts
        if (property_exists($instance, 'title') && (empty($instance->title) || !isset($data['title']))) {
            $candidate = $data['name'] ?? $data['label'] ?? null;
            if (!empty($candidate)) {
                $instance->title = (string) $candidate;
            }
        }
        if (property_exists($instance, 'name') && (empty($instance->name) || !isset($data['name']))) {
            $candidate = $data['title'] ?? $data['label'] ?? null;
            if (!empty($candidate)) {
                $instance->name = (string) $candidate;
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
}
