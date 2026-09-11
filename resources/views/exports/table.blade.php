{{--
    The print view behind a PDF export.

    Hand-written CSS rather than Tailwind: this page is rendered by whichever PDF
    driver the install is configured for, and the pure-PHP ones never load the compiled
    stylesheet. Everything here has to be in the document itself.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $heading }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 10px; color: #0f172a; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .meta { color: #64748b; font-size: 10px; margin: 0 0 14px; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            border-bottom: 1px solid #cbd5e1;
            padding: 6px 8px;
        }
        tbody td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        .empty { padding: 24px 8px; text-align: center; color: #64748b; }
    </style>
</head>
<body>
    <h1>{{ $heading }}</h1>

    <p class="meta">
        @if ($subheading)
            {{ $subheading }} &middot;
        @endif
        {{ kSiteConfig('name') }} &middot; {{ now()->format('jS M, Y') }}
    </p>

    <table>
        <thead>
            <tr>
                @foreach ($headers as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($headers as $key => $label)
                        <td>{{ $row[$key] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ count($headers) }}">Nothing matched the filters this was taken with.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
