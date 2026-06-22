@php
    /** @var \App\Models\Emission $emission */
    /** @var \App\Models\EmissionPuCurveVersion|null $version */
    /** @var \App\Domain\PuCalculator\DTOs\PuIndexCoverageReport $coverage */
    $status = $version?->status;
@endphp

<div class="space-y-6">
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</p>
            <p class="mt-2">
                @if ($status !== null)
                    <x-filament::badge :color="$status->color()">{{ $status->label() }}</x-filament::badge>
                @else
                    <span class="text-sm text-gray-500 dark:text-gray-400">Nenhuma curva gerada</span>
                @endif
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Versao atual</p>
            <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $version?->calculation_version ?? '-' }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Ultima geracao</p>
            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $version?->generated_at?->format('d/m/Y H:i') ?? '-' }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Responsavel</p>
            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $version?->generatedBy?->name ?? '-' }}
            </p>
        </div>
    </div>

    @if ($version?->validation_summary !== null)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Ultima validacao</p>
            <div class="mt-2 grid gap-2 text-sm text-gray-700 md:grid-cols-3 dark:text-gray-200">
                <span>Resultado: <strong>{{ $version->validation_summary['status'] ?? '-' }}</strong></span>
                <span>Linhas comparadas: <strong>{{ $version->validation_summary['total_rows_compared'] ?? 0 }}</strong></span>
                <span>Linhas divergentes: <strong>{{ $version->validation_summary['total_divergences'] ?? 0 }}</strong></span>
                <span>Campos divergentes: <strong>{{ $version->validation_summary['total_field_divergences'] ?? 0 }}</strong></span>
                <span>Primeira divergencia: <strong>{{ $version->validation_summary['first_divergence_date'] ?? '-' }}</strong></span>
                <span>Validado por: <strong>{{ $version->validatedBy?->name ?? '-' }}</strong></span>
            </div>
        </div>
    @endif

    @if ($version?->error_message !== null)
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950/40 dark:text-danger-300">
            <strong>Ultimo erro:</strong> {{ $version->error_message }}
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Relatorio de indices (CDI)</p>

        @if (! $coverage->hasParameter)
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Configure os parametros do calculo de PU para avaliar a cobertura de indices.</p>
        @else
            <div class="mt-2 grid gap-2 text-sm text-gray-700 md:grid-cols-2 dark:text-gray-200">
                <span>Periodo: <strong>{{ $coverage->startDate ?? '-' }} ate {{ $coverage->endDate ?? '-' }}</strong></span>
                <span>Ultimo CDI disponivel: <strong>{{ $coverage->lastAvailableIndexDate ?? '-' }}</strong></span>
            </div>

            @if ($coverage->hasBlockingGaps())
                <div class="mt-3 space-y-1 text-sm text-danger-700 dark:text-danger-300">
                    @if ($coverage->missingCalendarDates !== [])
                        <p>Datas de calendario faltantes ({{ count($coverage->missingCalendarDates) }}): {{ implode(', ', array_slice($coverage->missingCalendarDates, 0, 10)) }}{{ count($coverage->missingCalendarDates) > 10 ? '…' : '' }}</p>
                    @endif
                    @if ($coverage->missingIndexDates !== [])
                        <p>Datas sem CDI obrigatorio ({{ count($coverage->missingIndexDates) }}): {{ implode(', ', array_slice($coverage->missingIndexDates, 0, 10)) }}{{ count($coverage->missingIndexDates) > 10 ? '…' : '' }}</p>
                    @endif
                </div>
            @else
                <p class="mt-3 text-sm text-success-700 dark:text-success-300">Cobertura de indices completa para o periodo.</p>
            @endif

            @if ($coverage->usesProjectedIndex())
                <p class="mt-2 text-sm text-warning-700 dark:text-warning-400">
                    Datas usando CDI projetado ({{ count($coverage->projectedIndexDates) }}): {{ implode(', ', array_slice($coverage->projectedIndexDates, 0, 10)) }}{{ count($coverage->projectedIndexDates) > 10 ? '…' : '' }}
                </p>
            @endif
        @endif
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        O detalhamento de divergencias permanece em "Ver Relatorio de Divergencias"; a curva completa, na aba "Curva PU Diario".
    </p>
</div>
