<?php

namespace LaraSlice\Schema;

class Relation
{
    protected string $name;

    protected string $type; // 'hasMany', 'belongsTo', 'hasOne', 'belongsToMany'

    protected string $schemaClass;

    protected ?string $label = null;

    protected ?string $foreignKey = null;

    protected ?string $localKey = null;

    protected ?string $pivotTable = null;

    protected string $icon = 'layers';

    public function __construct(string $type, string $name, string $schemaClass)
    {
        $this->type = $type;
        $this->name = $name;
        $this->schemaClass = $schemaClass;
        $this->label = ucwords(str_replace('_', ' ', $name));
    }

    public static function hasMany(string $name, string $schemaClass): static
    {
        return new static('hasMany', $name, $schemaClass);
    }

    public static function belongsTo(string $name, string $schemaClass): static
    {
        return new static('belongsTo', $name, $schemaClass);
    }

    public static function hasOne(string $name, string $schemaClass): static
    {
        return new static('hasOne', $name, $schemaClass);
    }

    public static function belongsToMany(string $name, string $schemaClass, ?string $pivotTable = null): static
    {
        $rel = new static('belongsToMany', $name, $schemaClass);
        $rel->pivotTable = $pivotTable;

        return $rel;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function foreignKey(string $key): static
    {
        $this->foreignKey = $key;

        return $this;
    }

    public function localKey(string $key): static
    {
        $this->localKey = $key;

        return $this;
    }

    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSchemaClass(): string
    {
        return $this->schemaClass;
    }

    public function getLabel(): string
    {
        return $this->label ?? ucwords($this->name);
    }

    public function getForeignKey(): ?string
    {
        return $this->foreignKey;
    }

    public function toArray(): array
    {
        $childSchema = class_exists($this->schemaClass) ? $this->schemaClass : null;

        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->getLabel(),
            'schema' => $this->schemaClass,
            'foreign_key' => $this->foreignKey,
            'icon' => $this->icon,
            'pivot_table' => $this->pivotTable,
            'child_schema' => $childSchema ? $childSchema::toArray() : null,
        ];
    }
}
