@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('title', 'Porting Studio')

@section('content')
@include('laraslice::partials.port-panel')
@endsection
