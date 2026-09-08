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
        table.diff { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 9px; }
        table.diff th, table.diff td { padding: 4px; border: 1px solid #ddd; text-align: right; }
        table.diff th:first-child, table.diff td:first-child { text-align: left; }
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
        <tr><td class="label">Papel da curva</td><td class="value">{{ $version['curve_role'] ?? '-' }}</td></tr>
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
    @if (($version['curve_role'] ?? null) === 'candidate')
        <p class="note">Esta versão permanece candidate. Aprovação interna ou validação externa não a torna operacional.</p>
    @endif

    <div class="section-title">Promoção Operacional</div>
    @if ($promotion['has_promotion'] ?? false)
        <table class="kv">
            <tr><td class="label">Situação</td><td class="value">{{ $promotion['status_label'] ?? ($promotion['status'] ?? '-') }}</td></tr>
            <tr><td class="label">Solicitada por</td><td class="value">{{ $promotion['requested_by'] ?? '-' }} {{ $promotion['requested_at'] ? '— '.$promotion['requested_at'] : '' }}</td></tr>
            <tr><td class="label">Revisor da promoção</td><td class="value">{{ $promotion['reviewed_by'] ?? '-' }} {{ $promotion['reviewed_at'] ? '— '.$promotion['reviewed_at'] : '' }}</td></tr>
            <tr><td class="label">Motivo/notas</td><td class="value">{{ $promotion['review_reason'] ?? '-' }}</td></tr>
            <tr><td class="label">Executada por</td><td class="value">{{ $promotion['executed_by'] ?? '-' }} {{ $promotion['promoted_at'] ? '— '.$promotion['promoted_at'] : '' }}</td></tr>
            <tr><td class="label">Operacional anterior</td><td class="value">{{ $promotion['previous_operational_calculation_version'] ?? 'nenhuma' }}</td></tr>
        </table>
        <p class="note">O revisor da promoção é independente do maker da curva, do revisor interno e do revisor externo. Aprovar não trocou a curva: a execução foi um evento separado, que revalidou toda a integridade imediatamente antes do switch.</p>
    @else
        <p class="note">Esta versão não possui dossiê de promoção operacional.</p>
    @endif

    <div class="section-title">Governança da Candidate</div>
    <table class="kv">
        <tr><td class="label">Validação interna</td><td class="value">{{ $version['internal_validation_status'] ?? '-' }}</td></tr>
        <tr><td class="label">Review interno</td><td class="value">{{ $version['review_status'] ?? '-' }}</td></tr>
        <tr><td class="label">Reviewer interno</td><td class="value">{{ $version['reviewed_by'] ?? '-' }} {{ $version['reviewed_at'] ? '— '.$version['reviewed_at'] : '' }}</td></tr>
        <tr><td class="label">Validação externa</td><td class="value">{{ $version['external_validation_status'] ?? '-' }}</td></tr>
        <tr><td class="label">Checksum da candidate</td><td class="value">{{ $version['curve_checksum'] ?? '-' }}</td></tr>
        <tr><td class="label">Fingerprint dos inputs</td><td class="value">{{ $version['input_fingerprint'] ?? '-' }}</td></tr>
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

    <div class="section-title">Validação Externa Independente</div>
    @if (($external_validation['has_comparison'] ?? false) && ($version['curve_role'] ?? null) === 'operational')
        <p class="note">O dossiê abaixo descreve a validação externa desta versão enquanto ela era candidate. Ele continua íntegro e válido depois da promoção.</p>
    @endif
    @if ($external_validation['has_comparison'] ?? false)
        <table class="kv">
            <tr><td class="label">Decisão</td><td class="value">{{ $external_validation['status'] ?? '-' }}</td></tr>
            <tr><td class="label">Fonte</td><td class="value">{{ $external_validation['benchmark']['source_name'] ?? '-' }}</td></tr>
            <tr><td class="label">Documento</td><td class="value">{{ $external_validation['benchmark']['source_document'] ?? '-' }}</td></tr>
            <tr><td class="label">Data de referência</td><td class="value">{{ $external_validation['benchmark']['reference_as_of'] ?? '-' }}</td></tr>
            <tr><td class="label">Dataset SHA-256</td><td class="value">{{ $external_validation['benchmark_dataset_sha256'] ?? '-' }}</td></tr>
            <tr><td class="label">Comparison SHA-256</td><td class="value">{{ $external_validation['comparison_sha256'] ?? '-' }}</td></tr>
            <tr><td class="label">Algoritmo</td><td class="value">{{ $external_validation['comparison_algorithm_version'] ?? '-' }}</td></tr>
            <tr><td class="label">Cobertura</td><td class="value">{{ $external_validation['coverage_status'] ?? '-' }}</td></tr>
            <tr><td class="label">Datas comparadas</td><td class="value">{{ $external_validation['compared_rows'] ?? 0 }}</td></tr>
            <tr><td class="label">Candidate sem referência</td><td class="value">{{ $external_validation['candidate_dates_without_reference'] ?? 0 }}</td></tr>
            <tr><td class="label">Referência sem candidate</td><td class="value">{{ $external_validation['reference_dates_without_candidate'] ?? 0 }}</td></tr>
            <tr><td class="label">Política de tolerância</td><td class="value">não definida — diferenças apenas reportadas</td></tr>
            <tr><td class="label">Revisor externo</td><td class="value">{{ $external_validation['reviewed_by'] ?? '-' }} {{ $external_validation['reviewed_at'] ? '— '.$external_validation['reviewed_at'] : '' }}</td></tr>
            <tr><td class="label">Motivo/notas</td><td class="value">{{ $external_validation['review_reason'] ?? '-' }}</td></tr>
        </table>

        @if (!empty($external_validation['differences']))
            <table class="diff">
                <thead><tr><th>Data</th><th>Candidate</th><th>Referência</th><th>Dif. absoluta</th><th>Dif. relativa (%)</th></tr></thead>
                <tbody>
                @foreach ($external_validation['differences'] as $difference)
                    <tr>
                        <td>{{ $difference['reference_date'] }}</td>
                        <td>{{ $difference['candidate_unit_value'] }}</td>
                        <td>{{ $difference['external_unit_value'] }}</td>
                        <td>{{ $difference['absolute_difference'] }}</td>
                        <td>{{ $difference['relative_difference_percentage'] ?? '-' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="note">Amostra limitada a {{ $external_validation['difference_sample_limit'] }} checkpoints; o dossiê estruturado permanece completo no banco.</p>
        @endif
    @else
        <p class="note">Nenhum benchmark externo estruturado foi comparado com esta versão.</p>
    @endif

    <div class="section-title">Observações</div>
    <p class="note">Documento gerado automaticamente pela plataforma para fins de governança da curva de PU.
        Os valores refletem os dados persistidos da versão indicada; uma candidate externamente validada continua não operacional.</p>

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
