<?php

namespace LaraSlice\Schema;

use Illuminate\Support\Str;

class Column
{
    protected string $name;

    protected ?string $label = null;

    protected string $type = 'text';

    protected bool $sortable = false;

    protected bool $searchable = false;

    protected array $badgeTones = [];

    protected ?string $prefix = null;

    protected ?string $suffix = null;

    public function __construct(string $name, string $type = 'text')
    {
        $this->name = $name;
        $this->type = $type;
        $this->label = Str::title(str_replace('_', ' ', $name));
    }

    public static function make(string $name): static
    {
        return new static($name, 'text');
    }

    public static function text(string $name): static
    {
        return new static($name, 'text');
    }

    public static function badge(string $name, array $tones = []): static
    {
        $col = new static($name, 'badge');
        $col->badgeTones = $tones ?: [
            'active' => 'success',
            'published' => 'success',
            'draft' => 'warning',
            'archived' => 'secondary',
        ];

        return $col;
    }

    public static function money(string $name, string $currency = '$'): static
    {
        $col = new static($name, 'money');
        $col->prefix = $currency;

        return $col;
    }

    public static function date(string $name): static
    {
        return new static($name, 'date');
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Str::title($this->name);
    }

    public function renderHeader(): string
    {
        return "<x-ui.table-head>{$this->getLabel()}</x-ui.table-head>";
    }

    public function renderCell(): string
    {
        $name = $this->name;

        if ($this->type === 'badge') {
            return <<<BLADE
<x-ui.table-cell>
    @php \$val = strtolower(\$item->{$name} ?? ''); @endphp
    <x-ui.badge :tone="\$val === 'active' || \$val === 'published' ? 'success' : (\$val === 'draft' ? 'warning' : 'secondary')">
        {{ \$item->{$name} }}
    </x-ui.badge>
</x-ui.table-cell>
BLADE;
        }

        if ($this->type === 'money') {
            $prefix = $this->prefix ?? '$';

            return "<x-ui.table-cell class=\"font-mono text-sm\">{{ \$item->{$name} !== null ? '{$prefix}' . number_format(\$item->{$name}, 2) : '—' }}</x-ui.table-cell>";
        }

        return "<x-ui.table-cell>{{ \$item->{$name} ?? '-' }}</x-ui.table-cell>";
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->getLabel(),
            'type' => $this->type,
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
        ];
    }
}
