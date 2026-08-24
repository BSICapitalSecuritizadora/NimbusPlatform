@php
    $stateLabels = [
        'missing' => 'Sem calendário',
        'partial' => 'Parcial',
        'provisional' => 'Provisório',
        'confirmed' => 'Confirmado',
        'stale' => 'Desatualizado',
    ];
    $statusLabels = [
        'provisional' => 'Status: provisório',
        'confirmed' => 'Status: confirmado',
        'stale' => 'Status: desatualizado',
    ];
@endphp

<x-filament-widgets::widget class="business-calendar-overview">
    <div class="flex flex-col gap-5">
        <div class="grid gap-4 lg:grid-cols-3">
            @foreach ($definitions as $code => $definition)
                @php
                    $isLegacy = $definition['legacy'];
                    $isTrading = $code === \App\Domain\PuCalculator\Support\BusinessCalendarRegistry::B3_LISTED_TRADING;
                @endphp

                <section
                    wire:key="calendar-definition-{{ $code }}"
                    @class([
                        'rounded-xl border p-4',
                        'border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' => $isLegacy,
                        'border-info-300 bg-info-50 dark:border-info-500/30 dark:bg-info-500/10' => ! $isLegacy && ! $isTrading,
                        'border-primary-300 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10' => $isTrading,
                    ])
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $code }}</p>
                            <h2 class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $definition['label'] }}</h2>
                        </div>

                        @if ($isLegacy)
                            <span class="shrink-0 rounded-md bg-amber-200 px-2 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-500/20 dark:text-amber-100">
                                Alias legado
                            </span>
                        @elseif ($isTrading)
                            <span class="shrink-0 rounded-md bg-primary-100 px-2 py-1 text-xs font-semibold text-primary-800 dark:bg-primary-500/20 dark:text-primary-200">
                                Negociação
                            </span>
                        @else
                            <span class="shrink-0 rounded-md bg-info-100 px-2 py-1 text-xs font-semibold text-info-800 dark:bg-info-500/20 dark:text-info-200">
                                Bancário
                            </span>
                        @endif
                    </div>

                    <p class="mt-3 text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $definition['meaning'] }}</p>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <div>
                            <dt class="font-semibold text-gray-900 dark:text-white">Tipo</dt>
                            <dd>{{ $definition['type'] }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-900 dark:text-white">Status</dt>
                            <dd>{{ $definition['status'] }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-900 dark:text-white">Origem</dt>
                            <dd>{{ $definition['source'] ?? 'Não definida' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-900 dark:text-white">Anos confirmados</dt>
                            <dd>{{ $definition['confirmed_years_count'] }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-900 dark:text-white">Uso financeiro</dt>
                            <dd>{{ $definition['financial_use_allowed'] ? 'Suportado pelo catálogo' : 'Não permitido' }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-900 dark:text-white">Novas configurações</dt>
                            <dd>{{ $definition['available_for_new_configurations'] ? 'Disponível' : 'Indisponível' }}</dd>
                        </div>
                    </dl>

                    @if ($isLegacy)
                        <p class="mt-3 text-xs font-semibold text-amber-900 dark:text-amber-100">
                            Não é redirecionado automaticamente. Consumidores existentes continuam lendo os dados B3 atuais.
                        </p>
                    @endif

                    @if ($latestAttempts->get($code))
                        <p class="mt-3 border-t border-gray-900/10 pt-3 text-xs text-gray-600 dark:border-white/10 dark:text-gray-300">
                            Última tentativa: {{ $latestAttempts->get($code)->started_at?->format('d/m/Y H:i') }}
                            • {{ $latestAttempts->get($code)->result }}
                            • {{ $latestAttempts->get($code)->dry_run ? 'dry-run' : 'aplicação' }}
                        </p>
                    @else
                        <p class="mt-3 border-t border-gray-900/10 pt-3 text-xs text-gray-600 dark:border-white/10 dark:text-gray-300">
                            Nunca houve tentativa de importação/sincronização.
                        </p>
                    @endif
                </section>
            @endforeach
        </div>

        @foreach ($definitions as $code => $definition)
            @php
                $calendarRows = $summaries->get($code, collect());
                $availableYears = $calendarRows->pluck('year')->sort()->values();
            @endphp

            <x-filament::section
                wire:key="calendar-years-{{ $code }}"
                :heading="$definition['label']"
                :description="$availableYears->isEmpty()
                    ? 'Nenhum ano disponível.'
                    : sprintf('%d ano(s) disponível(is): %d a %d.', $availableYears->count(), $availableYears->first(), $availableYears->last())"
                collapsible
                collapsed
            >
                @if ($calendarRows->isEmpty())
                    <div class="rounded-lg border border-dashed border-gray-300 p-5 text-sm text-gray-600 dark:border-white/20 dark:text-gray-300">
                        Sem dados, versão anual ou tentativa de importação para este calendário.
                    </div>
                @else
                    <div class="max-h-[32rem] overflow-auto rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead class="sticky top-0 bg-gray-50 text-left text-xs font-semibold tracking-wide text-gray-600 uppercase dark:bg-gray-900 dark:text-gray-300">
                                <tr>
                                    <th class="px-3 py-2.5">Ano / estado</th>
                                    <th class="px-3 py-2.5">Cobertura</th>
                                    <th class="px-3 py-2.5">Fonte / documento</th>
                                    <th class="px-3 py-2.5">Importações</th>
                                    <th class="px-3 py-2.5 text-right">Não úteis</th>
                                    <th class="px-3 py-2.5 text-right">Conflitos</th>
                                    <th class="px-3 py-2.5 text-right">Overrides</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white dark:divide-white/5 dark:bg-gray-950">
                                @foreach ($calendarRows as $row)
                                    <tr wire:key="calendar-year-{{ $code }}-{{ $row['year'] }}" class="align-top">
                                        <td class="px-3 py-3">
                                            <div class="font-semibold text-gray-950 dark:text-white">{{ $row['year'] }}</div>
                                            <span @class([
                                                'mt-1 inline-flex rounded-md px-2 py-0.5 text-xs font-semibold',
                                                'bg-success-100 text-success-800 dark:bg-success-500/15 dark:text-success-200' => $row['state'] === 'confirmed',
                                                'bg-info-100 text-info-800 dark:bg-info-500/15 dark:text-info-200' => $row['state'] === 'provisional',
                                                'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-100' => $row['state'] === 'partial',
                                                'bg-danger-100 text-danger-800 dark:bg-danger-500/15 dark:text-danger-200' => in_array($row['state'], ['missing', 'stale'], true),
                                            ])>{{ $stateLabels[$row['state']] ?? $row['state'] }}</span>
                                            <div class="mt-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                                {{ $row['status'] ? ($statusLabels[$row['status']] ?? $row['status']) : 'Sem versão anual' }}
                                            </div>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">rev. {{ $row['revision'] }}</div>
                                        </td>
                                        <td class="px-3 py-3 text-gray-700 dark:text-gray-300">
                                            <div>{{ $row['covered_days'] }} / {{ $row['expected_days'] }} dias</div>
                                            @if ($row['missing_days'] > 0)
                                                <div class="mt-1 text-xs font-semibold text-danger-700 dark:text-danger-300">{{ $row['missing_days'] }} ausente(s)</div>
                                            @endif
                                            @if ($row['confirmed_at'])
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    Confirmado em {{ $row['confirmed_at']->format('d/m/Y H:i') }}{{ $row['confirmed_by'] ? ' por '.$row['confirmed_by'] : '' }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="max-w-xs px-3 py-3 text-gray-700 dark:text-gray-300">
                                            <div class="font-medium">{{ $row['source'] ?? 'Não informada' }}{{ $row['source_is_official'] ? ' • oficial' : '' }}</div>
                                            <div class="mt-1 break-words text-xs text-gray-500 dark:text-gray-400">{{ $row['source_document'] ?? 'Sem documento' }}</div>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Fonte rev.: {{ $row['source_revision'] ?? '—' }}</div>
                                            @if ($row['checksum'])
                                                <div class="mt-1 font-mono text-[0.6875rem] text-gray-500 dark:text-gray-400">sha256 {{ \Illuminate\Support\Str::limit($row['checksum'], 16, '…') }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">
                                            <div>Última aplicação: {{ $row['last_import_at']?->format('d/m/Y H:i') ?? 'nunca' }}</div>
                                            <div class="mt-1">Última tentativa: {{ $row['last_attempt_at']?->format('d/m/Y H:i') ?? 'nunca' }}</div>
                                            @if ($row['last_attempt_result'])
                                                <div class="mt-1 font-semibold">Resultado: {{ $row['last_attempt_result'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-right font-semibold tabular-nums text-gray-900 dark:text-white">{{ $row['non_business_days'] }}</td>
                                        <td class="px-3 py-3 text-right font-semibold tabular-nums {{ $row['conflicts'] > 0 ? 'text-danger-700 dark:text-danger-300' : 'text-gray-900 dark:text-white' }}">{{ $row['conflicts'] }}</td>
                                        <td class="px-3 py-3 text-right font-semibold tabular-nums {{ $row['overrides'] > 0 ? 'text-amber-800 dark:text-amber-200' : 'text-gray-900 dark:text-white' }}">{{ $row['overrides'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
