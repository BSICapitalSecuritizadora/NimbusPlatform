<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Termo de Homologação de Curva PU - {{ $emission['name'] ?? '-' }}</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #333; line-height: 1.5; margin: 0; padding: 0; font-size: 12px; }
        .header { background-color: #091b23; color: #e6e4e4; padding: 20px 30px; }
        .header h1 { margin: 0; font-size: 20px; text-transform: uppercase; letter-spacing: 1px; }
        .header p { margin: 4px 0 0; font-size: 11px; color: #c9c6c6; }
        .gold-bar { height: 5px; background-color: #a06e28; width: 100%; }
        .content { padding: 24px 30px; }
        .section-title { color: #091b23; border-bottom: 2px solid #a06e28; padding-bottom: 4px; margin: 22px 0 10px; font-size: 14px; text-transform: uppercase; letter-spacing: .5px; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 5px 8px; vertical-align: top; border-bottom: 1px solid #eee; }
        table.kv td.label { width: 38%; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        table.kv td.value { font-weight: bold; color: #091b23; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: bold; }
        .badge.ok { background: #e7f6ec; color: #1c7c45; }
        .badge.warn { background: #fdf0e3; color: #a06e28; }
        .badge.bad { background: #fbe9e9; color: #b3261e; }
        .note { font-size: 10px; color: #888; margin-top: 6px; }
        .sign-block { margin-top: 36px; }
        .sign-row { width: 100%; margin-top: 40px; }
        .sign-cell { display: inline-block; width: 46%; border-top: 1px solid #333; padding-top: 4px; font-size: 11px; color: #555; text-align: center; }
        .sign-spacer { display: inline-block; width: 6%; }
        .footer { margin-top: 30px; font-size: 9px; color: #aaa; text-align: center; }
    </style>
</head>
<body>
@php
    $status = $version['status'] ?? null;
    $badgeClass = in_array($status, ['homologated', 'validated'], true) ? 'ok' : (in_array($status, ['divergent', 'error'], true) ? 'bad' : 'warn');
@endphp
<div class="header">
    <h1>Termo de Homologação — Curva de PU</h1>
    <p>{{ $emission['name'] ?? '-' }} &middot; {{ $emission['identifier'] ?? '-' }}</p>
</div>
<div class="gold-bar"></div>

<div class="content">
    <div class="section-title">Identificação</div>
    <table class="kv">
        <tr><td class="label">Emissão</td><td class="value">{{ $emission['name'] ?? '-' }}</td></tr>
        <tr><td class="label">Identificador</td><td class="value">{{ $emission['identifier'] ?? '-' }}</td></tr>
        <tr><td class="label">Tipo</td><td class="value">{{ $emission['type'] ?? '-' }}</td></tr>
        <tr><td class="label">Quantidade emitida</td><td class="value">{{ $emission['issued_quantity'] ?? '-' }}</td></tr>
        <tr><td class="label">Quantidade integralizada</td><td class="value">{{ $emission['integralized_quantity'] ?? '-' }}</td></tr>
    </table>

    <div class="section-title">Versão da Curva</div>
    <table class="kv">
        <tr><td class="label">Versão</td><td class="value">{{ $version['calculation_version'] ?? '-' }}</td></tr>
        <tr><td class="label">Status</td><td class="value"><span class="badge {{ $badgeClass }}">{{ $version['status_label'] ?? '-' }}</span></td></tr>
        <tr><td class="label">Versão da engine</td><td class="value">{{ $version['engine_version'] ?? '-' }}</td></tr>
        <tr><td class="label">Total de linhas geradas</td><td class="value">{{ $version['rows_count'] ?? '-' }}</td></tr>
        <tr><td class="label">Gerada em</td><td class="value">{{ $version['generated_at'] ?? '-' }} {{ $version['generated_by'] ? '— '.$version['generated_by'] : '' }}</td></tr>
        <tr><td class="label">Validada em</td><td class="value">{{ $version['validated_at'] ?? '-' }} {{ $version['validated_by'] ? '— '.$version['validated_by'] : '' }}</td></tr>
        <tr><td class="label">Homologada em</td><td class="value">{{ $version['homologated_at'] ?? '-' }} {{ $version['homologated_by'] ? '— '.$version['homologated_by'] : '' }}</td></tr>
        @if (!empty($version['obsolete_reason']))
            <tr><td class="label">Motivo de obsolescência</td><td class="value">{{ $version['obsolete_reason'] }}</td></tr>
        @endif
        @if (!empty($version['error_message']))
            <tr><td class="label">Erro</td><td class="value">{{ $version['error_message'] }}</td></tr>
        @endif
    </table>

    <div class="section-title">Parâmetros do Cálculo</div>
    <table class="kv">
        <tr><td class="label">Indexador</td><td class="value">{{ $parameters['indexer_label'] ?? ($parameters['indexer'] ?? '-') }}
            @if (($parameters['is_homologated_indexer'] ?? false) === false)
                <span class="badge warn">experimental</span>
            @endif
        </td></tr>
        <tr><td class="label">Método de cálculo</td><td class="value">{{ $parameters['calculation_method'] ?? '-' }} ({{ $parameters['method_version'] ?? '-' }})</td></tr>
        @if (!empty($parameters['annual_rate']))
            <tr><td class="label">Taxa prefixada (% a.a.)</td><td class="value">{{ $parameters['annual_rate'] }}</td></tr>
        @endif
        <tr><td class="label">Spread (% a.a.)</td><td class="value">{{ $parameters['spread_rate'] ?? '-' }}</td></tr>
        <tr><td class="label">PU inicial</td><td class="value">{{ $parameters['initial_unit_value'] ?? '-' }}</td></tr>
        <tr><td class="label">Início da curva</td><td class="value">{{ $parameters['curve_start_date'] ?? '-' }}</td></tr>
        <tr><td class="label">Fim da curva</td><td class="value">{{ $parameters['curve_end_date'] ?? '-' }}</td></tr>
        <tr><td class="label">Base de dias úteis</td><td class="value">{{ $parameters['business_day_basis'] ?? '-' }}</td></tr>
        <tr><td class="label">Calendário</td><td class="value">{{ $parameters['calendar_code'] ?? '-' }}</td></tr>
    </table>

    <div class="section-title">Resumo da Validação</div>
    @if ($validation['has_validation'])
        <table class="kv">
            <tr><td class="label">Resultado</td><td class="value">{{ $validation['status'] ?? '-' }}</td></tr>
            <tr><td class="label">Escala</td><td class="value">{{ $validation['mode'] ?? '-' }}</td></tr>
            <tr><td class="label">Linhas comparadas</td><td class="value">{{ $validation['total_rows_compared'] ?? '-' }}</td></tr>
            <tr><td class="label">Linhas divergentes</td><td class="value">{{ $validation['total_divergences'] ?? '-' }}</td></tr>
            <tr><td class="label">Campos divergentes</td><td class="value">{{ $validation['total_field_divergences'] ?? '-' }}</td></tr>
            <tr><td class="label">Primeira divergência</td><td class="value">{{ $validation['first_divergence_date'] ?? '-' }}</td></tr>
            <tr><td class="label">Maior diferença de PU</td><td class="value">{{ $validation['largest_pu_difference'] ?? '-' }}</td></tr>
            <tr><td class="label">Maior diferença de valor total</td><td class="value">{{ $validation['largest_total_value_difference'] ?? '-' }}</td></tr>
            <tr><td class="label">Maior diferença de pagamento</td><td class="value">{{ $validation['largest_payment_difference'] ?? '-' }}</td></tr>
        </table>
        <p class="note">Escala "display-scale" compara valores arredondados para exibição; "raw-scale" compara valores brutos de alta precisão.</p>
    @else
        <p class="note">Esta versão ainda não foi validada contra planilha de referência.</p>
    @endif

    <div class="section-title">Observações</div>
    <p class="note">Documento gerado automaticamente pela plataforma para fins internos de homologação da curva
        de PU. Os valores refletem os dados persistidos no momento da geração/validação da versão indicada.</p>

    <div class="sign-block">
        <div class="section-title">Aprovação Interna</div>
        <div class="sign-row">
            <span class="sign-cell">Responsável pela geração<br>{{ $version['generated_by'] ?? '________________________' }}</span>
            <span class="sign-spacer"></span>
            <span class="sign-cell">Responsável pela homologação<br>{{ $version['homologated_by'] ?? '________________________' }}</span>
        </div>
    </div>

    <div class="footer">Emitido em {{ $generated_at }} — NimbusPlatform</div>
</div>
</body>
</html>
