<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatório Mensal — {{ $header['name'] }}</title>
    @include('pdf.partials.emission-monthly-report-styles')
</head>
<body>
<div class="header">
    <h1>Relatório Mensal</h1>
    <h2>{{ $header['name'] }}</h2>
    <p>Referência: {{ $meta['reference_label'] }} &middot; {{ $header['identifier'] }} &middot; {{ $header['offer'] }}</p>
</div>
<div class="gold-bar"></div>

<div class="content">
    @include('pdf.partials.emission-monthly-report-body')
</div>

<div class="footer">
    BSI Capital Securitizadora — Documento gerado automaticamente pela plataforma em {{ $meta['generated_at'] }}.
</div>
</body>
</html>
