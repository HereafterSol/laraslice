@extends('layouts.app')

@section('title', 'Import')

@section('content')
<div class="mx-auto w-full max-w-3xl p-6">
    <div class="mb-6 flex items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-foreground">Import records</h1>
            <p class="text-sm text-muted-foreground">Upload a CSV file with the columns below. @if($decl['key']) Rows matching an existing <span class="font-medium text-foreground">{{ $decl['key'] }}</span> are updated in place; new values are created. @else Every row creates a new record. @endif</p>
        </div>
        <a href="{{ $indexUrl }}" class="text-sm font-medium text-primary hover:underline">Back to list</a>
    </div>

    @if($report)
        <div class="mb-6 rounded-xl border border-border bg-card p-4 text-sm">
            <p class="font-medium text-foreground">Import finished: {{ $report['created'] }} created · {{ $report['updated'] }} updated · {{ count($report['errors']) }} failed.</p>
            @if(!empty($report['errors']))
                <ul class="mt-2 list-inside list-disc text-destructive">
                    @foreach(array_slice($report['errors'], 0, 20) as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="rounded-xl border border-border bg-card p-5">
        <h2 class="text-sm font-semibold text-foreground">Expected columns</h2>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach(array_keys($decl['columns']) as $header)
                <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">{{ $header }}</span>
            @endforeach
        </div>
        <a href="{{ $templateUrl }}" class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline">Download the template CSV</a>

        <form method="POST" action="{{ $storeUrl }}" enctype="multipart/form-data" class="mt-6 flex flex-col gap-4">
            @csrf
            <input type="file" name="file" accept=".csv,text/csv" required class="block w-full text-sm text-foreground file:mr-3 file:rounded-lg file:border file:border-border file:bg-background file:px-3 file:py-2 file:text-sm file:font-medium" />
            @error('file')<p class="text-sm text-destructive">{{ $message }}</p>@enderror
            <div>
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Upload and import</button>
            </div>
        </form>
    </div>
</div>
@endsection
