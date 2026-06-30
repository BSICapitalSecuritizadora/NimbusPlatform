<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatório Mensal Consolidado — {{ $emission['name'] }}</title>
    @include('pdf.partials.emission-monthly-report-styles')
</head>
<body>
<div class="header">
    <h1>Relatório Mensal Consolidado</h1>
    <h2>{{ $emission['name'] }}</h2>
    <p>Período: {{ $meta['period_label'] }} &middot; {{ $emission['identifier'] }} &middot; {{ $emission['offer'] }}</p>
</div>
<div class="gold-bar"></div>

@foreach ($months as $month)
    <div style="{{ $loop->first ? '' : 'page-break-before: always;' }}">
        <div class="month-divider">Competência: {{ $month['label'] }}</div>
        <div class="content">
            @include('pdf.partials.emission-monthly-report-body', $month['data'])
        </div>
    </div>
@endforeach

<div class="footer">
    BSI Capital Securitizadora — Documento consolidado gerado pela plataforma em {{ $meta['generated_at'] }}.
</div>
</body>
</html>
