@php
    use App\Enums\MeasurementRevisionStatus;
    use App\Enums\MeasurementRevisionAdjustmentStatus;
    use App\DTOs\Measurements\MeasurementPhysicalProgress;
    use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
    use App\Services\MeasurementFinancialReconciliationService;
    use App\Services\MeasurementPhysicalProgressService;
    use App\Services\MeasurementRevisionService;
    use Illuminate\Support\Str;

    $record = $getRecord();
    $overview = app(MeasurementRevisionService::class)->overview($record);

    $badges = [
        'gray' => 'text-gray-700 bg-gray-50 border-gray-200 dark:text-gray-300 dark:bg-white/5 dark:border-white/10',
        'warning' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        'success' => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        'info' => 'text-sky-700 bg-sky-50 border-sky-200 dark:text-sky-400 dark:bg-sky-500/10 dark:border-sky-500/20',
        'danger' => 'text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-500/10 dark:border-red-500/20',
    ];
    $tones = [
        'gray' => 'text-gray-500 dark:text-gray-400',
        'warning' => 'text-amber-700 dark:text-amber-400',
        'success' => 'text-emerald-700 dark:text-emerald-400',
        'info' => 'text-sky-700 dark:text-sky-400',
        'danger' => 'text-red-700 dark:text-red-400',
    ];

    $money = fn (?string $value): string => $value === null ? 'Sem referência' : MeasurementFinancialReconciliationService::formatCurrency($value);
    $currency = fn (?string $value): string => MeasurementFinancialReconciliationService::formatCurrency($value ?? '0.00');
    $percent = fn (string $value): string => MeasurementPhysicalProgress::format((int) MeasurementPhysicalProgress::basisPoints($value));
    $points = fn (int $basisPoints): string => ($basisPoints > 0 ? '+' : ($basisPoints < 0 ? '−' : '')).str_replace('%', '', MeasurementPhysicalProgress::format(abs($basisPoints))).' p.p.';
    $positive = fn (?string $value): bool => $value !== null && bccomp($value, '0', 2) > 0;

    $isEffective = $record->isEffectiveRevision();
    $isHistoricalRevision = $record->revisionStatus() === MeasurementRevisionStatus::Superseded;
    $currentLabel = $record->isRevision() ? $record->revisionLabel() : 'R0';
    $previousLabel = $overview['previous_label'] ?? 'anterior';
    $isClosedWithoutPosition = in_array($record->revisionStatus(), [MeasurementRevisionStatus::Rejected, MeasurementRevisionStatus::Cancelled], true);
    // Rótulo curto de cada revisão da família (R0, R1...), para nomear quem reconheceu antes.
    $familyLabels = collect($overview['family'])->mapWithKeys(fn (array $member): array => [(int) $member['id'] => Str::before((string) $member['label'], ' ·')]);

    /*
     * O que fica em aberto num empreendimento: valor pago a maior (a família
     * pagou acima do aprovado, reconhecido ou não -- o sistema não registra
     * devolução), saldo a pagar, ou nada. Sem Fundo de Obra não há aprovado
     * com que comparar: com pagamento da família, a exposição não é mensurável.
     */
    $openPosition = function (?string $approved, bool $unmeasurable, ?string $outstanding, ?string $remaining, bool $historical = false) use ($positive): array {
        // A posição da revisão substituída é a de quando ela valia: histórico,
        // não o que está em aberto hoje.
        return match (true) {
            $approved === null && $unmeasurable => ['label' => $historical ? 'Exposição não mensurável enquanto vigente (sem Fundo de Obra)' : 'Exposição não mensurável (sem Fundo de Obra)', 'amount' => null, 'tone' => $historical ? 'gray' : 'warning'],
            $approved === null => ['label' => 'Sem referência financeira', 'amount' => null, 'tone' => 'gray'],
            $positive($outstanding) => ['label' => $historical ? 'Pago a maior enquanto vigente' : 'Pago a maior em aberto', 'amount' => $outstanding, 'tone' => $historical ? 'gray' : 'danger'],
            $positive($remaining) => ['label' => $historical ? 'Saldo a pagar enquanto vigente' : 'Saldo a pagar em aberto', 'amount' => $remaining, 'tone' => $historical ? 'gray' : 'warning'],
            default => ['label' => null, 'amount' => null, 'tone' => 'gray'],
        };
    };

    $regularization = function (MeasurementRevisionPlanSetPosition $position): array {
        $status = $position->adjustmentStatus;

        return match (true) {
            $position->isHistorical() => ['label' => MeasurementRevisionAdjustmentStatus::PositionSuperseded->regularizationLabel(), 'tone' => 'gray'],
            $position->isExposureUnmeasurable() => ['label' => 'Não mensurável (sem Fundo de Obra)', 'tone' => 'warning'],
            $status->isSettled() => ['label' => $status->regularizationLabel(), 'tone' => 'success'],
            $status === MeasurementRevisionAdjustmentStatus::SupplementaryPaymentRegistered => ['label' => $status->regularizationLabel(), 'tone' => 'info'],
            $status->isUnresolved() => ['label' => $status->regularizationLabel(), 'tone' => $status->color()],
            default => ['label' => $status->regularizationLabel(), 'tone' => 'gray'],
        };
    };

    $acknowledgmentColor = fn (MeasurementRevisionAdjustmentStatus $status): string => match (true) {
        ! $status->isIdentified() || $status->isSettled() => 'gray',
        $status->isAcknowledged() => 'info',
        default => 'warning',
    };

    /*
     * O reconhecimento de uma posição. Na revisão substituída vale o que ela
     * era enquanto vigente: a finalizada reconheceu o que a Finalização dela
     * aceitou; a encerrada antes da Finalização não reconheceu nada -- e não
     * reconhecerá: a diferença passou para a revisão vigente.
     */
    $acknowledgment = function (MeasurementRevisionPlanSetPosition $position) use ($record, $acknowledgmentColor): array {
        $status = $position->statusWhenEffective ?? $position->adjustmentStatus;

        if ($position->isHistorical()
            && $record->status !== 'finalized'
            && $status->isIdentified()
            && ! $status->isAcknowledged()
            && ! $status->isSettled()
            && $status !== MeasurementRevisionAdjustmentStatus::SupplementaryPaymentRegistered) {
            return ['label' => 'Não reconhecida — encerrada antes da Finalização', 'color' => 'gray'];
        }

        return ['label' => $status->acknowledgmentLabel(), 'color' => $acknowledgmentColor($status)];
    };

    /*
     * O valor pago a maior em aberto, com as contas fechando: o reconhecido
     * por uma finalização anterior, o que esta revisão reconhece (ou ainda
     * precisa reconhecer) e o que passou do aprovado por pagamento da própria
     * revisão -- divergência do pagamento, tratada no enquadramento dele.
     */
    $overpaymentBreakdown = function (MeasurementRevisionPlanSetPosition $position) use ($currency, $familyLabels, $positive): string {
        $origins = collect($position->previouslyAcknowledgedByMeasurementIds)
            ->map(fn (int $id): string => $familyLabels[$id] ?? '#'.$id)
            ->implode(', ');
        $ownExcess = bcsub(bcsub((string) $position->outstandingOverpaymentAmount, $position->previouslyAcknowledgedOverpaymentAmount ?? '0.00', 2), $position->unacknowledgedOverpaymentAmount ?? '0.00', 2);
        $acknowledgedHere = ($position->statusWhenEffective ?? $position->adjustmentStatus) === MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered;
        $hereLabel = match (true) {
            $acknowledgedHere => 'reconhecido nesta revisão ',
            $position->isHistorical() => 'não reconhecido nesta revisão ',
            default => 'a reconhecer nesta revisão ',
        };

        return collect([
            'reconhecido anteriormente '.$currency($position->previouslyAcknowledgedOverpaymentAmount).($origins === '' ? '' : ' ('.$origins.')'),
            $hereLabel.$currency($position->unacknowledgedOverpaymentAmount),
            $positive($ownExcess) ? 'excedente do pagamento desta revisão '.$currency($ownExcess) : null,
        ])->filter()->implode(' · ');
    };

    $acknowledgmentSentence = function (array $acknowledgment): string {
        $scope = $acknowledgment['scope'] === 'anteriormente' ? 'anterior' : (string) $acknowledgment['scope'];
        $decision = filled($acknowledgment['decision'])
            ? 'decisão de '.($acknowledgment['decided_by'] ?? 'responsável não identificado').($acknowledgment['decided_at'] ? ' em '.$acknowledgment['decided_at'] : '').': “'.$acknowledgment['decision'].'”'
            : 'sem decisão registrada na etapa Pagamento';
        $acceptance = filled($acknowledgment['accepted_by'])
            ? 'aceite expresso de '.$acknowledgment['accepted_by'].' na Finalização'.($acknowledgment['accepted_at'] ? ' em '.$acknowledgment['accepted_at'] : '')
            : 'sem aceite registrado na Finalização';

        return 'Reconhecimento '.$scope.' — '.$acknowledgment['label'].': '.$decision.'; '.$acceptance.'.';
    };

    $paidSource = $overview['paid_source'];
    $paidSourceNote = $paidSource === null || $isClosedWithoutPosition ? null : ((int) $paidSource['payments_count'] === 1
        ? 'A '.$paidSource['label'].' tem 1 pagamento registrado ('.$currency($paidSource['paid_amount']).'): ele continua vinculado a ela, como histórico da medição, e entra na posição financeira desta revisão como “pago antes da revisão” — nunca é copiado.'
        : 'A '.$paidSource['label'].' tem '.(int) $paidSource['payments_count'].' pagamentos registrados ('.$currency($paidSource['paid_amount']).'): eles continuam vinculados a ela, como histórico da medição, e entram na posição financeira desta revisão como “pago antes da revisão” — nunca são copiados.');

    $lastKnownHeading = function (array $rows): string {
        $invalidatedAt = collect($rows)->pluck('invalidated_at')->filter()->first();

        return 'Revisão em reanálise da Engenharia — última posição financeira conhecida'.($invalidatedAt ? ' (registro invalidado em '.$invalidatedAt.')' : '');
    };

    // A revisão vigente em reanálise da Engenharia: a contribuição física aprovada antes fica fora do avanço reconhecido.
    $reanalysisNotice = $overview['is_revision'] && $isEffective
        ? app(MeasurementPhysicalProgressService::class)->reanalysisNoticeFor($record)
        : [];

    $familyPosition = $overview['family_position'];
    $showsFamilyPosition = $familyPosition !== null && ($familyPosition['positions'] !== [] || $familyPosition['last_known_positions'] !== []);
