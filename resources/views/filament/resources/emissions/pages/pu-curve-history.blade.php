<x-filament-panels::page>
    @php
        $emission = $this->getRecord();
        $versions = $this->getVersions();
        $activities = $this->getActivities();
        $canExport = auth()->user()?->can('pu.curve.export') ?? false;
        $staleProcessing = $versions->first(fn ($v) => $v->status->value === 'processing' && $v->updated_at?->lt(now()->subMinutes(30)));
    @endphp

    <div class="space-y-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Geração e validação da curva de PU rodam em segundo plano (fila). Os status abaixo refletem o
            andamento de cada versão.
        </p>

        @if ($staleProcessing)
            <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-700 dark:bg-warning-950/40 dark:text-warning-300">
                A versão <strong>{{ $staleProcessing->calculation_version }}</strong> está em "processando" há mais de 30 minutos.
                Verifique se o worker de fila (<code>queue:work</code>) está ativo.
            </div>
        @endif

        {{-- Timeline de versões --}}
        <section class="space-y-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Versões da curva</h2>

            @forelse ($versions as $version)
                @php $params = $version->parameters_snapshot ?? []; $validation = $version->validation_summary ?? []; @endphp
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $version->calculation_version }}</span>
                            <x-filament::badge :color="$version->status->color()">{{ $version->status->label() }}</x-filament::badge>
                            @if ($version->obsolete_reason)
                                <span class="text-xs text-gray-400">({{ $version->obsolete_reason }})</span>
                            @endif
                        </div>
                        @if ($canExport)
                            <x-filament::button
                                tag="a"
                                size="sm"
                                color="gray"
                                icon="heroicon-o-document-arrow-down"
                                href="{{ route('admin.emissions.pu-homologation.pdf', ['emission' => $emission, 'version' => $version]) }}"
                            >
                                PDF de homologação
                            </x-filament::button>
                        @endif
                    </div>

                    <div class="mt-3 grid gap-x-6 gap-y-1 text-sm text-gray-600 md:grid-cols-2 xl:grid-cols-3 dark:text-gray-300">
                        <span>Gerada: <strong>{{ $version->generated_at?->format('d/m/Y H:i') ?? '-' }}</strong> {{ $version->generatedBy?->name ? '— '.$version->generatedBy->name : '' }}</span>
                        <span>Validada: <strong>{{ $version->validated_at?->format('d/m/Y H:i') ?? '-' }}</strong> {{ $version->validatedBy?->name ? '— '.$version->validatedBy->name : '' }}</span>
                        <span>Homologada: <strong>{{ $version->homologated_at?->format('d/m/Y H:i') ?? '-' }}</strong> {{ $version->homologatedBy?->name ? '— '.$version->homologatedBy->name : '' }}</span>
                        <span>Linhas geradas: <strong>{{ $version->rows_count ?? '-' }}</strong></span>
                        <span>Indexador: <strong>{{ $params['indexer'] ?? '—' }}</strong></span>
                        <span>Método: <strong>{{ $params['calculation_method'] ?? '—' }}</strong></span>
                        @if (!empty($params['annual_rate']))
                            <span>Taxa prefixada: <strong>{{ $params['annual_rate'] }}</strong></span>
                        @else
                            <span>Spread: <strong>{{ $params['spread_rate'] ?? '—' }}</strong></span>
                        @endif
                        <span>PU inicial: <strong>{{ $params['initial_unit_value'] ?? '—' }}</strong></span>
                        <span>Período: <strong>{{ $params['curve_start_date'] ?? '-' }} → {{ $params['curve_end_date'] ?? '-' }}</strong></span>
                        <span>Engine: <strong>{{ $version->engine_version ?? '-' }}</strong></span>
                    </div>

                    @if ($validation !== [])
                        <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            <p class="font-medium text-gray-700 dark:text-gray-200">Validação: {{ $validation['status'] ?? '-' }} ({{ $validation['mode'] ?? '-' }})</p>
                            <div class="mt-1 grid gap-x-6 gap-y-1 md:grid-cols-2 xl:grid-cols-3">
                                <span>Linhas comparadas: <strong>{{ $validation['total_rows_compared'] ?? 0 }}</strong></span>
                                <span>Linhas divergentes: <strong>{{ $validation['total_divergences'] ?? 0 }}</strong></span>
                                <span>Campos divergentes: <strong>{{ $validation['total_field_divergences'] ?? 0 }}</strong></span>
                                <span>Maior dif. PU: <strong>{{ $validation['largest_pu_difference'] ?? '-' }}</strong></span>
                                <span>Maior dif. valor total: <strong>{{ $validation['largest_total_value_difference'] ?? '-' }}</strong></span>
                                <span>Maior dif. pagamento: <strong>{{ $validation['largest_payment_difference'] ?? '-' }}</strong></span>
                            </div>
                        </div>
                    @endif

                    @if ($version->error_message)
                        <p class="mt-3 text-sm text-danger-600 dark:text-danger-400"><strong>Erro:</strong> {{ $version->error_message }}</p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma versão de curva gerada para esta emissão.</p>
            @endforelse
        </section>

        {{-- Auditoria das ações --}}
        <section class="space-y-3">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Auditoria das ações</h2>

            @if ($activities->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma ação registrada ainda.</p>
            @else
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Data</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Ação</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Versão</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Responsável</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($activities as $activity)
                                <tr>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $activity->created_at?->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $this->describeEvent($activity->description) }}</td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $activity->properties['calculation_version'] ?? '-' }}</td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $activity->causer?->name ?? 'Sistema' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
