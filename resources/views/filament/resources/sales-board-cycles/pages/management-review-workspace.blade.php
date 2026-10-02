<x-filament-panels::page>
    @php
        /**
         * Apresentação apenas. Todo dado exibido vem pronto do workspace, que é
         * quem decide o que a Gestão vê -- nada é lido da fonte viva nem do
         * model cru aqui, e nenhuma conta é refeita: o portão e a prévia de
         * publicação chegam calculados pelos mesmos serviços que decidem.
         */
        $workspace = $this->workspace();
        $canDecide = $this->canDecide();
        $attempts = $this->attempts();
    @endphp

    @if ($workspace === null)
        <x-filament::section>
            <x-slot name="heading">Nenhuma análise aberta</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nenhuma análise da Gestão foi aberta para esta competência. A análise fica disponível depois que a
                construtora envia a validação; então use a ação <strong>Análise da Gestão</strong> na tela da competência.
            </p>
        </x-filament::section>
    @else
        {{-- Cabeçalho: o que está sendo analisado, e em que rodada. --}}
        <x-filament::section>
            <div class="grid gap-6 md:grid-cols-3 lg:grid-cols-6">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Emissão</p>
                    <p class="mt-1 text-sm font-semibold">{{ $workspace->emissionName }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Empreendimento</p>
                    <p class="mt-1 text-sm font-semibold">{{ $workspace->constructionName }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Competência</p>
                    <p class="mt-1 text-sm font-semibold">{{ $workspace->referenceMonth }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Posição em</p>
                    <p class="mt-1 text-sm font-semibold">{{ $workspace->positionDate->format('d/m/Y') }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Versão / rodadas</p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ $workspace->baselineLabel }} · Validação {{ $workspace->builderAttempt }} · Análise {{ $workspace->managementAttempt }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Situação</p>
                    <p class="mt-1">
                        <x-filament::badge :color="$workspace->status->color()">
                            {{ $workspace->status->label() }}
                        </x-filament::badge>
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-6 md:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Validação de origem</p>
                    <p class="mt-1 text-sm font-semibold">
                        {{ $workspace->builderFullyConfirmed ? 'Confirmada integralmente' : $workspace->builderDivergenceCount.' divergência(s) declarada(s)' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Não conformidades</p>
                    <p class="mt-1 text-sm font-semibold">{{ $workspace->progressLabel() }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Pendentes de decisão</p>
                    <p class="mt-1 text-sm font-semibold">{{ $workspace->pendingCount() }}</p>
                </div>
            </div>

            <div class="mt-6">
                @include('filament.sales-boards.next-action', ['nextAction' => $this->nextAction($workspace)])
            </div>

            @if (! $workspace->isApplicable)
                <p class="mt-4 rounded-md bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                    @if ($this->closedByReopenedCancellation($workspace))
                        Esta análise foi encerrada pelo cancelamento da competência, que depois foi reaberta. As decisões
                        registradas continuam consultáveis, mas nada pode ser aprovado a partir dela.
                    @elseif ($this->closedByAbandonedRectification($workspace))
                        Esta análise foi encerrada pela desistência da retificação: a competência voltou à posição publicada.
                        As decisões registradas continuam consultáveis, mas nada pode ser aprovado a partir dela.
                    @else
                        Esta análise se refere a uma versão que não é mais a vigente. As decisões registradas
                        continuam consultáveis, mas nada pode ser aprovado a partir dela.
                    @endif
                </p>
            @endif

            @if (count($attempts) > 1)
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($attempts as $attempt)
                        <a
                            href="{{ \App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace::getUrl(['record' => $this->getRecord(), 'review' => $attempt->getKey()]) }}"
                            @class([
                                'rounded-md px-3 py-1 text-xs font-medium ring-1 ring-inset',
                                'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400' => $attempt->getKey() === $workspace->reviewId,
                                'text-gray-600 ring-gray-300 dark:text-gray-400 dark:ring-gray-700' => $attempt->getKey() !== $workspace->reviewId,
                            ])
                        >
                            {{ $attempt->attemptLabel() }} · {{ $attempt->status->label() }}
                        </a>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- A retificação: o que esta análise aprova substitui a posição publicada. --}}
        @if ($workspace->isRectification())
            <x-filament::section wire:key="management-review-rectification">
                <x-slot name="heading">Retificação da competência publicada</x-slot>
                <x-slot name="description">
                    A posição publicada ({{ $workspace->rectification['published_version'] ?? '—' }}@if ($workspace->rectification['published_at']), em {{ \App\Support\BusinessTime::at($workspace->rectification['published_at'])->format('d/m/Y') }}@endif)
                    continua valendo até esta aprovação. Aprovar publica de novo o mesmo quadro, com o motivo no histórico de versões.
                </x-slot>

                <div class="space-y-2 text-sm">
                    <p class="rounded-md bg-warning-50 p-3 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                        <span class="font-medium">Motivo da retificação:</span> {{ $workspace->rectification['reason'] }}
                        <span class="block text-xs">
                            Aberta por {{ $workspace->rectification['requested_by'] ?? 'usuário não registrado' }}@if ($workspace->rectification['requested_at']) em {{ \App\Support\BusinessTime::at($workspace->rectification['requested_at'])->format('d/m/Y \à\s H:i') }}@endif. Quem abriu a retificação não aprova a publicação dela.
                        </span>
                    </p>
                    @if (filled($workspace->publishedDiff))
                        <p class="text-gray-600 dark:text-gray-300">
                            <span class="font-medium">O que muda contra a posição publicada:</span> {{ $workspace->publishedDiff }}
                        </p>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{--
            Os avisos que a apuração congelou com a versão analisada, com código
            e dica. Sem as vendas fora da política -- do mês ou de competência
            anterior --, que já estão nas não conformidades abaixo. Versão sem
            registro diz isso, em vez de parecer limpa.
        --}}
        @if ($workspace->warnings !== [])
            <x-filament::section wire:key="management-review-warnings">
                <x-slot name="heading">Avisos da apuração</x-slot>
                <x-slot name="description">
                    Sinais que a apuração registrou nesta versão sem impedir o congelamento. Não bloqueiam a aprovação, mas precisam ser conferidos antes dela.
                </x-slot>

                @include('filament.sales-boards.derivation-warnings', [
                    'warningGroups' => $workspace->warnings,
                    'showCodes' => true,
                ])
            </x-filament::section>
        @endif

        {{-- Resumo da posição: os quatro baldes, como o fechamento os apresenta. --}}
        <x-filament::section>
            <x-slot name="heading">Resumo da posição</x-slot>
            <x-slot name="description">
                {{ $workspace->unitsTotal }} unidade(s) · total apurado {{ $workspace->formattedTotalValue() }}.
            </x-slot>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($workspace->buckets as $bucket)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $bucket->label }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $bucket->units }}</p>
                        <p class="text-sm text-gray-500 tabular-nums dark:text-gray-400">{{ $bucket->formattedValue() }}</p>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- O que a construtora declarou. --}}
        <x-filament::section collapsible>
            <x-slot name="heading">Validação da construtora</x-slot>
            <x-slot name="description">
                @if ($workspace->builderSubmittedAt)
                    Enviada por {{ $workspace->builderReviewerName ?? '—' }} em {{ \App\Support\BusinessTime::at($workspace->builderSubmittedAt)->format('d/m/Y H:i') }} ·
                    {{ $workspace->builderFullyConfirmed ? 'confirmada integralmente' : $workspace->builderDivergenceCount.' divergência(s) declarada(s)' }}
                @else
                    Nenhuma submissão vinculada.
                @endif
            </x-slot>

            @if ($workspace->builderOverallComment)
                <p class="mb-4 rounded-md bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    {{ $workspace->builderOverallComment }}
                </p>
            @endif

            @if ($workspace->builderResponse !== null)
                <div class="mb-4">
                    @include('filament.sales-boards.builder-response', ['builderResponse' => $workspace->builderResponse])
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($workspace->builderSections as $section)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <p class="text-sm font-medium">{{ $section['label'] }}</p>
                        <p class="mt-1">
                            <x-filament::badge :color="$section['color']" size="sm">{{ $section['status'] }}</x-filament::badge>
                        </p>
                        {{-- warning-800 no claro: o 600 sobre o cartão claro fica em 2,5:1, abaixo do AA para texto pequeno. --}}
                        @if ($section['divergences'] > 0)
                            <p class="mt-1 text-xs text-warning-800 dark:text-warning-400" data-section-divergences>
                                {{ $section['divergences'] }} divergência(s)
                            </p>
                        @endif
                        @if ($section['comment'])
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $section['comment'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Os fatos de competências anteriores que esta competência recebeu, com a conformidade (área interna). --}}
        @if ($workspace->lateMovements !== [])
            <x-filament::section collapsible wire:key="management-review-late-movements">
                <x-slot name="heading">
                    <span class="flex items-center gap-3">
                        Movimentos de competências anteriores
                        <x-filament::badge color="warning">{{ count($workspace->lateMovements) }}</x-filament::badge>
                    </span>
                </x-slot>
                <x-slot name="description">
                    Vendas, distratos e quitações de competências já fechadas lançados depois, vendas publicadas com valor ou data revistos e
                    fatos de competências canceladas que esta absorve. A posição publicada das competências anteriores não muda: os fatos entram aqui.
                    As vendas fora da política ou sem conformidade determinável também estão nas pendências abaixo.
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-4">Movimento</th>
                                <th class="py-2 pr-4">Unidade</th>
                                <th class="py-2 pr-4">Contrato</th>
                                <th class="py-2 pr-4">Origem</th>
                                <th class="py-2 pr-4">Data</th>
                                <th class="py-2 pr-4 text-right">Valor</th>
                                <th class="py-2 pr-4">Conformidade</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($workspace->lateMovements as $movement)
                                <tr wire:key="management-late-movement-{{ $loop->index }}">
                                    <td class="py-2 pr-4">{{ $movement['type'] }}</td>
                                    <td class="py-2 pr-4 font-medium">{{ $movement['unit'] }}</td>
                                    <td class="py-2 pr-4">{{ $movement['contract'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge :color="$movement['timing_color']" size="sm">{{ $movement['timing'] }}</x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4">{{ $movement['date'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $movement['value'] }}</td>
                                    <td class="py-2 pr-4">{{ $movement['conformity'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- Como a posição da competência anterior chega a esta, pelos movimentos congelados. --}}
        @if ($workspace->bridge?->hasAnchor())
            <x-filament::section collapsible wire:key="management-review-bridge">
                <x-slot name="heading">Ponte com a competência anterior</x-slot>
                <x-slot name="description">{{ $workspace->bridge->summary() }}</x-slot>

                @include('filament.resources.sales-board-cycles.partials.competence-bridge', ['bridge' => $workspace->bridge])
            </x-filament::section>
        @endif

        {{-- As pendências, separadas por origem: são fatos de naturezas diferentes. --}}
        @foreach ([
            [\App\Enums\SalesBoardNonconformityOrigin::SystemSaleNonConform, 'Não conformidades do sistema', 'Vendas que o Nimbus apurou abaixo do mínimo autorizado pela política vigente na data. Área interna.'],
            [\App\Enums\SalesBoardNonconformityOrigin::SystemSaleUndetermined, 'Vendas sem conformidade determinável', 'Vendas que o Nimbus não conseguiu avaliar por falta do dado contra o qual elas seriam comparadas. Não são não conformidades: são ausências de informação, e só se resolvem corrigindo a fonte.'],
            [\App\Enums\SalesBoardNonconformityOrigin::SystemLateSaleWithoutPolicy, 'Vendas de competência publicada sem política aplicável', 'Vendas extemporâneas ou revistas, com valor e tabela conhecidos, num mês já publicado em que não havia política de desconto aplicável. A política dessa competência não pode mais ser registrada: a Gestão aprova a exceção, com motivo, ou exige a correção da venda.'],
            [\App\Enums\SalesBoardNonconformityOrigin::BuilderDeclared, 'Divergências declaradas pela construtora', 'O que a construtora afirma que não confere. A posição apurada não é alterada por nenhuma delas.'],
        ] as [$origin, $title, $description])
            @php($rows = $workspace->nonconformitiesOf($origin))

            <x-filament::section collapsible>
                <x-slot name="heading">
                    <span class="flex items-center gap-3">
                        {{ $title }}
                        <x-filament::badge :color="$origin->color()">{{ count($rows) }}</x-filament::badge>
                    </span>
                </x-slot>
                <x-slot name="description">{{ $description }}</x-slot>

                @if (count($rows) === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum apontamento nesta origem.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($rows as $row)
                            <div @class([
                                'rounded-lg border p-4',
                                'border-danger-300 dark:border-danger-700' => $row->blocksApproval(),
                                'border-gray-200 dark:border-gray-700' => ! $row->blocksApproval(),
                            ])>
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold">
                                            {{ $row->typeLabel }}
                                            @if ($row->unitLabel)
                                                <span class="text-gray-500 dark:text-gray-400">· {{ $row->unitLabel }}</span>
                                            @endif
                                            @if ($row->contractCode)
                                                <span class="text-gray-500 dark:text-gray-400">· {{ $row->contractCode }}</span>
                                            @endif
                                        </p>
                                        @if ($row->isFromEarlierCompetence())
                                            <p class="mt-1">
                                                <x-filament::badge color="warning" size="sm">{{ $row->timingLabel }}</x-filament::badge>
                                            </p>
                                        @endif
                                    </div>
                                    <x-filament::badge :color="$row->decision->color()">{{ $row->decision->label() }}</x-filament::badge>
                                </div>

                                {{-- Nimbus contra construtora, quando há duas versões do fato. --}}
                                @if ($row->builderStatement !== null)
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        <div class="rounded-md bg-gray-50 p-3 dark:bg-gray-800">
                                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Nimbus</p>
                                            <p class="mt-1 text-sm">{{ $row->systemStatement ?? '—' }}</p>
                                        </div>
                                        <div class="rounded-md bg-warning-50 p-3 dark:bg-warning-400/10">
                                            <p class="text-xs font-medium uppercase tracking-wide text-warning-700 dark:text-warning-400">Construtora</p>
                                            <p class="mt-1 text-sm">{{ $row->builderStatement }}</p>
                                        </div>
                                    </div>
                                @elseif ($row->systemStatement !== null)
                                    {{-- Apontamento do Nimbus: uma versão só do fato, e a causa congelada quando ele não concluiu. --}}
                                    <p class="mt-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-800">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Nimbus</span>
                                        <span class="mt-1 block">{{ $row->systemStatement }}</span>
                                    </p>
                                @endif

                                @if ($row->builderReason)
                                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                                        <span class="font-medium">Motivo declarado:</span> {{ $row->builderReason }}
                                    </p>
                                @endif

                                {{-- Só a conformidade indeterminada carrega callout: as outras se explicam pelos próprios números. --}}
                                @if ($callout = $row->callout())
                                    <p class="mt-3 rounded-md bg-info-50 p-3 text-sm text-info-700 dark:bg-info-400/10 dark:text-info-400">
                                        {{ $callout }}
                                    </p>
                                @endif

                                {{-- O caminho de correção conhecido, quando a pendência tem um. O link não consulta a unidade: o id vem da linha congelada. --}}
                                @if ($row->correctionGuidance)
                                    <div class="mt-3 rounded-md bg-info-50 p-3 text-sm text-info-700 dark:bg-info-400/10 dark:text-info-400">
                                        <p>{{ $row->correctionGuidance }}</p>
                                        @if ($row->unitToRetireId !== null && \App\Filament\Resources\ConstructionUnits\ConstructionUnitResource::canViewAny())
                                            <a
                                                href="{{ \App\Filament\Resources\ConstructionUnits\ConstructionUnitResource::getUrl('view', ['record' => $row->unitToRetireId]) }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="mt-2 inline-flex items-center gap-1 font-medium underline"
                                            >
                                                Abrir a unidade
                                            </a>
                                        @endif
                                    </div>
                                @endif

                                @if (count($row->facts) > 0)
                                    <div class="mt-3 overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <tbody>
                                                <tr class="border-b border-gray-100 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                                    @foreach ($row->facts as $fact)
                                                        <th class="py-1 pr-4 text-left font-medium">{{ $fact['label'] }}</th>
                                                    @endforeach
                                                </tr>
                                                <tr>
                                                    @foreach ($row->facts as $fact)
                                                        <td class="py-2 pr-4 tabular-nums">{{ $fact['value'] }}</td>
                                                    @endforeach
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                @endif

                                @if ($row->decisionReason)
                                    <p class="mt-3 rounded-md bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                        <span class="font-medium">{{ $row->decision->label() }}:</span> {{ $row->decisionReason }}
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            {{ $row->decidedByName ?? '—' }}@if ($row->decidedAt) · {{ \App\Support\BusinessTime::at($row->decidedAt)->format('d/m/Y H:i') }} @endif
                                        </span>
                                    </p>
                                @endif

                                @if ($canDecide)
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        {{ ($this->decideAction)(['nonconformity' => $row->id]) }}
                                        @unless ($row->isPending())
                                            {{ ($this->resetDecisionAction)(['nonconformity' => $row->id]) }}
                                        @endunless
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endforeach

        {{-- A análise aprovada não apura a fonte de novo: mostra quando e por quem a posição foi publicada. --}}
        @if ($workspace->approvedSummary !== null)
            <x-filament::section wire:key="management-review-approved">
                <x-slot name="heading">
                    <span class="flex items-center gap-3">
                        Publicação
                        <x-filament::badge color="success">Publicada</x-filament::badge>
                    </span>
                </x-slot>

                <p class="text-sm">{{ $workspace->approvedSummary }}</p>
            </x-filament::section>
        @else
        {{-- O portão: read model do estado real, nunca um checklist a marcar. --}}
        <x-filament::section>
            <x-slot name="heading">
                <span class="flex items-center gap-3">
                    Situação da fonte e portão de publicação
                    <x-filament::badge :color="$workspace->isReadyToPublish() ? 'success' : 'danger'">
                        {{ $workspace->isReadyToPublish() ? 'Pronto para publicação' : 'Publicação bloqueada' }}
                    </x-filament::badge>
                </span>
            </x-slot>
            <x-slot name="description">
                Nenhum item aqui é marcado à mão: cada linha é o estado real, conferido no momento da aprovação.
            </x-slot>

            @if ($workspace->staleImpact())
                <div @class([
                    'mb-4 rounded-md p-3 text-sm',
                    'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-400' => $workspace->requiresSourceOverride(),
                    'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => $workspace->isBlockedBySource(),
                    'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => ! $workspace->requiresSourceOverride() && ! $workspace->isBlockedBySource(),
                ])>
                    <p class="font-medium">{{ $workspace->staleImpact()->label() }}</p>
                    <p class="mt-1">
                        @if ($workspace->requiresSourceOverride())
                            Os dados de origem foram alterados, mas o resultado final da posição permanece igual.
                            A aprovação exigirá uma justificativa.
                        @elseif ($workspace->staleImpact() === \App\Enums\SalesBoardStaleImpact::Material)
                            Recalcule a posição antes da aprovação.
                        @else
                            {{ $workspace->staleImpact()->description() }}
                        @endif
                    </p>
                </div>
            @endif

            <ul class="space-y-2">
                @foreach ($workspace->gate['checks'] as $check)
                    @php($informative = (bool) ($check['informative'] ?? false))
                    <li class="flex items-start gap-3 text-sm">
                        <x-filament::icon
                            :icon="$informative ? 'heroicon-o-information-circle' : ($check['passed'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')"
                            @class([
                                'mt-0.5 h-5 w-5 shrink-0',
                                'text-info-600 dark:text-info-400' => $informative,
                                'text-success-600 dark:text-success-400' => ! $informative && $check['passed'],
                                'text-danger-600 dark:text-danger-400' => ! $informative && ! $check['passed'],
                            ])
                        />
                        <span>
                            {{ $check['label'] }}
                            @if ($check['detail'])
                                <span class="text-gray-500 dark:text-gray-400">— {{ $check['detail'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            @if ($workspace->publicationPreview !== null)
                <div class="mt-6">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        O que será registrado no Quadro de Vendas
                    </p>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <tr>
                                    <th class="py-2 pr-4">Balde</th>
                                    <th class="py-2 pr-4 text-right">Unidades</th>
                                    <th class="py-2 pr-4 text-right">Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($workspace->publicationPreview->buckets() as $bucket)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 pr-4">{{ $bucket['label'] }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $bucket['units'] }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $this->money($bucket['valueCents']) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold">
                                    <td class="py-2 pr-4">Total</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $workspace->publicationPreview->totalUnits }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $this->money($workspace->publicationPreview->totalValueCents()) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{--
                Ação escrita direto no Blade é impressa mesmo quando `visible()` a
                esconde, como um botão inerte: rodada encerrada não mostra nenhuma
                das duas, pela mesma condição das ações. O botão de aprovar só
                aparece com o portão aberto, lido do workspace que a página já
                trouxe, sem apurá-lo de novo -- a ação em si não depende do portão,
                que ela refaz ao executar. O motivo do maker/checker também vai em
                texto ao lado do botão desabilitado: o tooltip não existe em tela
                de toque.
            --}}
            @if ($canDecide)
                @php($approvalConflict = $workspace->isReadyToPublish() ? $this->approvalConflict() : null)

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    {{ $this->returnToBuilderAction }}

                    @if ($workspace->isReadyToPublish())
                        {{ $this->approveAction }}

                        @if ($approvalConflict !== null)
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-medium">{{ $this->approveLabel() }} indisponível:</span>
                                {{ $approvalConflict }}
                            </p>
                        @endif
                    @else
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            <span class="font-medium">{{ $this->approveLabel() }} indisponível:</span>
                            {{ \App\Support\SalesBoards\GateChecklistSummary::sentence($this->failedGateChecks($workspace)) }}
                        </p>
                    @endif
                </div>
            @elseif ($this->awaitsGestao())
                <p class="mt-6 text-sm text-gray-600 dark:text-gray-300">
                    <span class="font-medium">Decidir, devolver e publicar são da Gestão:</span>
                    peça a quem tem a permissão de aprovação do Quadro de Vendas.
                </p>
            @endif
        </x-filament::section>
        @endif

        {{-- O encerramento, quando já houve um. --}}
        @if ($workspace->approvedAt || $workspace->returnReason)
            <x-filament::section>
                <x-slot name="heading">Encerramento desta rodada</x-slot>

                @if ($workspace->approvedAt)
                    <p class="text-sm">
                        Aprovada por <strong>{{ $workspace->approvedByName ?? '—' }}</strong>
                        em {{ \App\Support\BusinessTime::at($workspace->approvedAt)->format('d/m/Y H:i') }}.
                    </p>
                    @if ($workspace->approvedBySubmitter)
                        <p class="mt-2 rounded-md bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                            Aprovada pelo mesmo usuário que registrou a validação — isenção de segregação do super-admin.
                        </p>
                    @endif
                    @if ($workspace->sourceChangeReason)
                        <p class="mt-2 rounded-md bg-info-50 p-3 text-sm text-info-700 dark:bg-info-400/10 dark:text-info-400">
                            <span class="font-medium">Aprovada com a fonte alterada:</span> {{ $workspace->sourceChangeReason }}
                        </p>
                    @endif
                @endif

                @if ($workspace->returnReason)
                    <p class="mt-2 rounded-md bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                        <span class="font-medium">Devolvida à construtora:</span> {{ $workspace->returnReason }}
                    </p>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
