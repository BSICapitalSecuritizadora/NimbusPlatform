<x-filament-panels::page>
    @php
        /**
         * Apresentação apenas. A comparação, o delta e a prontidão vêm do retrato
         * congelado na homologação -- nada é relido da fonte ao renderizar. O
         * portão é a única leitura viva, porque ele precisa dizer se a
         * homologação ainda descreve o mundo.
         */
        $emission = $this->emission();
        $homologation = $this->currentHomologation();
        $gate = $this->gate();
        $recipients = $this->recipients();
        $canManage = $this->canManage();
    @endphp

    {{-- O modo atual, e o que ele significa. --}}
    <x-filament::section>
        <div class="grid gap-6 md:grid-cols-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Emissão</p>
                <p class="mt-1 text-sm font-semibold">{{ $emission->name }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Modo</p>
                <p class="mt-1">
                    <x-filament::badge :color="$emission->sales_board_source->color()">
                        {{ $emission->sales_board_source->label() }}
                    </x-filament::badge>
                </p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Competência inicial</p>
                <p class="mt-1 text-sm font-semibold">
                    {{ $emission->sales_board_automation_start_reference_month?->format('m/Y') ?? '—' }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Empreendimentos</p>
                <p class="mt-1 text-sm font-semibold">{{ $emission->constructions()->count() }}</p>
            </div>
        </div>

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            {{ $emission->sales_board_source->description() }}
        </p>

        @unless ($this->globalAutomationEnabled())
            <p class="mt-4 rounded-md bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                A automação global está desligada. Uma Emissão pode ser homologada e ativada, mas
                <strong>nenhuma competência será processada</strong> enquanto o agendador global estiver desligado.
            </p>
        @endunless

        @if ($this->hasScopeDrift())
            <p class="mt-4 rounded-md bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                <strong>Automação suspensa por alteração de escopo.</strong>
                Os empreendimentos da Emissão mudaram desde a homologação vigente. O rollout é por Emissão inteira,
                então nenhum empreendimento é processado até que uma nova homologação cubra o conjunto atual —
                automatizar só os antigos deixaria metade da Emissão sem competência.
            </p>
        @endif

        @if ($canManage)
            <div class="mt-6 flex flex-wrap gap-3">
                {{ $this->openHomologationAction }}
                {{ $this->activateAction }}
                {{ $this->returnToLegacyAction }}
            </div>
        @endif
    </x-filament::section>

    @if ($homologation === null)
        <x-filament::section>
            <x-slot name="heading">Nenhuma homologação registrada</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Automatizar uma Emissão não é ligar uma chave: antes é preciso comparar a posição do legado com a
                que o motor novo apura, entender as diferenças, revisar os impactos e definir os responsáveis.
            </p>
        </x-filament::section>
    @else
        {{-- A homologação em curso. --}}
        <x-filament::section>
            <x-slot name="heading">
                <span class="flex items-center gap-3">
                    {{ $homologation->attemptLabel() }}
                    <x-filament::badge :color="$homologation->status->color()">
                        {{ $homologation->status->label() }}
                    </x-filament::badge>
                </span>
            </x-slot>
            <x-slot name="description">
                Competência inicial {{ $homologation->startMonthLabel() }} ·
                comparação contra {{ $homologation->comparisonMonthLabel() }} ·
                abre validação: {{ $homologation->auto_open_builder_review ? 'sim' : 'não' }}
            </x-slot>

            @if ($homologation->auto_open_builder_review)
                <p class="mb-4 rounded-md bg-info-50 p-3 text-sm text-info-700 dark:bg-info-400/10 dark:text-info-400">
                    A abertura automática cria apenas a <strong>validação interna em rascunho</strong>.
                    A entrega e a autenticação externa da construtora ainda não estão configuradas,
                    então nada é enviado a terceiros.
                </p>
            @endif

            @if ($canManage)
                <div class="flex flex-wrap gap-3">
                    {{ $this->reassessAction }}
                    {{ $this->markGuaranteesReviewedAction }}
                    {{ $this->markMonthlyReportReviewedAction }}
                </div>
            @endif

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Impacto sobre Garantias</p>
                    <p class="mt-1 text-sm">
                        {{ $homologation->guaranteesReviewed()
                            ? 'Revisado por '.($homologation->guaranteesReviewedBy?->name ?? '—').' em '.$homologation->guarantees_reviewed_at->format('d/m/Y H:i')
                            : 'Ainda não revisado.' }}
                    </p>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Impacto sobre o Relatório Mensal</p>
                    <p class="mt-1 text-sm">
                        {{ $homologation->monthlyReportReviewed()
                            ? 'Revisado por '.($homologation->monthlyReportReviewedBy?->name ?? '—').' em '.$homologation->monthly_report_reviewed_at->format('d/m/Y H:i')
                            : 'Ainda não revisado.' }}
                    </p>
                </div>
            </div>
        </x-filament::section>

        {{-- Legado contra derivado, empreendimento a empreendimento. --}}
        <x-filament::section>
            <x-slot name="heading">Posição do legado × posição apurada</x-slot>
            <x-slot name="description">
                Comparação na competência {{ $homologation->comparisonMonthLabel() }} — a última antes do corte.
            </x-slot>

            <div class="space-y-4">
                @foreach ($homologation->constructions as $row)
                    <div @class([
                        'rounded-lg border p-4',
                        'border-danger-300 dark:border-danger-700' => ! $row->is_ready,
                        'border-warning-300 dark:border-warning-700' => $row->is_ready && $row->requiresAcknowledgement(),
                        'border-gray-200 dark:border-gray-700' => $row->is_ready && ! $row->requiresAcknowledgement(),
                    ])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <p class="text-sm font-semibold">{{ $row->construction?->development_name ?? '—' }}</p>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::badge :color="$row->is_ready ? 'success' : 'danger'">
                                    {{ $row->is_ready ? 'Fonte pronta' : 'Fonte incompleta' }}
                                </x-filament::badge>
                                <x-filament::badge :color="$row->comparison_status->color()">
                                    {{ $row->comparison_status->label() }}
                                </x-filament::badge>
                            </div>
                        </div>

                        @unless ($row->is_ready)
                            <p class="mt-3 rounded-md bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                                {{ $row->blocker_message }}
                            </p>
                        @endunless

                        @if ($row->comparison_status === \App\Enums\SalesBoardRolloutComparisonStatus::NoLegacyPosition)
                            <p class="mt-3 rounded-md bg-info-50 p-3 text-sm text-info-700 dark:bg-info-400/10 dark:text-info-400">
                                Sem posição legada disponível para comparação. A homologação se apoia apenas nas
                                fontes operacionais — comparar contra zero afirmaria que a posição era zero,
                                que é outra coisa.
                            </p>
                        @else
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <tr>
                                            <th class="py-2 pr-4">Balde</th>
                                            <th class="py-2 pr-4 text-right">Legado</th>
                                            <th class="py-2 pr-4 text-right">Apurado</th>
                                            <th class="py-2 pr-4 text-right">Diferença</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach (['stock' => 'Estoque', 'financed' => 'Financiado', 'paid' => 'Quitado', 'exchanged' => 'Permutado'] as $key => $label)
                                            @php
                                                $legacy = $row->legacy_position['buckets'][$key] ?? null;
                                                $derived = $row->derived_position['buckets'][$key] ?? null;
                                                $delta = $row->deltaFor($key);
                                            @endphp
                                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                                <td class="py-2 pr-4">{{ $label }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums">
                                                    {{ $legacy['units'] ?? '—' }} · {{ $this->money($legacy['value_cents'] ?? null) }}
                                                </td>
                                                <td class="py-2 pr-4 text-right tabular-nums">
                                                    {{ $derived['units'] ?? '—' }} · {{ $this->money($derived['value_cents'] ?? null) }}
                                                </td>
                                                <td @class([
                                                    'py-2 pr-4 text-right tabular-nums',
                                                    'text-warning-700 dark:text-warning-400' => $delta && ($delta['units'] !== 0 || $delta['valueCents'] !== 0),
                                                ])>
                                                    @if ($delta)
                                                        {{ $delta['units'] > 0 ? '+' : '' }}{{ $delta['units'] }} ·
                                                        {{ $this->signedMoney($delta['valueCents']) }}
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if ($row->accepted_difference)
                            <p class="mt-3 rounded-md bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                <span class="font-medium">Diferença analisada:</span> {{ $row->difference_reason }}
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row->acceptedBy?->name ?? '—' }}
                                    @if ($row->accepted_at) · {{ $row->accepted_at->format('d/m/Y H:i') }} @endif
                                </span>
                            </p>
                        @endif

                        @if ($row->has_cancelled_cycle_at_or_after_start)
                            <p class="mt-3 text-xs text-warning-700 dark:text-warning-400">
                                Existe ciclo cancelado a partir da competência inicial: a automação vai considerá-lo
                                como identidade já existente e não criará outro.
                            </p>
                        @endif

                        @if ($canManage && $homologation->isEditable() && $row->requiresAcknowledgement())
                            <div class="mt-3">
                                {{ ($this->acceptDifferenceAction)(['row' => $row->id]) }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Responsáveis internos. --}}
    <x-filament::section>
        <x-slot name="heading">Responsáveis pelos avisos</x-slot>
        <x-slot name="description">
            Define para quem os avisos da automação vão. Não concede permissão nenhuma — quem abre as telas
            continua passando pelas permissões de sempre.
        </x-slot>

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (\App\Enums\SalesBoardRolloutRecipientRole::cases() as $role)
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-sm font-semibold">{{ $role->label() }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $role->description() }}</p>

                    @if (count($recipients[$role->value]) === 0)
                        <p class="mt-3 text-sm text-danger-700 dark:text-danger-400">Nenhum responsável definido.</p>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach ($recipients[$role->value] as $recipient)
                                <li class="flex items-center justify-between gap-3 text-sm">
                                    <span>
                                        {{ $recipient->user?->name ?? '—' }}
                                        @unless ($recipient->user?->isOperational())
                                            <x-filament::badge color="danger" size="sm">Inativo</x-filament::badge>
                                        @endunless
                                    </span>
                                    @if ($canManage)
                                        {{ ($this->removeRecipientAction)(['recipient' => $recipient->id]) }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($canManage)
                        <div class="mt-3">
                            {{ ($this->addRecipientAction)(['role' => $role->value]) }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- O portão: read model do estado real, nunca um checklist a marcar. --}}
    @if ($gate !== null)
        <x-filament::section>
            <x-slot name="heading">
                <span class="flex items-center gap-3">
                    Portão da homologação
                    <x-filament::badge :color="$gate['ready'] ? 'success' : 'danger'">
                        {{ $gate['ready'] ? 'Pronta para aprovação' : 'Aprovação bloqueada' }}
                    </x-filament::badge>
                </span>
            </x-slot>
            <x-slot name="description">
                Nenhum item é marcado à mão: cada linha é o estado real, conferido de novo no momento da aprovação.
            </x-slot>

            <ul class="space-y-2">
                @foreach ($gate['checks'] as $check)
                    <li class="flex items-start gap-3 text-sm">
                        <x-filament::icon
                            :icon="$check['passed'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'"
                            @class([
                                'mt-0.5 h-5 w-5 shrink-0',
                                'text-success-600 dark:text-success-400' => $check['passed'],
                                'text-danger-600 dark:text-danger-400' => ! $check['passed'],
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

            @if ($canManage)
                <div class="mt-6 flex flex-wrap gap-3">
                    {{ $this->approveAction }}
                    {{ $this->rejectAction }}
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- A trilha de mudanças de modo. --}}
    @php($events = $emission->salesBoardRolloutEvents()->with('actor')->limit(10)->get())

    @if ($events->isNotEmpty())
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Histórico de rollout</x-slot>

            <ul class="space-y-3">
                @foreach ($events as $event)
                    <li class="text-sm">
                        <x-filament::badge :color="$event->event_type->color()">
                            {{ $event->event_type->label() }}
                        </x-filament::badge>
                        <span class="ml-2 text-gray-500 dark:text-gray-400">
                            {{ $event->created_at?->format('d/m/Y H:i') }} ·
                            {{ $event->actor?->name ?? '—' }}
                            @if ($event->start_reference_month) · a partir de {{ $event->start_reference_month->format('m/Y') }} @endif
                        </span>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $event->reason }}</p>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
