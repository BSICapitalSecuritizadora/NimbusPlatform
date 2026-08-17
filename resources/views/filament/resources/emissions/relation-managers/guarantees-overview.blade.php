@php
    use App\Enums\GuaranteeValueStatus;

    /** @var \App\DTOs\Guarantees\EmissionGuaranteePositionData $position */
    /** @var \Illuminate\Support\Collection $alerts */
    /** @var \Illuminate\Support\Collection $history */

    $alerts ??= collect();
    $history ??= collect();
    $pendingDetections ??= 0;
    $canUpdateValues ??= false;
    $canCloseCompetence ??= false;
    $canCreate ??= false;
    $isCompetenceClosed ??= false;

    // Ausência é dita, nunca convertida em zero (§25 do escopo).
    $money = static fn (?float $value): string => $value === null
        ? 'Não informado'
        : 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);
    $ratio = static fn (?float $value): string => $value === null
        ? '—'
        : number_format($value * 100, 2, ',', '.') . '%';

    $statusClasses = match ($position->coverageStatus->color()) {
        'success' => 'border-emerald-400/20 bg-emerald-500/10 text-emerald-200',
        'warning' => 'border-amber-400/20 bg-amber-500/10 text-amber-200',
        'danger' => 'border-rose-400/20 bg-rose-500/10 text-rose-200',
        default => 'border-white/10 bg-white/[0.03] text-gray-300',
    };

    $coverageCardClasses = match ($position->coverageStatus->color()) {
        'success' => 'border-emerald-400/20 bg-emerald-500/10',
        'warning' => 'border-amber-400/20 bg-amber-500/10',
        'danger' => 'border-rose-400/20 bg-rose-500/10',
        default => 'border-white/10 bg-white/[0.03]',
    };
@endphp

