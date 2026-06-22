@php
    /** @var \App\Models\Emission $emission */
    /** @var array<string, mixed> $summary */
    /** @var \Illuminate\Support\Collection<int, \App\Models\EmissionPuDailyCurve> $rows */
@endphp

<div class="space-y-6">
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Versao</p>
            <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $summary['calculation_version'] ?? '-' }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Linhas</p>
            <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $summary['rows_count'] ?? 0 }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Periodo</p>
            <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $summary['first_date'] ?? '-' }} ate {{ $summary['last_date'] ?? '-' }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Ultimo valor total</p>
            <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $summary['last_total_value'] ?? '-' }}
            </p>
        </div>
    </div>

    @if (($summary['rows_count'] ?? 0) === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Nenhuma curva PU foi gerada para esta emissao.
        </p>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Data</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">PU atualizado</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">PU residual</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Quantidade</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Valor total</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Pagamento</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">CDI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->curve_date?->format('d/m/Y') }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->updated_unit_value }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->residual_unit_value }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->quantity }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->total_value }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->payment_total_value }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row->index_rate_value ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            A listagem completa permanece disponivel na aba "Curva PU Diario" da emissao.
        </p>
    @endif
</div>
