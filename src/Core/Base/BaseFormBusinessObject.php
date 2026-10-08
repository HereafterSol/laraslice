<?php

namespace LaraSlice\Core\Base;

use LaraSlice\Core\Contracts\IBusinessObject;

abstract class BaseFormBusinessObject implements IBusinessObject
{
    public string|int|null $id = null;

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): static
    {
        $instance = new static();
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            // Support camelCase and snake_case
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $property = property_exists($instance, $key) ? $key : (property_exists($instance, $camel) ? $camel : null);

            if ($property !== null && static::coerce($instance, $property, $value, $coerced)) {
                $instance->{$property} = $coerced;
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
            'array' => is_array($value) ? $value : null,
            default => $value,
        };

        return $coerced !== null;
    }
}
