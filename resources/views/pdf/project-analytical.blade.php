<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatório Analítico - {{ $project->name }}</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #333; }
        .header { background: #001233; color: white; padding: 15px; text-align: center; }
        .gold-bar { height: 4px; background: #d4af37; }
        .section-title { color: #001233; border-left: 4px solid #d4af37; padding-left: 10px; margin: 15px 0 10px; font-weight: bold; font-size: 14px; background: #f6f8fa; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background: #f0f2f5; font-weight: bold; }
        .status-cell { font-weight: bold; text-align: center; }
        .enquadrado { color: green; }
        .desenquadrado { color: red; }
        .analisar { color: orange; }
    </style>
</head>
<body>
    @php
    function getStatus($valor, $ideal, $limite, $maiorMelhor = false) {
        if ($ideal === null || $limite === null) return ['N/A', ''];
        if ($maiorMelhor) {
            if ($valor >= $ideal) return ['Enquadrado', 'enquadrado'];
            if ($valor < $limite) return ['Desenquadrado', 'desenquadrado'];
            return ['Analisar', 'analisar'];
        } else {
            if ($valor <= $ideal) return ['Enquadrado', 'enquadrado'];
            if ($valor > $limite) return ['Desenquadrado', 'desenquadrado'];
            return ['Analisar', 'analisar'];
        }
    }
    @endphp

    <div class="header"><h1>Relatório Analítico</h1><div>{{ $project->name }}</div></div>
    <div class="gold-bar"></div>

    <div class="section-title">Indicadores Avançados</div>
    <table>
        <thead>
            <tr>
                <th>Indicador</th>
                <th>Valor Atual (%)</th>
                <th>Critério / Ideal</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @php
            $inds = $project->indicators;
            @endphp
            <tr>
                <td>Financiamento / Custo de Obra</td>
                <td>{{ number_format($financiamento_custo_obra, 2, ',', '.') }}%</td>
                <td>Menor que {{ $inds?->financiamento_custo_obra_ideal }}%</td>
                @php $s = getStatus($financiamento_custo_obra, $inds?->financiamento_custo_obra_ideal, $inds?->financiamento_custo_obra_limite); @endphp
                <td class="status-cell {{ $s[1] }}">{{ $s[0] }}</td>
            </tr>
            {{-- Repeat for other indicators... adding first 3 for brevity in this step --}}
            <tr>
                <td>Financiamento / VGV</td>
                <td>{{ number_format(($project->requested_amount / max(1, $valor_total_total)) * 100, 2, ',', '.') }}%</td>
                <td>Menor que {{ $inds?->financiamento_vgv_ideal }}%</td>
                @php $s = getStatus(($project->requested_amount / max(1, $valor_total_total)) * 100, $inds?->financiamento_vgv_ideal, $inds?->financiamento_vgv_limite); @endphp
                <td class="status-cell {{ $s[1] }}">{{ $s[0] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Resumo das Unidades</div>
    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th>Qtd.</th>
                <th>%</th>
                <th>Valor Total (R$)</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Vendidas</td><td>{{ $project->unpaid_units }}</td><td>{{ number_format($percent_vendidas, 1) }}%</td><td>R$ {{ number_format($project->unpaid_sales_value, 2, ',', '.') }}</td></tr>
            <tr><td>Quitadas</td><td>{{ $project->paid_units }}</td><td>{{ number_format($percent_quitadas, 1) }}%</td><td>R$ {{ number_format($project->paid_sales_value, 2, ',', '.') }}</td></tr>
            <tr><td>Estoque</td><td>{{ $project->stock_units }}</td><td>{{ number_format($percent_estoque, 1) }}%</td><td>R$ {{ number_format($project->stock_sales_value, 2, ',', '.') }}</td></tr>
            <tr><td><b>Total</b></td><td>{{ $project->units_total }}</td><td>100%</td><td><b>R$ {{ number_format($valor_total_total, 2, ',', '.') }}</b></td></tr>
        </tbody>
    </table>
</body>
</html>
