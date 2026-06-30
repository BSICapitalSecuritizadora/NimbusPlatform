{{-- Corpo das seções do relatório mensal. Reutilizado pelo template de mês único
     e pelo template consolidado multi-mês (incluído por competência). Compatível com
     DomPDF (sem JavaScript). --}}

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
    <p class="no-data">{{ $payment['empty_message'] }}</p>
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
    <p class="no-data">{{ $calendar['empty_message'] }}</p>
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
    <p class="no-data">{{ $accounts['empty_message'] }}</p>
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
    <p class="no-data">{{ $expenses['empty_message'] }}</p>
@endif

{{-- ===== Histórico de despesas (por competência / vencimento) ===== --}}
@if ($expenses_history['has_data'])
    <div class="section-title">Histórico de Despesas (por Competência)</div>
    <table class="data">
        <thead><tr><th>Competência</th><th class="num">Total</th><th class="num">Variação</th><th>Distribuição</th></tr></thead>
        <tbody>
            @foreach ($expenses_history['rows'] as $row)
                <tr>
                    <td>{{ $row['competencia'] }}</td>
                    <td class="num">{{ $row['total'] }}</td>
                    <td class="num">{{ $row['variation'] }}</td>
                    <td><div class="mini-bar-wrap"><div class="mini-fill" style="width: {{ $row['bar_percent'] }}%;"></div></div></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="note">Série baseada nos lançamentos de despesas por data de vencimento.</p>
@endif

