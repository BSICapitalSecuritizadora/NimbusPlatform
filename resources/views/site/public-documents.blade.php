<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Documentos Públicos') }} - {{ config('app.name', 'BSI Capital') }}</title>
        <meta name="description" content="Documentos públicos disponíveis para consulta.">
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif;
                background: #ece9e8;
                color: #091b23;
                min-height: 100vh;
                padding: 2rem;
            }
            .container { max-width: 56rem; margin: 0 auto; }
            h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 1.5rem; color: #091b23; }
            .doc-list { list-style: none; display: flex; flex-direction: column; gap: 0.75rem; }
            .doc-item {
                padding: 1rem 1.25rem;
                background: #ffffff;
                border: 1px solid rgba(9, 27, 35, 0.08);
                border-radius: 0.5rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 1px 2px rgba(9, 27, 35, 0.04);
            }
            .doc-title { font-weight: 600; color: #091b23; }
            .doc-category { font-size: 0.75rem; color: #4b6871; margin-top: 0.25rem; }
            .doc-date { font-size: 0.75rem; color: #758d95; white-space: nowrap; }
            .empty { color: #758d95; font-size: 0.875rem; }
            .pagination { margin-top: 1.5rem; display: flex; justify-content: center; gap: 0.5rem; }
            .pagination a, .pagination span {
                padding: 0.375rem 0.75rem;
                background: #ffffff;
                border: 1px solid rgba(9, 27, 35, 0.08);
                border-radius: 0.25rem;
                font-size: 0.875rem;
                text-decoration: none;
                color: #4b6871;
            }
            .pagination a:hover { border-color: #8d6123; color: #8d6123; }
            .pagination span.current { background: #8d6123; border-color: #8d6123; color: #ffffff; }
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
                                    <div class="doc-category">{{ $document->category_label }}</div>
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
