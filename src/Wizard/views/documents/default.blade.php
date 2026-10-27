{{--
    Standard document template (G2): renders any slice's declared
    ui.show.document — header fields, line-items table, totals.
    A slice overrides it by adding Resources/views/document.blade.php.
--}}
@extends(($layout ?? 'print') === 'app' ? 'layouts.app' : 'laraslice::layouts.print', ['title' => $document['title']])

@section('content')
@if(($layout ?? 'print') === 'app')
{{-- App-shell mode (document-primary show): the print layout's
     self-contained styles, scoped to this page. --}}
<style>
    .sheet { max-width: 820px; margin: 8px auto 24px; background: #fff; padding: 40px 48px; box-shadow: 0 1px 3px rgba(0,0,0,.12); border-radius: 8px; color: #111827; }
    .doc-title { font-size: 26px; font-weight: 700; letter-spacing: -.01em; margin: 0; }
    .doc-meta { color: #6b7280; font-size: 12px; margin-top: 4px; }
    .doc-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 32px; margin: 28px 0 8px; }
    .doc-field dt { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; margin: 0 0 2px; }
    .doc-field dd { margin: 0; font-size: 13px; font-weight: 500; }
    table.doc-items { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 13px; }
    table.doc-items th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; border-bottom: 2px solid #111827; padding: 8px 6px; }
    table.doc-items td { border-bottom: 1px solid #e5e7eb; padding: 8px 6px; vertical-align: top; }
    .doc-totals { margin-top: 20px; margin-left: auto; width: 260px; font-size: 13px; }
    .doc-totals .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e5e7eb; }
    .doc-totals .row:last-child { border-bottom: 2px solid #111827; font-weight: 700; }
    .doc-actions { max-width: 820px; margin: 0 auto 12px; display: flex; justify-content: flex-end; gap: 8px; }
    .doc-actions a { font-size: 13px; font-weight: 600; padding: 8px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; color: #111827; text-decoration: none; }
    .doc-actions a:first-child { margin-right: auto; }
</style>
<div class="doc-actions">
    @isset($indexUrl)<a href="{{ $indexUrl }}">&larr; Back</a>@endisset
    @isset($editUrl)<a href="{{ $editUrl }}">Edit</a>@endisset
    @isset($printUrl)<a href="{{ $printUrl }}" target="_blank" rel="noopener">Print view</a>@endisset
</div>
@else
<div class="doc-actions">
    <button type="button" onclick="window.print()">Print</button>
</div>
@endif
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
