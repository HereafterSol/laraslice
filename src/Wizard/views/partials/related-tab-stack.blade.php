@if(!empty($rtab['lazy']))
    @include('laraslice::partials.related-tab-lazy', ['rtab' => $rtab])
@else
{{--
    Stacked-relations tab panel (render: stack): several
    relation blocks composed in ONE tab, in declared order —
    the shape of the source's composite hub tabs (its employee
    Immigration tab is a passport card above a visa table).
    Each block was enriched by DirectoryUi like a tabular tab
    of its own; a block declared `layout: card` renders its
    single record as label/value rows, otherwise the block
    renders the shared tabular partial.
--}}
@foreach($rtab['blocks'] ?? [] as $block)
    <div class="space-y-2">
        @if(!empty($block['label']))
        <h4 class="text-sm font-bold text-foreground">{{ $block['label'] }}</h4>
        @endif
        @if(($block['layout'] ?? 'table') === 'card')
            <div class="rounded-xl border border-border overflow-hidden bg-card">
                @if(!empty($block['rows']))
                    @php $cardRow = $block['rows'][0]; @endphp
                    @foreach($block['columns'] as $col)
                    <div class="flex items-start gap-4 px-4 py-2.5 {{ !$loop->last ? 'border-b border-border/50' : '' }}">
                        <span class="w-48 shrink-0 text-xs font-semibold text-muted-foreground">{{ $col['label'] }}</span>
                        <span class="text-xs text-foreground">{!! $cardRow[$col['key']] ?? '—' !!}</span>
                    </div>
                    @endforeach
                @else
                    <div class="px-4 py-4 flex items-center justify-between">
                        <p class="text-xs text-muted-foreground">No related records yet.</p>
                        @if(!empty($block['create_url']))
                        <a href="{{ $block['create_url'] }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-2xs transition-colors">
                            <x-lucide-plus class="size-3.5" />
                            <span>{{ $block['create_label'] ?? 'Add' }}</span>
                        </a>
                        @endif
                    </div>
                @endif
            </div>
        @else
            @include('laraslice::partials.related-tab-table', ['rtab' => $block])
        @endif
    </div>
@endforeach

@endif