{{-- ===== Inadimplência ===== --}}
<div class="section-title">Inadimplência (R$)</div>
@if ($delinquency['has_data'])
    <table class="data">
        <thead><tr><th>Faixa de atraso</th><th class="num">Valor</th><th class="num">%</th><th>Distribuição</th></tr></thead>
        <tbody>
            @foreach ($delinquency['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $row['value'] }}</td>
                    <td class="num">{{ $row['percent'] }}</td>
                    <td><div class="mini-bar-wrap"><div class="mini-fill" style="width: {{ $row['bar_percent'] }}%;"></div></div></td>
                </tr>
            @endforeach
            <tr class="total"><td>Total</td><td class="num">{{ $delinquency['total'] }}</td><td class="num">100,00%</td><td></td></tr>
        </tbody>
    </table>
    <p class="note">Os valores correspondem às parcelas em aberto, conforme a data de vencimento.</p>
@else
    <p class="no-data">{{ $delinquency['empty_message'] }}</p>
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
    <p class="no-data">{{ $receivables['empty_message'] }}</p>
@endif

{{-- ===== Histórico de recebíveis e inadimplência (séries mensais) ===== --}}
@if ($receivables_history['has_data'])
    <div class="section-title">Histórico de Recebíveis e Inadimplência</div>
    <table class="data">
        <thead>
            <tr>
                <th>Competência</th>
                <th class="num">Previsto</th>
                <th class="num">Recebido</th>
                <th class="num">% Recebido</th>
                <th class="num">Inadimplência</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($receivables_history['rows'] as $row)
                <tr>
                    <td>{{ $row['competencia'] }}</td>
                    <td class="num">{{ $row['expected'] }}</td>
                    <td class="num">{{ $row['received'] }}</td>
                    <td class="num">{{ $row['received_percent'] }}</td>
                    <td class="num">{{ $row['delinquency'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="note">Série baseada nas competências de recebíveis cadastradas para a emissão.</p>
@endif

{{-- ===== Unidades / quadro de vendas ===== --}}
<div class="section-title">Unidades / Quadro de Vendas</div>
@if ($units['has_data'])
    <table class="kv">
        @foreach ($units['rows'] as $row)
            <tr><td class="label">{{ $row['label'] }}</td><td class="value">{{ $row['value'] }}</td></tr>
        @endforeach
    </table>
    @if ($units['composition'] !== [])
        <table class="comp-bar">
            <tr>
                @foreach ($units['composition'] as $seg)
                    @if ($seg['percent'] > 0)
                        <td class="{{ $seg['class'] }}" style="width: {{ $seg['percent'] }}%;">{{ $seg['percent'] }}%</td>
                    @endif
                @endforeach
            </tr>
        </table>
        <p class="comp-legend">Dourado: quitadas &middot; Azul: financiadas/vendidas &middot; Cinza: permutadas &middot; Claro: estoque.</p>
    @endif
@else
    <p class="no-data">{{ $units['empty_message'] }}</p>
@endif

{{-- ===== Histórico de unidades (composição e variação por competência) ===== --}}
@if ($units_history['has_data'])
    <div class="section-title">Histórico de Unidades</div>
    <table class="data">
        <thead>
            <tr>
                <th>Competência</th>
                <th class="num">Estoque</th>
                <th class="num">Financ./Vend.</th>
                <th class="num">Quitadas</th>
                <th class="num">Permutadas</th>
                <th class="num">Total</th>
                <th class="num">Variação</th>
                <th>Composição</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($units_history['rows'] as $row)
                <tr>
                    <td>{{ $row['competencia'] }}</td>
                    <td class="num">{{ $row['stock'] }}</td>
                    <td class="num">{{ $row['financed'] }}</td>
                    <td class="num">{{ $row['paid'] }}</td>
                    <td class="num">{{ $row['exchanged'] }}</td>
                    <td class="num">{{ $row['total'] }}</td>
                    <td class="num">{{ $row['variation'] }}</td>
                    <td>
                        @if ($row['composition'] !== [])
                            <table class="comp-bar">
                                <tr>
                                    @foreach ($row['composition'] as $seg)
                                        @if ($seg['percent'] > 0)
                                            <td class="{{ $seg['class'] }}" style="width: {{ $seg['percent'] }}%;">&nbsp;</td>
                                        @endif
                                    @endforeach
                                </tr>
                            </table>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="comp-legend">Composição: dourado = quitadas &middot; azul = financiadas/vendidas &middot; cinza = permutadas &middot; claro = estoque. Variação refere-se ao total de unidades ante o mês anterior.</p>
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
    <p class="no-data">{{ $negotiations['empty_message'] }}</p>
@endif

{{-- ===== Histórico de negociações (séries mensais) ===== --}}
@if ($negotiations_history['has_data'])
    <div class="section-title">Histórico de Negociações</div>
    <table class="data">
        <thead>
            <tr><th>Competência</th><th class="num">Vendas</th><th class="num">Distratos</th><th class="num">Líquido</th></tr>
        </thead>
        <tbody>
            @foreach ($negotiations_history['rows'] as $row)
                <tr>
                    <td>{{ $row['competencia'] }}</td>
                    <td class="num">{{ $row['sales'] }}</td>
                    <td class="num">{{ $row['cancellations'] }}</td>
                    <td class="num">{{ $row['net'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="note">Série de quantidades por competência. A base de negociações não possui valor monetário negociado.</p>
@endif

{{-- Análise do Mês e Evolução da Obra: representações compatíveis com DomPDF
     (cards + barras HTML/CSS), sem Chart.js/JavaScript. Caso futuramente se queira
     um visual idêntico ao do PDF de referência (gráficos de navegador), será
     necessário avaliar Browsershot em uma fase própria (muda a arquitetura de
     geração do PDF). --}}

{{-- ===== Análise do Mês — Recebíveis (Previsto × Recebido) ===== --}}
<div class="section-title">Análise do Mês — Recebíveis (Previsto × Recebido)</div>
@if ($analise_mes['has_data'])
    <table class="cards" cellspacing="4">
        <tr>
            @foreach ($analise_mes['cards'] as $card)
                <td>
                    <div class="card-label">{{ $card['label'] }}</div>
                    <div class="card-value">{{ $card['value'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>
    <table class="bar">
        <tr>
            @if ($analise_mes['paid_percent'] > 0)
                <td class="c-paid" style="width: {{ $analise_mes['paid_percent'] }}%;">{{ $analise_mes['paid_percent_label'] }}</td>
            @endif
            @if ($analise_mes['unpaid_percent'] > 0)
                <td class="c-unpaid" style="width: {{ $analise_mes['unpaid_percent'] }}%;">{{ $analise_mes['unpaid_percent_label'] }}</td>
            @endif
        </tr>
    </table>
    <p class="bar-legend">Dourado: recebido (pago) &nbsp;·&nbsp; Escuro: em aberto (não pago).</p>
@else
    <div class="no-data">{{ $analise_mes['empty_message'] }}</div>
@endif

{{-- ===== Evolução da Obra (%) ===== --}}
<div class="section-title">Evolução da Obra (%)</div>
@if ($construction['has_progress'])
    <table class="data">
        <thead>
            <tr>
                <th>Empreendimento</th>
                <th class="num">Prev. acum.</th>
                <th class="num">Real. acum.</th>
                <th class="num">Prev. mês</th>
                <th class="num">Real. mês</th>
                <th class="num">Dif.</th>
                <th>Tendência</th>
                <th>Medição</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($construction['progress'] as $row)
                <tr>
                    <td>
                        {{ $row['name'] }}
                        <div class="mini-bar-wrap"><div class="mini-fill" style="width: {{ $row['bar_percent'] }}%;"></div></div>
                    </td>
                    <td class="num">{{ $row['planned_cumulative'] }}</td>
                    <td class="num">{{ $row['realized_cumulative'] }}</td>
                    <td class="num">{{ $row['planned_monthly'] }}</td>
                    <td class="num">{{ $row['realized_monthly'] }}</td>
                    <td class="num">{{ $row['diff'] }}</td>
                    <td>{{ $row['trend'] }}</td>
                    <td>{{ $row['measurement_date'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <div class="no-data">{{ $construction['empty_message'] }}</div>
@endif

@if ($construction['has_constructions'])
    <p class="note"><strong>Empreendimentos vinculados</strong></p>
    <table class="data">
        <thead>
            <tr><th>Empreendimento</th><th>Local</th><th>Período</th><th class="num">Valor estimado</th></tr>
        </thead>
        <tbody>
            @foreach ($construction['constructions'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['location'] }}</td>
                    <td>{{ $row['period'] }}</td>
                    <td class="num">{{ $row['estimated_value'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- ===== Histórico de evolução da obra (séries mensais por empreendimento) ===== --}}
@if ($construction_history['has_data'])
    <div class="section-title">Histórico de Evolução da Obra</div>
    @foreach ($construction_history['series'] as $serie)
        <p class="subsection">{{ $serie['name'] }}</p>
        <table class="data">
            <thead>
                <tr>
                    <th>Competência</th>
                    <th class="num">Prev. acum.</th>
                    <th class="num">Real. acum.</th>
                    <th>Progresso (realizado acum.)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($serie['points'] as $point)
                    <tr>
                        <td>{{ $point['competencia'] }}</td>
                        <td class="num">{{ $point['planned_cumulative'] }}</td>
                        <td class="num">{{ $point['realized_cumulative'] }}</td>
                        <td><div class="mini-bar-wrap"><div class="mini-fill" style="width: {{ $point['bar_percent'] }}%;"></div></div></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
    <p class="note">Série baseada nas medições efetivas registradas por competência.</p>
@endif

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
    <div class="no-data">{{ $notes['empty_message'] }}</div>
@endif
