<?php

namespace LaraSlice\Schema;

abstract class SliceSchema
{
    /**
     * Define the form fields (Filament-style declarative schema)
     *
     * @return Field[]
     */
    public static function fields(): array
    {
        if (method_exists(static::class, 'form')) {
            return static::form();
        }

        return [];
    }

    /**
     * Define the table listing columns (Filament-style declarative schema)
     *
     * @return Column[]
     */
    public static function columns(): array
    {
        if (method_exists(static::class, 'table')) {
            return static::table();
        }

        return [];
    }

    /**
     * Define child relations and sub-tables (hasMany, belongsTo, belongsToMany)
     *
     * @return Relation[]
     */
    public static function relations(): array
    {
        return [];
    }

    /**
     * Render the form fields dynamically for BlatUI
     */
    public static function renderFormFields(?object $form = null): string
    {
        $html = [];
        foreach (static::fields() as $field) {
            $html[] = $field->renderBlatUi($form);
        }

        return implode("\n\n", $html);
    }

    /**
     * Export schema array structure including child relations
     */
    public static function toArray(): array
    {
        return [
            'fields' => array_map(fn (Field $f) => $f->toArray(), static::fields()),
            'columns' => array_map(fn (Column $c) => $c->toArray(), static::columns()),
            'relations' => array_map(fn (Relation $r) => $r->toArray(), static::relations()),
        ];
    }

    /**
     * Export schema metadata to JSON string (for AI agents & Flutter generators)
     */
    public static function toJson(int $options = JSON_PRETTY_PRINT): string
    {
        return json_encode(static::toArray(), $options);
    }
}
