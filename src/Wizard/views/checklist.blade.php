@extends('layouts.app')

@section('content')
    @php $progress = \LaraSlice\Support\SetupProgress::forUser(auth()->user()); @endphp
    <div class="w-full max-w-3xl mx-auto space-y-6">
        <div>
            <h1 class="text-xl font-bold text-foreground">Welcome{{ auth()->user()?->name ? ', '.auth()->user()->name : '' }}</h1>
            <p class="text-sm text-muted-foreground mt-1">We have prepared a list of things that will help you get there quickly.</p>
        </div>

        @if ($progress === null)
            <p class="text-sm text-muted-foreground">The setup checklist is available to administrators.</p>
        @else
            <div class="rounded-2xl border border-border bg-card p-5 space-y-4">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-semibold text-foreground">Setup Progress</span>
                    <span class="text-muted-foreground">{{ $progress['completed'] }}/{{ $progress['total'] }}</span>
                </div>
                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                    <div class="h-full bg-primary rounded-full" style="width: {{ $progress['percent'] }}%"></div>
                </div>
                <ul class="divide-y divide-border/60">
                    @foreach ($progress['steps'] as $step)
                        <li class="flex items-start gap-3 py-3">
                            @if ($step['done'])
                                <x-lucide-check-circle-2 class="size-5 text-emerald-500 shrink-0 mt-0.5" />
                            @else
                                <x-lucide-circle class="size-5 text-muted-foreground shrink-0 mt-0.5" />
                            @endif
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium {{ $step['done'] ? 'text-muted-foreground line-through' : 'text-foreground' }}">{{ $step['label'] }}</div>
                                <div class="text-xs text-muted-foreground">{{ $step['info'] }}</div>
                            </div>
                            @if (! $step['done'] && $step['section'] !== null && $progress['hub_url'])
                                <a href="{{ $progress['hub_url'] }}" class="text-xs font-medium text-primary hover:underline shrink-0 mt-1">Open Settings</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endsection
