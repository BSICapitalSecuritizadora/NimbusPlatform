@php
    use App\Domain\PuCalculator\DTOs\PuBaselineRequirement;
    use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;

    $dossier = $report->indexSourceDiagnostics;
    $dossierReport = $dossier['report'] ?? [];
    $comparison = data_get($dossierReport, 'comparison.summary', []);
    $sourceLabel = $dossier['source_label'] ?? 'fonte do índice';
    $referenceIndexLabel = $dossier['reference_index_label'] ?? null;
    $dossierHeading = 'Dossiê da fonte · '.($referenceIndexLabel ? $referenceIndexLabel.' × '.$sourceLabel : $sourceLabel);
    $dossierSources = array_filter([
        'b3' => $referenceIndexLabel,
        'bcb' => $dossier['source_label'] ?? null,
    ]);
    $statusClasses = match ($report->status->value) {
        'externally_validated' => 'border-success-300 bg-success-50 text-success-900 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-100',
        'ready_for_numeric_homologation' => 'border-info-300 bg-info-50 text-info-900 dark:border-info-500/30 dark:bg-info-500/10 dark:text-info-100',
        'ready_for_candidate_configuration' => 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
        default => 'border-danger-300 bg-danger-50 text-danger-950 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-100',
    };
    $requirementStyles = [
        PuBaselineRequirementStatus::Satisfied->value => ['icon' => 'heroicon-m-check-circle', 'class' => 'text-success-700 dark:text-success-300'],
        PuBaselineRequirementStatus::AdministrativePending->value => ['icon' => 'heroicon-m-exclamation-triangle', 'class' => 'text-amber-700 dark:text-amber-300'],
        PuBaselineRequirementStatus::Blocking->value => ['icon' => 'heroicon-m-x-circle', 'class' => 'text-danger-700 dark:text-danger-300'],
        PuBaselineRequirementStatus::Recommended->value => ['icon' => 'heroicon-m-information-circle', 'class' => 'text-info-700 dark:text-info-300'],
    ];
    $blockScopeLabels = [
        'candidate_configuration' => 'configuração candidata',
        'numeric_homologation' => 'homologação numérica',
        'aggregate_outputs' => 'somente saldos e pagamentos agregados',
        'external_validation' => 'somente o status de validação externa',
    ];
    $requirementsByCategory = collect($report->requirements)
        ->groupBy(fn (PuBaselineRequirement $requirement): string => $requirement->category->value);
    $blockingRequirements = collect($report->requirements)
        ->reject(fn (PuBaselineRequirement $requirement): bool => $requirement->isSatisfied())
        ->filter(fn (PuBaselineRequirement $requirement): bool => $requirement->blocks !== []);
    $calendarCode = $report->calendarDiagnostics['calendar_code'] ?? 'Calendário';
    $calendarFrom = $report->calendarDiagnostics['required_from'] ?? null;
    $calendarTo = $report->calendarDiagnostics['required_to'] ?? null;
    $calendarHeading = $calendarCode;
    if ($calendarFrom && $calendarTo) {
        $calendarHeading .= ' · '.substr($calendarFrom, 0, 4).'–'.substr($calendarTo, 0, 4);
    }
@endphp

