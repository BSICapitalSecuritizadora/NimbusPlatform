<h1 class="project-title">{{ $project->name }}</h1>

<h2 class="section-title">Dados gerais e cronograma</h2>
<table class="info-table">
    <tr><th>Empreendimento</th><td>{{ $project->name }}</td><th>Incorporadora / SPE</th><td>{{ $project->development_name ?: '—' }}</td></tr>
    <tr><th>Site</th><td>{{ $project->website_url ?: '—' }}</td><th>Localização</th><td>{{ collect([$project->city, $project->state])->filter()->join('/') ?: '—' }}</td></tr>
    <tr><th>Endereço</th><td colspan="3">{{ collect([$project->street, $project->address_number, $project->address_complement, $project->neighborhood, $project->zip_code])->filter()->join(', ') ?: '—' }}</td></tr>
    <tr><th>Lançamento</th><td>{{ $project->formatted_launch_month }}</td><th>Lançamento de vendas</th><td>{{ $project->formatted_sales_launch_month }}</td></tr>
    <tr><th>Início da obra</th><td>{{ $project->formatted_construction_start_month }}</td><th>Previsão de entrega</th><td>{{ $project->formatted_delivery_forecast_month }}</td></tr>
    <tr><th>Prazo remanescente</th><td>{{ ! $project->construction_start_date || ! $project->delivery_forecast_date ? '—' : $project->remaining_months.' meses' }}</td><th>Estágio da obra</th><td>{{ number_format($analysis['costs']['work_stage_percentage'], 2, ',', '.') }}%</td></tr>
    <tr><th>Valor solicitado</th><td>R$ {{ number_format((float) $project->requested_amount, 2, ',', '.') }}</td><th>Terreno</th><td>R$ {{ number_format($analysis['land']['market_value'], 2, ',', '.') }}</td></tr>
    <tr><th>Área do terreno</th><td>{{ number_format($analysis['land']['area'], 2, ',', '.') }} m²</td><th>Terreno por m²</th><td>{{ $analysis['land']['value_per_square_meter'] === null ? '—' : 'R$ '.number_format($analysis['land']['value_per_square_meter'], 2, ',', '.') }}</td></tr>
</table>

