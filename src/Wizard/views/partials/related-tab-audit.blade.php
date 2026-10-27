{{--
    Audit tab panel — a declared `type: audit` hub tab renders the
    record's audit trail, prepared by DirectoryUi from the
    framework's audit infrastructure (AuditLogger::forEntity, keyed
    by model class + record id). An empty trail renders a proper
    empty state; the tab itself never silently disappears.
--}}
<div>
    <div class="flex items-center justify-between gap-2 mb-3">
        <h3 class="text-sm font-bold text-foreground">{{ $rtab['label'] ?? 'Activity' }}</h3>
    </div>
    @if(empty($rtab['entries']))
        <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
            No activity recorded yet.
        </div>
    @else
        <div class="rounded-xl border border-border overflow-hidden bg-card">
            <table class="w-full text-xs">
                <thead class="bg-muted/40 border-b border-border">
                    <tr>
                        <th class="px-3 py-2 text-start font-semibold">Action</th>
                        <th class="px-3 py-2 text-start font-semibold">Actor</th>
                        <th class="px-3 py-2 text-start font-semibold">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/50">
                    @foreach($rtab['entries'] as $entry)
                    <tr class="hover:bg-muted/30">
                        <td class="px-3 py-2 font-medium">{{ $entry['action'] ?? '' }}</td>
                        <td class="px-3 py-2">{{ $entry['actor'] ?? 'System' }}</td>
                        <td class="px-3 py-2">{{ $entry['created_at'] ?? '' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
