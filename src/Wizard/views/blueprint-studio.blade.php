@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('title', 'Blueprint Studio · Low-Code Slice Modeler')

@section('content')
{{-- The Design component factory (window.blueprintStudio) is a shared
     package asset. The Studio shell loads it lazily and awaits it before
     fragment init — but this standalone re-render (POST plan/apply/show)
     has no shell, so nothing loaded the factory and Alpine evaluated
     x-data="blueprintStudio(...)" against a missing global: the panel
     arrived dead with a cascade of "blueprintStudio is not defined"
     errors (Day-2 drill F7). Load it synchronously here, before the
     panel markup: a classic script runs during parsing, ahead of the
     layout's deferred Alpine init, and the factory registers itself on
     alpine:init. --}}
<script src="{{ route('laraslice.studio.blueprint_design_factory') }}"></script>
{{-- Standalone wrapper (POST plan/apply re-renders). The canonical surface is the
     Dev Studio shell, Design mode — GET requests redirect there. The panel
     markup lives in partials/design-panel so both contexts share one source. --}}
<div class="mx-auto w-full max-w-[1600px] px-3 py-4 sm:px-6 lg:px-8">
    @include('laraslice::partials.design-panel')
</div>
@endsection
