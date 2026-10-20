@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('title', 'Blueprint Studio · Low-Code Slice Modeler')

@section('content')
{{-- Standalone wrapper (POST plan/apply re-renders). The canonical surface is the
     Dev Studio shell, Design mode — GET requests redirect there. The panel
     markup lives in partials/design-panel so both contexts share one source. --}}
<div class="mx-auto w-full max-w-[1600px] px-3 py-4 sm:px-6 lg:px-8">
    @include('laraslice::partials.design-panel')
</div>
@endsection
