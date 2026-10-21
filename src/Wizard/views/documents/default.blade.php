{{--
    Standard document template (G2): renders any slice's declared
    ui.show.document — header fields, line-items table, totals.
    A slice overrides it by adding Resources/views/document.blade.php.
--}}
@extends('laraslice::layouts.print', ['title' => $document['title']])

@section('content')
<div class="doc-actions">
    <button type="button" onclick="window.print()">Print</button>
</div>
<div class="sheet">
    <h1 class="doc-title">{{ $document['title'] }}</h1>
    <div class="doc-meta">#{{ $record->getKey() }}</div>

    @if($document['header'] !== [])
    <dl class="doc-fields">
        @foreach($document['header'] as $field)
        <div class="doc-field">
            <dt>{{ $field['label'] }}</dt>
            <dd>{{ $field['value'] }}</dd>
        </div>
        @endforeach
    </dl>
    @endif

    @if(!empty($document['items']))
    <table class="doc-items">
        <thead>
            <tr>
                @foreach($document['items']['columns'] as $col)
                <th>{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($document['items']['rows'] as $row)
            <tr>
                @foreach($row as $cell)
                <td>{{ $cell }}</td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($document['totals'] !== [])
    <div class="doc-totals">
        @foreach($document['totals'] as $total)
        <div class="row"><span>{{ $total['label'] }}</span><span>{{ $total['value'] }}</span></div>
        @endforeach
    </div>
    @endif
</div>
@endsection
