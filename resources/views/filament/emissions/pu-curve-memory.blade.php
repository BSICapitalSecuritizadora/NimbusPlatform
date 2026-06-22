@php
    /** @var \App\Models\EmissionPuDailyCurve $row */
    $memory = $row->calculation_memory ?? [];
@endphp

<div class="space-y-4">
    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Data</p>
            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $row->curve_date?->format('d/m/Y') }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Versao</p>
            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $row->calculation_version }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">PU residual</p>
            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $row->residual_unit_value }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Quantidade</p>
            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $row->quantity }}</p>
        </div>
    </div>

    @if ($memory === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Nenhuma memoria de calculo foi registrada para esta linha.
        </p>
    @else
        <pre class="overflow-x-auto rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-100">{{ json_encode($memory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    @endif
</div>
