<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;

/**
 * Patches the Blade views of an existing slice when fields are added: form inputs and listing columns.
 */
final class BladeFieldInjector
{
    /**
     * Add inputs for $fields to the slice form views, skipping fields already present.
     */
    public function injectFormFields(string $sliceDir, string $studlyName, array $fields): void
    {
        $formBladeFiles = [
            $sliceDir.'/resources/views/form.blade.php',
            $sliceDir.'/resources/views/create.blade.php',
            $sliceDir.'/resources/views/edit.blade.php',
            $sliceDir.'/Resources/views/form.blade.php',
        ];

        foreach ($formBladeFiles as $bladeFile) {
            if (! file_exists($bladeFile)) {
                continue;
            }

            $content = file_get_contents($bladeFile);

            foreach ($fields as $field) {
                if (! empty($field['hidden'])) {
                    continue;
                }
                $snakeField = Str::snake($field['name']);
                if (str_contains($content, "name=\"{$snakeField}\"")) {
                    continue;
                }

                $label = Str::title(str_replace('_', ' ', $snakeField));
                $type = strtolower($field['type'] ?? 'string');

                if ($type === 'text' || $type === 'mediumtext' || $type === 'longtext') {
                    $snippet = <<<HTML

            <div class="space-y-1.5">
                <x-ui.label for="{$snakeField}">{$label}</x-ui.label>
                <x-ui.textarea id="{$snakeField}" name="{$snakeField}" rows="3" placeholder="Enter {$label}...">{{ old('{$snakeField}', \$form->{$snakeField} ?? '') }}</x-ui.textarea>
            </div>
HTML;
                } elseif ($type === 'boolean' || $type === 'bool') {
                    $snippet = <<<HTML

            <div class="flex items-center justify-between p-3 rounded-lg border bg-card">
                <div>
                    <x-ui.label for="{$snakeField}">{$label}</x-ui.label>
                    <p class="text-xs text-muted-foreground">Toggle status for {$label}</p>
                </div>
                <input type="hidden" name="{$snakeField}" value="0">
                <input type="checkbox" id="{$snakeField}" name="{$snakeField}" value="1" {{ old('{$snakeField}', \$form->{$snakeField} ?? false) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
            </div>
HTML;
                } elseif (str_ends_with($snakeField, '_id')) {
                    $relatedTable = Str::plural(str_replace('_id', '', $snakeField));
                    $snippet = <<<HTML

            <div class="space-y-1.5">
                @php
                    \$relatedTableStr = '{$relatedTable}';
                    \$rawOpts = \Illuminate\Support\Facades\Schema::hasTable(\$relatedTableStr) ? \Illuminate\Support\Facades\DB::table(\$relatedTableStr)->get() : collect();
                    \$optsMap = [];
                    foreach (\$rawOpts as \$o) {
                        \$optsMap[\$o->id] = \$o->name ?? \$o->title ?? \$o->label ?? ('#' . \$o->id);
                    }
                    \$quickUrl = null;
                    \$baseRel = str_replace('_id', '', '{$snakeField}');
                    foreach ([\$baseRel . '.store', \Illuminate\Support\Str::plural(\$baseRel) . '.store'] as \$rName) {
                        if (\Illuminate\Support\Facades\Route::has(\$rName)) {
                            \$quickUrl = route(\$rName);
                            break;
                        }
                    }
                @endphp
                <x-ui.combobox-relationship
                    name="{$snakeField}"
                    label="{$label}"
                    :options="\$optsMap"
                    :selected="old('{$snakeField}', \$form->{$snakeField} ?? '')"
                    placeholder="Select {$label}..."
                    :quickAddUrl="\$quickUrl"
                    quickAddTitle="{$label}"
                />
            </div>
HTML;
                } else {
                    $htmlType = match ($type) {
                        'integer', 'int', 'biginteger', 'smallinteger', 'tinyinteger' => 'number',
                        'decimal', 'float', 'double' => 'number" step="any',
                        'date' => 'date',
                        'datetime', 'timestamp' => 'datetime-local',
                        'time' => 'time',
                        default => 'text',
                    };

                    $snippet = <<<HTML

            <div class="space-y-1.5">
                <x-ui.label for="{$snakeField}">{$label}</x-ui.label>
                <x-ui.input id="{$snakeField}" type="{$htmlType}" name="{$snakeField}" value="{{ old('{$snakeField}', \$form->{$snakeField} ?? '') }}" placeholder="Enter {$label}..." />
            </div>
HTML;
                }

                $target = '<div class="flex items-center justify-end';
                if (str_contains($content, $target)) {
                    $content = str_replace($target, $snippet."\n\n            ".$target, $content);
                }
            }

            file_put_contents($bladeFile, $content);
        }
    }

    /**
     * Add listing table columns for $fields to the slice index views, skipping existing ones.
     */
    public function injectTableColumns(string $sliceDir, string $studlyName, array $fields): void
    {
        $indexBladeFiles = [
            $sliceDir.'/resources/views/index.blade.php',
            $sliceDir.'/Resources/views/index.blade.php',
        ];

        foreach ($indexBladeFiles as $bladeFile) {
            if (! file_exists($bladeFile)) {
                continue;
            }

            $content = file_get_contents($bladeFile);

            // Data-table index views: add a column definition before the marker; rows are built from the columns
            if (preg_match('/^([ \t]*)\/\/ @laraslice:columns/m', $content, $marker)) {
                foreach ($fields as $field) {
                    $snakeField = Str::snake($field['name']);
                    if (! empty($field['hidden']) || str_contains($content, "'key' => '{$snakeField}'")) {
                        continue;
                    }
                    $label = Str::title(str_replace('_', ' ', $snakeField));
                    $columnLine = $marker[1]."['key' => ".var_export($snakeField, true).", 'label' => ".var_export($label, true)."],\n";
                    $content = preg_replace('/^[ \t]*\/\/ @laraslice:columns/m', $columnLine.'$0', $content, 1);
                }
                file_put_contents($bladeFile, $content);

                continue;
            }

            // Detect loop item variable name, e.g. @forelse ($users as $user) or ($pagedList->items as $item)
            $varName = 'item';
            if (preg_match('/@forelse\s*\([^\)]+as\s+\$([a-zA-Z0-9_]+)\)/i', $content, $matches)) {
                $varName = $matches[1];
            } elseif (preg_match('/@foreach\s*\([^\)]+as\s+\$([a-zA-Z0-9_]+)\)/i', $content, $matches)) {
                $varName = $matches[1];
            }

            foreach ($fields as $field) {
                if (! empty($field['hidden'])) {
                    continue;
                }
                $snakeField = Str::snake($field['name']);
                if (str_contains($content, "\${$varName}->{$snakeField}") || str_contains($content, "\$item->{$snakeField}")) {
                    continue;
                }

                $label = Str::title(str_replace('_', ' ', $snakeField));

                // Check if head already exists
                $headAlreadyExists = str_contains($content, "<x-ui.table-head>{$label}</x-ui.table-head>")
                    || str_contains($content, '<x-ui.table-head>'.strtoupper($snakeField).'</x-ui.table-head>')
                    || str_contains($content, "<th class=\"px-6 py-4\">{$label}</th>");

                if (! $headAlreadyExists) {
                    if (str_contains($content, '<x-ui.table-head class="text-right">Actions</x-ui.table-head>')) {
                        $content = str_replace(
                            '<x-ui.table-head class="text-right">Actions</x-ui.table-head>',
                            "<x-ui.table-head>{$label}</x-ui.table-head>\n                        <x-ui.table-head class=\"text-right\">Actions</x-ui.table-head>",
                            $content
                        );
                    } elseif (str_contains($content, '<th class="px-6 py-4 text-right">Actions</th>')) {
                        $content = str_replace(
                            '<th class="px-6 py-4 text-right">Actions</th>',
                            "<th class=\"px-6 py-4\">{$label}</th>\n<th class=\"px-6 py-4 text-right\">Actions</th>",
                            $content
                        );
                    }
                }

                // Inject cell before Actions cell
                if (preg_match('/([ \t]*)(<x-ui\.table-cell\s+class="text-right[^"]*">)/i', $content, $m)) {
                    $indent = $m[1];
                    $actionCell = $m[2];
                    $cellSnippet = "{$indent}<x-ui.table-cell class=\"text-xs text-muted-foreground font-mono\">{{ \${$varName}->{$snakeField} ?? '—' }}</x-ui.table-cell>\n{$indent}{$actionCell}";
                    $content = preg_replace('/[ \t]*<x-ui\.table-cell\s+class="text-right[^"]*">/i', $cellSnippet, $content, 1);
                } elseif (preg_match('/([ \t]*)(<td\s+class="[^"]*text-right[^"]*">)/i', $content, $m)) {
                    $indent = $m[1];
                    $actionCell = $m[2];
                    $cellSnippet = "{$indent}<td class=\"px-6 py-4 text-gray-700\">{{ \${$varName}->{$snakeField} ?? '—' }}</td>\n{$indent}{$actionCell}";
                    $content = preg_replace('/[ \t]*<td\s+class="[^"]*text-right[^"]*">/i', $cellSnippet, $content, 1);
                }
            }

            file_put_contents($bladeFile, $content);
        }
    }
}
