<x-filament-panels::page>
    @php
        $report = $this->result();
        $rows = $this->rows();
        $detail = $this->detail();
        $detailUserNames = $detail ? $this->detailUserNames() : [];
        $detailDecisionCount = $detail ? collect($detail->events)->whereIn('eventType', [
            \App\Enums\MeasurementCycleEventType::StageApproved,
            \App\Enums\MeasurementCycleEventType::StageRejected,
        ])->count() : 0;
        $detailReceiptCount = $detail ? collect($detail->events)->whereIn('eventType', [
            \App\Enums\MeasurementCycleEventType::ReceiptAttached,
            \App\Enums\MeasurementCycleEventType::ReceiptDeleted,
        ])->count() : 0;
        $detailFinalizationCount = $detail ? collect($detail->events)->whereIn('eventType', [
            \App\Enums\MeasurementCycleEventType::FinalizationReturned,
            \App\Enums\MeasurementCycleEventType::Finalized,
        ])->count() : 0;
        $approvalDrilldownAvailable = in_array($decisionType, ['', 'approved'], true)
            && in_array($completeness, ['', 'complete'], true);
        $finalizationReturnDrilldownAvailable = in_array($decisionType, ['', 'returned_from_finalization'], true)
            && in_array($completeness, ['', 'complete'], true);
        $completeLabel = fn (string $value): string => match ($value) {
            'complete' => 'Completa',
            'partial' => 'Parcial',
            'insufficient' => 'Insuficiente',
            default => 'N/D',
        };
    @endphp

    <div class="space-y-6" wire:loading.class="opacity-60">
        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Filtros históricos</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        O período recorta pela saída da visita. A entrada e as durações continuam representando a visita histórica integral.
                    </p>
                </div>

                @if ($this->hasFilters())
                    <button type="button" wire:click="clearFilters" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/15 dark:text-gray-200 dark:hover:bg-white/5">
                        Limpar filtros
                    </button>
                @endif
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Saída desde</span>
                    <input type="date" wire:model.live="periodFrom" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950" />
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Saída até</span>
                    <input type="date" wire:model.live="periodTo" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950" />
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Emissão</span>
                    <select wire:model.live="emissionId" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950">
                        <option value="">Todas</option>
                        @foreach ($report->emissionOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Operação</span>
                    <select wire:model.live="operationId" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950">
                        <option value="">Todas</option>
                        @foreach ($report->operationOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Medição</span>
                    <select wire:model.live="measurementId" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950">
                        <option value="">Todas</option>
                        @foreach ($report->measurementOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Etapa</span>
                    <select wire:model.live="stage" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950">
                        <option value="">Todas</option>
                        @foreach (range(1, 5) as $stageOption)
                            <option value="{{ $stageOption }}">Etapa {{ $stageOption }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Decisão</span>
                    <select wire:model.live="decisionType" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950">
                        <option value="">Todas</option>
                        @foreach ($this->decisionOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Actor histórico</span>
                    <select wire:model.live="actorId" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950">
                        <option value="">Todos</option>
                        @foreach ($report->actorOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Responsável esperado</span>
                    <select wire:model.live="expectedResponsibleId" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950">
                        <option value="">Todos</option>
                        @foreach ($report->responsibleOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                    <span>Cobertura</span>
                    <select wire:model.live="completeness" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/15 dark:bg-gray-950">
                        <option value="">Todas</option>
                        @foreach ($this->completenessOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if ($this->exportUrl('xlsx') || $this->exportUrl('csv'))
                <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4 dark:border-white/10">
                    @if ($measurementId)
                        <button type="button" wire:click="showDetail({{ $measurementId }})" class="rounded-lg border border-primary-300 px-3 py-2 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-500/40 dark:text-primary-300 dark:hover:bg-primary-500/10">
                            Abrir histórico da medição
                        </button>
                    @endif
                    @if ($url = $this->exportUrl('csv'))
                        <a href="{{ $url }}" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/15 dark:text-gray-200 dark:hover:bg-white/5">Exportar CSV</a>
                    @endif
                    @if ($url = $this->exportUrl('xlsx'))
                        <a href="{{ $url }}" class="rounded-lg bg-primary-600 px-3 py-2 text-xs font-semibold text-white hover:bg-primary-500">Exportar XLSX</a>
                    @endif
                </div>
            @elseif ($measurementId)
                <div class="mt-4 flex justify-end border-t border-gray-100 pt-4 dark:border-white/10">
                    <button type="button" wire:click="showDetail({{ $measurementId }})" class="rounded-lg border border-primary-300 px-3 py-2 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-500/40 dark:text-primary-300 dark:hover:bg-primary-500/10">
                        Abrir histórico da medição
                    </button>
                </div>
            @endif
        </section>

        <section aria-label="Resumo histórico" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <button type="button" wire:click="applyMetricDrilldown('approved')" @disabled(! $approvalDrilldownAvailable) class="rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm enabled:hover:border-primary-400 disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/10 dark:bg-gray-900">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Aprovações elegíveis</span>
                <strong class="mt-2 block text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report->summary->approvals, 0, ',', '.') }}</strong>
            </button>
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm dark:border-white/10 dark:bg-gray-900">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Rejeições elegíveis</span>
                <strong class="mt-2 block text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report->summary->rejections, 0, ',', '.') }}</strong>
                <span class="text-xs text-gray-500">Taxa {{ $this->rate($report->summary->rejectionRate) }}</span>
            </div>
            <button type="button" wire:click="applyMetricDrilldown('returned_from_finalization')" @disabled(! $finalizationReturnDrilldownAvailable) class="rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm enabled:hover:border-primary-400 disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/10 dark:bg-gray-900">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Retornos da finalização</span>
                <strong class="mt-2 block text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($report->summary->finalizationReturns, 0, ',', '.') }}</strong>
                <span class="text-xs text-gray-500">Taxa {{ $this->rate($report->summary->finalizationReturnRate) }} · retornos / (retornos + finalizações)</span>
            </button>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Ciclo completo elegível</span>
                <strong class="mt-2 block text-lg font-semibold text-gray-950 dark:text-white">Mediana {{ $this->duration($report->summary->medianCycleDuration) }}</strong>
                <span class="text-xs text-gray-500">Média {{ $this->duration($report->summary->averageCycleDuration) }} · {{ $report->summary->eligibleCycles }} ciclos</span>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Métricas por etapa</h2>
                    <p class="mt-1 text-xs text-gray-500">Taxas e durações usam apenas decisões fechadas com cobertura completa. Rejeição = rejeições / (aprovações + rejeições).</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">Etapa</th>
                                <th class="px-4 py-3">Decisões</th>
                                <th class="px-4 py-3">Aprovações</th>
                                <th class="px-4 py-3">Rejeições</th>
                                <th class="px-4 py-3">Taxa rejeição</th>
                                <th class="px-4 py-3">Calendário média / mediana</th>
                                <th class="px-4 py-3">Líquida média / mediana</th>
                                <th class="px-4 py-3">Pausas</th>
                                <th class="px-4 py-3">Finalizações / devoluções</th>
                                <th class="px-4 py-3">Base / excluídas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                            @foreach ($report->stageMetrics as $metric)
                                <tr wire:key="stage-metric-{{ $metric->stage }}">
                                    <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                                        Etapa {{ $metric->stage }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $metric->decisions }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $metric->approvals }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $metric->rejections }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $this->rate($metric->rejectionRate) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">{{ $this->duration($metric->averageCalendarDuration) }} / {{ $this->duration($metric->medianCalendarDuration) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">{{ $this->duration($metric->averageActiveDuration) }} / {{ $this->duration($metric->medianActiveDuration) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">Total {{ $this->duration($metric->pausedDurationTotal) }}<br />Média {{ $this->duration($metric->averagePausedDuration) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">{{ $metric->finalizations }} / {{ $metric->finalizationReturns }}<br />Taxa {{ $this->rate($metric->finalizationReturnRate) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">
                                        {{ $metric->completeCohort }} completas<br />
                                        {{ $metric->partialExcluded }} parciais · {{ $metric->insufficientExcluded }} insuficientes
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Cobertura histórica</h2>
                <p class="mt-1 text-xs text-gray-500">A cobertura é exibida, nunca convertida silenciosamente em zero.</p>
                <div class="mt-4 space-y-2">
                    @foreach (['complete' => $report->coverage->complete, 'partial' => $report->coverage->partial, 'insufficient' => $report->coverage->insufficient] as $level => $count)
                        <div class="flex w-full items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
                            <span class="text-gray-700 dark:text-gray-300">{{ $completeLabel($level) }}</span>
                            <strong class="text-gray-950 dark:text-white">{{ $count }}</strong>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 border-t border-gray-100 pt-4 text-xs text-gray-500 dark:border-white/10">
                    Visitas elegíveis: {{ $report->summary->eligibleStageVisits }}<br />
                    Tempo total em pausa: {{ $this->duration($report->summary->pausedDurationTotal) }}
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Visitas históricas</h2>
                <p class="mt-1 text-xs text-gray-500">{{ number_format($report->totalRows, 0, ',', '.') }} registros no recorte. Linhas parciais e insuficientes permanecem visíveis e não entram nas métricas elegíveis.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">Medição</th>
                            <th class="px-4 py-3">Etapa / visita</th>
                            <th class="px-4 py-3">Entrada / saída</th>
                            <th class="px-4 py-3">Decisão</th>
                            <th class="px-4 py-3">Durações</th>
                            <th class="px-4 py-3">Actor / responsável</th>
                            <th class="px-4 py-3">Cobertura</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @forelse ($rows as $row)
                            <tr wire:key="visit-{{ $row->measurementId }}-{{ $row->stage }}-{{ $row->sequence }}" class="align-top">
                                <td class="px-4 py-3">
                                    <button type="button" wire:click="showDetail({{ $row->measurementId }})" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $row->measurementLabel }}</button>
                                    <div class="mt-1 max-w-64 text-xs text-gray-500">{{ $row->operationLabel }} · {{ $row->emissionLabel }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">Etapa {{ $row->stage }} · #{{ $row->sequence }}</td>
                                <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">
                                    {{ $row->enteredAt?->format('d/m/Y H:i:s') ?? 'N/D' }}<br />
                                    {{ $row->exitedAt?->format('d/m/Y H:i:s') ?? 'Em aberto' }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $this->exitReason($row->exitReason) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">
                                    Calendário: {{ $this->duration($row->calendarDuration) }}<br />
                                    Pausa: {{ $this->duration($row->pausedDuration) }}<br />
                                    Líquida: {{ $this->duration($row->activeDuration) }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">
                                    Actor: {{ $row->actorName ?? 'N/D' }}<br />
                                    Esperado: {{ $row->expectedResponsibleName ?? 'N/D' }}<br />
                                    Delegado: {{ $this->triState($row->delegated) }} · Override: {{ $this->triState($row->adminOverride) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ $completeLabel($row->completeness->value) }}</span>
                                    @if ($row->missingReasons !== [])
                                        <div class="mt-2 max-w-72 text-xs text-amber-700 dark:text-amber-400">{{ collect($row->missingReasons)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-gray-500">Nenhuma visita histórica encontrada para o recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <div class="border-t border-gray-100 px-4 py-3 dark:border-white/10">{{ $rows->links() }}</div>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Carga operacional atual</h2>
                <p class="mt-1 text-xs text-gray-500">Snapshot atual, separado das métricas históricas. Período, decisão, actor histórico e cobertura não se aplicam a esta seção. Delegações efetivas não são inferidas novamente: quando indisponíveis, aparecem como N/D.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr><th class="px-4 py-3">Responsável permanente</th><th class="px-4 py-3">Responsabilidade</th><th class="px-4 py-3">Etapa</th><th class="px-4 py-3">Pendentes</th><th class="px-4 py-3">Em atraso</th><th class="px-4 py-3">Delegados</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @forelse ($report->workload as $workload)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $workload->responsibleName }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $workload->responsibility->label() }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $workload->stage }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $workload->pendingCount }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $workload->overdueCount }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $workload->delegatedCount ?? 'N/D' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">Nenhuma pendência atual visível no recorte estrutural.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($detail)
            <section class="rounded-xl border border-primary-200 bg-white shadow-sm dark:border-primary-500/30 dark:bg-gray-900">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-4 dark:border-white/10">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Histórico canônico · {{ $detail->measurementLabel ?? 'Medição #'.$detail->measurementId }}</h2>
                        <p class="mt-1 text-xs text-gray-500">Status atual {{ $detail->currentStatus }} · etapa {{ $detail->currentStage }} · cobertura {{ $completeLabel($detail->completeness->value) }}</p>
                    </div>
                    <button type="button" wire:click="closeDetail" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 dark:border-white/15 dark:text-gray-200">Fechar detalhe</button>
                </div>

                <div class="grid gap-4 p-4 lg:grid-cols-3">
                    <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                        <span class="text-xs text-gray-500">Início do ciclo</span>
                        <strong class="mt-1 block text-gray-950 dark:text-white">{{ $detail->cycleStart?->format('d/m/Y H:i:s') ?? 'N/D' }}</strong>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                        <span class="text-xs text-gray-500">Fim do ciclo</span>
                        <strong class="mt-1 block text-gray-950 dark:text-white">{{ $detail->cycleEnd?->format('d/m/Y H:i:s') ?? 'N/D' }}</strong>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                        <span class="text-xs text-gray-500">Motivo terminal</span>
                        <strong class="mt-1 block text-gray-950 dark:text-white">{{ $detail->terminalReason ? $this->exitReason($detail->terminalReason) : 'N/D' }}</strong>
                    </div>
                </div>

                <div class="mx-4 mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                    @if ($detail->completeness->value === 'partial')
                        Parte do histórico está disponível, mas algumas informações da época não foram registradas.
                    @elseif ($detail->completeness->value === 'insufficient')
                        Não há evidência histórica suficiente para reconstruir este trecho com segurança.
                    @else
                        A evidência necessária para reconstruir o ciclo está disponível no recorte canônico.
                    @endif
                </div>

                <div class="mx-4 mb-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                        <span class="text-gray-500">Decisões de etapa</span>
                        <strong class="mt-1 block text-lg text-gray-950 dark:text-white">{{ $detailDecisionCount }}</strong>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                        <span class="text-gray-500">Ações de comprovante</span>
                        <strong class="mt-1 block text-lg text-gray-950 dark:text-white">{{ $detailReceiptCount }}</strong>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                        <span class="text-gray-500">Decisões de finalização</span>
                        <strong class="mt-1 block text-lg text-gray-950 dark:text-white">{{ $detailFinalizationCount }}</strong>
                    </div>
                </div>

                @if ($detail->warnings !== [])
                    <div class="mx-4 mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                        <strong>Limitações históricas:</strong> {{ collect($detail->warnings)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}
                    </div>
                @endif

                <div class="grid gap-5 border-t border-gray-100 p-4 dark:border-white/10 xl:grid-cols-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Timeline normalizada</h3>
                        <p class="mt-1 text-xs text-gray-500">A auditoria técnica mostra somente fonte, identificador e revisão; campos técnicos brutos não são serializados.</p>
                        <ol class="mt-3 space-y-3">
                            @foreach ($detail->events as $event)
                                <li class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                                    <div class="flex flex-wrap justify-between gap-2">
                                        <strong class="text-gray-950 dark:text-white">{{ $this->eventType($event->eventType) }}</strong>
                                        <span class="text-gray-500">{{ $event->occurredAt?->format('d/m/Y H:i:s') ?? 'Data N/D' }}</span>
                                    </div>
                                    <div class="mt-2 text-gray-600 dark:text-gray-300">
                                        Etapa {{ $event->stageBefore ?? 'N/D' }} → {{ $event->stageAfter ?? 'N/D' }} ·
                                        {{ $event->statusBefore ?? 'N/D' }} → {{ $event->statusAfter ?? 'N/D' }} ·
                                        actor {{ $event->actorId ? ($detailUserNames[$event->actorId] ?? 'N/D') : 'N/D' }} ·
                                        responsável esperado {{ $event->expectedResponsibleId ? ($detailUserNames[$event->expectedResponsibleId] ?? 'N/D') : 'N/D' }} ·
                                        delegado {{ $this->triState($event->delegated) }} ·
                                        delegante {{ $event->delegatorId ? ($detailUserNames[$event->delegatorId] ?? 'N/D') : 'N/D' }} ·
                                        override {{ $this->triState($event->adminOverride) }} ·
                                        cobertura {{ $completeLabel($event->completeness->value) }}
                                    </div>
                                    <div class="mt-2 text-gray-500">
                                        Fonte: {{ $this->sourceType($event->sourceType) }} · Activity {{ $event->sourceActivityId ?? 'N/D' }} · revisão {{ $event->workflowRevision ?? 'N/D' }}
                                    </div>
                                    @if ($event->missingReasons !== [])
                                        <div class="mt-2 text-amber-700 dark:text-amber-400">{{ collect($event->missingReasons)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <div class="space-y-5">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Visitas de etapa</h3>
                            <div class="mt-3 space-y-2">
                                @foreach ($detail->stageVisits as $visit)
                                    <div class="rounded-lg border border-gray-200 p-3 text-xs text-gray-600 dark:border-white/10 dark:text-gray-300">
                                        <strong class="text-gray-950 dark:text-white">Etapa {{ $visit->stage }} · visita #{{ $visit->sequence }} · {{ $this->exitReason($visit->exitReason) }}</strong>
                                        <div class="mt-2">{{ $visit->enteredAt?->format('d/m/Y H:i:s') ?? 'N/D' }} → {{ $visit->exitedAt?->format('d/m/Y H:i:s') ?? 'Em aberto' }}</div>
                                        <div>Calendário {{ $this->duration($visit->calendarDuration) }} · pausa {{ $this->duration($visit->pausedDuration) }} · líquida {{ $this->duration($visit->activeDuration) }}</div>
                                        <div>Actor {{ $visit->exitActorId ? ($detailUserNames[$visit->exitActorId] ?? 'N/D') : 'N/D' }} · responsável esperado {{ $visit->expectedResponsibleId ? ($detailUserNames[$visit->expectedResponsibleId] ?? 'N/D') : 'N/D' }}</div>
                                        <div>Delegado {{ $this->triState($visit->delegated) }} · delegante {{ $visit->delegatorId ? ($detailUserNames[$visit->delegatorId] ?? 'N/D') : 'N/D' }} · override {{ $this->triState($visit->adminOverride) }} · cobertura {{ $completeLabel($visit->completeness->value) }}</div>
                                        @if ($visit->missingReasons !== [])
                                            <div class="mt-2 text-amber-700 dark:text-amber-400">{{ collect($visit->missingReasons)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Pausas</h3>
                            <div class="mt-3 space-y-2">
                                @forelse ($detail->pauses as $pause)
                                    <div class="rounded-lg border border-gray-200 p-3 text-xs text-gray-600 dark:border-white/10 dark:text-gray-300">
                                        Etapa {{ $pause->stage }} · {{ $pause->pausedAt?->format('d/m/Y H:i:s') ?? 'N/D' }} → {{ $pause->resumedAt?->format('d/m/Y H:i:s') ?? 'Em aberto' }}<br />
                                        Pausado por {{ $pause->pausedById ? ($detailUserNames[$pause->pausedById] ?? 'N/D') : 'N/D' }} · retomado por {{ $pause->resumedById ? ($detailUserNames[$pause->resumedById] ?? 'N/D') : 'N/D' }} · motivo {{ $pause->reason ?: 'N/D' }} · cobertura {{ $completeLabel($pause->completeness->value) }}
                                        @if ($pause->missingReasons !== [])
                                            <div class="mt-2 text-amber-700 dark:text-amber-400">{{ collect($pause->missingReasons)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}</div>
                                        @endif
                                    </div>
                                @empty
                                    <p class="mt-2 text-xs text-gray-500">Nenhuma pausa histórica.</p>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Pagamentos</h3>
                            <div class="mt-3 space-y-2">
                                @forelse ($detail->payments as $payment)
                                    <div class="rounded-lg border border-gray-200 p-3 text-xs text-gray-600 dark:border-white/10 dark:text-gray-300">
                                        <strong class="text-gray-950 dark:text-white">R$ {{ number_format((float) $payment->amount, 2, ',', '.') }}</strong> · pagamento {{ $payment->payDate?->format('d/m/Y') ?? 'N/D' }}<br />
                                        Método {{ $payment->method ?: 'N/D' }} · registrado por {{ $payment->createdById ? ($detailUserNames[$payment->createdById] ?? 'N/D') : 'N/D' }} · em {{ $payment->createdAt?->format('d/m/Y H:i:s') ?? 'N/D' }}
                                    </div>
                                @empty
                                    <p class="mt-2 text-xs text-gray-500">Nenhum pagamento histórico.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