<x-filament-widgets::widget class="bsi-cockpit-widget">
    <div class="flex flex-col gap-5">
        <section class="rounded-2xl border p-5 {{ $statusClasses }}" aria-labelledby="pu-baseline-readiness-title">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <h2 id="pu-baseline-readiness-title" class="text-xl font-semibold">Gate de prontidão do PU</h2>
                    <p class="mt-1 text-sm font-semibold">Estado calculado: {{ $report->status->label() }}</p>
                    <p class="mt-2 text-sm leading-relaxed opacity-90">
                        O estado é calculado a partir das evidências; não existe marcação manual de "pronto".
                        Esta avaliação não persiste configuração, eventos, taxas, curva, PU History ou pagamentos.
                    </p>
                </div>

                <div class="shrink-0 rounded-xl border border-current/15 bg-white/50 px-4 py-3 dark:bg-black/10">
                    <p class="text-xs font-semibold uppercase opacity-70">Próxima ação mínima</p>
                    <p class="mt-1 max-w-sm text-sm font-medium">{{ $report->nextActions[0] ?? 'Nenhuma pendência para o estado atual.' }}</p>
                </div>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-current/15 bg-white/40 p-4 dark:bg-black/10">
                    <p class="text-xs font-semibold uppercase opacity-70">Bloqueios em vigor</p>
                    @if ($blockingRequirements->isEmpty())
                        <p class="mt-2 text-sm">Nenhum requisito pendente restringe o estado atual.</p>
                    @else
                        <ul class="mt-2 space-y-1.5 text-sm">
                            @foreach ($blockingRequirements as $key => $requirement)
                                <li wire:key="blocking-{{ $key }}">
                                    <span class="font-semibold">{{ $requirement->symbol() }} {{ $requirement->name }}</span>
                                    <span class="opacity-80">— impede {{ collect($requirement->blocks)->map(fn (string $scope): string => $blockScopeLabels[$scope] ?? $scope)->implode(' e ') }}.</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-xl border border-current/15 bg-white/40 p-4 dark:bg-black/10">
                    <p class="text-xs font-semibold uppercase opacity-70">Próximas ações</p>
                    @if ($report->nextActions === [])
                        <p class="mt-2 text-sm">Nenhuma ação pendente registrada pelo gate.</p>
                    @else
                        <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-sm">
                            @foreach ($report->nextActions as $action)
                                <li wire:key="next-action-{{ $loop->index }}">{{ $action }}</li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>

            @if ($report->limitations !== [])
                <ul class="mt-4 list-disc space-y-1 pl-5 text-sm opacity-90">
                    @foreach ($report->limitations as $limitation)
                        <li wire:key="limitation-{{ $loop->index }}">{{ $limitation }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <x-filament::section
            heading="Leitura por dimensão"
            description="Regra contratual, capacidade da engine, governança, disponibilidade operacional e validação independente são avaliadas separadamente."
            icon="heroicon-o-squares-2x2"
        >
            <div class="grid gap-5 lg:grid-cols-2">
                @foreach ($requirementsByCategory as $categoryValue => $categoryRequirements)
                    <div wire:key="dimension-{{ $categoryValue }}" class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $categoryRequirements->first()->category->label() }}</h3>
                        <ul class="mt-3 space-y-2">
                            @foreach ($categoryRequirements as $key => $requirement)
                                @php $style = $requirementStyles[$requirement->status->value]; @endphp
                                <li wire:key="dimension-{{ $categoryValue }}-{{ $key }}" class="flex items-start gap-2 text-sm">
                                    <x-dynamic-component :component="$style['icon']" class="mt-0.5 size-4 shrink-0 {{ $style['class'] }}" aria-hidden="true" />
                                    <span class="text-gray-700 dark:text-gray-300"><span class="font-medium text-gray-950 dark:text-white">{{ $requirement->name }}:</span> {{ $requirement->reason }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section
            heading="Por que o baseline ainda não avança?"
            description="✓ satisfeito · ! pendência administrativa ou recomendação · ✕ bloqueio objetivo"
            icon="heroicon-o-shield-exclamation"
        >
            <div class="grid gap-x-7 gap-y-3 lg:grid-cols-2">
                @foreach ($report->requirements as $key => $requirement)
                    @php $style = $requirementStyles[$requirement->status->value]; @endphp
                    <div wire:key="requirement-{{ $key }}" class="flex items-start gap-2.5 border-b border-gray-100 pb-3 text-sm last:border-0 dark:border-white/5">
                        <x-dynamic-component :component="$style['icon']" class="mt-0.5 size-5 shrink-0 {{ $style['class'] }}" aria-hidden="true" />
                        <div>
                            <p class="font-semibold {{ $style['class'] }}">{{ $requirement->symbol() }} {{ $requirement->name }}</p>
                            <p class="mt-0.5 leading-relaxed text-gray-600 dark:text-gray-300">{{ $requirement->reason }}</p>
                            @if ($requirement->blocks !== [])
                                <p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                    Impacto:
                                    {{ collect($requirement->blocks)->map(fn (string $scope): string => $blockScopeLabels[$scope] ?? $scope)->implode(' e ') }}.
                                </p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section
            heading="Configuração candidata — somente leitura"
            description="Representação não persistente composta apenas com valores comprovados. PENDING nunca é convertido em valor inferido."
            icon="heroicon-o-document-magnifying-glass"
            collapsible
        >
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs font-semibold tracking-wide text-gray-600 uppercase dark:bg-white/5 dark:text-gray-300">
                        <tr>
                            <th class="px-3 py-2.5">Campo</th>
                            <th class="px-3 py-2.5">Valor</th>
                            <th class="px-3 py-2.5">Documento</th>
                            <th class="px-3 py-2.5">Página / cláusula</th>
                            <th class="px-3 py-2.5">Status</th>
                            <th class="px-3 py-2.5">Confiança</th>
                            <th class="px-3 py-2.5">Persistível depois</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($report->candidateFields as $field)
                            <tr wire:key="candidate-field-{{ $field['field'] }}" class="align-top">
                                <td class="px-3 py-3 font-mono text-xs font-semibold text-gray-950 dark:text-white">{{ $field['field'] }}</td>
                                <td class="px-3 py-3 font-mono text-xs {{ $field['value'] === 'PENDING' ? 'font-semibold text-danger-700 dark:text-danger-300' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ is_bool($field['value']) ? ($field['value'] ? 'true' : 'false') : $field['value'] }}
                                </td>
                                <td class="px-3 py-3 text-gray-700 dark:text-gray-300">{{ $field['document'] }}</td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-400">{{ $field['reference'] ?? '—' }}</td>
                                <td class="px-3 py-3">{{ match ($field['status']) {
                                    'proven' => 'Comprovado',
                                    'blocked' => 'Bloqueado',
                                    'governance_control' => 'Controle de governança',
                                    'not_required' => 'Não aplicável',
                                    default => $field['status'],
                                } }}</td>
                                <td class="px-3 py-3">{{ match ($field['confidence']) {
                                    'high' => 'Alta',
                                    'medium' => 'Média',
                                    'low' => 'Baixa',
                                    'pending' => 'Pendente',
                                    'not_applicable' => '—',
                                    default => $field['confidence'],
                                } }}</td>
                                <td class="px-3 py-3 font-semibold {{ $field['ready_for_future_persistence'] ? 'text-success-700 dark:text-success-300' : 'text-danger-700 dark:text-danger-300' }}">
                                    {{ $field['ready_for_future_persistence'] ? 'Sim' : 'Não' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($dossier['artifact_found'] ?? false)
            <x-filament::section
                :heading="$dossierHeading"
                :description="($dossier['workflow_status'] ?? 'ausente').' · approved='.($dossier['approved'] ? 'true' : 'false')"
                icon="heroicon-o-arrows-right-left"
                collapsible
            >
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.7fr)]">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="font-semibold text-gray-950 dark:text-white">Período solicitado</dt><dd class="text-gray-600 dark:text-gray-300">{{ data_get($dossierReport, 'requested_period.from') }} a {{ data_get($dossierReport, 'requested_period.to') }}</dd></div>
                        <div><dt class="font-semibold text-gray-950 dark:text-white">Período comparado</dt><dd class="text-gray-600 dark:text-gray-300">{{ data_get($dossierReport, 'compared_period.from') }} a {{ data_get($dossierReport, 'compared_period.to') }}</dd></div>
                        <div><dt class="font-semibold text-gray-950 dark:text-white">Resultado</dt><dd class="text-gray-600 dark:text-gray-300">{{ $comparison['common_dates'] ?? 0 }} datas · {{ $comparison['present_equal'] ?? 0 }} iguais</dd></div>
                        <div><dt class="font-semibold text-gray-950 dark:text-white">Divergências</dt><dd class="text-gray-600 dark:text-gray-300">{{ $comparison['present_different'] ?? 0 }} diferentes · {{ $comparison['only_b3'] ?? 0 }} só na referência · {{ $comparison['only_bcb'] ?? 0 }} só na fonte</dd></div>
                        <div><dt class="font-semibold text-gray-950 dark:text-white">Conclusão</dt><dd class="text-gray-600 dark:text-gray-300">{{ $dossier['classification'] }}</dd></div>
                        <div><dt class="font-semibold text-gray-950 dark:text-white">Executor / data</dt><dd class="text-gray-600 dark:text-gray-300">{{ data_get($dossier, 'executor.label', 'Não registrado') }} · {{ $dossier['executed_at'] ?? '—' }}</dd></div>
                    </dl>

                    <div class="rounded-xl bg-gray-50 p-4 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">
                        <p class="font-semibold text-gray-950 dark:text-white">Transformação aplicada</p>
                        <ul class="mt-2 space-y-1">
                            @foreach ((array) data_get($dossierReport, 'normalization', []) as $source => $transformation)
                                <li><span class="font-mono">{{ $source }}:</span> {{ $transformation }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-3 font-semibold text-gray-950 dark:text-white">Integridade</p>
                        <p class="mt-1 break-all font-mono">artefato {{ $dossier['artifact_checksum'] }}</p>
                        <p class="mt-1 break-all font-mono">relatório {{ $dossier['report_checksum'] }}</p>
                        <p class="mt-1">Checksum do relatório: {{ $dossier['checksum_valid'] ? 'válido' : 'inválido' }}</p>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 lg:grid-cols-2">
                    @foreach ($dossierSources as $sourceKey => $comparedSourceLabel)
                        @php $source = (array) data_get($dossierReport, 'sources.'.$sourceKey, []); @endphp
                        <details wire:key="di-source-{{ $sourceKey }}" class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-950 dark:text-white">{{ $comparedSourceLabel }} · fontes, arquivos e fingerprints</summary>
                            <dl class="mt-3 space-y-2 text-xs text-gray-600 dark:text-gray-300">
                                <div><dt class="font-semibold">Registros</dt><dd>{{ $source['compared_records'] ?? '—' }}</dd></div>
                                <div><dt class="font-semibold">Checksum normalizado</dt><dd class="break-all font-mono">{{ $source['normalized_checksum'] ?? '—' }}</dd></div>
                                <div><dt class="font-semibold">Manifesto bruto</dt><dd class="break-all font-mono">{{ $source['raw_payload_manifest_checksum'] ?? '—' }}</dd></div>
                                @foreach ((array) ($source['payloads'] ?? []) as $payload)
                                    <div class="rounded-lg bg-gray-50 p-2 dark:bg-white/5">
                                        <dt class="font-semibold">{{ $payload['source_reference'] ?? $payload['file_path'] ?? $payload['archive_path'] ?? $payload['url'] ?? 'Payload' }}</dt>
                                        <dd class="mt-1 break-all font-mono">sha256 {{ $payload['sha256'] ?? '—' }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </details>
                    @endforeach
                </div>

                @if (! $dossier['approved'])
                    <div class="mt-5 flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/30 dark:bg-amber-500/10">
                        <p class="text-sm text-amber-950 dark:text-amber-100"><strong>Homologação técnica satisfeita.</strong> Aprovação operacional pendente; nenhuma aprovação automática foi realizada.</p>
                        {{ $this->approveDiDossierAction }}
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if (($report->calendarDiagnostics['years'] ?? []) !== [])
            <x-filament::section
                :heading="$calendarHeading"
                description="Definição jurídica e cobertura técnica são distintas da confirmação administrativa anual."
                icon="heroicon-o-calendar-days"
                collapsible
            >
                <div class="space-y-3">
                    @foreach ($report->calendarDiagnostics['years'] as $year => $review)
                        <details wire:key="calendar-year-review-{{ $year }}" class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <summary class="cursor-pointer list-none">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <span class="text-base font-semibold text-gray-950 dark:text-white">{{ $year }}</span>
                                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">
                                            @if (array_key_exists('expected_holiday_count', $review))
                                                {{ $review['holiday_count'] }} / {{ $review['expected_holiday_count'] }} feriados legais
                                            @else
                                                {{ $review['covered_days'] ?? 0 }} / {{ $review['expected_days'] ?? 0 }} dias materializados
                                            @endif
                                        </span>
                                    </div>
                                    <span @class([
                                        'rounded-md px-2.5 py-1 text-xs font-semibold',
                                        'bg-success-100 text-success-800 dark:bg-success-500/15 dark:text-success-200' => $review['review_state'] === 'confirmed',
                                        'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-100' => $review['review_state'] === 'ready_for_administrative_review',
                                        'bg-danger-100 text-danger-800 dark:bg-danger-500/15 dark:text-danger-200' => $review['review_state'] === 'technical_review_blocked',
                                    ])>
                                        {{ match ($review['review_state']) {
                                            'confirmed' => 'Confirmado',
                                            'ready_for_administrative_review' => 'Pronto para revisão administrativa',
                                            default => 'Revisão técnica bloqueada',
                                        } }}
                                    </span>
                                </div>
                            </summary>

                            <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
                                @if (($review['holidays'] ?? []) !== [])
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-xs">
                                        <thead class="text-left text-gray-500 dark:text-gray-400"><tr><th class="pb-2">Data</th><th class="pb-2">Feriado</th><th class="pb-2">Fonte / vigência</th><th class="pb-2">Fingerprint</th></tr></thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                            @foreach ($review['holidays'] as $holiday)
                                                <tr>
                                                    <td class="py-2 pr-3">{{ $holiday['date'] }}</td>
                                                    <td class="py-2 pr-3 font-medium">{{ $holiday['name'] }}</td>
                                                    <td class="py-2 pr-3">
                                                        @if ($holiday['source_url'])
                                                            <a href="{{ $holiday['source_url'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-700 underline decoration-primary-400 underline-offset-2 dark:text-primary-300">{{ $holiday['source'] }} · {{ $holiday['article'] }}</a>
                                                        @else
                                                            {{ $holiday['source'] }} · {{ $holiday['article'] }}
                                                        @endif
                                                        <br>{{ $holiday['effective_from'] }} a {{ $holiday['effective_until'] ?? 'vigente' }}
                                                    </td>
                                                    <td class="max-w-40 break-all py-2 font-mono">{{ $holiday['fingerprint'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @endif
                                <dl class="space-y-2 rounded-xl bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                    <div><dt class="font-semibold text-gray-950 dark:text-white">Cobertura</dt><dd>{{ $review['coverage_status'] ?? '—' }}</dd></div>
                                    <div><dt class="font-semibold text-gray-950 dark:text-white">Checksum</dt><dd class="break-all font-mono">{{ $review['checksum'] ?? '—' }}</dd></div>
                                    @if (array_key_exists('checksum_reproducible', $review))
                                        <div><dt class="font-semibold text-gray-950 dark:text-white">Reprodutível</dt><dd>{{ $review['checksum_reproducible'] ? 'Sim' : 'Não' }}</dd></div>
                                        <div><dt class="font-semibold text-gray-950 dark:text-white">Conflitos / overrides</dt><dd>{{ $review['conflicts'] }} / {{ $review['overrides'] }}</dd></div>
                                        <div>
                                            <dt class="font-semibold text-gray-950 dark:text-white">Exclusões verificadas</dt>
                                            <dd>
                                                @foreach ($review['excluded_observances'] ?? [] as $observanceKey => $observanceSatisfied)
                                                    <span class="block">{{ str($observanceKey)->replace('_', ' ')->title() }}: {{ $observanceSatisfied ? 'ausente' : 'revisar' }}</span>
                                                @endforeach
                                            </dd>
                                        </div>
                                        <div><dt class="font-semibold text-gray-950 dark:text-white">Finais de semana</dt><dd>{{ $review['weekend_treatment'] }}</dd></div>
                                    @else
                                        <div><dt class="font-semibold text-gray-950 dark:text-white">Base de cobertura</dt><dd>{{ $review['coverage_basis'] ?? '—' }}</dd></div>
                                        <div><dt class="font-semibold text-gray-950 dark:text-white">Fonte oficial documentada</dt><dd>{{ ($review['source_is_official'] ?? false) && ($review['source_documented'] ?? false) ? 'Sim' : 'Não' }}</dd></div>
                                    @endif
                                </dl>
                            </div>
                        </details>
                    @endforeach
                </div>

                @if (! $report->calendarDiagnostics['administratively_confirmed'])
                    <div class="mt-4 flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/30 dark:bg-amber-500/10">
                        <p class="text-sm text-amber-950 dark:text-amber-100">Os anos tecnicamente íntegros estão prontos para revisão administrativa. O gate não os confirma.</p>
                        <x-filament::button tag="a" :href="$calendarReviewUrl" color="warning" icon="heroicon-o-arrow-top-right-on-square">Abrir governança de calendários</x-filament::button>
                    </div>
                @endif
            </x-filament::section>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">
            <x-filament::section heading="Integralização e quantidade" icon="heroicon-o-banknotes">
                <dl class="space-y-3 text-sm">
                    <div><dt class="font-semibold text-gray-950 dark:text-white">Primeira integralização</dt><dd class="text-gray-600 dark:text-gray-300">Comprovada: {{ $report->integralizationDiagnostics['proven_first_date'] ?? 'não' }} · inferível: {{ $report->integralizationDiagnostics['inferred_first_date'] ?? 'não disponível' }}</dd></div>
                    <div><dt class="font-semibold text-gray-950 dark:text-white">Quantidade</dt><dd class="text-gray-600 dark:text-gray-300">Emitida: {{ number_format((float) $report->quantityDiagnostics['issued_quantity'], 0, ',', '.') }} · integralizada comprovada: {{ $report->quantityDiagnostics['proven_integralized_quantity'] ?? 'não' }}</dd></div>
                    <div><dt class="font-semibold text-gray-950 dark:text-white">Dependência correta</dt><dd class="text-gray-600 dark:text-gray-300">Quantidade não é necessária para o PU unitário; é necessária para saldo devedor e pagamentos agregados.</dd></div>
                    <div><dt class="font-semibold text-gray-950 dark:text-white">Oferta em distribuição</dt><dd class="text-gray-600 dark:text-gray-300">Anúncio de Encerramento não é documento único obrigatório; liquidação ou posição confiável anterior pode comprovar a posição.</dd></div>
                </dl>
            </x-filament::section>

            <x-filament::section heading="Dados para a curva experimental" icon="heroicon-o-circle-stack">
                <dl class="space-y-3 text-sm">
                    <div><dt class="font-semibold text-gray-950 dark:text-white">Janela de snapshots</dt><dd class="text-gray-600 dark:text-gray-300">{{ $report->rateWindow['resolvable'] ? ($report->rateWindow['financial_requirement_start_date'].' a '.$report->rateWindow['homologation_end_date']) : 'PENDING até comprovar curve_start_date' }} · resolvida por {{ $report->rateWindow['derived_with'] }}</dd></div>
                    <div><dt class="font-semibold text-gray-950 dark:text-white">Snapshots</dt><dd class="text-gray-600 dark:text-gray-300">{{ $report->rateWindow['loaded_rate_count'] }} / {{ $report->rateWindow['required_rate_count'] }} carregados · {{ count($report->rateWindow['missing_rate_dates']) }} ausentes</dd></div>
                    <div><dt class="font-semibold text-gray-950 dark:text-white">Eventos PU</dt><dd class="text-gray-600 dark:text-gray-300">Cronograma contratual conhecido ({{ $report->eventDiagnostics['contractual_event_count'] }} eventos). Para a janela: {{ $report->eventDiagnostics['loaded_required_event_count'] }} / {{ $report->eventDiagnostics['required_event_count'] }} materializados.</dd></div>
                    <div><dt class="font-semibold text-gray-950 dark:text-white">Gabarito externo</dt><dd class="font-medium text-info-700 dark:text-info-300">{{ $report->limitations[0] ?? 'Comparação externa aderente.' }}</dd></div>
                </dl>
            </x-filament::section>
        </div>

        <x-filament::section heading="Próximos estados" icon="heroicon-o-arrow-long-right">
            <ol class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($report->nextActions as $action)
                    <li wire:key="next-action-{{ $loop->index }}" class="flex gap-2"><span class="font-semibold text-primary-600 dark:text-primary-300">{{ $loop->iteration }}.</span><span>{{ $action }}</span></li>
                @endforeach
            </ol>
        </x-filament::section>
    </div>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
