{{--
    Related-tab BOARD render mode (Body v4 detail pattern):
    the tab's records grouped into columns by the declared
    group_by field. The group label resolves by convention —
    the group_by foreign key's relation on the record
    (board_column_id -> boardColumn) and its title/name —
    and groups order by that relation's priority when the
    related model carries one. Cards show the record's
    title-ish field, its due/end date and its status.
--}}
@php
    $records = $rtab['records'] ?? collect();
    $groupBy = (string) ($rtab['group_by'] ?? 'status');
    $relName = \Illuminate\Support\Str::camel(\Illuminate\Support\Str::beforeLast($groupBy, '_id'));
    $groups = [];
    foreach ($records as $rec) {
        $key = (string) (data_get($rec, $groupBy) ?? '—');
        $label = $key;
        $order = 9999;
        try {
            if (is_object($rec) && method_exists($rec, $relName)) {
                $rel = $rec->{$relName};
                if (is_object($rel)) {
                    $label = (string) ($rel->title ?? $rel->column_name ?? $rel->name ?? $rel->label ?? $key);
                    $order = (int) ($rel->priority ?? $rel->position ?? 9999);
                }
            }
        } catch (\Throwable $e) {
            // Convention miss: fall back to the raw group value.
        }
        $groups[$key] ??= ['label' => $label, 'order' => $order, 'items' => []];
        $groups[$key]['items'][] = $rec;
    }
    uasort($groups, fn ($a, $b) => $a['order'] <=> $b['order']);
@endphp
@if(count($groups) === 0)
    <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
        No related records yet.
    </div>
@else
    <div class="flex gap-4 overflow-x-auto pb-2">
        @foreach($groups as $group)
            <div class="min-w-[240px] w-[240px] shrink-0 rounded-xl border border-border bg-muted/30">
                <div class="flex items-center justify-between px-3 py-2 border-b border-border/60">
                    <span class="text-xs font-bold text-foreground">{{ $group['label'] }}</span>
                    <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-bold leading-none">{{ count($group['items']) }}</span>
                </div>
                <div class="p-2 space-y-2">
                    @foreach($group['items'] as $rec)
                        <div class="rounded-lg border border-border bg-card p-3 shadow-xs">
                            <p class="text-[13px] font-medium text-foreground leading-snug">{{ $rec->heading ?? $rec->title ?? $rec->name ?? $rec->subject ?? ('#'.$rec->id) }}</p>
                            <div class="mt-1.5 flex items-center gap-2 text-[11px] text-muted-foreground">
                                @if(!empty($rec->due_date))
                                    <span>Due {{ \Illuminate\Support\Carbon::parse($rec->due_date)->format('M j') }}</span>
                                @endif
                                @if(!empty($rec->status))
                                    <span class="font-semibold">{{ \Illuminate\Support\Str::headline((string) $rec->status) }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
