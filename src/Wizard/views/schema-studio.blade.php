@extends('laraslice::layout')

@section('title', 'Schema Studio - 2-Column Visual Slice Builder')

@section('content')
{{-- Standalone wrapper. The canonical surface is the Dev Studio shell,
     Design mode with the Schema sub-view — GET requests redirect there.
     Panel markup lives in partials/schema-panel (one source). --}}
<div class="mx-auto w-full max-w-[1600px] px-3 py-4 sm:px-6">
    @include('laraslice::partials.schema-panel')
</div>
@endsection
