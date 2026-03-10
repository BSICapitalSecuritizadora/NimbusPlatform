<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Documentos Públicos') }} - {{ config('app.name', 'BSI Capital') }}</title>
        <meta name="description" content="Documentos públicos disponíveis para consulta.">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
                background: #FDFDFC;
                color: #1b1b18;
                min-height: 100vh;
                padding: 2rem;
            }
            .container { max-width: 56rem; margin: 0 auto; }
            h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; }
            .doc-list { list-style: none; display: flex; flex-direction: column; gap: 0.75rem; }
            .doc-item {
                padding: 1rem 1.25rem;
                border: 1px solid #e3e3e0;
                border-radius: 0.5rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .doc-title { font-weight: 500; }
            .doc-category { font-size: 0.75rem; color: #706f6c; margin-top: 0.25rem; }
            .doc-date { font-size: 0.75rem; color: #706f6c; white-space: nowrap; }
            .empty { color: #706f6c; font-size: 0.875rem; }
            .pagination { margin-top: 1.5rem; display: flex; justify-content: center; gap: 0.5rem; }
            .pagination a, .pagination span {
                padding: 0.375rem 0.75rem;
                border: 1px solid #e3e3e0;
                border-radius: 0.25rem;
                font-size: 0.875rem;
                text-decoration: none;
                color: #1b1b18;
            }
            .pagination span.current { background: #1b1b18; color: #fff; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Documentos Públicos</h1>

            @if($documents->isEmpty())
                <p class="empty">Nenhum documento público disponível no momento.</p>
            @else
                <ul class="doc-list">
                    @foreach($documents as $document)
                        <li class="doc-item">
                            <div>
                                <div class="doc-title">{{ $document->title }}</div>
                                @if($document->category)
                                    <div class="doc-category">{{ $document->category }}</div>
                                @endif
                            </div>
                            <div class="doc-date">
                                {{ $document->published_at?->format('d/m/Y') ?? $document->created_at->format('d/m/Y') }}
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if($documents->hasPages())
                    <div class="pagination">
                        {{ $documents->links('pagination::simple-default') }}
                    </div>
                @endif
            @endif
        </div>
    </body>
</html>
