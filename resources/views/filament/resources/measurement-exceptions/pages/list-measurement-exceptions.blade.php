<x-filament-panels::page>
    @php
        $result = $this->result();
        $exceptions = $this->exceptions();
        $hasActiveExceptionFilter = $this->exceptionType !== '';
    @endphp

    <div class="space-y-5" wire:loading.class="opacity-60">
        {{-- BLOCO 1: RESUMO DA POPULAÇÃO FILTRADA --}}
        <section class="moe-card" aria-label="Resumo da população filtrada">
            <div class="moe-card-head">
                <div>
                    <h2>Resumo da população filtrada</h2>
                    <p>Cada contagem usa os mesmos filtros e a mesma classificação da lista abaixo.</p>
                </div>

                @if ($hasActiveExceptionFilter)
                    <div class="moe-card-head-actions">
                        <button
                            type="button"
                            wire:click="clearExceptionFilter"
                            class="moe-btn"
                            title="Remover filtro de tipo de exceção"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                            <span>Mostrar todas as exceções</span>
                        </button>
                    </div>
                @endif
            </div>

            <div class="moe-kpis-grid">
                {{-- KPI TOTAL --}}
                <button
                    type="button"
                    wire:click="clearExceptionFilter"
                    class="moe-kpi moe-kpi-total {{ ! $hasActiveExceptionFilter ? 'moe-kpi--active' : '' }}"
                    title="{{ $hasActiveExceptionFilter ? 'Clique para ver todas as exceções' : 'Total de exceções identificadas' }}"
                >
                    <span class="moe-kpi-label">Total</span>
                    <span class="moe-kpi-value moe-kpi-value--total {{ $result->total > 0 ? 'moe-kpi-value--attention' : 'moe-kpi-value--zero' }}">
                        {{ $result->total }}
                    </span>
                </button>

                {{-- KPIS DE CADA TIPO DE EXCEÇÃO --}}
                @foreach (\App\Enums\MeasurementOperationalExceptionType::cases() as $type)
                    @php
                        $canDrillDown = $this->canDrillDown($type);
                        $count = $result->counts[$type->value] ?? 0;
                        $isSelected = $this->exceptionType === $type->value;
                    @endphp

                    @if ($canDrillDown)
                        <button
                            type="button"
                            wire:key="exception-summary-{{ $type->value }}"
                            wire:click="filterByException('{{ $type->value }}')"
                            class="moe-kpi {{ $isSelected ? 'moe-kpi--active' : '' }}"
                            title="Filtrar por: {{ $type->label() }}"
                        >
                            <span class="moe-kpi-label" title="{{ $type->label() }}">{{ $type->label() }}</span>
                            <span class="moe-kpi-value {{ $count > 0 ? 'moe-kpi-value--attention' : 'moe-kpi-value--zero' }}">
                                {{ $count }}
                            </span>
                        </button>
                    @else
                        <div
                            wire:key="exception-summary-{{ $type->value }}"
                            class="moe-kpi opacity-40 cursor-not-allowed"
                            title="{{ $type->label() }} (desabilitado pelos filtros atuais)"
                        >
                            <span class="moe-kpi-label" title="{{ $type->label() }}">{{ $type->label() }}</span>
                            <span class="moe-kpi-value moe-kpi-value--zero">0</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </section>

        {{-- BLOCO 2: FILTROS COMO TOOLBAR OPERACIONAL --}}
        <section class="moe-card" aria-label="Filtros operacionais">
            <div class="moe-card-head">
                <div>
                    <h2>Filtros</h2>
                    <p>Refine a população por texto, operação, emissão, período de competência, etapa ou tipo de exceção.</p>
                </div>

                @if ($this->hasFilters())
                    <div class="moe-card-head-actions">
                        <button
                            type="button"
                            wire:click="clearFilters"
                            class="moe-btn"
                            title="Limpar todos os filtros ativos"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                            </svg>
                            <span>Limpar filtros</span>
                        </button>
                    </div>
                @endif
            </div>

            <div class="moe-filters-body space-y-3">
                {{-- LINHA 1: Busca | Operação | Emissão --}}
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                    <div class="moe-field md:col-span-2 lg:col-span-2">
                        <label for="exceptionSearch">Busca</label>
                        <div class="moe-input-control">
                            <svg class="moe-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                id="exceptionSearch"
                                type="search"
                                wire:model.live.debounce.400ms="search"
                                class="moe-input moe-search-input"
                                placeholder="Medição, operação ou emissão"
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <div class="moe-field">
                        <label for="exceptionOperation">Operação</label>
                        <div class="moe-input-control">
                            <select id="exceptionOperation" wire:model.live="operationId" class="moe-input">
                                <option value="">Todas</option>
                                @foreach ($this->operationOptions() as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="moe-field">
                        <label for="exceptionEmission">Emissão</label>
                        <div class="moe-input-control">
                            <select id="exceptionEmission" wire:model.live="emissionId" class="moe-input">
                                <option value="">Todas</option>
                                @foreach ($this->emissionOptions() as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- LINHA 2: Competência Inicial | Competência Final | Etapa | Tipo de Exceção | Ação Limpar --}}
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 xl:items-end">
                    <div class="moe-field">
                        <label for="exceptionCompetenceFrom">Competência inicial</label>
                        <x-month-picker
                            wire:model.live="competenceFrom"
                            id="exceptionCompetenceFrom"
                            placeholder="mm/aaaa"
                            displayMode="numeric"
                        />
                    </div>

                    <div class="moe-field">
                        <label for="exceptionCompetenceTo">Competência final</label>
                        <x-month-picker
                            wire:model.live="competenceTo"
                            id="exceptionCompetenceTo"
                            placeholder="mm/aaaa"
                            displayMode="numeric"
                        />
                    </div>

                    <div class="moe-field">
                        <label for="exceptionStage">Etapa</label>
                        <div class="moe-input-control">
                            <select id="exceptionStage" wire:model.live="stage" class="moe-input">
                                <option value="">Todas</option>
                                @foreach ($this->stageOptions() as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="moe-field">
                        <label for="exceptionType">Tipo de exceção</label>
                        <div class="moe-input-control">
                            <select id="exceptionType" wire:model.live="exceptionType" class="moe-input">
                                <option value="">Todos</option>
                                @foreach ($this->exceptionTypeOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="moe-field sm:col-span-2 lg:col-span-4 xl:col-span-1">
                        <span class="hidden xl:block">&nbsp;</span>
                        <button
                            type="button"
                            wire:click="clearFilters"
                            class="moe-btn w-full {{ ! $this->hasFilters() ? 'opacity-50 pointer-events-none' : '' }}"
                            title="Limpar todos os filtros"
                        >
                            Limpar filtros
                        </button>
                    </div>
                </div>
            </div>
        </section>

        {{-- BLOCO 3: EXCEÇÕES IDENTIFICADAS (TABELA OPERACIONAL) --}}
        <section class="moe-card" aria-label="Exceções identificadas">
            <div class="moe-card-head">
                <div>
                    <h2>Exceções identificadas</h2>
                    <p>Uma medição aparece uma vez para cada tipo de exceção estrutural atual.</p>
                </div>

                <div class="moe-card-head-actions">
                    <span class="text-xs font-semibold text-muted moe-num tracking-wide">
                        {{ $exceptions->total() }} {{ $exceptions->total() === 1 ? 'registro' : 'registros' }}
                    </span>
                </div>
            </div>

            <div class="moe-table-scroll">
                <table class="moe-table">
                    <thead>
                        <tr>
                            <th style="width: 26%;">Exceção</th>
                            <th style="width: 18%;">Operação / Emissão</th>
                            <th style="width: 13%;">Medição</th>
                            <th style="width: 12%;">Etapa / Status</th>
                            <th style="width: 15%;">Responsabilidade</th>
                            <th style="width: 8%;">Atualização</th>
                            <th style="width: 8%; text-align: right;">Navegação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($exceptions as $exception)
                            @php
                                $measurementUrl = $this->measurementUrl($exception);
                                $operationUrl = $this->operationUrl($exception);
                                $manageResponsibilitiesUrl = $this->manageResponsibilitiesUrl($exception);
                                $isSla = in_array($exception->type, [
                                    \App\Enums\MeasurementOperationalExceptionType::SlaNotConfigured,
                                    \App\Enums\MeasurementOperationalExceptionType::SlaInvalidConfig,
                                ], true);
                            @endphp

                            <tr wire:key="measurement-exception-{{ $exception->key() }}">
                                {{-- COLUNA 1: Exceção --}}
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="moe-badge-pill {{ $isSla ? 'moe-badge-pill--warning' : 'moe-badge-pill--danger' }}">
                                            {{ $exception->type->label() }}
                                        </span>
                                    </div>
                                    <p class="mt-1.5 max-w-sm text-xs text-muted leading-relaxed">
                                        {{ $exception->description() }}
                                    </p>
                                    @if ($exception->slaStatusLabel())
                                        <p class="mt-1 text-xs text-stone-400">
                                            Estado SLA: <span class="text-stone-300 font-medium">{{ $exception->slaStatusLabel() }}</span>
                                        </p>
                                    @endif
                                </td>

                                {{-- COLUNA 2: Operação / Emissão --}}
                                <td>
                                    <p class="font-semibold text-white leading-snug">
                                        {{ $exception->operationLabel() }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-muted leading-snug">
                                        {{ $exception->emissionLabel() }}
                                    </p>
                                </td>

                                {{-- COLUNA 3: Medição --}}
                                <td>
                                    <p class="font-semibold text-white leading-snug">
                                        {{ $exception->measurementLabel() }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-muted moe-num leading-snug">
                                        Competência {{ $exception->competenceLabel() }}
                                    </p>
                                </td>

                                {{-- COLUNA 4: Etapa / Status --}}
                                <td>
                                    <p class="font-semibold text-white leading-snug">
                                        {{ $exception->stageLabel() }}
                                    </p>
                                    <div class="mt-1">
                                        <span class="moe-badge-pill moe-badge-pill--neutral">
                                            {{ $exception->statusLabel() }}
                                        </span>
                                    </div>
                                </td>

                                {{-- COLUNA 5: Responsabilidade --}}
                                <td>
                                    <p class="font-medium text-white leading-snug">
                                        {{ $exception->expectedResponsibility?->label() ?? 'Não se aplica' }}
                                    </p>
                                    <p class="mt-0.5 text-xs leading-snug">
                                        @if ($exception->configuredResponsibleName)
                                            <span class="text-amber-400/90 font-medium">{{ $exception->configuredResponsibleName }}</span>
                                        @elseif ($exception->expectedResponsibility)
                                            <span class="text-amber-400/80">Não configurado ou inativo</span>
                                        @else
                                            <span class="text-stone-500">—</span>
                                        @endif
                                    </p>
                                </td>

                                {{-- COLUNA 6: Atualização --}}
                                <td class="whitespace-nowrap text-xs text-muted moe-num">
                                    {{ $exception->measurement->updated_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>

                                {{-- COLUNA 7: Navegação --}}
                                <td>
                                    <div class="flex flex-col items-end gap-1.5">
                                        @if ($measurementUrl)
                                            <a href="{{ $measurementUrl }}" class="moe-btn-nav" title="Abrir detalhes da medição">
                                                <span>Ver medição</span>
                                                <span aria-hidden="true">&rarr;</span>
                                            </a>
                                        @endif

                                        @if ($operationUrl)
                                            <a href="{{ $operationUrl }}" class="moe-btn-nav" title="Abrir detalhes da operação">
                                                <span>Ver operação</span>
                                                <span aria-hidden="true">&rarr;</span>
                                            </a>
                                        @endif

                                        @if ($manageResponsibilitiesUrl)
                                            <a href="{{ $manageResponsibilitiesUrl }}" class="moe-btn-nav moe-btn-nav--gold" title="Gerir responsáveis da operação">
                                                <span>Gerir responsáveis</span>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="moe-empty-state">
                                        <div class="moe-empty-icon-wrap" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                        <h3 class="text-sm font-semibold text-white">Nenhuma exceção operacional encontrada</h3>
                                        <p class="mt-1 text-xs text-muted max-w-md">
                                            Os filtros atuais não identificaram impedimentos estruturais para o avanço das medições.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($exceptions->hasPages())
                <div class="px-5 py-4 border-t border-[rgba(164,183,191,0.16)]">
                    {{ $exceptions->links() }}
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
