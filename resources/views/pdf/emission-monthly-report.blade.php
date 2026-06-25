<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatório Mensal — {{ $header['name'] }}</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #333; line-height: 1.5; margin: 0; padding: 0; font-size: 12px; }
        .header { background-color: #091b23; color: #e6e4e4; padding: 20px 30px; }
        .header h1 { margin: 0; font-size: 20px; text-transform: uppercase; letter-spacing: 1px; }
        .header h2 { margin: 6px 0 0; font-size: 15px; color: #fff; font-weight: normal; }
        .header p { margin: 4px 0 0; font-size: 11px; color: #c9c6c6; }
        .gold-bar { height: 5px; background-color: #a06e28; width: 100%; }
        .content { padding: 22px 30px 10px; }
        .section-title { color: #091b23; border-bottom: 2px solid #a06e28; padding-bottom: 4px; margin: 22px 0 10px; font-size: 14px; text-transform: uppercase; letter-spacing: .5px; }
        table { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 5px 8px; vertical-align: top; border-bottom: 1px solid #eee; }
        table.kv td.label { width: 38%; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        table.kv td.value { font-weight: bold; color: #091b23; }
        table.grid td { width: 50%; vertical-align: top; padding: 0 6px; }
        table.data th { background: #091b23; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .3px; }
        table.data td { padding: 5px 8px; border-bottom: 1px solid #eee; color: #091b23; }
        table.data td.num, table.data th.num { text-align: right; }
        table.data tr.total td { font-weight: bold; border-top: 2px solid #a06e28; background: #faf6ef; }
        .empty { font-size: 11px; color: #999; font-style: italic; padding: 6px 0; }
        .note { font-size: 10px; color: #888; margin-top: 6px; }
        .placeholder { border: 1px dashed #cfcfcf; background: #fafafa; color: #888; font-size: 11px; padding: 14px; text-align: center; border-radius: 4px; }
        .note-card { border: 1px solid #eee; border-left: 3px solid #a06e28; padding: 8px 12px; margin-bottom: 8px; background: #fcfbf9; }
        .note-head { margin: 0 0 4px; color: #091b23; font-size: 12px; }
        .note-badge { display: inline-block; background: #091b23; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; padding: 1px 6px; border-radius: 8px; margin-right: 6px; }
        .note-body { margin: 0; font-size: 11px; color: #333; }
        .note-meta { margin: 6px 0 0; font-size: 9px; color: #999; }
        .footer { margin-top: 22px; padding: 10px 30px; font-size: 9px; color: #aaa; text-align: center; border-top: 1px solid #eee; }
    </style>
</head>
<body>
<div class="header">
    <h1>Relatório Mensal</h1>
    <h2>{{ $header['name'] }}</h2>
    <p>Referência: {{ $meta['reference_label'] }} &middot; {{ $header['identifier'] }} &middot; {{ $header['offer'] }}</p>
</div>
<div class="gold-bar"></div>

<div class="content">

    {{-- ===== Resumo / cabeçalho ===== --}}
    <div class="section-title">Resumo da Operação</div>
    <table class="kv">
        <tr><td class="label">Saldo devedor do CRI</td><td class="value">{{ $header['debt_balance'] }} <span style="font-weight:normal;color:#888;font-size:10px;">(posição em {{ $header['debt_position'] }})</span></td></tr>
        <tr><td class="label">Quantidade em circulação</td><td class="value">{{ $header['circulating_quantity'] }}</td></tr>
        <tr><td class="label">Remuneração</td><td class="value">{{ $header['remuneration'] }}</td></tr>
        <tr><td class="label">PU</td><td class="value">{{ $header['current_pu'] }}</td></tr>
        <tr><td class="label">Próximo evento</td><td class="value">{{ $header['next_event'] }}</td></tr>
    </table>

    {{-- ===== Características ===== --}}
    <div class="section-title">Características Gerais da Emissão</div>
    <table class="grid">
        <tr>
            <td>
                <table class="kv">
                    @foreach (array_slice($characteristics, 0, (int) ceil(count($characteristics) / 2)) as $item)
                        <tr><td class="label">{{ $item['label'] }}</td><td class="value">{{ $item['value'] }}</td></tr>
                    @endforeach
                </table>
            </td>
            <td>
                <table class="kv">
                    @foreach (array_slice($characteristics, (int) ceil(count($characteristics) / 2)) as $item)
                        <tr><td class="label">{{ $item['label'] }}</td><td class="value">{{ $item['value'] }}</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    {{-- ===== Pagamento aos investidores ===== --}}
    <div class="section-title">Pagamento aos Investidores</div>
    @if ($payment['has_data'])
        <p class="note">Último pagamento: <strong>{{ $payment['payment_date'] }}</strong></p>
        <table class="data">
            <thead><tr><th>Componente</th><th class="num">Valor</th></tr></thead>
            <tbody>
                @foreach ($payment['rows'] as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">{{ $payment['empty_message'] }}</p>
    @endif

    {{-- ===== Calendário de eventos ===== --}}
    <div class="section-title">Calendário de Eventos</div>
    @if ($calendar['has_data'])
        <table class="kv">
            @foreach ($calendar['highlight'] as $row)
                <tr><td class="label">{{ $row['label'] }}</td><td class="value">{{ $row['value'] }}</td></tr>
            @endforeach
        </table>
        @if ($calendar['has_upcoming'])
            <p class="note"><strong>Próximos eventos</strong></p>
            <table class="data">
                <thead><tr><th>Seq.</th><th>Data</th><th>Tipo</th><th class="num">Amortização</th></tr></thead>
                <tbody>
                    @foreach ($calendar['upcoming'] as $row)
                        <tr>
                            <td>{{ $row['sequence'] }}</td>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ $row['type'] }}</td>
                            <td class="num">{{ $row['amortization'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        <p class="empty">{{ $calendar['empty_message'] }}</p>
    @endif

    {{-- ===== Saldo devedor ===== --}}
    <div class="section-title">Saldo Devedor</div>
    <table class="kv">
        @foreach ($debt_balance as $item)
            <tr><td class="label">{{ $item['label'] }}</td><td class="value">{{ $item['value'] }}</td></tr>
        @endforeach
    </table>

    {{-- ===== Contas vinculadas ===== --}}
    <div class="section-title">Contas Vinculadas</div>
    @if ($accounts['has_data'])
        <table class="data">
            <thead><tr><th>Conta/Fundo</th><th>Banco</th><th>Agência</th><th>Conta</th><th class="num">Saldo</th></tr></thead>
            <tbody>
                @foreach ($accounts['rows'] as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['bank'] }}</td>
                        <td>{{ $row['agency'] }}</td>
                        <td>{{ $row['account'] }}</td>
                        <td class="num">{{ $row['balance'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">{{ $accounts['empty_message'] }}</p>
    @endif

    {{-- ===== Despesas ===== --}}
    <div class="section-title">Despesas</div>
    @if ($expenses['has_data'])
        @if ($expenses['recurring'] !== [])
            <p class="note"><strong>Despesas Recorrentes</strong></p>
            <table class="data">
                <thead><tr><th>Categoria</th><th>Periodicidade</th><th class="num">Valor</th></tr></thead>
                <tbody>
                    @foreach ($expenses['recurring'] as $row)
                        <tr><td>{{ $row['category'] }}</td><td>{{ $row['period'] }}</td><td class="num">{{ $row['amount'] }}</td></tr>
                    @endforeach
                    <tr class="total"><td colspan="2">Total recorrente</td><td class="num">{{ $expenses['recurring_total'] }}</td></tr>
                </tbody>
            </table>
        @endif
        @if ($expenses['non_recurring'] !== [])
            <p class="note"><strong>Despesas Não Recorrentes</strong></p>
            <table class="data">
                <thead><tr><th>Categoria</th><th>Periodicidade</th><th class="num">Valor</th></tr></thead>
                <tbody>
                    @foreach ($expenses['non_recurring'] as $row)
                        <tr><td>{{ $row['category'] }}</td><td>{{ $row['period'] }}</td><td class="num">{{ $row['amount'] }}</td></tr>
                    @endforeach
                    <tr class="total"><td colspan="2">Total não recorrente</td><td class="num">{{ $expenses['non_recurring_total'] }}</td></tr>
                </tbody>
            </table>
        @endif
    @else
        <p class="empty">{{ $expenses['empty_message'] }}</p>
    @endif

    {{-- ===== Inadimplência ===== --}}
    <div class="section-title">Inadimplência (R$)</div>
    @if ($delinquency['has_data'])
        <table class="data">
            <thead><tr><th>Faixa de atraso</th><th class="num">Valor</th><th class="num">%</th></tr></thead>
            <tbody>
                @foreach ($delinquency['rows'] as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td><td class="num">{{ $row['percent'] }}</td></tr>
                @endforeach
                <tr class="total"><td>Total</td><td class="num">{{ $delinquency['total'] }}</td><td class="num">100,00%</td></tr>
            </tbody>
        </table>
        <p class="note">Os valores correspondem às parcelas em aberto, conforme a data de vencimento.</p>
    @else
        <p class="empty">{{ $delinquency['empty_message'] }}</p>
    @endif

    {{-- ===== Recebimentos (resumo) ===== --}}
    <div class="section-title">Recebimentos (Resumo)</div>
    @if ($receivables['has_data'])
        <table class="kv">
            @foreach ($receivables['rows'] as $row)
                <tr><td class="label">{{ $row['label'] }}</td><td class="value">{{ $row['value'] }}</td></tr>
            @endforeach
        </table>
    @else
        <p class="empty">{{ $receivables['empty_message'] }}</p>
    @endif

    {{-- ===== Unidades / quadro de vendas ===== --}}
    <div class="section-title">Unidades / Quadro de Vendas</div>
    @if ($units['has_data'])
        <table class="kv">
            @foreach ($units['rows'] as $row)
                <tr><td class="label">{{ $row['label'] }}</td><td class="value">{{ $row['value'] }}</td></tr>
            @endforeach
        </table>
    @else
        <p class="empty">{{ $units['empty_message'] }}</p>
    @endif

    {{-- ===== Negociações ===== --}}
    <div class="section-title">Negociações do Mês</div>
    @if ($negotiations['has_data'])
        <table class="kv">
            @foreach ($negotiations['rows'] as $row)
                <tr><td class="label">{{ $row['label'] }}</td><td class="value">{{ $row['value'] }}</td></tr>
            @endforeach
        </table>
    @else
        <p class="empty">{{ $negotiations['empty_message'] }}</p>
    @endif

    {{-- Seções gráficas: DomPDF não executa Chart.js (JavaScript), portanto as
         visualizações gráficas não são renderizáveis nesta engine. Mantemos um aviso
         corporativo simples (sem jargão técnico no PDF). Previstos para a V2 (ver
         análise/planejamento): Análise do Mês (pago × não pago, R$ e %) e Evolução
         da Obra (%). --}}
    <div class="section-title">Análise do Mês &amp; Evolução da Obra</div>
    <div class="placeholder">
        As visualizações gráficas de Análise do Mês e Evolução da Obra serão
        disponibilizadas em uma próxima versão deste relatório.
    </div>

    {{-- ===== Comentários e notas explicativas (módulo administrativo) ===== --}}
    <div class="section-title">Comentários e Notas Explicativas</div>
    @if ($notes['has_data'])
        @foreach ($notes['rows'] as $note)
            <div class="note-card">
                @if ($note['title'] || $note['category'])
                    <p class="note-head">
                        @if ($note['category'])<span class="note-badge">{{ $note['category'] }}</span>@endif
                        @if ($note['title'])<strong>{{ $note['title'] }}</strong>@endif
                    </p>
                @endif
                <p class="note-body">{{ $note['content'] }}</p>
                @if ($note['author'] || $note['date'])
                    <p class="note-meta">{{ $note['author'] ?? 'Autor não informado' }}@if ($note['date']) — {{ $note['date'] }}@endif</p>
                @endif
            </div>
        @endforeach
    @else
        <div class="placeholder">{{ $notes['empty_message'] }}</div>
    @endif

</div>

<div class="footer">
    BSI Capital Securitizadora — Documento gerado automaticamente pela plataforma em {{ $meta['generated_at'] }}.
</div>
</body>
</html>
