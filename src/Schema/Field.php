<?php

namespace LaraSlice\Schema;

use BadMethodCallException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Subclasses keep a compatible constructor; the static factories rely on new static().
 *
 * @phpstan-consistent-constructor
 */
class Field
{
    protected string $name;

    protected string $type = 'text';

    protected ?string $label = null;

    protected ?string $placeholder = null;

    protected bool $required = false;

    protected mixed $default = null;

    protected ?string $helperText = null;

    protected ?string $prefix = null;

    protected ?string $suffix = null;

    protected string $step = 'any';

    protected array $options = [];

    protected int $columnSpan = 1;

    protected bool $autofocus = false;

    protected int $rows = 3;

    public function __construct(string $name, string $type = 'text')
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException('Field names must be lowercase snake_case identifiers.');
        }

        if (! in_array($type, ['text', 'textarea', 'number', 'decimal', 'select', 'boolean', 'date', 'datetime-local', 'email'], true)) {
            throw new InvalidArgumentException("Unsupported field input type '{$type}'.");
        }

        $this->name = $name;
        $this->type = $type;
        $this->label = Str::title(str_replace('_', ' ', $name));
    }

    public static function make(string $name, string $type = 'text'): static
    {
        return new static($name, $type);
    }

    public static function text(string $name): static
    {
        return new static($name, 'text');
    }

    public static function textarea(string $name): static
    {
        return new static($name, 'textarea');
    }

    public static function number(string $name): static
    {
        return new static($name, 'number');
    }

    public static function decimal(string $name): static
    {
        return (new static($name, 'decimal'))->step('any');
    }

    public function step(float|int|string $step): static
    {
        $step = (string) $step;
        if ($step !== 'any' && ! preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)$/', $step)) {
            throw new InvalidArgumentException('Input step must be a positive number or "any".');
        }

        $this->step = $step;

        return $this;
    }

    public function autofocus(bool $condition = true): static
    {
        $this->autofocus = $condition;

        return $this;
    }

    public function rows(int $rows): static
    {
        $this->rows = max(1, min(20, $rows));

        return $this;
    }

    public function __call(string $method, array $arguments): static
    {
        if ($method === 'number') {
            $this->type = 'number';

            return $this;
        }
        if ($method === 'textarea') {
            $this->type = 'textarea';

            return $this;
        }
        if ($method === 'select') {
            $this->type = 'select';
            if (isset($arguments[0])) {
                $this->options($arguments[0]);
            }

            return $this;
        }
        if ($method === 'boolean' || $method === 'toggle') {
            $this->type = 'boolean';

            return $this;
        }

        throw new BadMethodCallException("Unknown field configuration method [{$method}].");
    }

    public static function select(string $name, array $options = []): static
    {
        $field = new static($name, 'select');
        $field->options($options);

        return $field;
    }

    public static function toggle(string $name): static
    {
        return new static($name, 'boolean');
    }

    public static function date(string $name): static
    {
        return new static($name, 'date');
    }

    public static function datetime(string $name): static
    {
        return new static($name, 'datetime-local');
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function required(bool $required = true): static
    {
        $this->required = $required;

        return $this;
    }

    public function default(mixed $default): static
    {
        if (! is_null($default) && ! is_scalar($default)) {
            throw new InvalidArgumentException('Field defaults must be scalar values or null.');
        }

        $this->default = $default;

        return $this;
    }

    public function helperText(string $text): static
    {
        $this->helperText = $text;

        return $this;
    }

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function options(array $options): static
    {
        foreach ($options as $value => $label) {
            if (! is_scalar($value) || ! is_string($label)) {
                throw new InvalidArgumentException('Select options must map scalar values to text labels.');
            }
        }

        $this->options = $options;

        return $this;
    }

    public function columnSpan(int $span): static
    {
        $this->columnSpan = max(1, min(12, $span));

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

    public function getLabel(): string
    {
        return $this->label ?? Str::title($this->name);
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Render pure BlatUI HTML component for this field
     */
    public function renderBlatUi(?object $form = null): string
    {
        $req = $this->required ? ' required' : '';
        $reqStar = $this->required ? ' *' : '';
        $label = htmlspecialchars($this->getLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = htmlspecialchars($this->name);
        $placeholder = htmlspecialchars($this->placeholder ?? "Enter {$this->getLabel()}...", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $defaultExpression = var_export($this->default, true);
        $autofocus = $this->autofocus ? ' autofocus' : '';

        if ($this->type === 'textarea') {
            return <<<HTML
            <div class="space-y-1.5">
                <x-ui.label for="{$name}">{$label}{$reqStar}</x-ui.label>
                <x-ui.textarea id="{$name}" name="{$name}" rows="{$this->rows}" placeholder="{$placeholder}"{$req}>{{ old('{$name}', \$form->{$name} ?? {$defaultExpression}) }}</x-ui.textarea>
                @error('{$name}')
                    <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                @enderror
            </div>
HTML;
        }

        if ($this->type === 'select') {
            $optionsHtml = '';
            foreach ($this->options as $optVal => $optLabel) {
                $optionValue = htmlspecialchars((string) $optVal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $optionLabel = htmlspecialchars($optLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $optionExpression = var_export((string) $optVal, true);
                $optionsHtml .= "<option value=\"{$optionValue}\" @selected(old('{$name}', \$form->{$name} ?? {$defaultExpression}) == {$optionExpression})>{$optionLabel}</option>\n";
            }

            return <<<HTML
            <div class="space-y-1.5">
                <x-ui.label for="{$name}">{$label}{$reqStar}</x-ui.label>
                <select id="{$name}" name="{$name}"{$req} class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                    {$optionsHtml}
                </select>
                @error('{$name}')
                    <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                @enderror
            </div>
HTML;
        }

        if ($this->type === 'boolean') {
            $helper = htmlspecialchars($this->helperText ?? "Enable or disable {$label}");

            return <<<HTML
            <div class="flex items-center justify-between p-3 rounded-xl border bg-card/60">
                <div>
                    <x-ui.label for="{$name}">{$label}</x-ui.label>
                    <p class="text-xs text-muted-foreground">{$helper}</p>
                </div>
                <input type="hidden" name="{$name}" value="0">
                <input type="checkbox" id="{$name}" name="{$name}" value="1" @checked(old('{$name}', \$form->{$name} ?? {$defaultExpression})) class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
            </div>
HTML;
        }

        $htmlType = match ($this->type) {
            'number' => 'number',
            'decimal' => 'number',
            'date' => 'date',
            'datetime-local' => 'datetime-local',
            'email' => 'email',
            default => 'text',
        };
        $stepAttribute = in_array($this->type, ['number', 'decimal'], true)
            ? ' step="'.htmlspecialchars($this->step, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"'
            : '';

        return <<<HTML
            <div class="space-y-1.5">
                <x-ui.label for="{$name}">{$label}{$reqStar}</x-ui.label>
                <x-ui.input id="{$name}" type="{$htmlType}" name="{$name}" value="{{ old('{$name}', \$form->{$name} ?? {$defaultExpression}) }}" placeholder="{$placeholder}"{$stepAttribute}{$autofocus}{$req} />
                @error('{$name}')
                    <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                @enderror
            </div>
HTML;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->getLabel(),
            'required' => $this->required,
            'default' => $this->default,
            'placeholder' => $this->placeholder,
            'options' => $this->options,
        ];
    }
}
