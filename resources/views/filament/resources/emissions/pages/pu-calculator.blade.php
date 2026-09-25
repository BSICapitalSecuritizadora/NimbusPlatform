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
        /**
         * Fator vindo da memória em escala de cálculo (24 casas). Exibido em 16,
         * a mesma escala dos fatores da linha, para que a auditoria compare
         * estágios na mesma régua em vez de ler uma cauda de zeros.
         */
        $auditFactor = fn ($value) => $show($value)
            ? $presenter->decimal($value, \App\Domain\PuCalculator\Services\DecimalRounder::FACTOR_SCALE)
            : null;
        /**
         * Valor monetário ANTES da quantização contratual. Vai a 16 casas de
         * propósito: exibi-lo em 8 esconderia exatamente a cauda que a regra
         * "8 casas sem arredondamento" corta, e a auditoria não veria o corte.
         */
        $auditMoney = fn ($value) => $show($value)
            ? $presenter->decimal($value, \App\Domain\PuCalculator\Services\DecimalRounder::UNIT_SCALE)
            : null;

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

        $activeOverridesCount = count(array_filter($this->overrides, fn ($val) => $val !== null && $val !== ''))
            + ($this->accrualCalendarOverride() ? 1 : 0)
            + ($this->indexRateCalendarOverride() ? 1 : 0);
    @endphp

    {{-- Contêiner Raiz: Base da tela em Azul-Petróleo Institucional (#091B23) --}}
    <div class="-m-4 sm:-m-6 lg:-m-8 p-4 sm:p-6 lg:p-8 min-h-[calc(100vh-4.5rem)] bg-[#091B23] space-y-6 text-[#E6E4E4]">
        {{-- Banner obrigatório: a natureza não-operacional precede tudo (Nível 2 #0D2530 com filete lateral âmbar) --}}
        <div class="relative overflow-hidden rounded-xl border border-[#1e4756] bg-[#0D2530] p-4.5 shadow-sm">
            <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-[#A06E28]"></div>
            <div class="flex items-start gap-3.5 pl-1.5">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#A06E28]/15 text-[#A06E28]">
                    <x-heroicon-o-shield-exclamation class="h-5 w-5" />
                </div>
                <div>
                    <h2 class="text-xs font-bold tracking-wider text-[#A06E28] uppercase">
                        SIMULAÇÃO — NÃO OPERACIONAL
                    </h2>
                    <p class="mt-0.5 text-xs text-[#E6E4E4]/90">
                        Os valores abaixo não alteram a emissão, não criam uma curva oficial e não podem ser
                        utilizados como evidência de homologação.
                    </p>
                </div>
            </div>
        </div>

        {{-- Identificação da Emissão (Nível 2 #0D2530) --}}
        <section class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#1e4756]/60 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-7 w-7 items-center justify-center rounded-md bg-[#12313B] text-[#A06E28]">
                        <x-heroicon-o-document-text class="h-4 w-4" />
                    </div>
                    <h2 class="text-sm font-semibold tracking-wide text-[#E6E4E4] uppercase">
                        Emissão
                    </h2>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="rounded bg-[#12313B] px-2.5 py-1 text-gray-300 border border-[#1e4756]">
                        IF: <strong class="text-[#E6E4E4] font-medium">{{ $show($emission->if_code) ?? '—' }}</strong>
                    </span>
                    <span class="rounded bg-[#12313B] px-2.5 py-1 text-gray-300 border border-[#1e4756]">
                        ISIN: <strong class="text-[#E6E4E4] font-medium">{{ $show($emission->isin_code) ?? '—' }}</strong>
                    </span>
                </div>
            </div>

            <div class="mt-4 grid gap-x-6 gap-y-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <span class="block text-gray-400">Emissão:</span>
                    <strong class="mt-0.5 block text-sm font-medium text-[#E6E4E4]">
                        #{{ $emission->id }} {{ $emission->name }}
                    </strong>
                </div>
                <div>
                    <span class="block text-gray-400">Tipo:</span>
                    <strong class="mt-0.5 block text-sm font-medium text-[#E6E4E4]">
                        {{ $show($emission->type) ?? '—' }}
                    </strong>
                </div>
                <div>
                    <span class="block text-gray-400">Emissão em / Vencimento:</span>
                    <strong class="mt-0.5 block text-sm font-medium text-[#E6E4E4]">
                        {{ $date($emission->issue_date) ?? '—' }} → {{ $date($emission->maturity_date) ?? '—' }}
                    </strong>
                </div>
                <div>
                    <span class="block text-gray-400">Governança Operacional:</span>
                    <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
                        <span class="text-gray-400">Curva:</span>
                        <strong class="text-[#E6E4E4]">{{ $emission->latestPuCurveVersion()->first()?->calculation_version ?? 'nenhuma' }}</strong>
                        <span class="text-gray-500">|</span>
                        <span class="text-gray-400">Parâmetro:</span>
                        <strong class="text-[#E6E4E4]">{{ $emission->puParameter()->exists() ? 'sim' : 'não' }}</strong>
                    </div>
                </div>
            </div>

            <p class="mt-3 border-t border-[#1e4756]/40 pt-2.5 text-[11px] text-gray-400">
                Gate C operacional e demais portões de governança não bloqueiam esta tela: eles
                controlam candidate, validação externa e promoção — não a simulação.
            </p>
        </section>

        {{-- Formulário de Premissas e Hipóteses (Nível 2 #0D2530) --}}
        <section class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-5 shadow-sm space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#1e4756]/60 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-7 w-7 items-center justify-center rounded-md bg-[#12313B] text-[#A06E28]">
                        <x-heroicon-o-adjustments-horizontal class="h-4 w-4" />
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold tracking-wide text-[#E6E4E4] uppercase">
                            Parâmetros da simulação
                        </h2>
                        <p class="text-xs text-gray-400">Configure as premissas contratuais e a janela de simulação desejada.</p>
                    </div>
                </div>

                {{-- Status de Overrides ativos --}}
                @if ($activeOverridesCount > 0)
                    <div class="inline-flex items-center gap-1.5 rounded-full border border-[#A06E28]/40 bg-[#A06E28]/10 px-3 py-1 text-xs text-[#A06E28]">
                        <span class="h-1.5 w-1.5 rounded-full bg-[#A06E28]"></span>
                        <span>{{ $activeOverridesCount }} override(s) ativos</span>
                    </div>
                @endif
            </div>

            {{-- Perfil de cálculo (Nível 3 #12313B) --}}
            <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-4">
                <div class="flex items-center justify-between gap-2 border-b border-[#1e4756]/60 pb-2 mb-3">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[#A06E28]">
                        Perfil de cálculo
                    </span>
                    <span class="text-[11px] text-gray-400">Contratual é a autoridade oficial</span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($this->calculationProfileOptions() as $profileOption)
                        <label @class([
                            'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                            'border-[#A06E28] bg-[#A06E28]/15 shadow-sm' => $calculationProfile === $profileOption['value'],
                            'border-[#1e4756] bg-[#0D2530] hover:bg-[#102A36]' => $calculationProfile !== $profileOption['value'],
                        ])>
                            <input
                                type="radio"
                                name="calculationProfile"
                                value="{{ $profileOption['value'] }}"
                                wire:model.live="calculationProfile"
                                class="mt-0.5 text-[#A06E28] focus:ring-[#A06E28] border-[#1e4756] bg-[#0D2530] accent-[#A06E28]"
                            />
                            <div class="min-w-0 flex-1">
                                <span class="block text-xs font-semibold text-[#E6E4E4]">{{ $profileOption['label'] }}</span>
                                <span class="mt-0.5 block text-[11px] text-gray-400 leading-relaxed">{{ $profileOption['description'] }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>

                @if ($this->calculationProfileWarning())
                    <div class="relative mt-3 overflow-hidden rounded-lg border border-[#1e4756] bg-[#0D2530] p-3 text-xs text-[#E6E4E4]">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#A06E28]"></div>
                        <div class="pl-2">
                            <p class="text-amber-300 font-medium">{{ $this->calculationProfileWarning() }}</p>
                            @if ($this->calculationProfileFirstCouponWarning())
                                <p class="mt-1.5 text-gray-300">{{ $this->calculationProfileFirstCouponWarning() }}</p>
                            @endif
                            <p class="mt-1.5 text-[11px] text-gray-400">
                                Este perfil existe apenas nesta tela: ele não é persistido, não gera candidate,
                                não promove parâmetro e não vale como homologação.
                            </p>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Premissas Principais da Simulação (Grid de 4 colunas horizontais em Nível 3 #12313B) --}}
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-300">
                        Premissas Principais & Janela
                    </h3>
                    <span class="text-[11px] text-gray-400">Inputs de execução da simulação</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {{-- 1. Data da primeira integralização --}}
                    <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-3.5 flex flex-col justify-between">
                        <div>
                            <div class="flex items-start justify-between gap-1">
                                <label class="block text-xs font-semibold text-[#E6E4E4]" for="firstIntegralizationDate">
                                    Data da primeira integralização
                                </label>
                                @if ($firstIntegralizationDate)
                                    <span class="rounded bg-[#A06E28]/20 px-1.5 py-0.5 text-[10px] font-medium text-amber-300 border border-[#A06E28]/30">
                                        Override de simulação
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 text-[11px] text-gray-300 leading-snug">
                                <strong class="text-[#E6E4E4]">PARÂMETRO DE SIMULAÇÃO</strong> — não satisfaz o Gate C.
                            </p>
                        </div>
                        <input
                            id="firstIntegralizationDate"
                            type="date"
                            wire:model.blur="firstIntegralizationDate"
                            class="mt-2.5 block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2.5 py-1.5 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
                        />
                    </div>

                    {{-- 2. Data final da simulação (Obrigatória) --}}
                    <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-3.5 flex flex-col justify-between">
                        <div>
                            <label class="block text-xs font-semibold text-[#E6E4E4]" for="simulationEndDate">
                                Data final da simulação <span class="text-rose-400">*</span>
                            </label>
                            <p class="mt-1 text-[11px] text-gray-300 leading-snug">
                                Obrigatória: janela máxima de {{ \App\Domain\PuCalculator\Services\PuSimulationService::MAX_WINDOW_YEARS }} anos.
                            </p>
                        </div>
                        <input
                            id="simulationEndDate"
                            type="date"
                            wire:model.blur="simulationEndDate"
                            class="mt-2.5 block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2.5 py-1.5 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
                        />
                    </div>

                    {{-- 3. Data do PU em destaque --}}
                    <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-3.5 flex flex-col justify-between">
                        <div>
                            <label class="block text-xs font-semibold text-[#E6E4E4]" for="focusDate">
                                Data do PU em destaque
                            </label>
                            <p class="mt-1 text-[11px] text-gray-300 leading-snug">
                                Em branco: usa a última data calculada da janela.
                            </p>
                        </div>
                        <input
                            id="focusDate"
                            type="date"
                            wire:model.blur="focusDate"
                            class="mt-2.5 block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2.5 py-1.5 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
                        />
                    </div>

                    {{-- 4. Quantidade para simulação --}}
                    <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-3.5 flex flex-col justify-between">
                        <div>
                            <label class="block text-xs font-semibold text-[#E6E4E4]" for="quantity">
                                Quantidade para simulação
                            </label>
                            <p class="mt-1 text-[11px] text-gray-300 leading-snug">
                                Opcional: calcula posição total (PU × quantidade).
                            </p>
                        </div>
                        <input
                            id="quantity"
                            type="text"
                            inputmode="decimal"
                            wire:model.blur="quantity"
                            placeholder="opcional"
                            class="mt-2.5 block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2.5 py-1.5 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
                        />
                    </div>
                </div>
            </div>

            {{-- Parâmetros Financeiros Ativos & Cronograma (Nível 3 #12313B) --}}
            <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-4">
                <div class="flex items-center justify-between border-b border-[#1e4756]/60 pb-2 mb-3">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-300">
                        Parâmetros Financeiros & Cronograma Contratual
                    </span>
                    <span class="text-[11px] text-gray-400">Origem documental resolvida</span>
                </div>

                <div class="grid gap-x-6 gap-y-2.5 text-xs sm:grid-cols-2 lg:grid-cols-4">
                    <div class="flex items-center justify-between py-1 border-b border-[#1e4756]/30">
                        <span class="text-gray-400">Indexador:</span>
                        <span class="font-medium text-[#E6E4E4]">{{ $show($values['indexer']) ?? 'CDI' }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1 border-b border-[#1e4756]/30">
                        <span class="text-gray-400">Spread:</span>
                        <span class="font-medium text-[#E6E4E4]">{{ $show($values['spread_rate']) ? $values['spread_rate'].' % a.a.' : '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1 border-b border-[#1e4756]/30">
                        <span class="text-gray-400">Base de dias úteis:</span>
                        <span class="font-medium text-[#E6E4E4]">{{ $show($values['business_day_basis']) ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1 border-b border-[#1e4756]/30">
                        <span class="text-gray-400">Calendário:</span>
                        <span class="font-medium text-[#E6E4E4]">{{ $show($values['calendar_code']) ?? '—' }}</span>
                    </div>

                    @if ($schedule !== [])
                        <div class="flex items-center justify-between py-1 border-b border-[#1e4756]/30">
                            <span class="text-gray-400">1º cupom:</span>
                            <span class="font-medium text-[#E6E4E4]">{{ $date($schedule['first_interest_payment_date'] ?? null) ?? 'Não definido' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-1 border-b border-[#1e4756]/30">
                            <span class="text-gray-400">Periodicidade:</span>
                            <span class="font-medium text-[#E6E4E4]">{{ $show($schedule['interest_payment_frequency'] ?? null) ?? 'Não definido' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-1 border-b border-[#1e4756]/30">
                            <span class="text-gray-400">Amortização:</span>
                            <span class="font-medium text-[#E6E4E4]">{{ $show($schedule['amortization'] ?? null) ?? 'Não definido' }}</span>
                        </div>
                        <div class="flex items-center justify-between py-1 border-b border-[#1e4756]/30">
                            <span class="text-gray-400">Convenção:</span>
                            <span class="font-medium text-[#E6E4E4]">{{ $show($schedule['payment_convention'] ?? null) ?? 'Não definido' }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Parâmetros Avançados, Calendários e Overrides (Accordion em Nível 3 #12313B) --}}
            <div x-data="{ open: false }" class="rounded-xl border border-[#1e4756] bg-[#12313B] overflow-hidden transition-all">
                <button
                    type="button"
                    x-on:click="open = ! open"
                    class="flex w-full items-center justify-between px-5 py-3.5 text-left text-xs font-semibold text-[#E6E4E4] hover:bg-[#163845] transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <div class="flex h-6 w-6 items-center justify-center rounded bg-[#0D2530] text-[#A06E28]">
                            <x-heroicon-o-variable class="h-3.5 w-3.5" />
                        </div>
                        <div>
                            <span class="uppercase tracking-wider text-gray-200">Parâmetros Avançados, Calendários e Overrides</span>
                            <span class="block text-[11px] font-normal text-gray-300">Ajuste hipóteses de cálculo, calendários de simulação e sobreposições por campo</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($activeOverridesCount > 0)
                            <span class="rounded bg-[#A06E28]/25 border border-[#A06E28]/40 px-2 py-0.5 text-[10px] font-semibold text-amber-300">
                                {{ $activeOverridesCount }} ativo(s)
                            </span>
                        @endif
                        <x-heroicon-m-chevron-down
                            class="h-4 w-4 text-gray-300 transition-transform duration-200"
                            x-bind:class="{ 'rotate-180 text-[#A06E28]': open }"
                        />
                    </div>
                </button>

                <div x-show="open" x-cloak class="border-t border-[#1e4756] p-5 space-y-6 bg-[#0D2530]">
                    {{-- Calendários de Hipótese da Simulação (Subcards em Nível 3 #12313B) --}}
                    <div class="grid gap-4 md:grid-cols-2">
                        {{-- Calendário de accrual --}}
                        <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-4">
                            <div class="flex items-start justify-between gap-2">
                                <label class="block text-xs font-semibold text-[#E6E4E4]" for="accrualCalendarCode">
                                    Calendário de accrual da curva
                                </label>
                                <span class="rounded bg-[#A06E28]/20 border border-[#A06E28]/30 px-2 py-0.5 text-[10px] font-medium text-amber-300">
                                    Override de simulação — não persiste
                                </span>
                            </div>
                            <p class="mt-1 text-[11px] text-gray-300 leading-relaxed">
                                Decide quais dias da curva contam como Dia Útil nesta simulação: contagem de DU, DUP/DUT e
                                incidência do fator diário. Pagamentos continuam no calendário contratual.
                            </p>
                            <select
                                id="accrualCalendarCode"
                                wire:model.blur="accrualCalendarCode"
                                class="mt-3 block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2.5 py-1.5 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
                            >
                                @foreach ($this->accrualCalendarOptions() as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Calendário de observação do CDI --}}
                        <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-4">
                            <div class="flex items-start justify-between gap-2">
                                <label class="block text-xs font-semibold text-[#E6E4E4]" for="indexRateCalendarCode">
                                    Calendário de observação do CDI
                                </label>
                                <span class="rounded bg-[#A06E28]/20 border border-[#A06E28]/30 px-2 py-0.5 text-[10px] font-medium text-amber-300">
                                    Hipótese de simulação
                                </span>
                            </div>
                            <p class="mt-1 text-[11px] text-gray-300 leading-relaxed">
                                Utilizado somente para resolver as datas de observação do CDI nesta simulação. Não altera a
                                definição contratual de Dia Útil e não modifica a emissão.
                            </p>
                            <select
                                id="indexRateCalendarCode"
                                wire:model.blur="indexRateCalendarCode"
                                class="mt-3 block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2.5 py-1.5 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
                            >
                                @foreach ($this->indexRateCalendarOptions() as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Comparativo dos Calendários Ativos --}}
                    <dl class="grid gap-2.5 rounded-lg border border-[#1e4756] bg-[#12313B] p-3 text-xs sm:grid-cols-3">
                        <div>
                            <dt class="font-medium text-gray-400">Calendário da curva</dt>
                            <dd class="mt-0.5 text-[#E6E4E4]">
                                {{ $this->curveCalendarCode() ?? '—' }}
                                <span class="text-gray-400">— Contratual</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-400">Calendário de accrual</dt>
                            <dd class="mt-0.5 text-[#E6E4E4]">
                                @if ($this->accrualCalendarOverride())
                                    <span class="text-amber-300 font-medium">{{ $this->accrualCalendarOverride() }}</span>
                                    <span class="text-[11px] text-gray-300">— Override</span>
                                @else
                                    <span class="text-gray-400">Mesmo calendário contratual</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-gray-400">Calendário CDI</dt>
                            <dd class="mt-0.5 text-[#E6E4E4]">
                                @if ($this->indexRateCalendarOverride())
                                    <span class="text-amber-300 font-medium">{{ $this->indexRateCalendarOverride() }}</span>
                                    <span class="text-[11px] text-gray-300">— Override</span>
                                @elseif ($this->savedIndexRateCalendarCode())
                                    <span class="text-[#E6E4E4]">{{ $this->savedIndexRateCalendarCode() }}</span>
                                    <span class="text-[11px] text-gray-300">— configuração salva</span>
                                @else
                                    <span class="text-gray-400">Mesmo calendário da curva</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- Overrides por grupo --}}
                    <div class="space-y-5 pt-2">
                        @foreach ($groups as $groupLabel => $fields)
                            <div>
                                <h4 class="text-xs font-semibold tracking-wider text-[#A06E28] uppercase">{{ $groupLabel }}</h4>
                                <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    @foreach ($fields as $field)
                                        @php
                                            $origin = $originBadge($origins[$field] ?? null);
                                            $resolved = $values[$field] ?? null;
                                            $isBoolField = str_contains($field, 'apply_') || str_ends_with($field, '_enabled');
                                        @endphp
                                        <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-3 flex flex-col justify-between">
                                            <div>
                                                <div class="flex items-start justify-between gap-1">
                                                    <label class="text-[11px] font-medium text-gray-300" for="override-{{ $field }}">
                                                        {{ $fieldLabels[$field] ?? $field }}
                                                    </label>
                                                    <x-filament::badge size="xs" :color="$origin->color()">
                                                        {{ $origin->label() }}
                                                    </x-filament::badge>
                                                </div>

                                                <p class="mt-1 text-xs font-semibold text-[#E6E4E4]">
                                                    @if ($isBoolField)
                                                        {{ $resolved === null ? 'Não definido' : (filter_var($resolved, FILTER_VALIDATE_BOOLEAN) ? 'Sim' : 'Não') }}
                                                    @elseif (in_array($field, $monetaryParameterFields, true))
                                                        {{ $money($resolved) ?? 'Não definido' }}
                                                    @else
                                                        {{ $show($resolved) ?? 'Não definido' }}
                                                    @endif
                                                </p>
                                            </div>

                                            <div class="mt-2.5">
                                                @if ($field === 'indexer')
                                                    <select
                                                        id="override-{{ $field }}"
                                                        wire:model.blur="overrides.{{ $field }}"
                                                        class="block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2 py-1 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
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
                                                        class="block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2 py-1 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
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
                                                        class="block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2 py-1 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
                                                    />
                                                @else
                                                    <input
                                                        id="override-{{ $field }}"
                                                        type="text"
                                                        wire:model.blur="overrides.{{ $field }}"
                                                        placeholder="informe para simulação"
                                                        class="block w-full rounded-md border border-[#1e4756] bg-[#0D2530] px-2 py-1 text-xs text-[#E6E4E4] shadow-sm focus:border-[#A06E28] focus:ring-1 focus:ring-[#A06E28]"
                                                    />
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Divergências / Conflitos --}}
            @if ($conflicts !== [])
                <div class="relative overflow-hidden rounded-xl border border-[#1e4756] bg-[#12313B] p-4 text-xs">
                    <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-rose-500"></div>
                    <div class="pl-2">
                        <p class="font-semibold text-rose-300">
                            Divergência entre contrato lido e parâmetro persistido
                        </p>
                        <ul class="mt-2 list-inside list-disc space-y-1 text-rose-200">
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
                </div>
            @endif

            {{-- Barra de Ação de Cálculo (Executiva e Destacada) --}}
            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-[#1e4756]/60 pt-4">
                <button
                    type="button"
                    wire:click="calculate"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center gap-2.5 rounded-lg bg-[#A06E28] px-6 py-2.5 text-xs font-semibold uppercase tracking-wider text-white shadow-md transition-all hover:bg-[#8c5f21] active:scale-[0.99] disabled:opacity-50"
                >
                    <x-heroicon-o-calculator class="h-4 w-4" wire:loading.remove wire:target="calculate" />
                    <svg class="h-4 w-4 animate-spin text-white" wire:loading wire:target="calculate" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span wire:loading.remove wire:target="calculate">Calcular PU</span>
                    <span wire:loading wire:target="calculate">Calculando…</span>
                </button>

                <p class="text-[11px] text-gray-400">
                    O cálculo é determinístico e usa apenas dados locais: nenhuma consulta externa é feita aqui.
                </p>
            </div>
        </section>

        {{-- Diagnósticos & Avisos de Simulação (Nível 2 #0D2530) --}}
        @if ($result)
            <section class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-5 shadow-sm space-y-4">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::badge :color="$result->state->color()">{{ $result->state->label() }}</x-filament::badge>
                    <span class="text-xs text-gray-300">{{ $result->reason }}</span>
                </div>

                {{-- Campos faltantes --}}
                @if ($result->missingFields !== [])
                    <div class="relative overflow-hidden rounded-lg border border-[#1e4756] bg-[#12313B] p-4 text-xs text-[#E6E4E4]">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#A06E28]"></div>
                        <div class="pl-2">
                            <p class="font-semibold text-amber-300">Informe para simulação</p>
                            <ul class="mt-2 list-inside list-disc space-y-0.5 text-gray-300">
                                @foreach ($result->missingFields as $field)
                                    <li>{{ $fieldLabels[$field] ?? $field }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                {{-- Diagnóstico de calendário --}}
                @if (! ($result->calendarDiagnostics['resolvable'] ?? true))
                    <div class="relative overflow-hidden rounded-lg border border-[#1e4756] bg-[#12313B] p-4 text-xs text-[#E6E4E4]">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#A06E28]"></div>
                        <div class="pl-2">
                            <p class="font-semibold text-amber-300">Cobertura de calendário incompleta</p>
                            <p class="mt-1 text-gray-300">
                                Calendário {{ $result->calendarDiagnostics['calendar_code'] ?? '—' }},
                                janela {{ $date($result->calendarDiagnostics['from'] ?? null) }}
                                a {{ $date($result->calendarDiagnostics['to'] ?? null) }}.
                            </p>
                            <p class="mt-1 whitespace-pre-line text-gray-400">
                                {{ $result->calendarDiagnostics['reason'] ?? '' }}
                            </p>
                        </div>
                    </div>
                @endif

                {{-- Taxas ausentes --}}
                @if ($result->missingRateDates !== [])
                    <div class="relative overflow-hidden rounded-lg border border-[#1e4756] bg-[#12313B] p-4 text-xs text-[#E6E4E4]">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#A06E28]"></div>
                        <div class="pl-2">
                            <p class="font-semibold text-amber-300">
                                Não foi possível calcular. Faltam taxas para:
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($result->missingRateDates as $missingDate)
                                    <span class="rounded bg-[#163845] border border-[#1e4756] px-2 py-0.5 font-mono text-[11px] text-amber-300 tabular-nums">
                                        {{ $date($missingDate) }}
                                    </span>
                                @endforeach
                            </div>
                            <p class="mt-2 text-[11px] text-gray-400">
                                Nenhuma taxa anterior, próxima, interpolada ou mais recente é usada como substituta.
                            </p>
                            @if ($canSync)
                                <div class="mt-3">
                                    {{ $this->syncRequiredRatesAction }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Conflitos em taxa --}}
                @if ($result->conflictingRates !== [])
                    <div class="relative overflow-hidden rounded-lg border border-[#1e4756] bg-[#12313B] p-4 text-xs text-[#E6E4E4]">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-rose-500"></div>
                        <div class="pl-2">
                            <p class="font-semibold text-rose-300">Conflito em taxa já armazenada</p>
                            <ul class="mt-2 list-inside list-disc space-y-0.5 text-rose-200">
                                @foreach ($result->conflictingRates as $conflict)
                                    <li>{{ $date($conflict['date'] ?? null) }} — {{ implode('; ', $conflict['reasons'] ?? []) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
            </section>
        @endif

        {{-- RESULTADO DA SIMULAÇÃO --}}
        @if ($result && $result->calculated())
            <div class="space-y-6 pt-2">
                {{-- Separador Visual Executivo --}}
                <div class="flex items-center gap-3">
                    <span class="text-xs font-bold tracking-widest uppercase text-[#A06E28]">
                        RESULTADO DA SIMULAÇÃO
                    </span>
                    <div class="h-px flex-1 bg-gradient-to-r from-[#1e4756] via-[#1e4756]/50 to-transparent"></div>
                </div>

                {{-- KPIs Executivos Principais (Grid horizontal de 4 colunas em Nível 2 #0D2530) --}}
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {{-- KPI 1: PU na data selecionada (Destaque Máximo) --}}
                    <div class="rounded-xl border border-[#A06E28]/60 bg-[#0D2530] p-4.5 shadow-md relative overflow-hidden flex flex-col justify-between">
                        <div class="absolute top-0 right-0 left-0 h-1 bg-gradient-to-r from-[#A06E28] to-[#d4af37]"></div>
                        <div>
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-semibold uppercase tracking-wider text-[#A06E28]">
                                    PU na data selecionada
                                </span>
                                <span class="rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[10px] font-medium px-1.5 py-0.5">
                                    Simulado
                                </span>
                            </div>
                            <p class="mt-2 font-mono text-2xl lg:text-3xl font-bold tracking-tight text-[#fbfaf8] tabular-nums">
                                {{ $money($detail?->updatedUnitValue) ?? '—' }}
                            </p>
                        </div>
                        <p class="mt-2 text-[11px] text-gray-400">
                            {{ $date($detail?->date?->toDateString()) ?? '—' }} — 8 casas; precisão integral preservada na memória
                        </p>
                    </div>

                    {{-- KPI 2: Posição Total & PU Residual --}}
                    <div class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-4.5 shadow-sm flex flex-col justify-between">
                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-300">
                                @if ($result->selectedTotalValue() !== null)
                                    Posição total (PU × quantidade)
                                @else
                                    PU residual
                                @endif
                            </span>
                            <p class="mt-2 font-mono text-xl lg:text-2xl font-bold text-[#fbfaf8] tabular-nums">
                                @if ($result->selectedTotalValue() !== null)
                                    {{ $money($result->selectedTotalValue()) ?? '—' }}
                                @else
                                    {{ $money($detail?->residualUnitValue) ?? '—' }}
                                @endif
                            </p>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center justify-between text-[11px] text-gray-300">
                            <span>PU residual: <strong class="font-mono text-[#E6E4E4] tabular-nums">{{ $money($detail?->residualUnitValue) ?? '—' }}</strong></span>
                            <span>Juros: <strong class="font-mono text-[#E6E4E4] tabular-nums">{{ $money($detail?->interestRealUnitValue) ?? '—' }}</strong></span>
                        </div>
                    </div>

                    {{-- KPI 3: CDI Utilizado --}}
                    <div class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-4.5 shadow-sm flex flex-col justify-between">
                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-300">
                                CDI utilizado
                            </span>
                            <p class="mt-2 font-mono text-xl lg:text-2xl font-bold text-[#fbfaf8] tabular-nums">
                                {{ $rate($detail?->indexRateValue) ?? '—' }}
                            </p>
                        </div>
                        <div class="mt-2 text-[11px] text-gray-300">
                            <span>Data da taxa: <strong class="font-mono text-[#E6E4E4] tabular-nums">{{ $date($detail?->indexRateDate?->toDateString()) ?? '—' }}</strong></span>
                        </div>
                    </div>

                    {{-- KPI 4: Janela & Linhas Geradas --}}
                    <div class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-4.5 shadow-sm flex flex-col justify-between">
                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-300">
                                Janela simulada
                            </span>
                            <p class="mt-2 text-sm lg:text-base font-semibold text-[#fbfaf8]">
                                {{ $date($result->startDate?->toDateString()) }} → {{ $date($result->endDate?->toDateString()) }}
                            </p>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-[11px] text-gray-300">
                            <span>Linhas geradas: <strong class="text-[#E6E4E4]">{{ $result->rowCount() }}</strong></span>
                        </div>
                    </div>
                </section>

                {{-- Prêmio do primeiro cupom (Nível 2 #0D2530) --}}
                @if ($result->premium['enabled'] ?? false)
                    <section class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-5 shadow-sm">
                        <div class="flex items-center gap-2 border-b border-[#1e4756]/60 pb-2 mb-3">
                            <div class="flex h-5 w-5 items-center justify-center rounded bg-[#12313B] text-[#A06E28]">
                                <x-heroicon-o-gift class="h-3.5 w-3.5" />
                            </div>
                            <h3 class="text-xs font-semibold tracking-wider text-[#E6E4E4] uppercase">
                                Prêmio do primeiro cupom
                            </h3>
                        </div>

                        @if ($result->premium['resolvable'] ?? false)
                            <div class="grid gap-x-6 gap-y-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <span class="text-gray-400">Situação:</span>
                                    <strong class="ml-1 text-emerald-400">ativo</strong>
                                </div>
                                <div>
                                    <span class="text-gray-400">Período:</span>
                                    <strong class="ml-1 text-[#E6E4E4]">{{ $result->premium['business_days'] }} DU anteriores à primeira integralização</strong>
                                </div>
                                <div>
                                    <span class="text-gray-400">CDI:</span>
                                    <strong class="ml-1 text-[#E6E4E4]">{{ ($result->premium['applies_index_factor'] ?? false) ? 'incluído' : 'não incluído' }}</strong>
                                </div>
                                <div>
                                    <span class="text-gray-400">Spread:</span>
                                    <strong class="ml-1 text-[#E6E4E4]">{{ ($result->premium['applies_spread_factor'] ?? false) ? 'incluído' : 'não incluído' }}</strong>
                                </div>
                            </div>

                            @if (!empty($result->premium['accrual_dates']))
                                <p class="mt-2 text-[11px] text-gray-400">
                                    Datas de acúmulo: {{ implode(', ', array_map($date, $result->premium['accrual_dates'])) }}
                                </p>
                            @endif
                            @if (!empty($result->premium['rate_dates']))
                                <p class="text-[11px] text-gray-400">
                                    Taxas CDI usadas: {{ implode(', ', array_map($date, $result->premium['rate_dates'])) }}
                                </p>
                            @endif
                            @if (!empty($result->premium['memory']))
                                <div class="mt-3 grid gap-x-6 gap-y-1.5 rounded-lg border border-[#1e4756] bg-[#12313B] p-3 font-mono text-xs tabular-nums sm:grid-cols-3">
                                    <div><span class="text-gray-400">Fator CDI:</span> <span class="text-[#E6E4E4]">{{ $factor($result->premium['memory']['factor_di'] ?? null) ?? '—' }}</span></div>
                                    <div><span class="text-gray-400">Fator spread:</span> <span class="text-[#E6E4E4]">{{ $factor($result->premium['memory']['factor_spread'] ?? null) ?? '—' }}</span></div>
                                    <div><span class="text-gray-400">Prêmio acumulado:</span> <span class="text-amber-300 font-semibold">{{ $factor($result->premium['memory']['factor'] ?? null) ?? '—' }}</span></div>
                                </div>
                            @endif
                        @else
                            <p class="whitespace-pre-line text-xs text-amber-300">
                                {{ $result->premium['reason'] ?? 'Prêmio habilitado, mas não resolvível com os parâmetros informados.' }}
                            </p>
                        @endif
                    </section>
                @endif

                {{-- Cronograma: honestidade sobre a origem dos eventos --}}
                @if (! ($result->scheduleDiagnostics['resolvable'] ?? true))
                    <div class="relative overflow-hidden rounded-xl border border-[#1e4756] bg-[#0D2530] p-4 text-xs text-[#E6E4E4]">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#A06E28]"></div>
                        <div class="pl-2">
                            <p class="font-semibold text-amber-300">Cronograma de eventos não resolvido</p>
                            <p class="mt-1 text-gray-300">
                                {{ $result->scheduleDiagnostics['reason'] ?? '' }}
                                Eventos aplicados nesta simulação: {{ $result->scheduleDiagnostics['event_count'] ?? 0 }}.
                            </p>
                        </div>
                    </div>
                @endif

                {{-- Eventos contratuais na janela (Nível 2 #0D2530) --}}
                @if ($result->events !== [])
                    <section class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-5 shadow-sm">
                        <div class="flex items-center justify-between border-b border-[#1e4756]/60 pb-2 mb-3">
                            <h3 class="text-xs font-semibold tracking-wider text-[#E6E4E4] uppercase">
                                Eventos contratuais na janela ({{ count($result->events) }})
                            </h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead>
                                    <tr class="border-b border-[#1e4756] text-[11px] font-semibold text-gray-300 uppercase tracking-wider">
                                        <th class="py-2 pr-3 text-left">Tipo</th>
                                        <th class="px-3 py-2 text-left">Data original</th>
                                        <th class="px-3 py-2 text-left">Data efetiva</th>
                                        <th class="pl-3 py-2 text-left">Amortização</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#1e4756]/40">
                                    @foreach (array_slice($result->events, 0, 60) as $event)
                                        <tr class="hover:bg-[#12313B]/70 transition-colors">
                                            <td class="py-2 pr-3 font-medium text-[#E6E4E4]">{{ $event['event_type'] }}</td>
                                            <td class="px-3 py-2 font-mono text-gray-300 tabular-nums">{{ $date($event['original_date'] ?? null) }}</td>
                                            <td class="px-3 py-2 font-mono text-gray-300 tabular-nums">{{ $date($event['effective_date'] ?? null) }}</td>
                                            <td class="pl-3 py-2 text-gray-400">{{ $event['amortization_type'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                {{-- Curva diária (Tabela Executiva em Nível 2 #0D2530 com Interior em Nível 3 #12313B) --}}
                <section class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#1e4756]/60 pb-3">
                        <div class="flex items-center gap-2">
                            <div class="flex h-6 w-6 items-center justify-center rounded bg-[#12313B] text-[#A06E28]">
                                <x-heroicon-o-chart-bar class="h-3.5 w-3.5" />
                            </div>
                            <h3 class="text-xs font-semibold tracking-wider text-[#E6E4E4] uppercase">
                                Curva diária ({{ count($rows) }} de {{ $result->rowCount() }} linhas)
                            </h3>
                        </div>
                        <div class="flex flex-wrap items-center gap-4 text-xs text-gray-300">
                            <label class="flex items-center gap-2 cursor-pointer hover:text-white transition-colors">
                                <input type="checkbox" wire:model.live="businessDaysOnly" class="rounded border-[#1e4756] bg-[#0D2530] text-[#A06E28] focus:ring-[#A06E28]" />
                                <span>Somente dias úteis</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer hover:text-white transition-colors">
                                <input type="checkbox" wire:model.live="paymentsOnly" class="rounded border-[#1e4756] bg-[#0D2530] text-[#A06E28] focus:ring-[#A06E28]" />
                                <span>Somente pagamentos</span>
                            </label>
                        </div>
                    </div>

                    {{-- Container da Tabela em Nível 3 #12313B --}}
                    <div class="mt-3 max-h-[32rem] overflow-auto rounded-lg border border-[#1e4756] bg-[#12313B]">
                        <table class="min-w-full text-xs">
                            {{-- Header fixo da Tabela em Azul Profundo Nível 4 #163845 --}}
                            <thead class="sticky top-0 bg-[#163845] text-[11px] font-semibold text-gray-200 uppercase tracking-wider z-10 shadow-sm border-b border-[#1e4756]">
                                <tr>
                                    <th class="px-3 py-2.5 text-left">Data</th>
                                    <th class="px-3 py-2.5 text-left">DU</th>
                                    <th class="px-3 py-2.5 text-left">Data CDI</th>
                                    <th class="px-3 py-2.5 text-right">CDI</th>
                                    <th class="px-3 py-2.5 text-right">Juros</th>
                                    <th class="px-3 py-2.5 text-right">Amortização</th>
                                    <th class="px-3 py-2.5 text-right">Pagamento</th>
                                    <th class="px-3 py-2.5 text-right">PU</th>
                                    <th class="px-3 py-2.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#1e4756]/40 bg-[#12313B]">
                                @forelse ($rows as $row)
                                    @php $rowDate = $row->date->toDateString(); @endphp
                                    <tr @class([
                                        'hover:bg-[#184150] transition-colors',
                                        'bg-[#A06E28]/20 border-l-2 border-l-[#A06E28]' => $rowDate === ($detail?->date?->toDateString()),
                                    ])>
                                        <td class="whitespace-nowrap px-3 py-2 font-mono text-[#E6E4E4] tabular-nums">{{ $row->date->format('d/m/Y') }}</td>
                                        <td class="px-3 py-2 text-gray-300">
                                            @if ($row->isBusinessDay)
                                                <span class="text-emerald-400 font-medium">Sim</span>
                                            @else
                                                <span class="text-gray-400">Não</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 font-mono text-gray-300 tabular-nums">{{ $date($row->indexRateDate?->toDateString()) ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-gray-300 tabular-nums">{{ $rate($row->indexRateValue) ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-gray-300 tabular-nums">{{ $money($row->interestRealUnitValue) }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-gray-300 tabular-nums">{{ $money($row->amortizationUnitValue) }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-gray-300 tabular-nums">{{ $money($row->paymentTotalUnitValue) }}</td>
                                        <td class="px-3 py-2 text-right font-mono font-semibold text-[#fbfaf8] tabular-nums">{{ $money($row->updatedUnitValue) }}</td>
                                        <td class="px-3 py-2 text-right">
                                            <button
                                                type="button"
                                                wire:click="selectCurveDate('{{ $rowDate }}')"
                                                class="rounded px-2 py-0.5 text-[11px] font-medium text-amber-300 hover:bg-[#A06E28]/25 hover:underline transition-colors"
                                            >
                                                Abrir memória
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-3 py-8 text-center text-xs text-gray-400">
                                            Nenhuma linha para os filtros selecionados.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- Memória de cálculo (Nível 2 #0D2530) --}}
                @if ($detail)
                    @php $memory = $detail->calculationMemory; @endphp
                    <section class="rounded-xl border border-[#1e4756] bg-[#0D2530] p-5 shadow-sm space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#1e4756]/60 pb-3">
                            <div class="flex items-center gap-2">
                                <div class="flex h-6 w-6 items-center justify-center rounded bg-[#12313B] text-[#A06E28]">
                                    <x-heroicon-o-calculator class="h-3.5 w-3.5" />
                                </div>
                                <h3 class="text-xs font-semibold tracking-wider text-[#E6E4E4] uppercase">
                                    Memória de cálculo — {{ $detail->date->format('d/m/Y') }}
                                </h3>
                            </div>
                            @if ($this->selectedCurveDate)
                                <button
                                    type="button"
                                    wire:click="clearSelectedCurveDate"
                                    class="text-xs font-medium text-[#A06E28] hover:underline"
                                >
                                    Voltar para a data em destaque
                                </button>
                            @endif
                        </div>

                        <p class="text-[11px] text-gray-400">
                            Todos os componentes abaixo são os que a engine efetivamente produziu para esta data.
                            Nenhum valor é recomposto na apresentação.
                        </p>

                        <div class="grid gap-x-8 gap-y-2 text-xs md:grid-cols-2">
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
                                    'Perfil de cálculo' => $memory['calculation_profile_label'] ?? null,
                                    'VNb aplicado (PU base do período)' => $money($detail->unitBaseValue),
                                    'DUP (juros)' => $detail->dupInterest,
                                    'DUT (juros)' => $detail->dutInterest,
                                    'Data da taxa CDI utilizada' => $date($detail->indexRateDate?->toDateString()),
                                    'Taxa CDI' => $rate($detail->indexRateValue),
                                    'Fator CDI diário' => $factor($detail->factorDi),
                                    'Fator CDI acumulado (bruto)' => $factor($detail->factorDiAccumulated),
                                    'Fator CDI aplicado na combinação' => $auditFactor($memory['factor_di_applied_raw'] ?? null),
                                    'Fator Spread bruto' => $auditFactor($memory['factor_spread_unrounded_raw'] ?? null),
                                    'Fator Spread acumulado' => $factor($detail->factorSpread),
                                    'Produto DI × Spread (bruto)' => $factor($detail->factorSpreadDi),
                                    'Fator de Juros aplicado' => $auditFactor($memory['interest_factor_applied_raw'] ?? null),
                                    'VNb bruto' => $auditMoney($memory['base_unit_value_unquantized_raw'] ?? null),
                                    'Juros bruto' => $auditMoney($memory['interest_real_unit_value_unquantized_raw'] ?? null),
                                    'Juros aplicado (J)' => $money($detail->interestRealUnitValue),
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
                                <div class="flex items-start justify-between gap-4 border-b border-[#1e4756]/40 pb-1.5">
                                    <span class="text-gray-400">{{ $memoryLabel }}</span>
                                    <span class="break-all text-right font-mono text-[#E6E4E4] tabular-nums font-medium">
                                        {{ $show($memoryValue) ?? '—' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        {{-- Precisão por estágio (Nível 3 #12313B) --}}
                        @if (!empty($memory['precision_rules']))
                            @php $rules = $memory['precision_rules']; @endphp
                            <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-4 text-xs">
                                <p class="font-semibold text-gray-300">
                                    Precisão aplicada por estágio
                                </p>
                                <p class="mt-1 text-[11px] text-gray-400">
                                    Casas decimais efetivamente aplicadas pela engine em cada etapa.
                                    &ldquo;integral&rdquo; = sem arredondamento intermediário nesta etapa.
                                    O produtório do Fator DI é TRUNCADO (corte, não arredondamento) após cada multiplicação.
                                </p>
                                <div class="mt-2.5 grid gap-x-6 gap-y-1.5 font-mono text-gray-300 sm:grid-cols-2">
                                    @php
                                        $ruleLabels = [
                                            'daily_index_factor' => 'Fator DI diário (1 + TDIk)',
                                            'accumulated_index_factor' => 'Produtório DI acumulado',
                                            'index_factor_for_combination' => 'Fator DI antes da combinação',
                                            'spread_factor' => 'Fator Spread',
                                            'combined_interest_factor' => 'Fator DI × Fator Spread',
                                        ];
                                        $unitValueLabels = [
                                            'unit_base_value' => 'VNb',
                                            'interest_unit_value' => 'J (juros)',
                                            'amortization_unit_value' => 'AMi (amortização)',
                                            'residual_unit_value' => 'SDa (saldo devedor)',
                                        ];
                                        $truncatesAccumulated = ($rules['accumulated_index_factor_mode'] ?? null) === 'truncate_after_each_multiplication';
                                    @endphp
                                    @foreach ($ruleLabels as $ruleKey => $ruleLabel)
                                        <div>
                                            {{ $ruleLabel }}:
                                            <strong class="text-[#E6E4E4]">
                                                @if ($ruleKey === 'accumulated_index_factor' && $truncatesAccumulated)
                                                    truncamento progressivo em {{ $rules[$ruleKey] }} casas após cada multiplicação
                                                @elseif (($rules[$ruleKey] ?? null) === null)
                                                    integral
                                                @else
                                                    {{ $rules[$ruleKey] }} casas
                                                @endif
                                            </strong>
                                        </div>
                                    @endforeach
                                    <div>Arredondamento dos fatores: <strong class="text-[#E6E4E4]">{{ $rules['rounding_mode'] ?? '—' }}</strong></div>
                                </div>

                                <p class="mt-4 font-semibold text-gray-300">
                                    Valores monetários — 8 casas SEM arredondamento
                                </p>
                                <p class="mt-1 text-[11px] text-gray-400">
                                    VNb, J, AMi e SDa são TRUNCADOS pela engine. A tela apenas formata a string
                                    já quantizada: ela não decide valor econômico.
                                </p>
                                <div class="mt-2.5 grid gap-x-6 gap-y-1.5 font-mono text-gray-300 sm:grid-cols-2">
                                    @foreach ($unitValueLabels as $ruleKey => $ruleLabel)
                                        <div>
                                            {{ $ruleLabel }}:
                                            <strong class="text-[#E6E4E4]">{{ ($rules[$ruleKey] ?? null) === null ? 'integral' : $rules[$ruleKey].' casas, truncado' }}</strong>
                                        </div>
                                    @endforeach
                                    <div>Perfil: <strong class="text-[#E6E4E4]">{{ $memory['calculation_profile_label'] ?? '—' }}</strong></div>
                                </div>
                            </div>
                        @endif

                        {{-- Reconciliação: contratual x perfil selecionado (Nível 3 #12313B) --}}
                        @php $comparison = $result?->selectedProfileComparison(); @endphp
                        @if (!empty($comparison))
                            <div class="relative overflow-hidden rounded-lg border border-[#1e4756] bg-[#12313B] p-4 text-xs">
                                <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#A06E28]"></div>
                                <div class="pl-2">
                                    <p class="font-semibold text-amber-300">
                                        Contratual × {{ $memory['calculation_profile_label'] ?? 'perfil selecionado' }}
                                    </p>
                                    <p class="mt-1 text-gray-300">
                                        A referência oficial do Nimbus continua sendo a coluna contratual. A diferença
                                        abaixo é medida, nunca ajustada.
                                    </p>
                                    <div class="mt-3 overflow-x-auto">
                                        <table class="w-full text-left font-mono text-xs tabular-nums">
                                            <thead>
                                                <tr class="border-b border-[#1e4756] text-[11px] uppercase tracking-wider text-gray-300">
                                                    <th class="py-1.5 pr-2 font-semibold">Campo</th>
                                                    <th class="py-1.5 pr-2 text-right font-semibold">Contratual</th>
                                                    <th class="py-1.5 pr-2 text-right font-semibold">Perfil</th>
                                                    <th class="py-1.5 text-right font-semibold">Diferença</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-[#1e4756]/40">
                                                @foreach ([
                                                    'Juros (J)' => 'interest',
                                                    'Pagamento' => 'payment',
                                                    'PU atualizado' => 'updated_unit_value',
                                                    'PU residual' => 'residual_unit_value',
                                                ] as $comparisonLabel => $comparisonKey)
                                                    <tr>
                                                        <td class="py-1.5 pr-2 text-gray-200">{{ $comparisonLabel }}</td>
                                                        <td class="py-1.5 pr-2 text-right text-gray-200">{{ $money($comparison[$comparisonKey.'_contractual'] ?? null) ?? '—' }}</td>
                                                        <td class="py-1.5 pr-2 text-right text-gray-200">{{ $money($comparison[$comparisonKey.'_profile'] ?? null) ?? '—' }}</td>
                                                        <td class="py-1.5 text-right text-amber-300 font-semibold">{{ $money($comparison[$comparisonKey.'_delta'] ?? null) ?? '—' }}</td>
                                                    </tr>
                                                @endforeach
                                                <tr>
                                                    <td class="py-1.5 pr-2 text-gray-200">Prêmio do 1º cupom</td>
                                                    <td class="py-1.5 pr-2 text-right text-gray-200">{{ $comparison['first_coupon_premium_contractual'] ?? '—' }}</td>
                                                    <td class="py-1.5 pr-2 text-right text-gray-200">{{ $comparison['first_coupon_premium_profile'] ?? '—' }}</td>
                                                    <td class="py-1.5 text-right text-gray-400">—</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if (!empty($memory['event_types']))
                            <p class="text-xs text-gray-400">
                                Eventos nesta data: <strong class="text-[#E6E4E4]">{{ implode(', ', $memory['event_types']) }}</strong>
                            </p>
                        @endif

                        @if ($memory['first_coupon_pre_integralization_premium_applied'] ?? false)
                            <div class="rounded-lg border border-[#1e4756] bg-[#12313B] p-4 text-xs">
                                <p class="font-semibold text-[#A06E28]">
                                    Prêmio pré-integralização aplicado nesta data
                                </p>
                                <div class="mt-2.5 grid gap-x-6 gap-y-1.5 font-mono text-gray-300 sm:grid-cols-2">
                                    <div>Fator antes do prêmio: <span class="text-[#E6E4E4]">{{ $factor($memory['factor_spread_di_before_first_coupon_premium_raw'] ?? null) ?? '—' }}</span></div>
                                    <div>Fator do prêmio: <span class="text-[#E6E4E4]">{{ $factor($memory['first_coupon_pre_integralization_premium']['factor'] ?? null) ?? '—' }}</span></div>
                                    <div>Fator CDI do prêmio: <span class="text-[#E6E4E4]">{{ $factor($memory['first_coupon_pre_integralization_premium']['factor_di'] ?? null) ?? '—' }}</span></div>
                                    <div>Fator spread do prêmio: <span class="text-[#E6E4E4]">{{ $factor($memory['first_coupon_pre_integralization_premium']['factor_spread'] ?? null) ?? '—' }}</span></div>
                                </div>
                            </div>
                        @endif
                    </section>
                @endif
            </div>
        @endif

        {{-- Estado Vazio / Inicial (Nível 2 #0D2530 Sólido com Borda Tracejada e Ícone Nível 3) --}}
        @if (! $result)
            <section class="rounded-xl border border-dashed border-[#1e4756] bg-[#0D2530] p-10 text-center shadow-sm">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-[#12313B] text-[#A06E28]">
                    <x-heroicon-o-chart-pie class="h-6 w-6" />
                </div>
                <h3 class="mt-3.5 text-sm font-semibold text-[#fbfaf8]">
                    Nenhuma simulação calculada ainda
                </h3>
                <p class="mx-auto mt-1 max-w-md text-xs text-gray-400 leading-relaxed">
                    Informe as hipóteses acima e clique em <strong class="text-[#A06E28]">Calcular PU</strong> para executar a engine
                    oficial em modo simulação.
                </p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
