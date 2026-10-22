@extends('layouts.app')

@section('title', 'Invite')

@section('content')
<div class="mx-auto w-full max-w-3xl p-6">
    <div class="mb-6 flex items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-foreground">Invite people</h1>
            <p class="text-sm text-muted-foreground">These records have no user account yet. Inviting creates the account{{ $decl['role'] ? ' with the '.$decl['role'].' role' : '' }}, links it, and emails an activation link so the person sets their own password.</p>
        </div>
        <a href="{{ $indexUrl }}" class="text-sm font-medium text-primary hover:underline">Back to list</a>
    </div>

    @if($report)
        <div class="mb-6 rounded-xl border border-border bg-card p-4 text-sm {{ !empty($report['error']) ? 'text-destructive' : 'text-foreground' }}">
            {{ $report['error'] ?? $report['success'] ?? '' }}
        </div>
    @endif

    <div class="rounded-xl border border-border bg-card">
        @forelse($records as $record)
            <form method="POST" action="{{ $storeUrl }}" class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3 last:border-0">
                @csrf
                <input type="hidden" name="record_id" value="{{ $record['id'] }}" />
                <span class="min-w-40 flex-1 text-sm font-medium text-foreground">{{ $record['name'] }}</span>
                <input type="email" name="email" required placeholder="person@example.com" class="h-9 w-64 rounded-lg border border-border bg-background px-2 text-sm text-foreground" />
                <button type="submit" class="rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground">Invite</button>
            </form>
        @empty
            <p class="px-5 py-8 text-center text-sm text-muted-foreground">Everyone already has an account. Nothing to invite.</p>
        @endforelse
    </div>
</div>
@endsection
