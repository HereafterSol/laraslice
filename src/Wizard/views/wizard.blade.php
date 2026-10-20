@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('content')
{{-- Standalone wrapper (form posts / validation returns). The canonical surface
     is the Dev Studio shell (Create / Manage modes) — GET requests redirect
     there. Panel markup lives in partials/wizard-panel (one source). --}}
<div class="mx-auto w-full max-w-[1600px] px-3 py-4 sm:px-6">
    @include('laraslice::partials.wizard-panel')
</div>
@endsection
