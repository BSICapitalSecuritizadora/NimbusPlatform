<x-filament-panels::page>
    @php
        /**
         * Apresentação apenas. Nenhuma conta financeira acontece neste arquivo:
         * todo valor exibido vem pronto da engine oficial, em string decimal.
         */
        $emission = $this->getRecord();
        $resolution = $this->parameterResolution();
        $values = $resolution['values'];
        $origins = $resolution['origins'];
        $conflicts = $resolution['conflicts'];
        $schedule = $resolution['schedule'];
        $result = $this->result();
        $rows = $this->visibleRows();
        $detail = $this->selectedRow();
        $canSync = auth()->user()?->can(\App\Enums\AccessPermission::PuIndexSync->value) ?? false;

        $originBadge = function (?string $origin) {
            $enum = \App\Domain\PuCalculator\Enums\PuSimulationValueOrigin::tryFrom((string) $origin)
                ?? \App\Domain\PuCalculator\Enums\PuSimulationValueOrigin::Undefined;

            return $enum;
        };

        $show = fn ($value) => ($value === null || $value === '') ? null : (string) $value;
        $date = fn ($value) => $show($value) ? \Carbon\CarbonImmutable::parse($value)->format('d/m/Y') : null;

        /**
         * Apresentação pt-BR com 8 casas para valores monetários e para a taxa.
         * Fatores técnicos seguem com a precisão integral da engine: a auditoria
         * de precisão desta curva acontece na 8ª e na 9ª casa dos fatores.
         */
        $presenter = app(\App\Support\PuCalculator\PuDecimalPresenter::class);
        $money = fn ($value) => $presenter->money($value);
        $rate = fn ($value) => $presenter->rate($value);
        $factor = fn ($value) => $presenter->factor($value);

        /** Campos de parâmetro que são valor monetário e por isso seguem a mesma escala. */
        $monetaryParameterFields = ['initial_unit_value'];

        $fieldLabels = [
            'indexer' => 'Indexador',
            'spread_rate' => 'Spread (% a.a.)',
            'annual_rate' => 'Taxa prefixada (% a.a.)',
            'business_day_basis' => 'Base de dias úteis',
            'calendar_code' => 'Calendário',
            'index_rate_lookup_mode' => 'Modo de busca do índice',
            'index_rate_lag_business_days' => 'Lag do índice (DU)',
            'initial_unit_value' => 'VNU inicial',
            'curve_start_date' => 'Primeira integralização (início da curva)',
            'curve_end_date' => 'Fim da janela simulada',
            'first_coupon_pre_integralization_premium_enabled' => 'Prêmio 1º cupom',
            'first_coupon_pre_integralization_business_days' => 'Prêmio: DU anteriores',
            'first_coupon_pre_integralization_apply_index_factor' => 'Prêmio: aplica índice',
            'first_coupon_pre_integralization_apply_spread_factor' => 'Prêmio: aplica spread',
        ];

        $groups = [
            'Instrumento' => ['initial_unit_value', 'business_day_basis'],
            'Indexação' => ['indexer', 'spread_rate', 'annual_rate'],
            'Calendário' => ['calendar_code', 'index_rate_lookup_mode', 'index_rate_lag_business_days'],
            'Integralização e janela' => ['curve_start_date', 'curve_end_date'],
            'Prêmio do primeiro cupom' => [
                'first_coupon_pre_integralization_premium_enabled',
                'first_coupon_pre_integralization_business_days',
                'first_coupon_pre_integralization_apply_index_factor',
                'first_coupon_pre_integralization_apply_spread_factor',
            ],
        ];
    @endphp

    <div class="space-y-6">
        {{-- Banner obrigatório: a natureza não-operacional precede tudo. --}}
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-950/40">
            <p class="text-sm font-semibold tracking-wide text-warning-800 dark:text-warning-200">
                SIMULAÇÃO — NÃO OPERACIONAL
            </p>
            <p class="mt-1 text-sm text-warning-700 dark:text-warning-300">
                Os valores abaixo não alteram a emissão, não criam uma curva oficial e não podem ser
                utilizados como evidência de homologação.
            </p>
        </div>

        {{-- Identificação --}}
        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Emissão</h2>
            <div class="mt-3 grid gap-x-6 gap-y-1 text-sm text-gray-600 md:grid-cols-2 xl:grid-cols-4 dark:text-gray-300">
                <span>Emissão: <strong>#{{ $emission->id }} {{ $emission->name }}</strong></span>
                <span>Tipo: <strong>{{ $show($emission->type) ?? '—' }}</strong></span>
                <span>IF: <strong>{{ $show($emission->if_code) ?? '—' }}</strong></span>
                <span>ISIN: <strong>{{ $show($emission->isin_code) ?? '—' }}</strong></span>
                <span>Emissão em: <strong>{{ $date($emission->issue_date) ?? '—' }}</strong></span>
                <span>Vencimento: <strong>{{ $date($emission->maturity_date) ?? '—' }}</strong></span>
                <span>
                    Curva operacional:
                    <strong>{{ $emission->latestPuCurveVersion()->first()?->calculation_version ?? 'nenhuma' }}</strong>
                </span>
                <span>
                    Parâmetro persistido:
                    <strong>{{ $emission->puParameter()->exists() ? 'sim' : 'não' }}</strong>
                </span>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Gate C operacional e demais portões de governança não bloqueiam esta tela: eles
                controlam candidate, validação externa e promoção — não a simulação.
            </p>
        </section>

        {{-- Formulário de hipóteses --}}
        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Parâmetros da simulação</h2>

            <div class="mt-4 rounded-lg border border-primary-300 bg-primary-50 p-3 dark:border-primary-700 dark:bg-primary-950/30">
                <label class="block text-sm font-semibold text-primary-900 dark:text-primary-200" for="firstIntegralizationDate">
                    Data da primeira integralização
                </label>
                <p class="mt-1 text-xs text-primary-800 dark:text-primary-300">
                    PARÂMETRO DE SIMULAÇÃO — este valor é usado somente nesta simulação. Ele não altera a
                    emissão, não cria evidência e não satisfaz o Gate C.
                </p>
                <input
                    id="firstIntegralizationDate"
                    type="date"
                    wire:model.blur="firstIntegralizationDate"
                    class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                />
            </div>

            {{-- Calendário de accrual da curva: hipótese, nunca contrato --}}
            <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/30">
                <label class="block text-sm font-semibold text-amber-900 dark:text-amber-200" for="accrualCalendarCode">
                    Calendário de accrual da curva
                </label>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-200">
                    Override de simulação — não persiste
                </p>
                <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">
                    Decide quais dias da curva contam como Dia Útil nesta simulação: contagem de DU, DUP/DUT e
                    incidência do fator diário. Não altera a emissão, não vira evidência e não desloca eventos —
                    pagamentos e convenção Following continuam no calendário contratual.
                </p>
                <select
                    id="accrualCalendarCode"
                    wire:model.blur="accrualCalendarCode"
                    class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                >
                    @foreach ($this->accrualCalendarOptions() as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Calendário de observação do índice: hipótese, nunca contrato --}}
            <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/30">
                <label class="block text-sm font-semibold text-amber-900 dark:text-amber-200" for="indexRateCalendarCode">
                    Calendário de observação do CDI
                </label>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-200">
                    Hipótese de simulação
                </p>
                <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">
                    Este calendário é utilizado somente para resolver as datas de observação do CDI nesta
                    simulação. Ele não altera a definição contratual de Dia Útil, não modifica a emissão e não
                    constitui evidência para homologação.
                </p>
                <select
                    id="indexRateCalendarCode"
                    wire:model.blur="indexRateCalendarCode"
                    class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                >
                    @foreach ($this->indexRateCalendarOptions() as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
                <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                    <div>
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Calendário da curva</dt>
                        <dd class="text-gray-900 dark:text-gray-100">
                            {{ $this->curveCalendarCode() ?? '—' }}
                            <span class="text-gray-500 dark:text-gray-400">— Contratual</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Calendário de accrual</dt>
                        <dd class="text-gray-900 dark:text-gray-100">
                            @if ($this->accrualCalendarOverride())
                                {{ $this->accrualCalendarOverride() }}
                                <span class="text-amber-700 dark:text-amber-300">— Override de simulação</span>
                            @else
                                Mesmo calendário contratual
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Calendário CDI</dt>
                        <dd class="text-gray-900 dark:text-gray-100">
                            @if ($this->indexRateCalendarOverride())
                                {{ $this->indexRateCalendarOverride() }}
                                <span class="text-amber-700 dark:text-amber-300">— Override de simulação</span>
                            @else
                                Mesmo calendário da curva
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="simulationEndDate">
                        Data final da simulação
                    </label>
                    <input
                        id="simulationEndDate"
                        type="date"
                        wire:model.blur="simulationEndDate"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Obrigatória: define até quando esta simulação corre. Nunca ultrapassa o vencimento
                        contratual. Janela máxima:
                        {{ \App\Domain\PuCalculator\Services\PuSimulationService::MAX_WINDOW_YEARS }} anos.
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="focusDate">
                        Data do PU em destaque
                    </label>
                    <input
                        id="focusDate"
                        type="date"
                        wire:model.blur="focusDate"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Em branco: usa a última data calculada da janela.
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="quantity">
                        Quantidade para simulação
                    </label>
                    <input
                        id="quantity"
                        type="text"
                        inputmode="decimal"
                        wire:model.blur="quantity"
                        placeholder="opcional"
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Serve apenas para <strong>valor total = PU × quantidade</strong>. Não altera o PU unitário.
                    </p>
                </div>
            </div>

            {{-- Overrides por grupo --}}
            <div class="mt-6 space-y-5">
                @foreach ($groups as $groupLabel => $fields)
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $groupLabel }}</h3>
                        <div class="mt-2 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            @foreach ($fields as $field)
                                @php
                                    $origin = $originBadge($origins[$field] ?? null);
                                    $resolved = $values[$field] ?? null;
                                    $isBoolField = str_contains($field, 'apply_') || str_ends_with($field, '_enabled');
                                @endphp
                                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                    <div class="flex items-start justify-between gap-2">
                                        <label class="text-xs font-medium text-gray-700 dark:text-gray-300" for="override-{{ $field }}">
                                            {{ $fieldLabels[$field] ?? $field }}
                                        </label>
                                        <x-filament::badge size="xs" :color="$origin->color()">
                                            {{ $origin->label() }}
                                        </x-filament::badge>
                                    </div>

                                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                        @if ($isBoolField)
                                            {{ $resolved === null ? 'Não definido' : (filter_var($resolved, FILTER_VALIDATE_BOOLEAN) ? 'Sim' : 'Não') }}
                                        @elseif (in_array($field, $monetaryParameterFields, true))
                                            {{ $money($resolved) ?? 'Não definido' }}
                                        @else
                                            {{ $show($resolved) ?? 'Não definido' }}
                                        @endif
                                    </p>

                                    @if ($field === 'indexer')
                                        <select
                                            id="override-{{ $field }}"
                                            wire:model.blur="overrides.{{ $field }}"
                                            class="mt-2 block w-full rounded-lg border-gray-300 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                        >
                                            <option value="">Usar origem resolvida</option>
                                            @foreach ($this->indexerOptions() as $optionValue => $optionLabel)
                                                <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($isBoolField)
                                        <select
                                            id="override-{{ $field }}"
                                            wire:model.blur="overrides.{{ $field }}"
                                            class="mt-2 block w-full rounded-lg border-gray-300 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                        >
                                            <option value="">Usar origem resolvida</option>
                                            <option value="1">Sim</option>
                                            <option value="0">Não</option>
                                        </select>
                                    @elseif (str_ends_with($field, '_date'))
                                        <input
                                            id="override-{{ $field }}"
                                            type="date"
                                            wire:model.blur="overrides.{{ $field }}"
                                            class="mt-2 block w-full rounded-lg border-gray-300 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                        />
                                    @else
                                        <input
                                            id="override-{{ $field }}"
                                            type="text"
                                            wire:model.blur="overrides.{{ $field }}"
                                            placeholder="informe para simulação"
                                            class="mt-2 block w-full rounded-lg border-gray-300 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                        />
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($schedule !== [])
                <div class="mt-5 rounded-lg border border-gray-200 p-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Cronograma contratual</h3>
                    <div class="mt-2 grid gap-x-6 gap-y-1 md:grid-cols-2 xl:grid-cols-4">
                        <span>1º cupom: <strong>{{ $date($schedule['first_interest_payment_date'] ?? null) ?? 'Não definido' }}</strong></span>
                        <span>Periodicidade: <strong>{{ $show($schedule['interest_payment_frequency'] ?? null) ?? 'Não definido' }}</strong></span>
                        <span>Amortização: <strong>{{ $show($schedule['amortization'] ?? null) ?? 'Não definido' }}</strong></span>
                        <span>Convenção: <strong>{{ $show($schedule['payment_convention'] ?? null) ?? 'Não definido' }}</strong></span>
                    </div>
                </div>
            @endif

            @if ($conflicts !== [])
                <div class="mt-4 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm dark:border-danger-700 dark:bg-danger-950/30">
                    <p class="font-semibold text-danger-800 dark:text-danger-200">
                        Divergência entre contrato lido e parâmetro persistido
                    </p>
                    <ul class="mt-2 list-inside list-disc text-danger-700 dark:text-danger-300">
                        @foreach ($conflicts as $conflict)
                            <li>
                                {{ $fieldLabels[$conflict['field']] ?? $conflict['field'] }}:
                                contratual <strong>{{ $conflict['contractual'] }}</strong>,
                                persistido <strong>{{ $conflict['persisted'] }}</strong>
                                — usado nesta simulação: <strong>{{ $conflict['used'] }}</strong>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <x-filament::button wire:click="calculate" wire:loading.attr="disabled" icon="heroicon-o-calculator">
                    <span wire:loading.remove wire:target="calculate">Calcular PU</span>
                    <span wire:loading wire:target="calculate">Calculando…</span>
                </x-filament::button>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    O cálculo é determinístico e usa apenas dados locais: nenhuma consulta externa é feita aqui.
                </span>
            </div>
        </section>

        @if ($result)
            {{-- Estado da simulação --}}
            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::badge :color="$result->state->color()">{{ $result->state->label() }}</x-filament::badge>
                    <span class="text-sm text-gray-600 dark:text-gray-300">{{ $result->reason }}</span>
                </div>

                @if ($result->missingFields !== [])
                    <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-700 dark:bg-warning-950/30">
                        <p class="font-semibold text-warning-800 dark:text-warning-200">Informe para simulação</p>
                        <ul class="mt-2 list-inside list-disc text-warning-700 dark:text-warning-300">
                            @foreach ($result->missingFields as $field)
                                <li>{{ $fieldLabels[$field] ?? $field }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! ($result->calendarDiagnostics['resolvable'] ?? true))
                    <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-700 dark:bg-warning-950/30">
                        <p class="font-semibold text-warning-800 dark:text-warning-200">Cobertura de calendário incompleta</p>
                        <p class="mt-1 text-warning-700 dark:text-warning-300">
                            Calendário {{ $result->calendarDiagnostics['calendar_code'] ?? '—' }},
                            janela {{ $date($result->calendarDiagnostics['from'] ?? null) }}
                            a {{ $date($result->calendarDiagnostics['to'] ?? null) }}.
                        </p>
                        <p class="mt-1 whitespace-pre-line text-warning-700 dark:text-warning-300">
                            {{ $result->calendarDiagnostics['reason'] ?? '' }}
                        </p>
                    </div>
                @endif

                @if ($result->missingRateDates !== [])
                    <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-700 dark:bg-warning-950/30">
                        <p class="font-semibold text-warning-800 dark:text-warning-200">
                            Não foi possível calcular. Faltam taxas para:
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($result->missingRateDates as $missingDate)
                                <span class="rounded bg-warning-100 px-2 py-0.5 font-mono text-xs text-warning-900 dark:bg-warning-900/40 dark:text-warning-200">
                                    {{ $date($missingDate) }}
                                </span>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-warning-700 dark:text-warning-300">
                            Nenhuma taxa anterior, próxima, interpolada ou mais recente é usada como substituta.
                        </p>
                        @if ($canSync)
                            <div class="mt-3">
                                {{ $this->syncRequiredRatesAction }}
                            </div>
                        @endif
                    </div>
                @endif

                @if ($result->conflictingRates !== [])
                    <div class="mt-3 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm dark:border-danger-700 dark:bg-danger-950/30">
                        <p class="font-semibold text-danger-800 dark:text-danger-200">Conflito em taxa já armazenada</p>
                        <ul class="mt-2 list-inside list-disc text-danger-700 dark:text-danger-300">
                            @foreach ($result->conflictingRates as $conflict)
                                <li>{{ $date($conflict['date'] ?? null) }} — {{ implode('; ', $conflict['reasons'] ?? []) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif

        @if ($result && $result->calculated())
            {{-- Resultado principal --}}
            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-success-300 bg-success-50 p-4 dark:border-success-700 dark:bg-success-950/30">
                    <p class="text-xs font-medium uppercase tracking-wide text-success-800 dark:text-success-300">
                        PU na data selecionada
                    </p>
                    <p class="mt-1 break-all font-mono text-xl font-semibold text-success-900 dark:text-success-200">
                        {{ $money($detail?->updatedUnitValue) ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs text-success-800 dark:text-success-300">
                        {{ $date($detail?->date?->toDateString()) ?? '—' }} — 8 casas; precisão integral preservada na memória
                    </p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">PU residual</p>
                    <p class="mt-1 break-all font-mono text-sm text-gray-900 dark:text-gray-100">{{ $money($detail?->residualUnitValue) ?? '—' }}</p>
                    <p class="mt-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Juros no dia</p>
                    <p class="mt-1 break-all font-mono text-sm text-gray-900 dark:text-gray-100">{{ $money($detail?->interestRealUnitValue) ?? '—' }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">CDI utilizado</p>
                    <p class="mt-1 break-all font-mono text-sm text-gray-900 dark:text-gray-100">{{ $rate($detail?->indexRateValue) ?? '—' }}</p>
                    <p class="mt-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Data da taxa</p>
                    <p class="mt-1 font-mono text-sm text-gray-900 dark:text-gray-100">{{ $date($detail?->indexRateDate?->toDateString()) ?? '—' }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Janela simulada</p>
                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                        {{ $date($result->startDate?->toDateString()) }} → {{ $date($result->endDate?->toDateString()) }}
                    </p>
                    <p class="mt-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Linhas geradas</p>
                    <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $result->rowCount() }}</p>
                    @if ($result->selectedTotalValue() !== null)
                        <p class="mt-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Posição total (PU × quantidade)
                        </p>
                        <p class="mt-1 break-all font-mono text-sm text-gray-900 dark:text-gray-100">
                            {{ $money($result->selectedTotalValue()) ?? '—' }}
                        </p>
                    @endif
                </div>
            </section>

            {{-- Prêmio do primeiro cupom --}}
            @if ($result->premium['enabled'] ?? false)
                <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Prêmio do primeiro cupom</h2>
                    @if ($result->premium['resolvable'] ?? false)
                        <div class="mt-2 grid gap-x-6 gap-y-1 text-sm text-gray-600 md:grid-cols-2 dark:text-gray-300">
                            <span>Situação: <strong>ativo</strong></span>
                            <span>Período: <strong>{{ $result->premium['business_days'] }} DU anteriores à primeira integralização</strong></span>
                            <span>CDI: <strong>{{ ($result->premium['applies_index_factor'] ?? false) ? 'incluído' : 'não incluído' }}</strong></span>
                            <span>Spread: <strong>{{ ($result->premium['applies_spread_factor'] ?? false) ? 'incluído' : 'não incluído' }}</strong></span>
                        </div>
                        @if (!empty($result->premium['accrual_dates']))
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Datas de acúmulo: {{ implode(', ', array_map($date, $result->premium['accrual_dates'])) }}
                            </p>
                        @endif
                        @if (!empty($result->premium['rate_dates']))
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Taxas CDI usadas: {{ implode(', ', array_map($date, $result->premium['rate_dates'])) }}
                            </p>
                        @endif
                        @if (!empty($result->premium['memory']))
                            <div class="mt-2 grid gap-x-6 gap-y-1 font-mono text-xs text-gray-600 md:grid-cols-3 dark:text-gray-300">
                                <span>Fator CDI: {{ $factor($result->premium['memory']['factor_di'] ?? null) ?? '—' }}</span>
                                <span>Fator spread: {{ $factor($result->premium['memory']['factor_spread'] ?? null) ?? '—' }}</span>
                                <span>Prêmio acumulado: {{ $factor($result->premium['memory']['factor'] ?? null) ?? '—' }}</span>
                            </div>
                        @endif
                    @else
                        <p class="mt-2 whitespace-pre-line text-sm text-warning-700 dark:text-warning-300">
                            {{ $result->premium['reason'] ?? 'Prêmio habilitado, mas não resolvível com os parâmetros informados.' }}
                        </p>
                    @endif
                </section>
            @endif

            {{-- Cronograma: honestidade sobre a origem dos eventos --}}
            @if (! ($result->scheduleDiagnostics['resolvable'] ?? true))
                <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-700 dark:bg-warning-950/40">
                    <p class="font-semibold text-warning-800 dark:text-warning-200">Cronograma de eventos não resolvido</p>
                    <p class="mt-1 text-warning-700 dark:text-warning-300">
                        {{ $result->scheduleDiagnostics['reason'] ?? '' }}
                        Eventos aplicados nesta simulação: {{ $result->scheduleDiagnostics['event_count'] ?? 0 }}.
                    </p>
                </div>
            @endif

            {{-- Eventos --}}
            @if ($result->events !== [])
                <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Eventos contratuais na janela ({{ count($result->events) }})
                    </h2>
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2 text-left">Tipo</th>
                                    <th class="px-3 py-2 text-left">Data original</th>
                                    <th class="px-3 py-2 text-left">Data efetiva</th>
                                    <th class="px-3 py-2 text-left">Amortização</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach (array_slice($result->events, 0, 60) as $event)
                                    <tr>
                                        <td class="px-3 py-1.5 text-gray-900 dark:text-gray-100">{{ $event['event_type'] }}</td>
                                        <td class="px-3 py-1.5 font-mono text-gray-700 dark:text-gray-300">{{ $date($event['original_date'] ?? null) }}</td>
                                        <td class="px-3 py-1.5 font-mono text-gray-700 dark:text-gray-300">{{ $date($event['effective_date'] ?? null) }}</td>
                                        <td class="px-3 py-1.5 text-gray-700 dark:text-gray-300">{{ $event['amortization_type'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            {{-- Curva diária --}}
            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Curva diária ({{ count($rows) }} de {{ $result->rowCount() }} linhas)
                    </h2>
                    <div class="flex flex-wrap items-center gap-4 text-xs text-gray-600 dark:text-gray-300">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model.live="businessDaysOnly" class="rounded border-gray-300 dark:border-gray-600" />
                            Somente dias úteis
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model.live="paymentsOnly" class="rounded border-gray-300 dark:border-gray-600" />
                            Somente pagamentos
                        </label>
                    </div>
                </div>

                <div class="mt-3 max-h-[32rem] overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 bg-white text-xs uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 text-left">Data</th>
                                <th class="px-3 py-2 text-left">DU</th>
                                <th class="px-3 py-2 text-left">Data CDI</th>
                                <th class="px-3 py-2 text-right">CDI</th>
                                <th class="px-3 py-2 text-right">Juros</th>
                                <th class="px-3 py-2 text-right">Amortização</th>
                                <th class="px-3 py-2 text-right">Pagamento</th>
                                <th class="px-3 py-2 text-right">PU</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($rows as $row)
                                @php $rowDate = $row->date->toDateString(); @endphp
                                <tr @class([
                                    'hover:bg-gray-50 dark:hover:bg-gray-800/50',
                                    'bg-primary-50 dark:bg-primary-950/30' => $rowDate === ($detail?->date?->toDateString()),
                                ])>
                                    <td class="whitespace-nowrap px-3 py-1.5 font-mono text-gray-900 dark:text-gray-100">{{ $row->date->format('d/m/Y') }}</td>
                                    <td class="px-3 py-1.5 text-gray-700 dark:text-gray-300">{{ $row->isBusinessDay ? 'Sim' : 'Não' }}</td>
                                    <td class="whitespace-nowrap px-3 py-1.5 font-mono text-gray-700 dark:text-gray-300">{{ $date($row->indexRateDate?->toDateString()) ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-right font-mono text-gray-700 dark:text-gray-300">{{ $rate($row->indexRateValue) ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-right font-mono text-gray-700 dark:text-gray-300">{{ $money($row->interestRealUnitValue) }}</td>
                                    <td class="px-3 py-1.5 text-right font-mono text-gray-700 dark:text-gray-300">{{ $money($row->amortizationUnitValue) }}</td>
                                    <td class="px-3 py-1.5 text-right font-mono text-gray-700 dark:text-gray-300">{{ $money($row->paymentTotalUnitValue) }}</td>
                                    <td class="px-3 py-1.5 text-right font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $money($row->updatedUnitValue) }}</td>
                                    <td class="px-3 py-1.5 text-right">
                                        <button
                                            type="button"
                                            wire:click="selectCurveDate('{{ $rowDate }}')"
                                            class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                        >
                                            Abrir memória
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                        Nenhuma linha para os filtros selecionados.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Memória de cálculo --}}
            @if ($detail)
                @php $memory = $detail->calculationMemory; @endphp
                <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            Memória de cálculo — {{ $detail->date->format('d/m/Y') }}
                        </h2>
                        @if ($this->selectedCurveDate)
                            <button
                                type="button"
                                wire:click="clearSelectedCurveDate"
                                class="text-xs font-medium text-gray-500 hover:underline dark:text-gray-400"
                            >
                                Voltar para a data em destaque
                            </button>
                        @endif
                    </div>

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Todos os componentes abaixo são os que a engine efetivamente produziu para esta data.
                        Nenhum valor é recomposto na apresentação.
                    </p>

                    <div class="mt-3 grid gap-x-8 gap-y-2 text-sm md:grid-cols-2">
                        @php
                            $memoryRows = [
                                'Data da curva' => $detail->date->format('d/m/Y'),
                                'Dia útil' => $detail->isBusinessDay ? 'Sim' : 'Não',
                                'Calendário' => $memory['calendar_code'] ?? null,
                                'Modo de busca do índice' => $memory['index_rate_lookup_mode'] ?? null,
                                'Período do cupom' => $date($memory['coupon_period_start_date'] ?? null)
                                    ? $date($memory['coupon_period_start_date']) . ' → ' . $date($memory['coupon_period_end_date'] ?? null)
                                    : null,
                                'Última Data de Pagamento' => $date($memory['last_payment_date'] ?? null),
                                'PU base do período' => $money($detail->unitBaseValue),
                                'DUP (juros)' => $detail->dupInterest,
                                'DUT (juros)' => $detail->dutInterest,
                                'Data da taxa CDI utilizada' => $date($detail->indexRateDate?->toDateString()),
                                'Taxa CDI' => $rate($detail->indexRateValue),
                                'Fator CDI diário' => $factor($detail->factorDi),
                                'Fator CDI acumulado' => $factor($detail->factorDiAccumulated),
                                'Fator spread diário' => $factor($detail->factorSpread),
                                'Fator spread + CDI' => $factor($detail->factorSpreadDi),
                                'Juros acumulados no período' => $money($detail->interestRealUnitValue),
                                'PU atualizado' => $money($detail->updatedUnitValue),
                                'Amortização' => $money($detail->amortizationUnitValue),
                                'Proporção de amortização' => $factor($detail->amortizationRatio),
                                'Pagamento de juros' => $money($detail->interestPaymentUnitValue),
                                'Pagamento total' => $money($detail->paymentTotalUnitValue),
                                'PU residual' => $money($detail->residualUnitValue),
                                'Data original do evento' => $date($detail->eventOriginalDate?->toDateString()),
                                'Data efetiva do evento' => $date($detail->eventEffectiveDate?->toDateString()),
                                'Reset após pagamento' => ($memory['reset_after_payment'] ?? false) ? 'Sim' : 'Não',
                                'Versão da engine' => $memory['engine_version'] ?? null,
                            ];
                        @endphp
                        @foreach ($memoryRows as $memoryLabel => $memoryValue)
                            <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-1 dark:border-gray-800">
                                <span class="text-gray-600 dark:text-gray-400">{{ $memoryLabel }}</span>
                                <span class="break-all text-right font-mono text-gray-900 dark:text-gray-100">
                                    {{ $show($memoryValue) ?? '—' }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    @if (!empty($memory['precision_rules']))
                        @php $rules = $memory['precision_rules']; @endphp
                        <div class="mt-3 rounded-lg border border-gray-200 p-3 text-xs dark:border-gray-700">
                            <p class="font-semibold text-gray-700 dark:text-gray-300">
                                Precisão aplicada por estágio
                            </p>
                            <p class="mt-1 text-gray-500 dark:text-gray-400">
                                Casas decimais efetivamente arredondadas pela engine em cada etapa.
                                &ldquo;integral&rdquo; = sem arredondamento intermediário nesta etapa.
                            </p>
                            <div class="mt-2 grid gap-x-6 gap-y-1 font-mono text-gray-600 md:grid-cols-2 dark:text-gray-300">
                                @php
                                    $ruleLabels = [
                                        'daily_index_factor' => 'Fator DI diário (1 + TDIk)',
                                        'accumulated_index_factor' => 'Produtório DI acumulado',
                                        'index_factor_for_combination' => 'Fator DI antes da combinação',
                                        'spread_factor' => 'Fator Spread',
                                        'combined_interest_factor' => 'Fator DI × Fator Spread',
                                        'interest_unit_value' => 'Juros',
                                    ];
                                @endphp
                                @foreach ($ruleLabels as $ruleKey => $ruleLabel)
                                    <span>
                                        {{ $ruleLabel }}:
                                        <strong>{{ ($rules[$ruleKey] ?? null) === null ? 'integral' : $rules[$ruleKey].' casas' }}</strong>
                                    </span>
                                @endforeach
                                <span>Arredondamento: <strong>{{ $rules['rounding_mode'] ?? '—' }}</strong></span>
                            </div>
                        </div>
                    @endif

                    @if (!empty($memory['event_types']))
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                            Eventos nesta data: <strong>{{ implode(', ', $memory['event_types']) }}</strong>
                        </p>
                    @endif

                    @if ($memory['first_coupon_pre_integralization_premium_applied'] ?? false)
                        <div class="mt-3 rounded-lg border border-primary-300 bg-primary-50 p-3 text-sm dark:border-primary-700 dark:bg-primary-950/30">
                            <p class="font-semibold text-primary-900 dark:text-primary-200">
                                Prêmio pré-integralização aplicado nesta data
                            </p>
                            <div class="mt-2 grid gap-x-6 gap-y-1 font-mono text-xs text-primary-800 dark:text-primary-300 md:grid-cols-2">
                                <span>Fator antes do prêmio: {{ $factor($memory['factor_spread_di_before_first_coupon_premium_raw'] ?? null) ?? '—' }}</span>
                                <span>Fator do prêmio: {{ $factor($memory['first_coupon_pre_integralization_premium']['factor'] ?? null) ?? '—' }}</span>
                                <span>Fator CDI do prêmio: {{ $factor($memory['first_coupon_pre_integralization_premium']['factor_di'] ?? null) ?? '—' }}</span>
                                <span>Fator spread do prêmio: {{ $factor($memory['first_coupon_pre_integralization_premium']['factor_spread'] ?? null) ?? '—' }}</span>
                            </div>
                        </div>
                    @endif
                </section>
            @endif
        @endif

        @if (! $result)
            <section class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Informe as hipóteses acima e clique em <strong>Calcular PU</strong> para executar a engine
                    oficial em modo simulação.
                </p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
