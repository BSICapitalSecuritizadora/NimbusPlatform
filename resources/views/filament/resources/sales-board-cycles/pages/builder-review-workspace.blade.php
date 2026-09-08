<x-filament-panels::page>
    @php
        /**
         * Apresentação apenas. Todo dado exibido vem pronto do workspace, que é
         * quem decide o que a construtora pode ver -- nada é lido da fonte viva
         * nem do model cru aqui.
         */
        $workspace = $this->workspace();
        $canEdit = $this->canEdit();
        $attempts = $this->attempts();
    @endphp

    @if ($workspace === null)
        <x-filament::section>
            <x-slot name="heading">Nenhuma validação aberta</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Esta competência ainda não foi enviada à construtora. Use a ação
                <strong>Enviar para validação da construtora</strong> na tela da competência.
            </p>
        </x-filament::section>
    @else
        {{-- Cabeçalho executivo: o que está sendo validado, e o quanto já foi. --}}
        <x-filament::section>
            <div class="grid gap-6 md:grid-cols-4">
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
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Situação</p>
                    <p class="mt-1">
                        <x-filament::badge :color="$workspace->status->color()">
                            {{ $workspace->status->label() }}
                        </x-filament::badge>
                    </p>
                </div>
            </div>

            <div class="mt-6">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium">{{ $workspace->progressLabel() }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ $workspace->progressPercent() }}%</span>
                </div>
                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div class="h-full rounded-full bg-primary-600" style="width: {{ $workspace->progressPercent() }}%"></div>
                </div>
            </div>
        </x-filament::section>

        {{-- O que a Gestão pediu ao devolver, quando esta rodada nasceu de uma devolução. --}}
        @if ($returnReason = $this->returnReason())
            <x-filament::section>
                <x-slot name="heading">Motivo da devolução da Gestão</x-slot>
                <x-slot name="description">
                    Esta rodada foi aberta a pedido da Gestão. As declarações da rodada anterior
                    continuam registradas e não foram copiadas para cá.
                </x-slot>

                <p class="rounded-md bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                    {{ $returnReason }}
                </p>
            </x-filament::section>
        @endif

        {{-- Os quatro baldes, como o fechamento os apresenta. --}}
        <x-filament::section>
            <x-slot name="heading">Posição no fechamento</x-slot>
            <x-slot name="description">Totais apurados em {{ $workspace->positionDate->format('d/m/Y') }}.</x-slot>

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

        @foreach ([['Posição no fechamento', $workspace->positionSections()], ['Movimentações do mês', $workspace->movementSections()]] as [$groupLabel, $sections])
            <h2 class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $groupLabel }}</h2>

            @foreach ($sections as $section)
                <x-filament::section collapsible :collapsed="$section->status->isResolved()">
                    <x-slot name="heading">
                        <span class="flex items-center gap-3">
                            {{ $section->section->label() }}
                            <x-filament::badge :color="$section->status->color()">{{ $section->status->label() }}</x-filament::badge>
                            @if ($section->divergenceCount > 0)
                                <x-filament::badge color="warning">
                                    {{ $section->divergenceCount }} divergência(s)
                                </x-filament::badge>
                            @endif
                        </span>
                    </x-slot>
                    <x-slot name="description">
                        {{ $section->section->description() }} · {{ $section->headline() }}
                    </x-slot>

                    @if ($section->comment)
                        <p class="mb-4 rounded-md bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ $section->comment }}
                        </p>
                    @endif

                    @if (count($section->rows) === 0)
                        <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum lançamento nesta seção.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                    <tr>
                                        <th class="py-2 pr-4">Unidade</th>
                                        <th class="py-2 pr-4">Contrato</th>
                                        @if ($section->isPosition())
                                            <th class="py-2 pr-4">Data da venda</th>
                                            <th class="py-2 pr-4 text-right">Valor da venda</th>
                                            <th class="py-2 pr-4 text-right">Valor de referência</th>
                                            <th class="py-2 pr-4">Quitação</th>
                                            <th class="py-2 pr-4 text-right">Permuta</th>
                                        @else
                                            <th class="py-2 pr-4">Data</th>
                                            <th class="py-2 pr-4 text-right">Valor</th>
                                            <th class="py-2 pr-4 text-right">Parcelas</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($section->rows as $row)
                                        <tr>
                                            <td class="py-2 pr-4 font-medium">{{ $row->displayName() }}</td>
                                            <td class="py-2 pr-4">{{ $row->contractCode ?? '—' }}</td>
                                            @if ($section->isPosition())
                                                <td class="py-2 pr-4">{{ $row->saleDate?->format('d/m/Y') ?? '—' }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums">{{ $this->money($row->saleValueCents) }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums">{{ $this->money($row->referenceValueCents) }}</td>
                                                <td class="py-2 pr-4">{{ $row->settlementLabel ?? '—' }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums">{{ $this->money($row->exchangeValueCents) }}</td>
                                            @else
                                                <td class="py-2 pr-4">{{ $row->eventDate?->format('d/m/Y') ?? '—' }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums">{{ $this->money($row->saleValueCents) }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row->settlementInstallmentsTotal ?? '—' }}</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($canEdit)
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($section->status === \App\Enums\SalesBoardBuilderReviewSectionStatus::Confirmed)
                                {{ ($this->reopenSectionAction)(['section' => $section->sectionId]) }}
                            @else
                                {{ ($this->confirmSectionAction)(['section' => $section->sectionId]) }}
                            @endif

                            {{ ($this->declareDivergenceAction)(['section' => $section->sectionId]) }}
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        @endforeach

        {{-- O que a construtora declarou. O snapshot acima continua intacto. --}}
        <x-filament::section>
            <x-slot name="heading">Divergências declaradas</x-slot>
            <x-slot name="description">
                A posição apresentada não é alterada por estas declarações. Elas seguem para análise da Gestão.
            </x-slot>

            @if ($workspace->divergenceCount() === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma divergência registrada.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-4">Tipo</th>
                                <th class="py-2 pr-4">Seção</th>
                                <th class="py-2 pr-4">Unidade</th>
                                <th class="py-2 pr-4">Contrato</th>
                                <th class="py-2 pr-4">Declarado</th>
                                <th class="py-2 pr-4">Motivo</th>
                                @if ($canEdit)
                                    <th class="py-2"></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($workspace->divergences as $divergence)
                                <tr>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge :color="$divergence->type->color()">{{ $divergence->type->label() }}</x-filament::badge>
                                    </td>
                                    <td class="py-2 pr-4">{{ $divergence->section?->section->label() }}</td>
                                    <td class="py-2 pr-4 font-medium">{{ $divergence->unitLabel() }}</td>
                                    <td class="py-2 pr-4">{{ $divergence->contractLabel() ?? '—' }}</td>
                                    <td class="py-2 pr-4 tabular-nums">
                                        {{ collect([
                                            $divergence->declared_classification?->label(),
                                            $divergence->declared_value === null ? null : $this->money(\App\Support\Money\IntegerMoney::cents($divergence->declared_value)),
                                            $divergence->declared_date?->format('d/m/Y'),
                                        ])->filter()->implode(' · ') ?: '—' }}
                                    </td>
                                    <td class="py-2 pr-4">{{ $divergence->reason }}</td>
                                    @if ($canEdit)
                                        <td class="py-2 text-right">
                                            {{ ($this->removeDivergenceAction)(['divergence' => $divergence->getKey()]) }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @if ($canEdit)
            <x-filament::section>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-medium">Concluir a validação</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            @if ($workspace->canSubmit())
                                Todas as seções foram revisadas. O envio é definitivo.
                            @else
                                Ainda falta revisar: {{ collect($workspace->pendingSections())->map(fn ($s) => $s->label())->implode(', ') }}.
                            @endif
                        </p>
                    </div>

                    @if ($workspace->canSubmit())
                        {{ $this->submitReviewAction }}
                    @endif
                </div>
            </x-filament::section>
        @endif

        @if ($workspace->submittedAt !== null)
            <x-filament::section>
                <x-slot name="heading">Envio</x-slot>
                <div class="grid gap-6 md:grid-cols-3 text-sm">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Enviada em</p>
                        <p class="mt-1 font-medium">{{ $workspace->submittedAt->format('d/m/Y \à\s H:i') }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Responsável</p>
                        <p class="mt-1 font-medium">{{ $workspace->reviewerName ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Observações</p>
                        <p class="mt-1">{{ $workspace->overallComment ?? '—' }}</p>
                    </div>
                </div>
            </x-filament::section>
        @endif

        @if (count($attempts) > 1)
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">Rodadas anteriores</x-slot>
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
                    @foreach ($attempts as $attempt)
                        <li class="flex items-center justify-between py-2">
                            <span class="flex items-center gap-3">
                                <span class="font-medium">{{ $attempt->attemptLabel() }}</span>
                                <x-filament::badge :color="$attempt->status->color()">{{ $attempt->status->label() }}</x-filament::badge>
                            </span>
                            <a class="text-primary-600 hover:underline"
                               href="{{ request()->url() }}?review={{ $attempt->getKey() }}">Ver</a>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
