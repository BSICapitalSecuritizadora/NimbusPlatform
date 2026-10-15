@php
    use App\Support\PuCalculator\PuDisplay;

    $overview = $this->overview();
    $official = $overview['official'];
    $activation = $overview['activation'];
    $indexer = $overview['indexer'];
    $obligations = $overview['obligations'];
    $refresh = array_filter($overview['refresh'], fn ($count) => (int) $count > 0);
@endphp

<x-filament-panels::page>
    <div class="pu-detail flex flex-col gap-6">
        {{-- Próxima ação --}}
        <section class="pu-ops-next" aria-labelledby="pu-next-action">
            <p class="pu-ops-card-label">Próxima ação</p>
            <h2 id="pu-next-action" class="pu-ops-next-title mt-1">{{ $overview['next_action']['title'] }}</h2>
            <p class="mt-1 text-secondary">{{ $overview['next_action']['text'] }}</p>
            <p class="mt-2 text-muted text-xs">Avaliado em {{ $overview['evaluated_at'] }}.</p>
        </section>

        {{-- Portões e situação --}}
        <div class="pu-ops-grid">
            <section class="pu-ops-card" aria-labelledby="pu-card-global">
                <p id="pu-card-global" class="pu-ops-card-label">Interruptor global do ambiente</p>
                <div>
                    <x-filament::badge :color="$overview['global']['enabled'] ? 'success' : 'gray'" :icon="$overview['global']['enabled'] ? 'heroicon-m-power' : 'heroicon-m-no-symbol'">{{ $overview['global']['label'] }}</x-filament::badge>
                </div>
                <p class="pu-ops-card-text">{{ $overview['global']['text'] }}</p>
            </section>

            <section class="pu-ops-card" aria-labelledby="pu-card-activation">
                <p id="pu-card-activation" class="pu-ops-card-label">Operação do PU desta emissão</p>
                <div>
                    <x-filament::badge :color="$activation['color']" :icon="$activation['icon']">{{ $activation['label'] }}</x-filament::badge>
                </div>
                @if ($activation['latest'] !== null)
                    <p class="pu-ops-card-text">Última decisão: {{ $activation['latest']['label'] }}, por {{ $activation['latest']['decided_by'] }} em {{ $activation['latest']['decided_at'] }}.</p>
                    <p class="pu-ops-card-text">Motivo: {{ $activation['latest']['reason'] }}</p>
                    @if (filled($activation['latest']['approval_reference']))
                        <p class="pu-ops-card-text">Aprovação: {{ $activation['latest']['approval_reference'] }}</p>
                    @endif
                @else
                    <p class="pu-ops-card-text">Nenhuma decisão registrada. Ter parâmetros ou estar "ativa" no cadastro não ativa a operação do PU.</p>
                @endif
            </section>

            <section class="pu-ops-card" aria-labelledby="pu-card-indexer">
                <p id="pu-card-indexer" class="pu-ops-card-label">Indexador e certificação</p>
                <p class="pu-ops-card-value">{{ $indexer['name'] }}</p>
                @if ($indexer['certification'] !== null)
                    <div>
                        <x-filament::badge :color="$indexer['color']" :icon="$indexer['official_use'] ? 'heroicon-m-shield-check' : 'heroicon-m-beaker'">{{ $indexer['certification'] }}</x-filament::badge>
                    </div>
                    <p class="pu-ops-card-text">{{ $indexer['official_use'] ? 'Pode chegar ao PU oficial, respeitadas as condições abaixo.' : 'Somente simulação: não gera curva oficial, não homologa e não pode ser ativado.' }}</p>
                    @if ($indexer['conditions'] !== [])
                        <details class="pu-ops-details">
                            <summary>{{ $indexer['official_use'] ? 'Condições de implantação' : 'Por que não é certificado' }} ({{ count($indexer['conditions']) }})</summary>
                            <ul class="pu-ops-list">
                                @foreach ($indexer['conditions'] as $condition)
                                    <li>{{ $condition }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                @endif
            </section>

            <section class="pu-ops-card" aria-labelledby="pu-card-official">
                <p id="pu-card-official" class="pu-ops-card-label">Curva oficial</p>
                @if ($official['exists'])
                    <p class="pu-ops-card-value">Versão {{ $official['version'] }} <span class="text-muted">· homologada em {{ $official['homologated_at'] ?? '—' }}</span></p>
                    <div>
                        <x-filament::badge :color="$official['freshness_color']" :icon="$official['freshness_icon']">{{ $official['freshness_label'] }}</x-filament::badge>
                    </div>
                    @if ($official['pu'] !== null)
                        <p class="pu-ops-card-text">
                            PU oficial <strong class="pu-num">{{ $official['pu'] }}</strong> — posição em <strong>{{ $official['pu_date'] }}</strong>.
                            @if ($official['pu_carried_forward'] && $official['pu_stands_for_today'])
                                Valor da última posição calculada, válido para {{ $official['today'] }}: o índice de hoje ainda não era esperado.
                            @elseif ($official['pu_carried_forward'])
                                Valor carregado de {{ $official['pu_date'] }}: a curva não está em dia, então ele NÃO responde por {{ $official['today'] }}.
                            @endif
                        </p>
                    @else
                        <p class="pu-ops-card-text">PU oficial indisponível para {{ $official['today'] }}: {{ mb_strtolower($official['freshness_label']) }}. Nenhum valor é mostrado no lugar.</p>
                    @endif
                    <p class="pu-ops-card-text">Realizada até {{ $official['realized_through'] ?? '—' }}@if ($official['expected_realized_through'] !== null) (esperado: {{ $official['expected_realized_through'] }})@endif · fim da curva {{ $official['curve_end'] ?? '—' }}.</p>
                    @if ($official['reprocessing_from'] !== null)
                        <p class="pu-ops-card-text">Reprocessamento necessário a partir de {{ $official['reprocessing_from'] }}.</p>
                    @endif
                    @if ($official['contractual_change_from'] !== null)
                        <p class="pu-ops-card-text">Mudança contratual não homologada a partir de {{ $official['contractual_change_from'] }}: nova versão necessária.</p>
                    @endif
                    @if (filled($official['freshness_reason']))
                        <p class="pu-ops-card-text">{{ $official['freshness_reason'] }}</p>
                    @endif
                @else
                    <p class="pu-ops-card-value">Sem curva oficial</p>
                    <p class="pu-ops-card-text">Nenhuma versão homologada. Versões geradas ou validadas são candidatas e não valem como PU oficial.</p>
                @endif
            </section>

            <section class="pu-ops-card" aria-labelledby="pu-card-attempt">
                <p id="pu-card-attempt" class="pu-ops-card-label">Última versão de trabalho (não oficial)</p>
                @if ($overview['latest_attempt'] !== null)
                    <p class="pu-ops-card-value">Versão {{ $overview['latest_attempt']['version'] }}</p>
                    <div>
                        <x-filament::badge :color="$overview['latest_attempt']['color']">{{ $overview['latest_attempt']['status'] }} — candidata</x-filament::badge>
                    </div>
                    <p class="pu-ops-card-text">Gerada em {{ $overview['latest_attempt']['generated_at'] ?? '—' }} por {{ $overview['latest_attempt']['generated_by'] }}. Só vira oficial pela homologação maker/checker.</p>
                    @if (filled($overview['latest_attempt']['error']))
                        <p class="pu-ops-card-text">Erro: {{ $overview['latest_attempt']['error'] }}</p>
                    @endif
                @else
                    <p class="pu-ops-card-text">Nenhuma versão além da oficial.</p>
                @endif
            </section>

            <section class="pu-ops-card" aria-labelledby="pu-card-obligations">
                <p id="pu-card-obligations" class="pu-ops-card-label">Obrigações e conciliação</p>
                @if (! $overview['health_available'])
                    <p class="pu-ops-card-text">Não avaliado: o retrato operacional falhou agora.</p>
                @elseif ($obligations === [] || array_sum(array_map('intval', $obligations)) === 0)
                    <p class="pu-ops-card-text">Nenhuma obrigação oficial: elas nascem da primeira curva homologada.</p>
                @else
                    <ul class="pu-ops-list">
                        @foreach ($obligations as $key => $count)
                            @if ((int) $count > 0)
                                <li>{{ PuDisplay::obligationCountLabel((string) $key) }}: <strong>{{ $count }}</strong></li>
                            @endif
                        @endforeach
                    </ul>
                @endif
                @can(\App\Enums\AccessPermission::PuReconciliationView->value)
                    <p class="mt-1"><x-filament::link :href="$this->obligationsUrl()" icon="heroicon-m-arrow-top-right-on-square">Abrir obrigações e liquidações</x-filament::link></p>
                @endcan
            </section>

            <section class="pu-ops-card" aria-labelledby="pu-card-refresh">
                <p id="pu-card-refresh" class="pu-ops-card-label">Atualização das obrigações</p>
                @if (! $overview['health_available'])
                    <p class="pu-ops-card-text">Não avaliado: o retrato operacional falhou agora.</p>
                @elseif ($refresh === [])
                    <p class="pu-ops-card-text">Nenhum pedido aberto.</p>
                @else
                    <ul class="pu-ops-list">
                        @foreach ($refresh as $status => $count)
                            <li>{{ \App\Domain\PuCalculator\Enums\PuObligationRefreshStatus::tryFrom((string) $status)?->label() ?? $status }}: <strong>{{ $count }}</strong></li>
                        @endforeach
                    </ul>
                    <p class="pu-ops-card-text">Esgotados ou bloqueados só voltam por retomada autorizada (Mais ações).</p>
                @endif
            </section>
        </div>

        {{-- Condições do monitor --}}
        <section class="pu-detail-card" aria-labelledby="pu-conditions">
            <h2 id="pu-conditions" class="pu-detail-card-title">Condições operacionais (retrato do monitor)</h2>
            @if (! $overview['health_available'])
                <p class="mt-2 text-secondary">Não foi possível montar o retrato operacional agora. Isto não significa operação saudável.</p>
            @elseif (! $overview['configured'])
                <p class="mt-2 text-secondary">Emissão sem parâmetros de PU: não é acompanhada.</p>
            @elseif ($overview['conditions'] === [])
                <p class="mt-2 text-secondary">O monitor não encontrou condição para esta emissão no momento da avaliação.</p>
            @else
                <div class="mt-2">
                    @foreach ($overview['conditions'] as $condition)
                        <div class="pu-check">
                            <span class="pu-check-label">{{ $condition['label'] }}</span>
                            <x-filament::badge :color="$condition['color']" :icon="$condition['icon']">{{ $condition['severity_label'] }}</x-filament::badge>
                            <p class="pu-check-details">{{ $condition['reason'] }}</p>
                            <p class="pu-check-details"><strong>O que fazer:</strong> {{ $condition['guidance'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Incidentes abertos --}}
        <section class="pu-detail-card" aria-labelledby="pu-incidents">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="pu-incidents" class="pu-detail-card-title">Incidentes abertos desta emissão</h2>
                @if (\App\Filament\Pages\PuOperationalIncidents::canAccess())
                    <x-filament::link :href="\App\Filament\Pages\PuOperationalIncidents::getUrl()" icon="heroicon-m-arrow-top-right-on-square">Todos os incidentes do PU</x-filament::link>
                @endif
            </div>
            @if ($overview['incidents']->isEmpty())
                <p class="mt-2 text-secondary">Nenhum incidente aberto.</p>
            @else
                <div class="pu-detail-scroll mt-2">
                    <table class="pu-detail-table">
                        <thead>
                            <tr>
                                <th scope="col">Severidade</th>
                                <th scope="col">Incidente</th>
                                <th scope="col">Situação</th>
                                <th scope="col">Última detecção</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($overview['incidents'] as $incident)
                                <tr>
                                    <td class="pu-nowrap"><x-filament::badge size="sm" :color="PuDisplay::severityColor($incident->severity)" :icon="PuDisplay::severityIcon($incident->severity)">{{ $incident->severity->label() }}</x-filament::badge></td>
                                    <th scope="row">{{ $incident->type->label() }}<span class="block font-normal text-muted">{{ \Illuminate\Support\Str::limit($incident->reason, 200) }}</span></th>
                                    <td class="pu-nowrap"><x-filament::badge size="sm" :color="PuDisplay::incidentStatusColor($incident->status)">{{ $incident->status->label() }}</x-filament::badge></td>
                                    <td>{{ PuDisplay::dateTime($incident->last_detected_at) ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Livro de ativação --}}
        <section class="pu-detail-card" aria-labelledby="pu-ledger">
            <h2 id="pu-ledger" class="pu-detail-card-title">Livro de ativação</h2>
            <p class="pu-detail-hint">Só de inclusão: cada ativação ou suspensão é uma decisão nova, com quem, quando, por quê e a aprovação.</p>
            @if ($overview['ledger']->isEmpty())
                <p class="mt-2 text-secondary">Nenhuma decisão registrada: a operação do PU desta emissão nunca foi ativada.</p>
            @else
                <div class="pu-detail-scroll mt-2">
                    <table class="pu-detail-table">
                        <thead>
                            <tr>
                                <th scope="col">Decisão</th>
                                <th scope="col">Quando</th>
                                <th scope="col">Quem</th>
                                <th scope="col">Motivo</th>
                                <th scope="col">Aprovação</th>
                                <th scope="col">Prontidão no momento</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($overview['ledger'] as $change)
                                <tr>
                                    <th scope="row"><x-filament::badge size="sm" :color="PuDisplay::activationColor($change->status)" :icon="PuDisplay::activationIcon($change->status)">{{ $change->status->label() }}</x-filament::badge></th>
                                    <td>{{ PuDisplay::dateTime($change->decided_at) ?? '—' }}</td>
                                    <td>{{ $change->decidedBy?->name ?? '—' }}<span class="block text-muted">via {{ $change->decided_via }}</span></td>
                                    <td>{{ $change->reason }}</td>
                                    <td>{{ $change->approval_reference ?? '—' }}</td>
                                    <td>{{ \App\Domain\PuCalculator\Enums\PuOperationalReadinessStatus::tryFrom((string) $change->readiness_status)?->label() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
