<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Empreendimento - {{ $project->name }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #333;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }
        .header {
            background-color: #091b23;
            color: #e6e4e4;
            padding: 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .gold-bar {
            height: 5px;
            background-color: #a06e28;
            width: 100%;
        }
        .content {
            padding: 30px;
        }
        .section-title {
            color: #091b23;
            border-bottom: 2px solid #a06e28;
            padding-bottom: 5px;
            margin-top: 25px;
            margin-bottom: 15px;
            font-size: 18px;
            font-weight: bold;
        }
        .info-grid {
            width: 100%;
            border-collapse: collapse;
        }
        .info-grid td {
            padding: 8px 0;
            vertical-align: top;
        }
        .info-grid .label {
            color: #a06e28;
            font-weight: bold;
            width: 180px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .table th {
            background-color: #091b23;
            color: #e6e4e4;
            text-align: left;
            padding: 10px;
            font-size: 12px;
        }
        .table td {
            border: 1px solid #ddd;
            padding: 8px;
            font-size: 11px;
        }
        .footer {
            position: fixed;
            bottom: 20px;
            width: 100%;
            text-align: center;
            font-size: 10px;
            color: #777;
        }
        .summary-box {
            background-color: #f2efee;
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .indicator-row {
            display: table;
            width: 100%;
            margin-bottom: 5px;
        }
        .indicator-cell {
            display: table-cell;
            width: 50%;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>BSI CAPITAL</h1>
        <div style="font-size: 14px; margin-top: 5px;">Relatório de Empreendimento</div>
    </div>
    <div class="gold-bar"></div>

    <div class="content">
        <div class="section-title">Informações Gerais</div>
        <table class="info-grid">
            <tr>
                <td class="label">Empreendimento:</td>
                <td>{{ $project->name }}</td>
                <td class="label">SPE:</td>
                <td>{{ $project->development_name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="label">Site:</td>
                <td>{{ $project->website_url ?? 'N/A' }}</td>
                <td class="label">Lançamento:</td>
                <td>{{ $project->launch_date ? \Carbon\Carbon::parse($project->launch_date)->format('m/Y') : 'N/A' }}</td>
            </tr>
            <tr>
                <td class="label">Localização:</td>
                <td colspan="3">
                    {{ $project->street }}, {{ $project->address_number }} {{ $project->address_complement }}<br>
                    {{ $project->neighborhood }} - {{ $project->city }}/{{ $project->state }} - CEP: {{ $project->zip_code }}
                </td>
            </tr>
        </table>

        <div class="section-title">Resumo das Unidades</div>
        <div class="summary-box">
            <table class="info-grid">
                <tr>
                    <td class="label">Total de Unidades:</td>
                    <td>{{ $project->units_total }}</td>
                    <td class="label">Permutadas:</td>
                    <td>{{ $project->exchanged_units }}</td>
                </tr>
                <tr>
                    <td class="label">Quitadas:</td>
                    <td>{{ $project->paid_units }}</td>
                    <td class="label">Não Quitadas:</td>
                    <td>{{ $project->unpaid_units }}</td>
                </tr>
                <tr>
                    <td class="label">Estoque:</td>
                    <td>{{ $project->stock_units }}</td>
                    <td class="label">% Vendidas:</td>
                    <td>{{ number_format($project->sales_percentage, 2, ',', '.') }}%</td>
                </tr>
            </table>
        </div>

        <div class="section-title">Resumo Financeiro (R$)</div>
        <div class="summary-box">
            <table class="info-grid">
                <tr>
                    <td class="label">Valor Solicitado:</td>
                    <td>R$ {{ number_format($project->requested_amount, 2, ',', '.') }}</td>
                    <td class="label">Custo Total:</td>
                    <td>R$ {{ number_format($project->total_cost, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="label">VGV Total:</td>
                    <td>R$ {{ number_format($project->gross_sales_value, 2, ',', '.') }}</td>
                    <td class="label">Estágio da Obra:</td>
                    <td>{{ number_format($project->work_stage_percentage, 2, ',', '.') }}%</td>
                </tr>
                <tr>
                    <td class="label">Já Recebido:</td>
                    <td>R$ {{ number_format($project->received_value, 2, ',', '.') }}</td>
                    <td class="label">Pós Chaves:</td>
                    <td>R$ {{ number_format($project->value_after_keys, 2, ',', '.') }}</td>
                </tr>
            </table>
        </div>

        @if($project->characteristics)
        <div class="section-title">Características Técnicas</div>
        <table class="info-grid">
            <tr>
                <td class="label">Blocos:</td>
                <td>{{ $project->characteristics->blocks }}</td>
                <td class="label">Pavimentos:</td>
                <td>{{ $project->characteristics->floors }}</td>
            </tr>
            <tr>
                <td class="label">Andares Tipo:</td>
                <td>{{ $project->characteristics->typical_floors }}</td>
                <td class="label">Unidades/Andar:</td>
                <td>{{ $project->characteristics->units_per_floor }}</td>
            </tr>
        </table>

        @if($project->characteristics->unitTypes->count() > 0)
        <table class="table">
            <thead>
                <tr>
                    <th>Dormitórios</th>
                    <th>Vagas</th>
                    <th>Área Útil (m²)</th>
                    <th>Qtd.</th>
                    <th>Preço Médio</th>
                    <th>Preço/m²</th>
                </tr>
            </thead>
            <tbody>
                @foreach($project->characteristics->unitTypes as $type)
                <tr>
                    <td>{{ $type->bedrooms }}</td>
                    <td>{{ $type->parking_spaces }}</td>
                    <td>{{ number_format($type->usable_area, 2, ',', '.') }}</td>
                    <td>{{ $type->total_units }}</td>
                    <td>R$ {{ number_format($type->average_price, 2, ',', '.') }}</td>
                    <td>R$ {{ number_format($type->price_per_square_meter, 2, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
        @endif
    </div>

    <div class="footer">
        BSI CAPITAL SECURITIZADORA S/A | Relatório gerado em {{ date('d/m/Y H:i') }}
    </div>
</body>
</html>
