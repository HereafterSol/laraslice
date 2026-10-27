@extends('layouts.app')

@section('content')
<div class="space-y-4">
    <div>
        <h1 class="text-base font-medium text-foreground">Settings</h1>
        <p class="text-xs text-muted-foreground mt-0.5">Every feature's settings live in exactly one named section of this hub.</p>
    </div>

    @if(session('success'))
        <div class="p-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-[13px] font-medium">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[270px_1fr] items-start" x-data="{ q: '' }">
        {{-- Rail: search + one flat section menu (built-in pages and declared sections interleaved by declaration order) --}}
        <x-ui.card class="bg-card border shadow-xs p-3 space-y-2">
            <div class="relative">
                <x-lucide-search class="size-4 text-muted-foreground absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input type="text" x-model="q" placeholder="Search settings"
                    class="h-9 w-full rounded-lg border border-border bg-background pl-8.5 pr-3 text-[13px] text-foreground placeholder:text-muted-foreground" />
            </div>
            <nav class="space-y-0.5">
                @foreach($builtin as $link)
                    <a href="{{ $link['url'] }}" data-title="{{ strtolower($link['title']) }}" x-show="q === '' || $el.dataset.title.includes(q.toLowerCase())"
                        class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-[13px] font-medium text-muted-foreground hover:bg-muted/70 hover:text-foreground transition-colors">
                        <x-dynamic-component :component="'lucide-' . $link['icon']" class="size-4" />
                        {{ $link['title'] }}
                        <x-lucide-arrow-up-right class="size-3.5 ml-auto opacity-60" />
                    </a>
                @endforeach
                @foreach($sections as $section)
                    <a href="{{ route('settings.hub', ['section' => $section['id']]) }}" data-title="{{ strtolower($section['title']) }}" x-show="q === '' || $el.dataset.title.includes(q.toLowerCase())"
                        class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-[13px] font-medium transition-colors {{ ($active['id'] ?? null) === $section['id'] ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground' }}">
                        @if($section['icon'])
                            <x-dynamic-component :component="'lucide-' . $section['icon']" class="size-4" />
                        @else
                            <x-lucide-settings-2 class="size-4" />
                        @endif
                        {{ $section['title'] }}
                    </a>
                @endforeach
                @if($sections === [])
                    <p class="px-2.5 py-2 text-xs text-muted-foreground">No declared sections yet. Slices declare sections under top-level <code class="rounded bg-muted px-1">settings:</code> in their manifest.</p>
                @endif
            </nav>
        </x-ui.card>

        {{-- Active section: one form card with its own Save --}}
        <div>
            @if($active)
                <x-ui.card class="bg-card border shadow-xs">
                    <div class="border-b border-border/60 px-6 py-4">
                        <h2 class="text-sm font-semibold text-foreground">{{ $active['title'] }}</h2>
                        @if($active['description'])
                            <p class="text-xs text-muted-foreground mt-0.5">{{ $active['description'] }}</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('settings.hub.save', ['section' => $active['id']]) }}" class="px-6 py-5 space-y-5">
                        @csrf
                        @if (! empty($active['rows']))
                            {{-- Keyed-rows binding (L5): one row per record, toggle columns --}}
                            <div class="overflow-x-auto">
                                <table class="w-full text-[13px]">
                                    <thead>
                                        <tr class="border-b border-border/60 text-left">
                                            <th class="py-2 pr-4 font-medium text-foreground">Event</th>
                                            @foreach ($active['toggle_columns'] as $column)
                                                <th class="py-2 px-3 font-medium text-foreground">{{ \Illuminate\Support\Str::headline($column) }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($active['rows'] as $row)
                                            <tr class="border-b border-border/40">
                                                <td class="py-2 pr-4 text-foreground">{{ $row['label'] }}</td>
                                                @foreach ($active['toggle_columns'] as $column)
                                                    <td class="py-2 px-3">
                                                        <input type="checkbox" name="rows[{{ $row['key'] }}][{{ $column }}]" value="1" @checked($row['toggles'][$column] ?? false)
                                                            class="size-4.5 rounded border-border accent-primary" />
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @foreach($active['fields'] as $field)
                            <div>
                                @if($field['type'] === 'toggle')
                                    <label class="flex items-center justify-between gap-4 cursor-pointer">
                                        <span>
                                            <span class="block text-[13px] font-medium text-foreground">{{ $field['label'] }}</span>
                                            @if($field['help'])<span class="block text-xs text-muted-foreground mt-0.5">{{ $field['help'] }}</span>@endif
                                        </span>
                                        <input type="checkbox" name="{{ $field['handle'] }}" value="1" @checked((string) ($field['value'] ?? $field['default'] ?? '0') === '1')
                                            class="size-4.5 rounded border-border accent-primary" />
                                    </label>
                                @else
                                    <label class="block text-[13px] font-medium text-foreground mb-1.5">{{ $field['label'] }}</label>
                                    @if($field['type'] === 'select')
                                        <select name="{{ $field['handle'] }}" class="h-9.5 w-full max-w-md rounded-lg border border-border bg-background px-2.5 text-[13px] text-foreground">
                                            @foreach($field['options'] as $value => $label)
                                                <option value="{{ $value }}" @selected((string) ($field['value'] ?? '') === (string) $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @elseif($field['type'] === 'textarea')
                                        <textarea name="{{ $field['handle'] }}" rows="3" class="w-full max-w-xl rounded-lg border border-border bg-background px-2.5 py-2 text-[13px] text-foreground">{{ $field['value'] ?? '' }}</textarea>
                                    @elseif($field['type'] === 'secret')
                                        <input type="password" name="{{ $field['handle'] }}" value="" placeholder="{{ ($field['value'] ?? '') !== '' && $field['value'] !== null ? '•••••••• (leave blank to keep)' : '' }}"
                                            class="h-9.5 w-full max-w-md rounded-lg border border-border bg-background px-2.5 text-[13px] text-foreground" autocomplete="new-password" />
                                    @else
                                        <input type="{{ $field['type'] === 'number' ? 'number' : 'text' }}" name="{{ $field['handle'] }}" value="{{ $field['value'] ?? '' }}"
                                            class="h-9.5 w-full max-w-md rounded-lg border border-border bg-background px-2.5 text-[13px] text-foreground" />
                                    @endif
                                    @if($field['help'])<p class="text-xs text-muted-foreground mt-1">{{ $field['help'] }}</p>@endif
                                @endif
                            </div>
                        @endforeach
                        <div class="flex items-center justify-end border-t border-border/60 pt-4">
                            <x-ui.button type="submit" size="sm">Save {{ $active['title'] }} Settings</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
@endsection
