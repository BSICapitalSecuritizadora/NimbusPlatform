@php
    /** @var array<string, mixed>|null $latestSummary */
    $latestSummary ??= null;
    $canManageGuarantees ??= false;
    $canRegisterMonthlyIndicators ??= false;
    $migrationPending ??= false;
    $needsMonthlyUpdate ??= false;

    $formatCurrency = static fn (mixed $value): string => 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);
    $formatRatio = static fn (?float $value): string => $value === null ? 'Nao disponivel' : number_format($value * 100, 0, ',', '.') . '%';
    $coverageRatio = $latestSummary['coverage_ratio'] ?? null;
    $coverageCardClasses = match (true) {
        $coverageRatio === null => 'border-white/10 bg-white/[0.03]',
        $coverageRatio > 1.3 => 'border-emerald-400/20 bg-emerald-500/10',
        $coverageRatio >= 1.2 => 'border-amber-400/20 bg-amber-500/10',
        default => 'border-rose-400/20 bg-rose-500/10',
    };
    $coverageLabelClasses = match (true) {
        $coverageRatio === null => 'text-gray-300',
        $coverageRatio > 1.3 => 'text-emerald-200',
        $coverageRatio >= 1.2 => 'text-amber-200',
        default => 'text-rose-200',
    };
    $coverageDescriptionClasses = match (true) {
        $coverageRatio === null => 'text-gray-300/80',
        $coverageRatio > 1.3 => 'text-emerald-100/80',
        $coverageRatio >= 1.2 => 'text-amber-100/80',
        default => 'text-rose-100/80',
    };
@endphp

<div class="mb-6 space-y-4">
    <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/10">
        <div class="flex flex-col gap-4 border-b border-white/10 px-6 py-5 sm:px-8 xl:flex-row xl:items-start xl:justify-between">
            <div class="space-y-2">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Cobertura de garantias</span>
                <div>
                    <h3 class="text-xl font-semibold text-white">
                        @if ($latestSummary)
                            Competencia base: {{ $latestSummary['reference_month_label'] }}
                        @else
                            Nenhum indicador mensal cadastrado
                        @endif
                    </h3>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-400">
                        Formula aplicada: (Valor das Quotas + Valor das Unidades + Recebiveis cedidos + Saldo das contas) / Saldo devedor.
                    </p>
                </div>

                @if ($canManageGuarantees)
                    <div class="flex flex-wrap gap-3 pt-2">
                        @if ($canRegisterMonthlyIndicators)
                            <x-filament::button
                                color="warning"
                                icon="heroicon-o-chart-bar-square"
                                size="sm"
                                wire:click="mountTableAction('update_monthly_snapshot')"
                            >
                                Atualizar indicadores mensais
                            </x-filament::button>
                        @endif

                        <x-filament::button
                            color="gray"
                            icon="heroicon-o-plus"
                            size="sm"
                            wire:click="mountTableAction('create')"
                        >
                            Cadastrar garantia
                        </x-filament::button>
                    </div>
                @endif
            </div>

            @if ($migrationPending)
                <div class="rounded-2xl border border-rose-400/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                    A tabela de indicadores mensais ainda nao foi criada. Execute a migration pendente e recarregue a pagina.
                </div>
            @elseif ($needsMonthlyUpdate)
                <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                    Atualizacao mensal pendente. Informe o valor das quotas do mes atual.
                </div>
            @endif
        </div>

        @if ($latestSummary)
            <div class="grid gap-4 px-6 py-6 sm:px-8 md:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Valor das quotas</span>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ $formatCurrency($latestSummary['quota_value']) }}</div>
                    <p class="mt-2 text-sm text-gray-400">Informado manualmente em Garantias.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Valor das unidades</span>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ $formatCurrency($latestSummary['units_value']) }}</div>
                    <p class="mt-2 text-sm text-gray-400">Soma do valor em estoque de todos os empreendimentos da emissao.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Recebiveis cedidos</span>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ $formatCurrency($latestSummary['receivables_value']) }}</div>
                    <p class="mt-2 text-sm text-gray-400">Consolidado a partir do resumo mensal de recebiveis.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Saldo das contas</span>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ $formatCurrency($latestSummary['account_balance_value']) }}</div>
                    <p class="mt-2 text-sm text-gray-400">Soma dos fundos relacionados a emissao na mesma competencia.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Saldo devedor</span>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ $formatCurrency($latestSummary['outstanding_balance_value']) }}</div>
                    <p class="mt-2 text-sm text-gray-400">Calculado automaticamente com base no ultimo PU do mes e na quantidade integralizada acumulada.</p>
                </div>

                <div class="rounded-2xl border p-4 {{ $coverageCardClasses }}">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] {{ $coverageLabelClasses }}">Indice de cobertura</span>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ $formatRatio($latestSummary['coverage_ratio']) }}</div>
                    <p class="mt-2 text-sm {{ $coverageDescriptionClasses }}">Total de garantias: {{ $formatCurrency($latestSummary['total_guarantees_value']) }}</p>
                </div>
            </div>

            @if (count($latestSummary['missing_sources']) > 0)
                <div class="border-t border-white/10 px-6 py-4 sm:px-8">
                    <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                        Dados automaticos ausentes na competencia {{ $latestSummary['reference_month_label'] }}:
                        {{ implode(', ', $latestSummary['missing_sources']) }}.
                    </div>
                </div>
            @endif
        @else
            <div class="px-6 py-6 sm:px-8">
                <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.03] px-4 py-5 text-sm text-gray-400">
                    @if ($migrationPending)
                        A consolidacao mensal ficara disponivel assim que a migration de <span class="font-medium text-white">guarantee snapshots</span> for aplicada.
                    @else
                        Use <span class="font-medium text-white">Atualizar indicadores mensais</span> para informar o valor das quotas da competencia. O sistema consolida automaticamente saldo devedor, unidades, recebiveis cedidos e saldo das contas.
                    @endif
                </div>
            </div>
        @endif
    </section>

    @include('filament.resources.emissions.relation-managers.guarantees-history', [
        'history' => $history,
    ])
</div>
