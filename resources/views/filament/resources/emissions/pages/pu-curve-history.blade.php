<x-filament-panels::page>
    @php
        $emission = $this->getRecord();
        $versions = $this->getVersions();
        $activities = $this->getActivities();
        $canExport = auth()->user()?->can('pu.curve.export') ?? false;
        $staleProcessing = $versions->first(fn ($v) => $v->status->value === 'processing' && $v->updated_at?->lt(now()->subMinutes(30)));

        $latestVersion = $versions->first();
        $lastUpdated = $latestVersion?->updated_at
            ?? $latestVersion?->generated_at
            ?? $activities->first()?->created_at
            ?? $emission->updated_at;
    @endphp

    <div class="space-y-6">
        {{-- Alerta de processamento estagnado (se houver) --}}
        @if ($staleProcessing)
            <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-700 dark:bg-warning-950/40 dark:text-warning-300">
                A versão <strong>{{ $staleProcessing->calculation_version }}</strong> está em "processando" há mais de 30 minutos.
                Verifique se o worker de fila (<code>queue:work</code>) está ativo.
            </div>
        @endif

        {{-- 1. BLOCO DE STATUS DA CURVA --}}
        <x-filament::section>
            <x-slot name="heading">Status da Curva</x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Status atual
                    </p>
                    <div class="flex items-center min-h-[1.75rem]">
                        @if ($latestVersion)
                            <div class="flex items-center gap-2">
                                <x-filament::badge :color="$latestVersion->status->color()" size="sm">
                                    {{ $latestVersion->status->label() }}
                                </x-filament::badge>
                                <span class="font-mono text-xs font-medium text-gray-600 dark:text-gray-300">
                                    {{ $latestVersion->calculation_version }}
                                </span>
                            </div>
                        @else
                            <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-400/25 dark:text-gray-300 dark:ring-white/10">
                                Sem versão gerada
                            </span>
                        @endif
                    </div>
                </div>

                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Versões geradas
                    </p>
                    <div class="flex items-center min-h-[1.75rem]">
                        <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            {{ $versions->count() }}
                        </span>
                    </div>
                </div>

                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Última atualização
                    </p>
                    <div class="flex items-center min-h-[1.75rem]">
                        <span class="text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">
                            {{ $lastUpdated?->format('d/m/Y H:i') ?? '—' }}
                        </span>
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- 2. SEÇÃO: VERSÕES DA CURVA --}}
        <x-filament::section>
            <x-slot name="heading">Versões da curva</x-slot>
            <x-slot name="description">Histórico das curvas geradas e seus respectivos estados de processamento.</x-slot>

            @if ($versions->isEmpty())
                <div class="flex flex-col items-center justify-center py-8 px-4 text-center">
                    <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-200 text-gray-500 dark:bg-[#0d2530] dark:ring-white/10 dark:text-[#a06e28]">
                        <x-filament::icon
                            icon="heroicon-o-document-chart-bar"
                            class="h-5 w-5"
                        />
                    </div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        Nenhuma versão gerada
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 max-w-sm">
                        As versões aparecerão aqui após o processamento da curva de PU.
                    </p>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($versions as $version)
                        @php
                            $params = $version->parameters_snapshot ?? [];
                            $validation = $version->validation_summary ?? [];
                        @endphp
                        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-[#091b23]">
                            {{-- Header da versão --}}
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-white/5">
                                <div class="flex items-center gap-2.5">
                                    <span class="font-mono text-base font-bold text-gray-900 dark:text-gray-100">
                                        {{ $version->calculation_version }}
                                    </span>
                                    <x-filament::badge :color="$version->status->color()" size="sm">
                                        {{ $version->status->label() }}
                                    </x-filament::badge>
                                    @if ($version->obsolete_reason)
                                        <span class="rounded px-1.5 py-0.5 text-xs text-gray-500 bg-gray-100 dark:bg-white/5 dark:text-gray-400">
                                            ({{ $version->obsolete_reason }})
                                        </span>
                                    @endif
                                </div>

                                @if ($canExport)
                                    <x-filament::button
                                        tag="a"
                                        size="xs"
                                        color="gray"
                                        outlined
                                        icon="heroicon-o-document-arrow-down"
                                        href="{{ route('admin.emissions.pu-homologation.pdf', ['emission' => $emission, 'version' => $version]) }}"
                                    >
                                        PDF de homologação
                                    </x-filament::button>
                                @endif
                            </div>

                            {{-- Detalhes da versão --}}
                            <div class="mt-3 grid gap-x-6 gap-y-2 text-xs md:grid-cols-2 xl:grid-cols-3 text-gray-600 dark:text-gray-300">
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Gerada:</span>
                                    <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $version->generated_at?->format('d/m/Y H:i') ?? '-' }}</strong>
                                    @if ($version->generatedBy?->name)
                                        <span class="text-gray-500 dark:text-gray-400">— {{ $version->generatedBy->name }}</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Validada:</span>
                                    <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $version->validated_at?->format('d/m/Y H:i') ?? '-' }}</strong>
                                    @if ($version->validatedBy?->name)
                                        <span class="text-gray-500 dark:text-gray-400">— {{ $version->validatedBy->name }}</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Homologada:</span>
                                    <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $version->homologated_at?->format('d/m/Y H:i') ?? '-' }}</strong>
                                    @if ($version->homologatedBy?->name)
                                        <span class="text-gray-500 dark:text-gray-400">— {{ $version->homologatedBy->name }}</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Linhas geradas:</span>
                                    <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $version->rows_count ?? '-' }}</strong>
                                </div>
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Indexador:</span>
                                    <strong class="text-gray-900 dark:text-gray-100">{{ $params['indexer'] ?? '—' }}</strong>
                                </div>
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Método:</span>
                                    <strong class="text-gray-900 dark:text-gray-100">{{ $params['calculation_method'] ?? '—' }}</strong>
                                </div>
                                <div>
                                    @if (!empty($params['annual_rate']))
                                        <span class="text-gray-500 dark:text-gray-400">Taxa prefixada:</span>
                                        <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $params['annual_rate'] }}</strong>
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">Spread:</span>
                                        <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $params['spread_rate'] ?? '—' }}</strong>
                                    @endif
                                </div>
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">PU inicial:</span>
                                    <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $params['initial_unit_value'] ?? '—' }}</strong>
                                </div>
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Período:</span>
                                    <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $params['curve_start_date'] ?? '-' }} → {{ $params['curve_end_date'] ?? '-' }}</strong>
                                </div>
                                <div>
                                    <span class="text-gray-500 dark:text-gray-400">Engine:</span>
                                    <strong class="font-mono text-gray-900 dark:text-gray-100">{{ $version->engine_version ?? '-' }}</strong>
                                </div>
                            </div>

                            {{-- Resumo da validação --}}
                            @if ($validation !== [])
                                <div class="mt-3 rounded-lg border border-gray-200/80 bg-gray-50/70 p-3 text-xs dark:border-white/5 dark:bg-[#0d2530]/60">
                                    <p class="font-semibold text-gray-800 dark:text-gray-200">
                                        Validação: {{ $validation['status'] ?? '-' }} ({{ $validation['mode'] ?? '-' }})
                                    </p>
                                    <div class="mt-1.5 grid gap-x-6 gap-y-1 md:grid-cols-2 xl:grid-cols-3 text-gray-600 dark:text-gray-300">
                                        <span>Linhas comparadas: <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $validation['total_rows_compared'] ?? 0 }}</strong></span>
                                        <span>Linhas divergentes: <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $validation['total_divergences'] ?? 0 }}</strong></span>
                                        <span>Campos divergentes: <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $validation['total_field_divergences'] ?? 0 }}</strong></span>
                                        <span>Maior dif. PU: <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $validation['largest_pu_difference'] ?? '-' }}</strong></span>
                                        <span>Maior dif. valor total: <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $validation['largest_total_value_difference'] ?? '-' }}</strong></span>
                                        <span>Maior dif. pagamento: <strong class="tabular-nums text-gray-900 dark:text-gray-100">{{ $validation['largest_payment_difference'] ?? '-' }}</strong></span>
                                    </div>
                                </div>
                            @endif

                            {{-- Mensagem de erro (se houver) --}}
                            @if ($version->error_message)
                                <div class="mt-3 rounded-lg border border-danger-200 bg-danger-50/80 p-3 text-xs text-danger-700 dark:border-danger-900/50 dark:bg-danger-950/30 dark:text-danger-300">
                                    <strong>Erro:</strong> {{ $version->error_message }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- 3. SEÇÃO: AUDITORIA DAS AÇÕES --}}
        <x-filament::section>
            <x-slot name="heading">Auditoria das ações</x-slot>
            <x-slot name="description">Registro cronológico das alterações e eventos relacionados à curva de PU.</x-slot>

            @if ($activities->isEmpty())
                <div class="flex flex-col items-center justify-center py-8 px-4 text-center">
                    <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-200 text-gray-500 dark:bg-[#0d2530] dark:ring-white/10 dark:text-[#a06e28]">
                        <x-filament::icon
                            icon="heroicon-o-clipboard-document-list"
                            class="h-5 w-5"
                        />
                    </div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        Nenhum evento registrado
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 max-w-sm">
                        Nenhum evento de auditoria registrado para esta curva de PU.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10 text-xs sm:text-sm">
                        <thead class="bg-gray-50/90 dark:bg-[#0d2530]">
                            <tr>
                                <th scope="col" class="py-2.5 px-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">
                                    Data
                                </th>
                                <th scope="col" class="py-2.5 px-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">
                                    Ação
                                </th>
                                <th scope="col" class="py-2.5 px-3.5 text-center text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">
                                    Versão
                                </th>
                                <th scope="col" class="py-2.5 px-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-300">
                                    Responsável
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5 bg-white dark:bg-[#091b23]">
                            @foreach ($activities as $activity)
                                <tr class="transition-colors hover:bg-gray-50/60 dark:hover:bg-[#12313b]/40">
                                    <td class="whitespace-nowrap py-2.5 px-3.5 tabular-nums text-gray-600 dark:text-gray-300">
                                        {{ $activity->created_at?->format('d/m/Y H:i') ?? '—' }}
                                    </td>
                                    <td class="py-2.5 px-3.5">
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700 border border-gray-200/70 dark:bg-[#12313b] dark:text-[#e6e4e4] dark:border-white/10">
                                            {{ $this->describeEvent($activity->description) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 px-3.5 text-center font-mono text-xs text-gray-600 dark:text-gray-300">
                                        {{ $activity->properties['calculation_version'] ?? '—' }}
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 px-3.5 font-medium text-gray-800 dark:text-gray-200">
                                        {{ $activity->causer?->name ?? 'Sistema' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
