{{--
    Print layout (G2): a bare, paper-first shell for document views —
    no app chrome, no assets, print CSS only. Documents must print
    identically from any install, so styling is self-contained here.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'Document' }}</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #111827; background: #f3f4f6; }
    .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 48px 56px; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
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
    .doc-actions { max-width: 820px; margin: 16px auto 0; text-align: right; }
    .doc-actions button { font: inherit; font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 0; background: #111827; color: #fff; cursor: pointer; }
    @media print {
        body { background: #fff; }
        .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; }
        .doc-actions { display: none; }
        @page { margin: 18mm; }
    }
</style>
</head>
<body>
@yield('content')
</body>
</html>
