@php
    /** @var \Spatie\Activitylog\Models\Activity|null $activity */

    $properties = $activity?->properties?->toArray() ?? [];
    $sampleDifferences = $properties['sample_differences'] ?? [];
    $severityColors = [
        'baixa' => 'gray',
        'media' => 'warning',
        'alta' => 'danger',
    ];
@endphp

<div class="space-y-6">
    @if ($activity === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Nenhuma validacao foi executada para esta emissao ainda.
        </p>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</p>
                <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $properties['status'] ?? '-' }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Modo</p>
                <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $properties['mode'] ?? '-' }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Linhas</p>
                <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $properties['total_rows_compared'] ?? 0 }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Divergencias</p>
                <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $properties['total_field_divergences'] ?? 0 }}</p>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <dl class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Planilha</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $properties['spreadsheet_name'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Versao calculada</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $properties['calculation_version'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Primeira divergencia</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $properties['first_divergence_date'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Auditado em</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $activity->created_at?->format('d/m/Y H:i:s') }}</dd>
                </div>
            </dl>
        </div>

        @if ($sampleDifferences === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nenhuma divergencia foi registrada neste relatorio.
            </p>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Operacao</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Data</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Coluna</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Calculado</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Esperado</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Diferenca</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Modo</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Severidade</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Possivel causa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($sampleDifferences as $difference)
                            @php $severity = $difference['severity'] ?? 'alta'; @endphp
                            <tr>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $difference['operation'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $difference['date'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $difference['column'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $difference['actual'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $difference['expected'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $difference['absolute_difference'] ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $difference['mode'] ?? '-' }}</td>
                                <td class="px-3 py-2">
                                    <x-filament::badge :color="$severityColors[$severity] ?? 'gray'">
                                        {{ $severity }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $difference['possible_cause'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
