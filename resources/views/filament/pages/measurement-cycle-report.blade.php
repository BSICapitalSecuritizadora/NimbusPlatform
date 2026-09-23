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
        $exitBadge = fn (\App\Enums\MeasurementStageExitReason $reason): string => match ($reason) {
            \App\Enums\MeasurementStageExitReason::Approved,
            \App\Enums\MeasurementStageExitReason::Finalized => 'bsi-status-badge bsi-status-success',
            \App\Enums\MeasurementStageExitReason::ReturnedByRejection,
            \App\Enums\MeasurementStageExitReason::ReturnedFromFinalization => 'bsi-status-badge bsi-status-warning',
            \App\Enums\MeasurementStageExitReason::RejectedTerminal => 'bsi-status-badge bsi-status-danger',
            default => 'bsi-status-badge bsi-status-default',
        };
        $coverageBadge = fn (string $value): string => match ($value) {
            'complete' => 'bsi-status-badge bsi-status-success',
            'partial' => 'bsi-status-badge bsi-status-warning',
            default => 'bsi-status-badge bsi-status-default',
        };
    @endphp

    <div class="space-y-5" wire:loading.class="opacity-60">
        <section class="mcr-card" aria-label="Filtros históricos">
            <div class="mcr-card-head">
                <div>
                    <h2>Filtros históricos</h2>
                    <p>
                        O período recorta pela saída da visita. A entrada e as durações continuam representando a visita histórica integral.
                    </p>
                </div>

                @if ($this->hasFilters())
                    <div class="mcr-card-head-actions">
                        <button type="button" wire:click="clearFilters" class="mcr-btn mcr-btn--ghost">
                            Limpar filtros
                        </button>
                    </div>
                @endif
            </div>

            <div class="mcr-filters grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <div
                    class="mcr-field mcr-datepicker-field"
                    x-data="mcrDatePicker('periodFrom')"
                    @click.outside="close()"
                    @keydown.escape.window="close()"
                >
                    <span>Saída desde</span>
                    <div class="mcr-datepicker-trigger">
                        <input
                            type="text"
                            x-ref="input"
                            x-model="displayValue"
                            @input="onInput($event)"
                            @focus="openPanel()"
                            @click="openPanel()"
                            placeholder="dd/mm/aaaa"
                            maxlength="10"
                            class="mcr-input mcr-datepicker-input"
                            aria-label="Saída desde"
                            autocomplete="off"
                        />
                        <button
                            type="button"
                            @click.stop="togglePanel()"
                            class="mcr-datepicker-btn-icon"
                            aria-label="Abrir calendário para Saída desde"
                            tabindex="-1"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                            </svg>
                        </button>
                    </div>

                    {{-- Dropdown / Painel Institucional --}}
                    <div
                        x-ref="panel"
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="mcr-datepicker-panel"
                        :class="{ 'align-right': alignRight }"
                        style="display: none;"
                    >
                        {{-- Cabeçalho do Calendário --}}
                        <div class="mcr-datepicker-header">
                            <span class="mcr-datepicker-title" x-text="monthName + ' ' + focusedYear"></span>
                            <div class="mcr-datepicker-nav">
                                <button
                                    type="button"
                                    @click.stop="prevMonth()"
                                    class="mcr-datepicker-nav-btn"
                                    aria-label="Mês anterior"
                                    title="Mês anterior"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    @click.stop="nextMonth()"
                                    class="mcr-datepicker-nav-btn"
                                    aria-label="Próximo mês"
                                    title="Próximo mês"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Dias da Semana --}}
                        <div class="mcr-datepicker-weekdays">
                            <span class="mcr-datepicker-weekday" title="Domingo">Dom</span>
                            <span class="mcr-datepicker-weekday" title="Segunda-feira">Seg</span>
                            <span class="mcr-datepicker-weekday" title="Terça-feira">Ter</span>
                            <span class="mcr-datepicker-weekday" title="Quarta-feira">Qua</span>
                            <span class="mcr-datepicker-weekday" title="Quinta-feira">Qui</span>
                            <span class="mcr-datepicker-weekday" title="Sexta-feira">Sex</span>
                            <span class="mcr-datepicker-weekday" title="Sábado">Sáb</span>
                        </div>

                        {{-- Grade de Dias do Mês --}}
                        <div class="mcr-datepicker-grid" role="grid">
                            <template x-for="item in calendarDays" :key="item.iso">
                                <button
                                    type="button"
                                    @click.stop="selectDate(item.iso)"
                                    class="mcr-datepicker-day"
                                    :class="{
                                        'is-selected': isSelected(item.iso),
                                        'is-today': isToday(item.iso),
                                        'is-outside': !item.isCurrentMonth,
                                        'is-in-range': isInRange(item.iso)
                                    }"
                                    :aria-selected="isSelected(item.iso)"
                                    x-text="item.day"
                                ></button>
                            </template>
                        </div>

                        {{-- Rodapé: Limpar e Hoje --}}
                        <div class="mcr-datepicker-footer">
                            <button
                                type="button"
                                @click.stop="clearDate()"
                                class="mcr-datepicker-action mcr-datepicker-action-clear"
                            >
                                Limpar
                            </button>
                            <button
                                type="button"
                                @click.stop="selectToday()"
                                class="mcr-datepicker-action mcr-datepicker-action-today"
                            >
                                Hoje
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    class="mcr-field mcr-datepicker-field"
                    x-data="mcrDatePicker('periodTo')"
                    @click.outside="close()"
                    @keydown.escape.window="close()"
                >
                    <span>Saída até</span>
                    <div class="mcr-datepicker-trigger">
                        <input
                            type="text"
                            x-ref="input"
                            x-model="displayValue"
                            @input="onInput($event)"
                            @focus="openPanel()"
                            @click="openPanel()"
                            placeholder="dd/mm/aaaa"
                            maxlength="10"
                            class="mcr-input mcr-datepicker-input"
                            aria-label="Saída até"
                            autocomplete="off"
                        />
                        <button
                            type="button"
                            @click.stop="togglePanel()"
                            class="mcr-datepicker-btn-icon"
                            aria-label="Abrir calendário para Saída até"
                            tabindex="-1"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                            </svg>
                        </button>
                    </div>

                    {{-- Dropdown / Painel Institucional --}}
                    <div
                        x-ref="panel"
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="mcr-datepicker-panel"
                        :class="{ 'align-right': alignRight }"
                        style="display: none;"
                    >
                        {{-- Cabeçalho do Calendário --}}
                        <div class="mcr-datepicker-header">
                            <span class="mcr-datepicker-title" x-text="monthName + ' ' + focusedYear"></span>
                            <div class="mcr-datepicker-nav">
                                <button
                                    type="button"
                                    @click.stop="prevMonth()"
                                    class="mcr-datepicker-nav-btn"
                                    aria-label="Mês anterior"
                                    title="Mês anterior"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    @click.stop="nextMonth()"
                                    class="mcr-datepicker-nav-btn"
                                    aria-label="Próximo mês"
                                    title="Próximo mês"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Dias da Semana --}}
                        <div class="mcr-datepicker-weekdays">
                            <span class="mcr-datepicker-weekday" title="Domingo">Dom</span>
                            <span class="mcr-datepicker-weekday" title="Segunda-feira">Seg</span>
                            <span class="mcr-datepicker-weekday" title="Terça-feira">Ter</span>
                            <span class="mcr-datepicker-weekday" title="Quarta-feira">Qua</span>
                            <span class="mcr-datepicker-weekday" title="Quinta-feira">Qui</span>
                            <span class="mcr-datepicker-weekday" title="Sexta-feira">Sex</span>
                            <span class="mcr-datepicker-weekday" title="Sábado">Sáb</span>
                        </div>

                        {{-- Grade de Dias do Mês --}}
                        <div class="mcr-datepicker-grid" role="grid">
                            <template x-for="item in calendarDays" :key="item.iso">
                                <button
                                    type="button"
                                    @click.stop="selectDate(item.iso)"
                                    class="mcr-datepicker-day"
                                    :class="{
                                        'is-selected': isSelected(item.iso),
                                        'is-today': isToday(item.iso),
                                        'is-outside': !item.isCurrentMonth,
                                        'is-in-range': isInRange(item.iso)
                                    }"
                                    :aria-selected="isSelected(item.iso)"
                                    x-text="item.day"
                                ></button>
                            </template>
                        </div>

                        {{-- Rodapé: Limpar e Hoje --}}
                        <div class="mcr-datepicker-footer">
                            <button
                                type="button"
                                @click.stop="clearDate()"
                                class="mcr-datepicker-action mcr-datepicker-action-clear"
                            >
                                Limpar
                            </button>
                            <button
                                type="button"
                                @click.stop="selectToday()"
                                class="mcr-datepicker-action mcr-datepicker-action-today"
                            >
                                Hoje
                            </button>
                        </div>
                    </div>
                </div>
                <label class="mcr-field">
                    <span>Emissão</span>
                    <select wire:model.live="emissionId" class="mcr-input">
                        <option value="">Todas</option>
                        @foreach ($report->emissionOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mcr-field">
                    <span>Operação</span>
                    <select wire:model.live="operationId" class="mcr-input">
                        <option value="">Todas</option>
                        @foreach ($report->operationOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mcr-field">
                    <span>Medição</span>
                    <select wire:model.live="measurementId" class="mcr-input">
                        <option value="">Todas</option>
                        @foreach ($report->measurementOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mcr-field">
                    <span>Etapa</span>
                    <select wire:model.live="stage" class="mcr-input">
                        <option value="">Todas</option>
                        @foreach (range(1, 5) as $stageOption)
                            <option value="{{ $stageOption }}">Etapa {{ $stageOption }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mcr-field">
                    <span>Decisão</span>
                    <select wire:model.live="decisionType" class="mcr-input">
                        <option value="">Todas</option>
                        @foreach ($this->decisionOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mcr-field">
                    <span>Actor histórico</span>
                    <select wire:model.live="actorId" class="mcr-input">
                        <option value="">Todos</option>
                        @foreach ($report->actorOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mcr-field">
                    <span>Responsável esperado</span>
                    <select wire:model.live="expectedResponsibleId" class="mcr-input">
                        <option value="">Todos</option>
                        @foreach ($report->responsibleOptions as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="mcr-field">
                    <span>Cobertura</span>
                    <select wire:model.live="completeness" class="mcr-input">
                        <option value="">Todas</option>
                        @foreach ($this->completenessOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if ($this->exportUrl('xlsx') || $this->exportUrl('csv'))
                <div class="mcr-card-foot">
                    @if ($measurementId)
                        <button type="button" wire:click="showDetail({{ $measurementId }})" class="mcr-btn mcr-btn--ghost">
                            Abrir histórico da medição
                        </button>
                    @endif
                    @if ($url = $this->exportUrl('csv'))
                        <a href="{{ $url }}" class="mcr-btn mcr-btn--ghost">Exportar CSV</a>
                    @endif
                    @if ($url = $this->exportUrl('xlsx'))
                        <a href="{{ $url }}" class="mcr-btn mcr-btn--gold">Exportar XLSX</a>
                    @endif
                </div>
            @elseif ($measurementId)
                <div class="mcr-card-foot">
                    <button type="button" wire:click="showDetail({{ $measurementId }})" class="mcr-btn mcr-btn--ghost">
                        Abrir histórico da medição
                    </button>
                </div>
            @endif
        </section>

        <section class="mcr-card mcr-kpis" aria-label="Resumo histórico">
            <div class="mcr-kpis-grid">
                <button type="button" wire:click="applyMetricDrilldown('approved')" @disabled(! $approvalDrilldownAvailable) class="mcr-kpi">
                    <span class="mcr-kpi-label">Aprovações elegíveis</span>
                    <strong class="mcr-kpi-value">{{ number_format($report->summary->approvals, 0, ',', '.') }}</strong>
                </button>
                <div class="mcr-kpi">
                    <span class="mcr-kpi-label">Rejeições elegíveis</span>
                    <strong class="mcr-kpi-value">{{ number_format($report->summary->rejections, 0, ',', '.') }}</strong>
                    <span class="mcr-kpi-sub">Taxa {{ $this->rate($report->summary->rejectionRate) }}</span>
                </div>
                <button type="button" wire:click="applyMetricDrilldown('returned_from_finalization')" @disabled(! $finalizationReturnDrilldownAvailable) class="mcr-kpi">
                    <span class="mcr-kpi-label">Retornos da finalização</span>
                    <strong class="mcr-kpi-value">{{ number_format($report->summary->finalizationReturns, 0, ',', '.') }}</strong>
                    <span class="mcr-kpi-sub">Taxa {{ $this->rate($report->summary->finalizationReturnRate) }} · retornos / (retornos + finalizações)</span>
                </button>
                <div class="mcr-kpi">
                    <span class="mcr-kpi-label">Ciclo completo elegível</span>
                    <strong class="mcr-kpi-value mcr-kpi-value--md">Mediana {{ $this->duration($report->summary->medianCycleDuration) }}</strong>
                    <span class="mcr-kpi-sub">Média {{ $this->duration($report->summary->averageCycleDuration) }} · {{ $report->summary->eligibleCycles }} ciclos</span>
                </div>
            </div>
        </section>

        <section class="mcr-card" aria-label="Métricas por etapa">
            <div class="mcr-card-head">
                <div>
                    <h2>Métricas por etapa</h2>
                    <p>Taxas e durações usam apenas decisões fechadas com cobertura completa. Rejeição = rejeições / (aprovações + rejeições).</p>
                </div>
            </div>
            <div class="mcr-table-scroll">
                <table class="mcr-table">
                    <thead>
                        <tr>
                            <th scope="col">Etapa</th>
                            <th scope="col" class="mcr-r">Decisões</th>
                            <th scope="col" class="mcr-r">Aprovações</th>
                            <th scope="col" class="mcr-r">Rejeições</th>
                            <th scope="col" class="mcr-r">Taxa rejeição</th>
                            <th scope="col">Calendário média / mediana</th>
                            <th scope="col">Líquida média / mediana</th>
                            <th scope="col">Pausas</th>
                            <th scope="col">Finalizações / devoluções</th>
                            <th scope="col">Base / excluídas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report->stageMetrics as $metric)
                            <tr wire:key="stage-metric-{{ $metric->stage }}">
                                <td>
                                    Etapa {{ $metric->stage }}
                                </td>
                                <td class="mcr-r mcr-num">{{ $metric->decisions }}</td>
                                <td class="mcr-r mcr-num">{{ $metric->approvals }}</td>
                                <td class="mcr-r mcr-num">{{ $metric->rejections }}</td>
                                <td class="mcr-r mcr-num">{{ $this->rate($metric->rejectionRate) }}</td>
                                <td class="mcr-num">{{ $this->duration($metric->averageCalendarDuration) }} / {{ $this->duration($metric->medianCalendarDuration) }}</td>
                                <td class="mcr-num">{{ $this->duration($metric->averageActiveDuration) }} / {{ $this->duration($metric->medianActiveDuration) }}</td>
                                <td class="mcr-num">Total {{ $this->duration($metric->pausedDurationTotal) }}<br />Média {{ $this->duration($metric->averagePausedDuration) }}</td>
                                <td class="mcr-num">{{ $metric->finalizations }} / {{ $metric->finalizationReturns }}<br />Taxa {{ $this->rate($metric->finalizationReturnRate) }}</td>
                                <td class="mcr-num">
                                    {{ $metric->completeCohort }} completas<br />
                                    {{ $metric->partialExcluded }} parciais · {{ $metric->insufficientExcluded }} insuficientes
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mcr-card" aria-label="Cobertura histórica">
            <div class="mcr-card-head">
                <div>
                    <h2>Cobertura histórica</h2>
                    <p>A cobertura é exibida, nunca convertida silenciosamente em zero.</p>
                </div>
            </div>
            <div class="mcr-rows">
                @foreach (['complete' => $report->coverage->complete, 'partial' => $report->coverage->partial, 'insufficient' => $report->coverage->insufficient] as $level => $count)
                    <div class="mcr-row">
                        <span class="mcr-row-label">
                            <span class="{{ $coverageBadge($level) }}">{{ $completeLabel($level) }}</span>
                        </span>
                        <span class="mcr-row-value">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mcr-meta-foot">
                <span>Visitas elegíveis: {{ $report->summary->eligibleStageVisits }}</span>
                <span>Tempo total em pausa: {{ $this->duration($report->summary->pausedDurationTotal) }}</span>
            </div>
        </section>

        <section class="mcr-card" aria-label="Visitas históricas">
            <div class="mcr-card-head">
                <div>
                    <h2>Visitas históricas</h2>
                    <p>{{ number_format($report->totalRows, 0, ',', '.') }} registros no recorte. Linhas parciais e insuficientes permanecem visíveis e não entram nas métricas elegíveis.</p>
                </div>
            </div>

            <div class="mcr-table-scroll">
                <table class="mcr-table">
                    <thead>
                        <tr>
                            <th scope="col">Medição</th>
                            <th scope="col">Etapa / visita</th>
                            <th scope="col">Entrada / saída</th>
                            <th scope="col">Decisão</th>
                            <th scope="col">Durações</th>
                            <th scope="col">Actor / responsável</th>
                            <th scope="col">Cobertura</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="visit-{{ $row->measurementId }}-{{ $row->stage }}-{{ $row->sequence }}">
                                <td>
                                    <button type="button" wire:click="showDetail({{ $row->measurementId }})" class="mcr-link">{{ $row->measurementLabel }}</button>
                                    @if ($row->referenceMonth)
                                        <div class="mcr-sub">Competência {{ \Carbon\CarbonImmutable::parse($row->referenceMonth)->format('m/Y') }}</div>
                                    @endif
                                    <div class="mcr-sub mcr-long">{{ $row->operationLabel }} · {{ $row->emissionLabel }}</div>
                                </td>
                                <td class="mcr-num">Etapa {{ $row->stage }} · #{{ $row->sequence }}</td>
                                <td class="mcr-sub mcr-num">
                                    {{ $row->enteredAt?->format('d/m/Y H:i:s') ?? 'N/D' }}<br />
                                    {{ $row->exitedAt?->format('d/m/Y H:i:s') ?? 'Em aberto' }}
                                </td>
                                <td>
                                    <span class="{{ $exitBadge($row->exitReason) }}">{{ $this->exitReason($row->exitReason) }}</span>
                                </td>
                                <td class="mcr-sub mcr-num">
                                    Calendário: {{ $this->duration($row->calendarDuration) }}<br />
                                    Pausa: {{ $this->duration($row->pausedDuration) }}<br />
                                    Líquida: {{ $this->duration($row->activeDuration) }}
                                </td>
                                <td class="mcr-sub">
                                    Actor: {{ $row->actorName ?? 'N/D' }}<br />
                                    Esperado: {{ $row->expectedResponsibleName ?? 'N/D' }}<br />
                                    Delegado: {{ $this->triState($row->delegated) }} · Override: {{ $this->triState($row->adminOverride) }}
                                </td>
                                <td>
                                    <span class="{{ $coverageBadge($row->completeness->value) }}">{{ $completeLabel($row->completeness->value) }}</span>
                                    @if ($row->missingReasons !== [])
                                        <div class="mcr-warn mcr-long">{{ collect($row->missingReasons)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="mcr-empty">Nenhuma visita histórica encontrada para o recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <div class="mcr-pagination">{{ $rows->links() }}</div>
            @endif
        </section>

        <section class="mcr-card" aria-label="Carga operacional atual">
            <div class="mcr-card-head">
                <div>
                    <h2>Carga operacional atual</h2>
                    <p>Snapshot atual, separado das métricas históricas. Período, decisão, actor histórico e cobertura não se aplicam a esta seção. Delegações efetivas não são inferidas novamente: quando indisponíveis, aparecem como N/D.</p>
                </div>
            </div>
            <div class="mcr-table-scroll">
                <table class="mcr-table">
                    <thead>
                        <tr>
                            <th scope="col">Responsável permanente</th>
                            <th scope="col">Responsabilidade</th>
                            <th scope="col">Etapa</th>
                            <th scope="col" class="mcr-r">Pendentes</th>
                            <th scope="col" class="mcr-r">Em atraso</th>
                            <th scope="col" class="mcr-r">Delegados</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report->workload as $workload)
                            <tr>
                                <td>{{ $workload->responsibleName }}</td>
                                <td>{{ $workload->responsibility->label() }}</td>
                                <td class="mcr-num">{{ $workload->stage }}</td>
                                <td class="mcr-r mcr-num">{{ $workload->pendingCount }}</td>
                                <td class="mcr-r mcr-num @if ($workload->overdueCount > 0) mcr-attention @endif">{{ $workload->overdueCount }}</td>
                                <td class="mcr-r mcr-num">{{ $workload->delegatedCount ?? 'N/D' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="mcr-empty">Nenhuma pendência atual visível no recorte estrutural.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($detail)
            <section class="mcr-card" aria-label="Histórico canônico">
                <div class="mcr-card-head">
                    <div>
                        <h2>Histórico canônico · {{ $detail->measurementLabel ?? 'Medição #'.$detail->measurementId }}</h2>
                        <p>Status atual {{ $detail->currentStatus }} · etapa {{ $detail->currentStage }} · cobertura {{ $completeLabel($detail->completeness->value) }}</p>
                    </div>
                    <div class="mcr-card-head-actions">
                        <button type="button" wire:click="closeDetail" class="mcr-btn mcr-btn--ghost">Fechar detalhe</button>
                    </div>
                </div>

                <div class="mcr-rows">
                    <div class="mcr-row">
                        <span class="mcr-row-label">Início do ciclo</span>
                        <span class="mcr-row-value mcr-row-value--text mcr-num">{{ $detail->cycleStart?->format('d/m/Y H:i:s') ?? 'N/D' }}</span>
                    </div>
                    <div class="mcr-row">
                        <span class="mcr-row-label">Fim do ciclo</span>
                        <span class="mcr-row-value mcr-row-value--text mcr-num">{{ $detail->cycleEnd?->format('d/m/Y H:i:s') ?? 'N/D' }}</span>
                    </div>
                    <div class="mcr-row">
                        <span class="mcr-row-label">Motivo terminal</span>
                        <span class="mcr-row-value mcr-row-value--text">{{ $detail->terminalReason ? $this->exitReason($detail->terminalReason) : 'N/D' }}</span>
                    </div>
                </div>

                <div class="mcr-note mt-4">
                    @if ($detail->completeness->value === 'partial')
                        Parte do histórico está disponível, mas algumas informações da época não foram registradas.
                    @elseif ($detail->completeness->value === 'insufficient')
                        Não há evidência histórica suficiente para reconstruir este trecho com segurança.
                    @else
                        A evidência necessária para reconstruir o ciclo está disponível no recorte canônico.
                    @endif
                </div>

                <div class="mcr-rows">
                    <div class="mcr-row">
                        <span class="mcr-row-label">Decisões de etapa</span>
                        <span class="mcr-row-value">{{ $detailDecisionCount }}</span>
                    </div>
                    <div class="mcr-row">
                        <span class="mcr-row-label">Ações de comprovante</span>
                        <span class="mcr-row-value">{{ $detailReceiptCount }}</span>
                    </div>
                    <div class="mcr-row">
                        <span class="mcr-row-label">Decisões de finalização</span>
                        <span class="mcr-row-value">{{ $detailFinalizationCount }}</span>
                    </div>
                </div>

                @if ($detail->warnings !== [])
                    <div class="mcr-note mcr-note--amber mt-4">
                        <strong>Limitações históricas:</strong> {{ collect($detail->warnings)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}
                    </div>
                @endif

                <div class="mcr-detail-grid">
                    <div class="mcr-detail-block">
                        <h3>Timeline normalizada</h3>
                        <p class="mcr-sub">A auditoria técnica mostra somente fonte, identificador e revisão; campos técnicos brutos não são serializados.</p>
                        <ol class="mcr-list">
                            @foreach ($detail->events as $event)
                                <li class="mcr-list-item">
                                    <div class="mcr-list-title">
                                        <strong>{{ $this->eventType($event->eventType) }}</strong>
                                        <span class="mcr-sub mcr-num">{{ $event->occurredAt?->format('d/m/Y H:i:s') ?? 'Data N/D' }}</span>
                                    </div>
                                    <div class="mcr-sub">
                                        Etapa {{ $event->stageBefore ?? 'N/D' }} → {{ $event->stageAfter ?? 'N/D' }} ·
                                        {{ $event->statusBefore ?? 'N/D' }} → {{ $event->statusAfter ?? 'N/D' }} ·
                                        actor {{ $event->actorId ? ($detailUserNames[$event->actorId] ?? 'N/D') : 'N/D' }} ·
                                        responsável esperado {{ $event->expectedResponsibleId ? ($detailUserNames[$event->expectedResponsibleId] ?? 'N/D') : 'N/D' }} ·
                                        delegado {{ $this->triState($event->delegated) }} ·
                                        delegante {{ $event->delegatorId ? ($detailUserNames[$event->delegatorId] ?? 'N/D') : 'N/D' }} ·
                                        override {{ $this->triState($event->adminOverride) }} ·
                                        cobertura {{ $completeLabel($event->completeness->value) }}
                                    </div>
                                    <div class="mcr-sub">
                                        Fonte: {{ $this->sourceType($event->sourceType) }} · Activity {{ $event->sourceActivityId ?? 'N/D' }} · revisão {{ $event->workflowRevision ?? 'N/D' }}
                                    </div>
                                    @if ($event->missingReasons !== [])
                                        <div class="mcr-warn">{{ collect($event->missingReasons)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <div class="mcr-detail-block">
                        <div>
                            <h3>Visitas de etapa</h3>
                            <div class="mcr-list">
                                @foreach ($detail->stageVisits as $visit)
                                    <div class="mcr-list-item">
                                        <div class="mcr-list-title"><strong>Etapa {{ $visit->stage }} · visita #{{ $visit->sequence }} · {{ $this->exitReason($visit->exitReason) }}</strong></div>
                                        <div class="mcr-sub mcr-num">{{ $visit->enteredAt?->format('d/m/Y H:i:s') ?? 'N/D' }} → {{ $visit->exitedAt?->format('d/m/Y H:i:s') ?? 'Em aberto' }}</div>
                                        <div class="mcr-sub mcr-num">Calendário {{ $this->duration($visit->calendarDuration) }} · pausa {{ $this->duration($visit->pausedDuration) }} · líquida {{ $this->duration($visit->activeDuration) }}</div>
                                        <div class="mcr-sub">Actor {{ $visit->exitActorId ? ($detailUserNames[$visit->exitActorId] ?? 'N/D') : 'N/D' }} · responsável esperado {{ $visit->expectedResponsibleId ? ($detailUserNames[$visit->expectedResponsibleId] ?? 'N/D') : 'N/D' }}</div>
                                        <div class="mcr-sub">Delegado {{ $this->triState($visit->delegated) }} · delegante {{ $visit->delegatorId ? ($detailUserNames[$visit->delegatorId] ?? 'N/D') : 'N/D' }} · override {{ $this->triState($visit->adminOverride) }} · cobertura {{ $completeLabel($visit->completeness->value) }}</div>
                                        @if ($visit->missingReasons !== [])
                                            <div class="mcr-warn">{{ collect($visit->missingReasons)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-6">
                            <h3>Pausas</h3>
                            <div class="mcr-list">
                                @forelse ($detail->pauses as $pause)
                                    <div class="mcr-list-item">
                                        <div class="mcr-sub mcr-num">Etapa {{ $pause->stage }} · {{ $pause->pausedAt?->format('d/m/Y H:i:s') ?? 'N/D' }} → {{ $pause->resumedAt?->format('d/m/Y H:i:s') ?? 'Em aberto' }}</div>
                                        <div class="mcr-sub">Pausado por {{ $pause->pausedById ? ($detailUserNames[$pause->pausedById] ?? 'N/D') : 'N/D' }} · retomado por {{ $pause->resumedById ? ($detailUserNames[$pause->resumedById] ?? 'N/D') : 'N/D' }} · motivo {{ $pause->reason ?: 'N/D' }} · cobertura {{ $completeLabel($pause->completeness->value) }}</div>
                                        @if ($pause->missingReasons !== [])
                                            <div class="mcr-warn">{{ collect($pause->missingReasons)->map(fn (string $reason): string => $this->missingReason($reason))->implode(' · ') }}</div>
                                        @endif
                                    </div>
                                @empty
                                    <p class="mcr-sub mt-2">Nenhuma pausa histórica.</p>
                                @endforelse
                            </div>
                        </div>

                        <div class="mt-6">
                            <h3>Pagamentos</h3>
                            <div class="mcr-list">
                                @forelse ($detail->payments as $payment)
                                    <div class="mcr-list-item">
                                        <div class="mcr-pay">
                                            <span class="mcr-sub">pagamento {{ $payment->payDate?->format('d/m/Y') ?? 'N/D' }}</span>
                                            <strong class="mcr-pay-amount">R$ {{ number_format((float) $payment->amount, 2, ',', '.') }}</strong>
                                        </div>
                                        <div class="mcr-sub">Método {{ $payment->method ?: 'N/D' }} · registrado por {{ $payment->createdById ? ($detailUserNames[$payment->createdById] ?? 'N/D') : 'N/D' }} · em {{ $payment->createdAt?->format('d/m/Y H:i:s') ?? 'N/D' }}</div>
                                    </div>
                                @empty
                                    <p class="mcr-sub mt-2">Nenhum pagamento histórico.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif
    </div>

    <script>
        (function () {
            function createMcrDatePicker(wireModelName) {
                return {
                    open: false,
                    alignRight: false,
                    focusedYear: new Date().getFullYear(),
                    focusedMonth: new Date().getMonth(),
                    displayValue: '',

                    init() {
                        this.syncFromLivewire();
                        this.$watch('$wire.' + wireModelName, () => {
                            this.syncFromLivewire();
                        });
                    },

                    syncFromLivewire() {
                        var raw = (typeof this.$wire.get === 'function' ? this.$wire.get(wireModelName) : this.$wire[wireModelName]) || '';
                        if (raw && /^\d{4}-\d{2}-\d{2}$/.test(raw)) {
                            var parts = raw.split('-').map(Number);
                            this.focusedYear = parts[0];
                            this.focusedMonth = parts[1] - 1;
                            this.displayValue = String(parts[2]).padStart(2, '0') + '/' + String(parts[1]).padStart(2, '0') + '/' + parts[0];
                        } else {
                            this.displayValue = '';
                            var today = new Date();
                            this.focusedYear = today.getFullYear();
                            this.focusedMonth = today.getMonth();
                        }
                    },

                    get currentIso() {
                        return (typeof this.$wire.get === 'function' ? this.$wire.get(wireModelName) : this.$wire[wireModelName]) || '';
                    },

                    get monthName() {
                        var months = [
                            'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                            'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
                        ];
                        return months[this.focusedMonth] || '';
                    },

                    openPanel() {
                        this.open = true;
                        this.checkAlignment();
                    },

                    togglePanel() {
                        this.open = !this.open;
                        if (this.open) {
                            this.checkAlignment();
                        }
                    },

                    close() {
                        this.open = false;
                    },

                    checkAlignment() {
                        this.$nextTick(() => {
                            if (this.$refs.panel) {
                                var rect = this.$refs.panel.getBoundingClientRect();
                                this.alignRight = rect.right > (window.innerWidth - 16);
                            }
                        });
                    },

                    prevMonth() {
                        if (this.focusedMonth === 0) {
                            this.focusedMonth = 11;
                            this.focusedYear--;
                        } else {
                            this.focusedMonth--;
                        }
                    },

                    nextMonth() {
                        if (this.focusedMonth === 11) {
                            this.focusedMonth = 0;
                            this.focusedYear++;
                        } else {
                            this.focusedMonth++;
                        }
                    },

                    get calendarDays() {
                        var year = this.focusedYear;
                        var month = this.focusedMonth;
                        var firstDayIndex = new Date(year, month, 1).getDay();
                        var totalDaysInMonth = new Date(year, month + 1, 0).getDate();
                        var prevMonthTotalDays = new Date(year, month, 0).getDate();
                        var days = [];

                        for (var i = firstDayIndex - 1; i >= 0; i--) {
                            var dayNum = prevMonthTotalDays - i;
                            var m = month === 0 ? 12 : month;
                            var y = month === 0 ? year - 1 : year;
                            var iso = y + '-' + String(m).padStart(2, '0') + '-' + String(dayNum).padStart(2, '0');
                            days.push({
                                day: dayNum,
                                iso: iso,
                                isCurrentMonth: false
                            });
                        }

                        for (var d = 1; d <= totalDaysInMonth; d++) {
                            var isoCur = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                            days.push({
                                day: d,
                                iso: isoCur,
                                isCurrentMonth: true
                            });
                        }

                        var rem = days.length % 7;
                        if (rem !== 0) {
                            var trailingNeeded = 7 - rem;
                            for (var t = 1; t <= trailingNeeded; t++) {
                                var mNext = month === 11 ? 1 : month + 2;
                                var yNext = month === 11 ? year + 1 : year;
                                var isoNext = yNext + '-' + String(mNext).padStart(2, '0') + '-' + String(t).padStart(2, '0');
                                days.push({
                                day: t,
                                iso: isoNext,
                                isCurrentMonth: false
                            });
                        }
                    }

                    return days;
                },

                isToday(iso) {
                    var now = new Date();
                    var todayIso = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
                    return iso === todayIso;
                },

                isSelected(iso) {
                    return iso === this.currentIso;
                },

                isInRange(iso) {
                    var from = (typeof this.$wire.get === 'function' ? this.$wire.get('periodFrom') : this.$wire.periodFrom) || '';
                    var to = (typeof this.$wire.get === 'function' ? this.$wire.get('periodTo') : this.$wire.periodTo) || '';
                    if (from && to && from <= to) {
                        return iso >= from && iso <= to;
                    }
                    return false;
                },

                selectDate(iso) {
                    if (typeof this.$wire.set === 'function') {
                        this.$wire.set(wireModelName, iso);
                    } else {
                        this.$wire[wireModelName] = iso;
                    }
                    this.close();
                },

                selectToday() {
                    var now = new Date();
                    var todayIso = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
                    this.selectDate(todayIso);
                },

                clearDate() {
                    if (typeof this.$wire.set === 'function') {
                        this.$wire.set(wireModelName, '');
                    } else {
                        this.$wire[wireModelName] = '';
                    }
                    this.close();
                },

                onInput(e) {
                    var val = e.target.value.replace(/\D/g, '');
                    if (val.length > 8) val = val.substring(0, 8);
                    var formatted = '';
                    if (val.length > 4) {
                        formatted = val.substring(0, 2) + '/' + val.substring(2, 4) + '/' + val.substring(4);
                    } else if (val.length > 2) {
                        formatted = val.substring(0, 2) + '/' + val.substring(2);
                    } else {
                        formatted = val;
                    }
                    this.displayValue = formatted;
                    if (val.length === 8) {
                        var d = parseInt(val.substring(0, 2), 10);
                        var m = parseInt(val.substring(2, 4), 10);
                        var y = parseInt(val.substring(4), 10);
                        if (m >= 1 && m <= 12 && d >= 1 && d <= 31 && y >= 1900 && y <= 2100) {
                            var iso = y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                            if (typeof this.$wire.set === 'function') {
                                this.$wire.set(wireModelName, iso);
                            } else {
                                this.$wire[wireModelName] = iso;
                            }
                            this.focusedYear = y;
                            this.focusedMonth = m - 1;
                        }
                    } else if (val.length === 0) {
                        if (typeof this.$wire.set === 'function') {
                            this.$wire.set(wireModelName, '');
                        } else {
                            this.$wire[wireModelName] = '';
                        }
                    }
                }
            };
        }

        window.mcrDatePicker = createMcrDatePicker;
        if (window.Alpine) {
            window.Alpine.data('mcrDatePicker', createMcrDatePicker);
        } else {
            document.addEventListener('alpine:init', function () {
                window.Alpine.data('mcrDatePicker', createMcrDatePicker);
            });
        }
    })();
</script>
</x-filament-panels::page>