@endphp

<div class="bsi-revision-overview space-y-4">
    @if ($overview['has_unresolved_difference'])
        <div role="alert" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 size-4 shrink-0" />
            <span class="font-medium">{{ $isEffective ? 'Esta revisão deixa diferença financeira em aberto.' : 'Se passar a valer, esta revisão deixa diferença financeira em aberto.' }} Os pagamentos registrados não foram modificados; o sistema não registra devolução, estorno nem compensação, e o reconhecimento não regulariza a diferença.</span>
        </div>
    @endif

    @if ($overview['frozen_by'] !== null)
        <div class="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300">
            <x-heroicon-o-lock-closed class="mt-0.5 size-4 shrink-0" />
            <span>Bloqueada pela revisão {{ $overview['frozen_by'] }} em análise: o fluxo desta medição fica suspenso até a revisão ser recusada ou passar a valer.</span>
        </div>
    @endif

    @if ($overview['superseded_by'] !== null)
        <div class="flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-300">
            <x-heroicon-o-arrow-path class="mt-0.5 size-4 shrink-0" />
            <span>
                Substituída pela revisão
                @if ($overview['superseded_by']['url'])
                    <a href="{{ $overview['superseded_by']['url'] }}" class="font-semibold text-[#A06E28] underline dark:text-bsi-gold-400">{{ $overview['superseded_by']['label'] }}</a>
                @else
                    <span class="font-semibold">{{ $overview['superseded_by']['label'] }}</span>
                @endif
                @if ($overview['superseded_by']['at'])
                    em {{ $overview['superseded_by']['at'] }}
                @endif
                . Esta revisão é histórico: os dados, os arquivos e os pagamentos dela não mudam.
            </span>
        </div>
    @endif

    @include('filament.infolists.measurement-reanalysis-notice', ['notice' => $reanalysisNotice])

    @if ($overview['is_revision'] && filled($overview['reason']))
        <div class="rounded-md border-l-2 border-[#A06E28] bg-gray-50/90 px-3 py-2 text-[11px] text-gray-600 dark:border-bsi-gold-500 dark:bg-[#071820]/90 dark:text-gray-300">
            <span class="font-semibold text-[#A06E28] dark:text-bsi-gold-400">Motivo da {{ Str::lcfirst($overview['label']) }}{{ $overview['previous_label'] ? ' (corrige a '.$overview['previous_label'].')' : '' }}:</span>
            <span class="break-words leading-relaxed">{{ $overview['reason'] }}</span>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#071820]/40">
        <table class="w-full text-left text-xs">
            <caption class="sr-only">Revisões desta medição</caption>
            <thead>
                <tr class="border-b border-gray-200/80 bg-gray-50/75 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                    <th scope="col" class="px-3.5 py-2.5">Revisão</th>
                    <th scope="col" class="px-3.5 py-2.5">Situação da revisão</th>
                    <th scope="col" class="px-3.5 py-2.5">Fluxo</th>
                    <th scope="col" class="px-3.5 py-2.5">Criada por</th>
                    <th scope="col" class="px-3.5 py-2.5">Criada em</th>
                    <th scope="col" class="px-3.5 py-2.5">Vigente desde</th>
                    <th scope="col" class="px-3.5 py-2.5">Substituída / encerrada em</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($overview['family'] as $member)
                    <tr @class(['bg-[#A06E28]/[0.04] dark:bg-bsi-gold-500/[0.05]' => $member['is_current_page']])>
                        <td class="px-3.5 py-2.5 font-medium text-gray-900 dark:text-white">
                            @if ($member['url'] && ! $member['is_current_page'])
                                <a href="{{ $member['url'] }}" class="text-[#A06E28] underline dark:text-bsi-gold-400">{{ $member['label'] }}</a>
                            @else
                                {{ $member['label'] }}
                            @endif
                            <span class="block font-mono text-[10px] text-gray-400">Medição #{{ $member['id'] }}</span>
                        </td>
                        <td class="px-3.5 py-2.5">
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $badges[$member['revision_status']->color()] ?? $badges['gray'] }}">
                                {{ $member['revision_status']->label() }}
                            </span>
                        </td>
                        <td class="px-3.5 py-2.5 text-gray-700 dark:text-gray-300">{{ $member['workflow_status'] }}</td>
                        <td class="px-3.5 py-2.5 text-gray-700 dark:text-gray-300">{{ $member['created_by'] ?? '—' }}</td>
                        <td class="px-3.5 py-2.5 font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $member['created_at'] ?? '—' }}</td>
                        <td class="px-3.5 py-2.5 font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $member['effective_at'] ?? ($member['revision_status'] === MeasurementRevisionStatus::Effective ? 'Desde o envio' : '—') }}</td>
                        <td class="px-3.5 py-2.5 font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $member['superseded_at'] ?? $member['closed_at'] ?? '—' }}</td>
                    </tr>
                    @if (filled($member['reason']) || filled($member['closed_reason']))
                        <tr class="bg-gray-50/40 dark:bg-white/[0.01]">
                            <td colspan="7" class="px-3.5 pb-2.5 pt-0 text-[11px] text-gray-600 dark:text-gray-300">
                                @if (filled($member['reason']))
                                    <span class="block"><span class="font-semibold">Motivo:</span> {{ $member['reason'] }}</span>
                                @endif
                                @if (filled($member['closed_reason']))
                                    <span class="block"><span class="font-semibold">Encerramento{{ $member['closed_by'] ? ' por '.$member['closed_by'] : '' }}:</span> {{ $member['closed_reason'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Página da original ou de uma revisão que não é a vigente: a posição financeira que vale hoje é a da revisão vigente. --}}
    @if ($showsFamilyPosition)
        <div class="space-y-2 rounded-lg border border-gray-200/80 bg-gray-50/50 p-3 text-xs text-gray-700 dark:border-white/10 dark:bg-white/[0.02] dark:text-gray-300">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <p class="font-semibold text-gray-900 dark:text-white">Posição financeira vigente da medição (revisão {{ $familyPosition['label'] }})</p>
                @if ($familyPosition['url'])
                    <a href="{{ $familyPosition['url'] }}" class="text-[11px] font-semibold text-[#A06E28] underline dark:text-bsi-gold-400">Abrir a revisão {{ $familyPosition['label'] }}</a>
                @endif
            </div>
            @if ($familyPosition['has_unresolved_difference'])
                <p class="text-amber-700 dark:text-amber-400">A revisão vigente deixa diferença financeira em aberto: o sistema não registra devolução, estorno nem compensação, e o reconhecimento não regulariza a diferença.</p>
            @endif

            @if ($familyPosition['positions'] !== [])
                <div class="overflow-x-auto rounded-md border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#071820]/40">
                    <table class="w-full text-left text-xs">
                        <caption class="sr-only">Posição financeira vigente por empreendimento</caption>
                        <thead>
                            <tr class="border-b border-gray-200/80 bg-gray-50/75 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                                <th scope="col" class="px-3 py-2">Empreendimento</th>
                                <th scope="col" class="px-3 py-2 text-right">Aprovado vigente</th>
                                <th scope="col" class="px-3 py-2 text-right">Pago pela família</th>
                                <th scope="col" class="px-3 py-2 text-right">Em aberto</th>
                                <th scope="col" class="px-3 py-2">Regularização</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($familyPosition['positions'] as $position)
                                @php
                                    $open = $openPosition($position->revisedApprovedAmount, $position->isExposureUnmeasurable(), $position->outstandingOverpaymentAmount, $position->remainingPayableAmount);
                                    $regularizationState = $regularization($position);
                                @endphp
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">{{ $position->label }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $money($position->revisedApprovedAmount) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $currency($position->familyPaidAmount) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        @if ($open['amount'] !== null)
                                            <span class="font-mono tabular-nums {{ $tones[$open['tone']] }}">{{ $currency($open['amount']) }}</span>
                                            <span class="block text-[10px] {{ $tones[$open['tone']] }}">{{ $open['label'] }}</span>
                                        @elseif ($open['label'] !== null)
                                            <span class="text-[10px] {{ $tones[$open['tone']] }}">{{ $open['label'] }}</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-[11px] font-medium {{ $tones[$regularizationState['tone']] }}">{{ $regularizationState['label'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sky-700 dark:text-sky-400">{{ $lastKnownHeading($familyPosition['last_known_positions']) }}</p>
                <div class="overflow-x-auto rounded-md border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#071820]/40">
                    <table class="w-full text-left text-xs">
                        <caption class="sr-only">Última posição financeira conhecida da revisão vigente por empreendimento</caption>
                        <thead>
                            <tr class="border-b border-gray-200/80 bg-gray-50/75 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                                <th scope="col" class="px-3 py-2">Empreendimento</th>
                                <th scope="col" class="px-3 py-2 text-right">Aprovado no registro invalidado</th>
                                <th scope="col" class="px-3 py-2 text-right">Pago pela família</th>
                                <th scope="col" class="px-3 py-2 text-right">Em aberto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($familyPosition['last_known_positions'] as $row)
                                @php
                                    $open = $openPosition($row['revised_approved_amount'], $row['revised_approved_amount'] === null && $row['unresolved'], $row['outstanding_overpayment_amount'], $row['remaining_payable_amount']);
                                @endphp
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $money($row['revised_approved_amount']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $currency($row['family_paid_amount']) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        @if ($open['amount'] !== null)
                                            <span class="font-mono tabular-nums {{ $tones[$open['tone']] }}">{{ $currency($open['amount']) }}</span>
                                            <span class="block text-[10px] {{ $tones[$open['tone']] }}">{{ $open['label'] }}</span>
                                        @elseif ($open['label'] !== null)
                                            <span class="text-[10px] {{ $tones[$open['tone']] }}">{{ $open['label'] }}</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    @if ($overview['is_revision'])
        @if ($overview['positions'] !== [])
            @if ($isHistoricalRevision)
                <p class="text-[11px] text-gray-600 dark:text-gray-300">Posição desta revisão enquanto ela valia, mantida como histórico: o que está em aberto hoje é a posição vigente da medição.</p>
            @endif
            <div class="overflow-x-auto rounded-lg border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#071820]/40">
                <table class="w-full text-left text-xs">
                    <caption class="sr-only">Comparação com a revisão anterior e posição financeira por empreendimento</caption>
                    <thead>
                        <tr class="border-b border-gray-200/80 bg-gray-50/75 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                            <th scope="col" class="px-3 py-2.5">Empreendimento</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Realizado {{ $previousLabel }} → {{ $currentLabel }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Aprovado {{ $previousLabel }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">{{ $isEffective ? 'Aprovado vigente' : 'Aprovado nesta revisão' }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Diferença desta revisão</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Pago pela família</th>
                            <th scope="col" class="px-3 py-2.5 text-right">{{ $isHistoricalRevision ? 'Enquanto vigente' : 'Em aberto' }}</th>
                            <th scope="col" class="px-3 py-2.5">Reconhecimento</th>
                            <th scope="col" class="px-3 py-2.5">Regularização</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($overview['positions'] as $position)
                            @php
                                $open = $openPosition($position->revisedApprovedAmount, $position->isExposureUnmeasurable(), $position->outstandingOverpaymentAmount, $position->remainingPayableAmount, $position->isHistorical());
                                $regularizationState = $regularization($position);
                                $acknowledgmentState = $acknowledgment($position);
                            @endphp
                            <tr>
                                <td class="px-3 py-2.5 font-medium text-gray-900 dark:text-white">
                                    {{ $position->label }}
                                    @if ($position->planVersionNumber)
                                        <span class="block text-[10px] font-normal text-gray-400">Versão do plano: V{{ $position->planVersionNumber }} → V{{ $position->planVersionNumber }} (mantida)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">
                                    {{ $percent($position->previousRealizedMonthlyPercent) }} → {{ $percent($position->revisedRealizedMonthlyPercent) }}
                                    <span class="block text-[10px] text-gray-500 dark:text-gray-400">{{ $points($position->physicalDifferenceBasisPoints) }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ $money($position->previousApprovedAmount) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ $money($position->revisedApprovedAmount) }}</td>
                                <td class="px-3 py-2.5 text-right">
                                    <span class="font-mono tabular-nums">{{ $position->financialDifferenceAmount === null ? '—' : MeasurementFinancialReconciliationService::formatCurrency($position->financialDifferenceAmount) }}</span>
                                    <span class="mt-0.5 block">
                                        <span class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium {{ $badges[$position->differenceType->color()] ?? $badges['gray'] }}">{{ $position->differenceType->label() }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <span class="font-mono tabular-nums">{{ $currency($position->familyPaidAmount) }}</span>
                                    <span class="mt-0.5 block text-[10px] text-gray-500 dark:text-gray-400">antes da revisão {{ $currency($position->historicalPaidAmount) }} · nesta revisão {{ $currency($position->ownPaidAmount) }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    @if ($open['amount'] !== null)
                                        <span class="font-mono tabular-nums {{ $tones[$open['tone']] }}">{{ $currency($open['amount']) }}</span>
                                        <span class="mt-0.5 block text-[10px] {{ $tones[$open['tone']] }}">{{ $open['label'] }}</span>
                                    @elseif ($open['label'] !== null)
                                        <span class="text-[10px] {{ $tones[$open['tone']] }}">{{ $open['label'] }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium {{ $badges[$acknowledgmentState['color']] }}">{{ $acknowledgmentState['label'] }}</span>
                                    @if ($position->hasOutstandingOverpayment())
                                        <span class="mt-0.5 block text-[10px] text-gray-600 dark:text-gray-300">{{ $overpaymentBreakdown($position) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="block text-[11px] font-medium {{ $tones[$regularizationState['tone']] }}">{{ $regularizationState['label'] }}</span>
                                    <span class="mt-0.5 inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium {{ $badges[$position->adjustmentStatus->color()] ?? $badges['gray'] }}">{{ $position->adjustmentStatus->label() }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($overview['last_known_positions'] !== [])
            <div class="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300">
                <x-heroicon-o-arrow-path class="mt-0.5 size-4 shrink-0" />
                <div class="min-w-0 space-y-0.5">
                    <p class="font-semibold">{{ $lastKnownHeading($overview['last_known_positions']) }}</p>
                    <p class="leading-relaxed">Os valores aprovados abaixo são os do registro invalidado e podem mudar na reanálise; os pagamentos são os registrados até agora. A comparação com a {{ $previousLabel }} volta a aparecer depois da nova aprovação da Engenharia.</p>
                </div>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#071820]/40">
                <table class="w-full text-left text-xs">
                    <caption class="sr-only">Última posição financeira conhecida por empreendimento</caption>
                    <thead>
                        <tr class="border-b border-gray-200/80 bg-gray-50/75 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                            <th scope="col" class="px-3 py-2.5">Empreendimento</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Aprovado no registro invalidado</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Pago pela família</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Em aberto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($overview['last_known_positions'] as $row)
                            @php
                                $open = $openPosition($row['revised_approved_amount'], $row['revised_approved_amount'] === null && $row['unresolved'], $row['outstanding_overpayment_amount'], $row['remaining_payable_amount']);
                            @endphp
                            <tr>
                                <td class="px-3 py-2.5 font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ $money($row['revised_approved_amount']) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ $currency($row['family_paid_amount']) }}</td>
                                <td class="px-3 py-2.5 text-right">
                                    @if ($open['amount'] !== null)
                                        <span class="font-mono tabular-nums {{ $tones[$open['tone']] }}">{{ $currency($open['amount']) }}</span>
                                        <span class="mt-0.5 block text-[10px] {{ $tones[$open['tone']] }}">{{ $open['label'] }}</span>
                                    @elseif ($open['label'] !== null)
                                        <span class="text-[10px] {{ $tones[$open['tone']] }}">{{ $open['label'] }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($isClosedWithoutPosition)
            <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50/50 p-3 text-xs text-gray-500 dark:border-white/10 dark:bg-white/[0.02] dark:text-gray-400">
                <x-heroicon-o-information-circle class="size-4 shrink-0 text-gray-400" />
                <span>Esta revisão foi encerrada sem passar a valer: não há comparação com a {{ $previousLabel }} nem posição financeira dela.</span>
            </div>
        @else
            <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50/50 p-3 text-xs text-gray-500 dark:border-white/10 dark:bg-white/[0.02] dark:text-gray-400">
                <x-heroicon-o-information-circle class="size-4 shrink-0 text-gray-400" />
                <span>A comparação com a {{ $overview['previous_label'] ?? 'revisão anterior' }} aparece depois da aprovação da Engenharia desta revisão.</span>
            </div>
        @endif

        @if ($overview['acknowledgments'] !== [])
            <ul class="space-y-1 text-[11px] text-gray-600 dark:text-gray-300">
                @foreach ($overview['acknowledgments'] as $acknowledgment)
                    <li class="flex items-start gap-1.5">
                        <x-heroicon-o-check-badge class="mt-px size-3.5 shrink-0 text-sky-600 dark:text-sky-400" />
                        <span class="whitespace-pre-line break-words">{{ $acknowledgmentSentence($acknowledgment) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($paidSourceNote !== null)
            <p class="text-[11px] leading-relaxed text-gray-600 dark:text-gray-300">{{ $paidSourceNote }}</p>
        @endif

        @if ($overview['positions'] !== [] || $overview['last_known_positions'] !== [])
            @if ($isHistoricalRevision)
                <p class="text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">O sistema não registra devolução, estorno nem compensação: os pagamentos ficam como estão. Esta revisão não recebe mais pagamento nem decisão; a regularização do que ficou em aberto segue na revisão vigente.</p>
            @else
                <p class="text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">O sistema não registra devolução, estorno nem compensação: os pagamentos das revisões anteriores ficam como estão. Saldo a pagar é regularizado pelo pagamento complementar desta revisão; valor pago a maior é reconhecido expressamente (decisão na etapa Pagamento e aceite na Finalização) e continua em aberto.</p>
            @endif
        @endif
    @endif
</div>
