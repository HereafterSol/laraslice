<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; padding: 2rem 1rem; }
        .doc { max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 1.5rem; }
        dl { display: grid; grid-template-columns: 220px 1fr; gap: .45rem 1rem; margin: 0; }
        dt { color: #71717a; font-size: .85rem; }
        dd { margin: 0; font-size: .9rem; }
        h2 { font-size: 1rem; margin: 2rem 0 .75rem; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th, td { border-bottom: 1px solid #e4e4e7; padding: .45rem .5rem; text-align: left; }
        th { color: #71717a; font-weight: 600; }
        @media print { body { background: #fff; padding: 0; } .doc { border: none; } }
    </style>
</head>
<body>
    <div class="doc">
        <h1>{{ $title }}</h1>
        @if (! empty($rows))
            <dl>
                @foreach ($rows as $row)
                    <dt>{{ $row['label'] ?? '' }}</dt>
                    <dd>{{ is_scalar($row['value'] ?? null) ? $row['value'] : '—' }}</dd>
                @endforeach
            </dl>
        @endif
        @foreach ($children as $child)
            <h2>{{ $child['label'] }}</h2>
            <table>
                <thead>
                    <tr>
                        @foreach ($child['columns'] as $column)
                            <th>{{ \Illuminate\Support\Str::headline($column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($child['rows'] as $item)
                        <tr>
                            @foreach ($child['columns'] as $column)
                                <td>{{ $item->{$column} }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </div>
</body>
</html>
