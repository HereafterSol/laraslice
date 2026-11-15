<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; color: #111; margin: 2rem; }
    header { border-bottom: 2px solid #111; padding-bottom: .5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: baseline; }
    h1 { font-size: 1.25rem; margin: 0; }
    .print-btn { font: inherit; padding: .35rem .9rem; border: 1px solid #111; background: #fff; cursor: pointer; border-radius: .375rem; }
    h2 { font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: #555; margin: 1.5rem 0 .5rem; }
    dl { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem 1.5rem; margin: 0; }
    dt { font-size: .7rem; color: #666; }
    dd { margin: .15rem 0 0; font-size: .9rem; }
    table { border-collapse: collapse; width: 100%; margin-top: .5rem; }
    th, td { border: 1px solid #ccc; padding: .35rem .5rem; font-size: .85rem; text-align: left; }
    th { background: #f4f4f5; }
    .child { grid-column: 1 / -1; }
    @media print { .print-btn { display: none; } body { margin: 0; } }
</style>
</head>
<body>
<header>
    <h1>{{ $sliceLabel }} — {{ $identity }}</h1>
    <button class="print-btn" onclick="window.print()">Print</button>
</header>
@foreach($sections as $section => $rows)
    <h2>{{ $section }}</h2>
    <dl>
    @foreach($rows as $row)
        @if($row['type'] === 'repeater')
            <div class="child">
                <dt>{{ $row['label'] }}</dt>
                @if($row['rows'] !== null && $row['rows']['rows'] !== [])
                    <table>
                        <thead><tr>@foreach($row['rows']['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach($row['rows']['rows'] as $cells)
                            <tr>@foreach($cells as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <dd>—</dd>
                @endif
            </div>
        @else
            <div>
                <dt>{{ $row['label'] }}</dt>
                <dd>{{ $row['display'] }}</dd>
            </div>
        @endif
    @endforeach
    </dl>
@endforeach
</body>
</html>
