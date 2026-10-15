@php
    /** @var array<string, mixed> $detail {@see \App\Support\PuCalculator\PuObligationDetail::build()} */
    $identity = $detail['identity'];
    $expected = $detail['expected'];
    $settlement = $detail['settlement'];
    $reconciliation = $detail['reconciliation'];
    $components = $detail['components'];
@endphp

<div class="pu-detail flex flex-col gap-5 text-sm">
    {{-- Identidade econômica --}}
    <dl class="pu-detail-grid">
        <div>
            <dt>Obrigação</dt>
            <dd>{{ $identity['type'] }} · sequência {{ $identity['sequence'] }}</dd>
        </div>
        <div>
            <dt>Data contratual</dt>
            <dd>{{ $identity['contractual_date'] ?? '—' }}</dd>
        </div>
        <div>
            <dt>Vencimento ajustado</dt>
            <dd>{{ $identity['due_date'] ?? '—' }}</dd>
        </div>
        <div>
            <dt>Ciclo de vida</dt>
            <dd>
                {{ $identity['lifecycle'] }}
                @if (! $identity['active'])
                    <span class="text-muted">— superada em {{ $identity['superseded_at'] ?? '—' }}{{ filled($identity['supersession_reason']) ? ': '.$identity['supersession_reason'] : '' }}</span>
                @endif
            </dd>
        </div>
    </dl>

    {{-- Os três fatos, lado a lado e nunca misturados --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="pu-detail-card" aria-labelledby="pu-fact-informed">
            <h3 id="pu-fact-informed" class="pu-detail-card-title">Cronograma contratual (informado)</h3>
            <p class="pu-detail-hint">Valor da planilha ou do cadastro manual. É referência contratual, não o cálculo oficial.</p>
            @if ($detail['informed'] !== null)
                <p class="mt-2 text-secondary">Linha de {{ $detail['informed']['date'] }}</p>
                <table class="pu-detail-table mt-2">
                    <tbody>
                        @foreach ($detail['informed']['rows'] as $row)
                            <tr><th scope="row">{{ $row['label'] }}</th><td class="pu-num">{{ $row['value'] ?? '—' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="mt-2 text-muted">Sem linha correspondente no Cronograma de Pagamentos informado.</p>
            @endif
        </section>

        <section class="pu-detail-card" aria-labelledby="pu-fact-expected">
            <h3 id="pu-fact-expected" class="pu-detail-card-title">Esperado oficial (curva homologada)</h3>
            <p class="pu-detail-hint">Calculado pela versão oficial vigente. Muda só com nova versão homologada; o histórico fica abaixo.</p>
            <p class="mt-2">
                <x-filament::badge :color="$expected['state_color']" :icon="$expected['state_icon']">{{ $expected['state_label'] }}</x-filament::badge>
            </p>
            @if ($expected['available'])
                <p class="pu-detail-amount">{{ $expected['total'] }}</p>
                <p class="text-secondary">Versão {{ $expected['version'] }} · vencimento {{ $expected['due_date'] ?? '—' }} · calculado em {{ $expected['calculated_at'] ?? '—' }}</p>
            @else
                <p class="pu-detail-amount pu-detail-unavailable">{{ $expected['unavailable_label'] }}</p>
                @if (filled($expected['state_reason']))
                    <p class="text-secondary">{{ $expected['state_reason'] }}</p>
                @endif
                @if ($expected['last_known_total'] !== null)
                    <p class="text-muted">Último cálculo gravado ({{ $expected['version'] }}): {{ $expected['last_known_total'] }} — em dúvida, não usar como esperado.</p>
                @endif
            @endif
        </section>

        <section class="pu-detail-card" aria-labelledby="pu-fact-settled">
            <h3 id="pu-fact-settled" class="pu-detail-card-title">Liquidação registrada</h3>
            <p class="pu-detail-hint">O que de fato foi pago. Liquidação é fechada: não existe pagamento parcial nem saldo residual.</p>
            @if ($settlement !== null)
                <p class="pu-detail-amount">{{ $settlement['amount'] }}</p>
                <p class="text-secondary">Liquidada em {{ $settlement['date'] }} · {{ $settlement['source'] }}{{ filled($settlement['reference']) ? ' · ref. '.$settlement['reference'] : '' }}</p>
                <p class="text-muted">{{ $settlement['entry'] }} registrada por {{ $settlement['recorded_by'] ?? '—' }} em {{ $settlement['recorded_at'] ?? '—' }}.
                    @if ($settlement['expected_version_at_recording'] !== null)
                        Esperado no registro: {{ $settlement['expected_total_at_recording'] ?? 'indisponível' }} ({{ $settlement['expected_version_at_recording'] }}).
                    @endif
                </p>
            @else
                <p class="pu-detail-amount pu-detail-unavailable">{{ \App\Support\PuCalculator\PuDisplay::NOT_SETTLED }}</p>
            @endif
        </section>
    </div>

    {{-- Esperado × liquidado --}}
    <section class="pu-detail-card" aria-labelledby="pu-reconciliation">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="pu-reconciliation" class="pu-detail-card-title">Conciliação</h3>
            <x-filament::badge :color="$reconciliation['color']" :icon="$reconciliation['icon']">{{ $reconciliation['label'] }}</x-filament::badge>
        </div>
        <dl class="pu-detail-grid mt-3">
            <div><dt>Esperado</dt><dd class="pu-tabular">{{ $expected['available'] ? $expected['total'] : $expected['unavailable_label'] }}</dd></div>
            <div><dt>Liquidado</dt><dd class="pu-tabular">{{ $settlement['amount'] ?? \App\Support\PuCalculator\PuDisplay::NOT_SETTLED }}</dd></div>
            <div><dt>Diferença (liquidado − esperado)</dt><dd class="pu-tabular">{{ $reconciliation['difference'] ?? '—' }}</dd></div>
            <div><dt>Avaliada em</dt><dd>{{ $reconciliation['evaluated_at'] ?? '—' }}</dd></div>
        </dl>
        <p class="mt-3 text-secondary">{{ $reconciliation['explanation'] }}</p>
        @if ($reconciliation['divergence'] !== [])
            <ul class="mt-2 list-disc space-y-1 pl-5 text-secondary">
                @foreach ($reconciliation['divergence'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Componentes --}}
    <section class="pu-detail-card" aria-labelledby="pu-components">
        <h3 id="pu-components" class="pu-detail-card-title">Componentes do esperado vigente</h3>
        <p class="pu-detail-hint">
            O total esperado é a soma dos componentes disponíveis. Componente "não suportado" não tem valor e deixa a obrigação sem cálculo — nunca vira zero.
            @if (! $components['settlement_provided'])
                A origem da liquidação informou só o total: a coluna "Informado pela origem" fica vazia de propósito (o total nunca é rateado).
            @endif
        </p>
        @if ($components['rows'] === [])
            <p class="mt-2 text-muted">Nenhum componente calculado para esta obrigação.</p>
        @else
            <div class="pu-detail-scroll mt-2">
                <table class="pu-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">Componente</th>
                            <th scope="col">Situação</th>
                            <th scope="col">Origem do esperado</th>
                            <th scope="col" class="pu-num">Esperado</th>
                            <th scope="col" class="pu-num">Informado pela origem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($components['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td>
                                    <x-filament::badge size="sm" :color="$row['status_color']">{{ $row['status'] }}</x-filament::badge>
                                    @if (filled($row['reason']))
                                        <span class="block text-muted">{{ $row['reason'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $row['owner'] ?? '—' }}</td>
                                <td class="pu-num">{{ $row['expected'] ?? '—' }}</td>
                                <td class="pu-num">{{ $row['actual'] ?? ($components['settlement_provided'] ? '—' : 'não informado') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Histórico do esperado por versão --}}
    <section class="pu-detail-card" aria-labelledby="pu-calculations">
        <h3 id="pu-calculations" class="pu-detail-card-title">Histórico do esperado por versão da curva</h3>
        <p class="pu-detail-hint">Cada versão homologada recalcula a mesma obrigação. O histórico não é uma segunda curva oficial: só a linha "vigente" vale.</p>
        @if ($detail['calculations'] === [])
            <p class="mt-2 text-muted">Nenhum cálculo registrado.</p>
        @else
            <div class="pu-detail-scroll mt-2">
                <table class="pu-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">Versão</th>
                            <th scope="col">Situação</th>
                            <th scope="col" class="pu-num">Total esperado</th>
                            <th scope="col">Vencimento</th>
                            <th scope="col">Calculado em</th>
                            <th scope="col">Substituído</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($detail['calculations'] as $calculation)
                            <tr>
                                <th scope="row">{{ $calculation['version'] }}</th>
                                <td>
                                    @if ($calculation['current'])
                                        <x-filament::badge size="sm" color="success" icon="heroicon-m-check-badge">Vigente</x-filament::badge>
                                    @else
                                        <x-filament::badge size="sm" color="gray">Substituído</x-filament::badge>
                                    @endif
                                    @if (! $calculation['complete'])
                                        <span class="block text-muted">incompleto</span>
                                    @endif
                                </td>
                                <td class="pu-num">{{ $calculation['total'] ?? 'Indisponível' }}</td>
                                <td>{{ $calculation['due_date'] ?? '—' }}</td>
                                <td>{{ $calculation['calculated_at'] ?? '—' }}</td>
                                <td>{{ $calculation['superseded_at'] ?? '—' }}@if (filled($calculation['supersession_reason']))<span class="block text-muted">{{ $calculation['supersession_reason'] }}</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Livro de liquidações --}}
    <section class="pu-detail-card" aria-labelledby="pu-ledger">
        <h3 id="pu-ledger" class="pu-detail-card-title">Livro de liquidações</h3>
        <p class="pu-detail-hint">Só de inclusão: correção e estorno são lançamentos novos; o lançamento original continua aqui, com quem, quando e por quê.</p>
        @if ($detail['ledger'] === [])
            <p class="mt-2 text-muted">Nenhuma liquidação registrada.</p>
        @else
            <div class="pu-detail-scroll mt-2">
                <table class="pu-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Lançamento</th>
                            <th scope="col">Situação</th>
                            <th scope="col">Data da liquidação</th>
                            <th scope="col" class="pu-num">Valor</th>
                            <th scope="col">Origem</th>
                            <th scope="col">Registro</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($detail['ledger'] as $entry)
                            <tr>
                                <th scope="row">{{ $entry['id'] }}</th>
                                <td>
                                    {{ $entry['entry'] }}
                                    @if ($entry['predecessor_id'] !== null)
                                        <span class="block text-muted">substitui #{{ $entry['predecessor_id'] }}</span>
                                    @endif
                                    @if (filled($entry['reason']))
                                        <span class="block text-muted">Motivo: {{ $entry['reason'] }}</span>
                                    @endif
                                </td>
                                <td><x-filament::badge size="sm" :color="$entry['status_color']">{{ $entry['status'] }}</x-filament::badge></td>
                                <td>{{ $entry['date'] ?? '—' }}</td>
                                <td class="pu-num">{{ $entry['amount'] ?? '—' }}</td>
                                <td>{{ $entry['source'] ?? '—' }}@if (filled($entry['reference']))<span class="block text-muted">ref. {{ $entry['reference'] }}</span>@endif</td>
                                <td>{{ $entry['recorded_by'] ?? '—' }}<span class="block text-muted">{{ $entry['recorded_at'] ?? '—' }}{{ $entry['expected_version'] !== null ? ' · esperado '.$entry['expected_version'] : '' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($detail['conflicts'] !== [])
        <section class="pu-detail-card" aria-labelledby="pu-conflicts">
            <h3 id="pu-conflicts" class="pu-detail-card-title">Conflitos de liquidação</h3>
            <div class="pu-detail-scroll mt-2">
                <table class="pu-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Motivo</th>
                            <th scope="col">Situação</th>
                            <th scope="col">Dados que chegaram</th>
                            <th scope="col">Detectado em</th>
                            <th scope="col">Decisão</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($detail['conflicts'] as $conflict)
                            <tr>
                                <th scope="row">{{ $conflict['id'] }}</th>
                                <td>{{ $conflict['kind'] }}</td>
                                <td><x-filament::badge size="sm" :color="$conflict['status_color']">{{ $conflict['status'] }}</x-filament::badge></td>
                                <td class="pu-num">{{ $conflict['incoming_amount'] ?? '—' }}<span class="block text-muted">{{ $conflict['incoming_date'] ?? '—' }} · {{ $conflict['source'] ?? '—' }}{{ filled($conflict['reference']) ? ' · ref. '.$conflict['reference'] : '' }}</span></td>
                                <td>{{ $conflict['detected_at'] ?? '—' }}</td>
                                <td>{{ $conflict['resolved_at'] ?? 'Aguardando decisão' }}@if (filled($conflict['resolution_reason']))<span class="block text-muted">{{ $conflict['resolution_reason'] }}</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($detail['reconciliations'] !== [])
        <section class="pu-detail-card" aria-labelledby="pu-reconciliation-history">
            <h3 id="pu-reconciliation-history" class="pu-detail-card-title">Últimas avaliações da conciliação</h3>
            <div class="pu-detail-scroll mt-2">
                <table class="pu-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">Avaliada em</th>
                            <th scope="col">Situação</th>
                            <th scope="col" class="pu-num">Esperado</th>
                            <th scope="col" class="pu-num">Liquidado</th>
                            <th scope="col" class="pu-num">Diferença</th>
                            <th scope="col">Gatilho</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($detail['reconciliations'] as $row)
                            <tr>
                                <th scope="row">{{ $row['evaluated_at'] ?? '—' }}</th>
                                <td><x-filament::badge size="sm" :color="$row['status_color']">{{ $row['status'] }}</x-filament::badge></td>
                                <td class="pu-num">{{ $row['expected'] ?? 'Indisponível' }}</td>
                                <td class="pu-num">{{ $row['actual'] ?? \App\Support\PuCalculator\PuDisplay::NOT_SETTLED }}</td>
                                <td class="pu-num">{{ $row['difference'] ?? '—' }}</td>
                                <td class="text-muted">{{ $row['trigger'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