<div class="mb-6 space-y-4">
    {{-- 1. Resumo executivo --}}
    <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/10">
        <div class="flex flex-col gap-4 border-b border-white/10 px-6 py-5 sm:px-8 xl:flex-row xl:items-start xl:justify-between">
            <div class="space-y-2">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Cobertura de garantias</span>
                <h3 class="text-xl font-semibold text-white">
                    Competência {{ $position->referenceMonthLabel() }}
                </h3>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-400">
                    Cobertura = valor elegível das garantias vigentes ÷ saldo devedor da mesma competência.
                    O mínimo contratual é a regra mais restritiva entre as garantias ativas.
                </p>
            </div>

            <div class="flex flex-col items-start gap-3 xl:items-end">
                <span class="rounded-full border px-4 py-1.5 text-sm font-semibold uppercase tracking-wide {{ $statusClasses }}">
                    {{ $position->coverageStatus->label() }}
                </span>

                @if ($isCompetenceClosed)
                    <span class="text-xs text-gray-400">Competência fechada — indicador imutável.</span>
                @endif
            </div>
        </div>

        <div class="grid gap-4 px-6 py-6 sm:px-8 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Valor das Garantias</span>
                <div class="mt-3 text-2xl font-semibold text-white">{{ $money($position->totalEligibleValue) }}</div>
                <p class="mt-2 text-sm text-gray-400">
                    Bruto: {{ $money($position->totalGrossValue) }}
                </p>
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Saldo Devedor</span>
                <div class="mt-3 text-2xl font-semibold text-white">{{ $money($position->outstandingBalance) }}</div>
                <p class="mt-2 text-sm text-gray-400">Curva de PU da competência.</p>
            </div>

            <div class="rounded-2xl border p-4 {{ $coverageCardClasses }}">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-300">Cobertura</span>
                <div class="mt-3 text-2xl font-semibold text-white">{{ $ratio($position->coverageRatio) }}</div>
                <p class="mt-2 text-sm text-gray-300/80">
                    Mínimo contratual: {{ $ratio($position->requiredRatio) }}
                </p>
            </div>

            <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">
                    {{ ($position->surplusDeficit !== null && $position->surplusDeficit < 0) ? 'Déficit' : 'Excedente' }}
                </span>
                <div class="mt-3 text-2xl font-semibold text-white">
                    {{ $position->surplusDeficit === null ? 'Não apurado' : $money(abs($position->surplusDeficit)) }}
                </div>
                <p class="mt-2 text-sm text-gray-400">
                    {{ $position->activeGuaranteesCount }} garantia(s) ativa(s) · Exigido: {{ $money($position->totalRequiredValue) }}
                </p>
            </div>
        </div>
    </section>

    {{-- 2. Pendências / alertas --}}
    @if ($alerts->isNotEmpty())
        <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70">
            <div class="border-b border-white/10 px-6 py-4 sm:px-8">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Pendências e alertas</span>
                <h3 class="mt-1 text-lg font-semibold text-white">{{ $alerts->count() }} ponto(s) de atenção</h3>
            </div>

            <ul class="divide-y divide-white/10">
                @foreach ($alerts as $alert)
                    @php
                        $alertClasses = match ($alert['severity']) {
                            'danger' => 'bg-rose-500/10 text-rose-200',
                            'warning' => 'bg-amber-500/10 text-amber-200',
                            default => 'bg-white/[0.03] text-gray-300',
                        };
                    @endphp
                    <li class="flex flex-col gap-1 px-6 py-4 sm:px-8">
                        <div class="flex items-center gap-3">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $alertClasses }}">
                                {{ $alert['severity'] === 'danger' ? 'Crítico' : 'Atenção' }}
                            </span>
                            <span class="font-medium text-white">{{ $alert['title'] }}</span>
                        </div>
                        <p class="text-sm text-gray-400">{{ $alert['description'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- 3. Garantias detectadas --}}
    @if ($pendingDetections > 0)
        <section class="rounded-3xl border border-amber-400/20 bg-amber-500/10 px-6 py-5 sm:px-8">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-white">
                        {{ $pendingDetections }} garantia(s) detectada(s) nos documentos
                    </h3>
                    <p class="mt-1 text-sm text-amber-100/80">
                        Aguardam revisão na aba <span class="font-medium text-white">Garantias Detectadas</span>.
                        Nenhuma integra a emissão até ser confirmada.
                    </p>
                </div>
            </div>
        </section>
    @endif

    {{-- 5. Posição da competência --}}
    <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70">
        <div class="flex flex-col gap-2 border-b border-white/10 px-6 py-5 sm:px-8">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Posição da competência</span>
            <div class="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
                <h3 class="text-lg font-semibold text-white">Componentes de {{ $position->referenceMonthLabel() }}</h3>
                <p class="text-sm text-gray-400">
                    O sistema consolida automaticamente o que consegue apurar; só o que não tem fonte é solicitado.
                </p>
            </div>
        </div>

        @if ($position->positions->isEmpty())
            <div class="px-6 py-6 sm:px-8">
                <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.03] px-4 py-5 text-sm text-gray-400">
                    Nenhuma garantia cadastrada nesta emissão ainda.
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/[0.03]">
                        <tr class="text-left text-xs font-semibold uppercase tracking-[0.16em] text-gray-400">
                            <th class="px-4 py-3">Componente</th>
                            <th class="px-4 py-3">Origem</th>
                            <th class="px-4 py-3 text-right">Valor</th>
                            <th class="px-4 py-3">Atualização</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($position->positions as $row)
                            <tr class="align-top text-gray-200">
                                <td class="px-4 py-4">
                                    <div class="font-medium text-white">{{ $row->guarantee->display_name }}</div>
                                    <div class="mt-0.5 text-xs text-gray-400">
                                        {{ \App\Enums\GuaranteeType::labelFor($row->guarantee->type) }}
                                    </div>
                                </td>
                                <td class="px-4 py-4">{{ $row->value->source->label() }}</td>
                                <td class="px-4 py-4 text-right">
                                    <div class="font-medium text-white">{{ $money($row->currentValue()) }}</div>
                                    @if ($row->eligibleValue !== null && $row->eligibilityFactor < 1.0)
                                        <div class="mt-0.5 text-xs text-gray-400">
                                            Elegível: {{ $money($row->eligibleValue) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">{{ $row->value->status->label() }}</td>
                                <td class="px-4 py-4">
                                    @php
                                        $rowStatusClasses = match ($row->coverageStatus->color()) {
                                            'success' => 'bg-emerald-500/10 text-emerald-200',
                                            'warning' => 'bg-amber-500/10 text-amber-200',
                                            'danger' => 'bg-rose-500/10 text-rose-200',
                                            default => 'bg-white/[0.05] text-gray-300',
                                        };
                                    @endphp
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $rowStatusClasses }}">
                                        {{ $row->coverageStatus->label() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @include('filament.resources.emissions.relation-managers.guarantees-history', ['history' => $history])
</div>