<h2 class="section-title">Características construtivas e tipologias</h2>
@if ($project->characteristics)
    <table class="info-table">
        <tr><th>Blocos</th><td>{{ $project->characteristics->blocks }}</td><th>Pavimentos</th><td>{{ $project->characteristics->floors }}</td></tr>
        <tr><th>Andares tipo</th><td>{{ $project->characteristics->typical_floors }}</td><th>Unidades por andar</th><td>{{ $project->characteristics->units_per_floor }}</td></tr>
        <tr><th>Total técnico</th><td>{{ $project->characteristics->total_units }}</td><th>Custo por m² de terreno</th><td>{{ $analysis['land']['construction_cost_per_land_square_meter'] === null ? '—' : 'R$ '.number_format($analysis['land']['construction_cost_per_land_square_meter'], 2, ',', '.') }}</td></tr>
    </table>

    @if ($project->characteristics->unitTypes->isNotEmpty())
        <table class="data-table">
            <thead><tr><th>Tipo</th><th>Qtd.</th><th>Dormitórios</th><th>Vagas</th><th>Área útil</th><th>Preço médio</th><th>Preço/m²</th></tr></thead>
            <tbody>
            @foreach ($project->characteristics->unitTypes->sortBy('order') as $unitType)
                <tr>
                    <td>Tipo {{ $unitType->order }}</td><td class="center">{{ $unitType->total_units }}</td><td>{{ $unitType->bedrooms ?: '—' }}</td><td>{{ $unitType->parking_spaces ?: '—' }}</td>
                    <td class="number">{{ number_format((float) $unitType->usable_area, 2, ',', '.') }} m²</td><td class="number">R$ {{ number_format((float) $unitType->average_price, 2, ',', '.') }}</td><td class="number">R$ {{ number_format((float) $unitType->price_per_square_meter, 2, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <div class="empty-state">Nenhuma tipologia cadastrada.</div>
    @endif
@else
    <div class="empty-state">Características construtivas não cadastradas.</div>
@endif

<h2 class="section-title">Resumo das unidades</h2>
<table class="data-table">
    <thead><tr><th>Categoria</th><th>Unidades</th><th>Participação</th><th>Valor de vendas</th><th>Ticket médio</th></tr></thead>
    <tbody>
        @foreach ([
            ['label' => 'Permutadas', 'key' => 'exchanged'],
            ['label' => 'Quitadas', 'key' => 'paid'],
            ['label' => 'Não quitadas', 'key' => 'unpaid'],
            ['label' => 'Estoque', 'key' => 'stock'],
            ['label' => 'Total', 'key' => 'total'],
        ] as $unitRow)
            <tr>
                <td>{{ $unitRow['label'] }}</td>
                <td class="center">{{ $analysis['units'][$unitRow['key']] }}</td>
                <td class="number">{{ $analysis['units'][$unitRow['key'].'_percentage'] === null ? '—' : number_format($analysis['units'][$unitRow['key'].'_percentage'], 2, ',', '.').'%' }}</td>
                <td class="number">{{ $analysis['units'][$unitRow['key'].'_sales_value'] === null ? '—' : 'R$ '.number_format($analysis['units'][$unitRow['key'].'_sales_value'], 2, ',', '.') }}</td>
                <td class="number">{{ $analysis['units'][$unitRow['key'].'_average_value'] === null ? '—' : 'R$ '.number_format($analysis['units'][$unitRow['key'].'_average_value'], 2, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<div class="derived-note"><strong>Percentual vendido líquido de permutas:</strong> {{ number_format($analysis['units']['sold_percentage'], 2, ',', '.') }}%</div>

<h2 class="section-title">Custos e vendas / VGV</h2>
<table class="data-table">
    <thead><tr><th>Métrica</th><th>Custos</th><th>Métrica</th><th>Vendas</th></tr></thead>
    <tbody>
        <tr><td>Custo incorrido</td><td class="number">R$ {{ number_format($analysis['costs']['incurred'], 2, ',', '.') }}</td><td>Unidades quitadas</td><td class="number">R$ {{ number_format($analysis['sales']['paid'], 2, ',', '.') }}</td></tr>
        <tr><td>Custo a incorrer</td><td class="number">R$ {{ number_format($analysis['costs']['to_incur'], 2, ',', '.') }}</td><td>Unidades não quitadas</td><td class="number">R$ {{ number_format($analysis['sales']['unpaid'], 2, ',', '.') }}</td></tr>
        <tr><td>Custo total</td><td class="number">R$ {{ number_format($analysis['costs']['total'], 2, ',', '.') }}</td><td>Estoque</td><td class="number">R$ {{ number_format($analysis['sales']['stock'], 2, ',', '.') }}</td></tr>
        <tr><td>Estágio da obra</td><td class="number">{{ number_format($analysis['costs']['work_stage_percentage'], 2, ',', '.') }}%</td><td>VGV</td><td class="number">R$ {{ number_format($analysis['sales']['total'], 2, ',', '.') }}</td></tr>
    </tbody>
</table>

<h2 class="section-title">Fluxo de recebimentos</h2>
<table class="data-table">
    <thead><tr><th>Período</th><th>Valor</th><th>Participação</th></tr></thead>
    <tbody>
        <tr><td>Já recebido</td><td class="number">R$ {{ number_format($analysis['receivables']['received'], 2, ',', '.') }}</td><td class="number">{{ number_format($analysis['receivables']['received_percentage'], 2, ',', '.') }}%</td></tr>
        <tr><td>Até as chaves</td><td class="number">R$ {{ number_format($analysis['receivables']['until_keys'], 2, ',', '.') }}</td><td class="number">{{ number_format($analysis['receivables']['until_keys_percentage'], 2, ',', '.') }}%</td></tr>
        <tr><td>Pós-chaves</td><td class="number">R$ {{ number_format($analysis['receivables']['after_keys'], 2, ',', '.') }}</td><td class="number">{{ number_format($analysis['receivables']['after_keys_percentage'], 2, ',', '.') }}%</td></tr>
        <tr><th>Total</th><th class="number">R$ {{ number_format($analysis['receivables']['total'], 2, ',', '.') }}</th><th class="number">{{ $analysis['receivables']['total'] > 0 ? '100,00%' : '—' }}</th></tr>
    </tbody>
</table>

@if ($showIndicators)
    <h2 class="section-title">Indicadores avançados</h2>
    <table class="data-table">
        <thead><tr><th>Indicador</th><th>Valor</th><th>Ideal</th><th>Limite</th><th>Critério</th><th>Classificação</th></tr></thead>
        <tbody>
        @foreach ($analysis['indicators'] as $indicator)
            <tr>
                <td>{{ $indicator['name'] }}<div class="formula">{{ $indicator['formula'] }}</div></td>
                <td class="number {{ $indicator['value'] === null ? 'not-calculable' : '' }}">{{ $indicator['value'] === null ? 'Não calculável' : number_format($indicator['value'], 2, ',', '.').'%' }}</td>
                <td class="number">{{ $indicator['ideal'] === null ? '—' : number_format($indicator['ideal'], 2, ',', '.').'%' }}</td>
                <td class="number">{{ $indicator['limit'] === null ? '—' : number_format($indicator['limit'], 2, ',', '.').'%' }}</td>
                <td>{{ $indicator['direction'] }}</td>
                <td class="status {{ $indicator['classification_class'] }}">{{ $indicator['classification'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
