@php
    $measurement ??= $getRecord();
    $measurement->loadMissing('payments');
    $money = fn ($value) => \App\Services\MeasurementFinancialReconciliationService::formatCurrency($value);

    /*
     * Empreendimento que a Engenharia aprovou com valor a pagar e ficou sem
     * pagamento nesta medição não tem pagamento onde guardar justificativa e
     * aceite: os dois moram na aprovação da etapa Pagamento e na Finalização.
     * O bloco aparece da etapa Pagamento em diante, fora do @forelse, para que
     * a medição sem pagamento nenhum também o mostre. Na medição finalizada, o
     * aceite é o que a auditoria da Finalização registrou -- a finalizada antes
     * desta conferência não tem aceite, e não pode parecer que tem.
     *
     * A medição encerrada pela revisão que passou a valer antes da Finalização
     * ('superseded': parada na etapa Pagamento sem pagamento, ou paga com a
     * documentação completa) não tem etapa no fluxo unificado: vale a etapa em
     * que ela foi encerrada, para mostrar o que ficou sem aceite -- e que nela
     * não será mais aceito.
     */
    $workflow = app(\App\Services\MeasurementWorkflow::class);
    $isClosedByRevision = $measurement->status === 'superseded';
    $stage = $isClosedByRevision ? (int) $measurement->current_stage : $workflow->unifiedStage($measurement);
    $closedByRevisionNote = null;
    $closedWithoutPaymentNote = null;

    if ($isClosedByRevision) {
        $supersedingNumber = $measurement->revisionSuccessors()
            ->whereIn('revision_status', [
                \App\Enums\MeasurementRevisionStatus::Effective->value,
                \App\Enums\MeasurementRevisionStatus::Superseded->value,
            ])
            ->orderBy('revision_number')
            ->value('revision_number');
        $supersedingLabel = $supersedingNumber === null ? 'seguinte' : 'R'.(int) $supersedingNumber;
        $closedByRevisionNote = 'Não aceita nesta medição: ela foi encerrada pela revisão '.$supersedingLabel.' antes da Finalização; a diferença passou a compor a posição financeira da revisão vigente.';
        $closedWithoutPaymentNote = 'Nenhum pagamento foi registrado nesta medição: ela foi encerrada pela revisão '.$supersedingLabel.' antes do pagamento, que segue na revisão vigente.';
    }

    $unpaidPlanSets = $stage >= \App\Services\MeasurementWorkflow::STAGE_PAYMENT
        ? app(\App\Services\MeasurementPaymentFinancialService::class)->unpaidRequiredPlanSets($measurement)
        : [];
    $unpaidJustification = '';
    $missingJustification = '';
    $acceptedUnpaidPlanSetIds = [];
    $paymentApproval = null;

    /*
     * Na revisão (R1, R2...), o valor pago a maior em aberto -- a família pagou
     * mais do que ela aprova -- aparece da etapa Pagamento em diante, mesmo
     * quando nada novo precisa ser reconhecido: o Finalizador vê o que continua
     * em aberto. A parte que nenhuma finalização anterior reconheceu precisa da
     * decisão da etapa Pagamento e do aceite expresso do Finalizador. Nada é
     * devolvido, estornado nem compensado: o reconhecimento não regulariza.
     */
    $revisions = app(\App\Services\MeasurementRevisionService::class);
    $isRevision = $measurement->isRevision();
    $revisionPositions = $isRevision && $stage >= \App\Services\MeasurementWorkflow::STAGE_PAYMENT
        ? $revisions->positions($measurement)
        : [];
    $revisionOverpayments = array_values(array_filter($revisionPositions, fn ($position): bool => $position->hasOutstandingOverpayment()));
    // Substituída pela revisão seguinte, a revisão é histórico: os valores são
    // os de quando ela valia, e o que está em aberto hoje é a posição da
    // revisão vigente.
    $isHistoricalRevision = $isRevision && $measurement->revisionStatus() === \App\Enums\MeasurementRevisionStatus::Superseded;
    $overpaymentNeedsDecision = collect($revisionOverpayments)->contains(fn ($position): bool => $position->hasUnacknowledgedOverpayment());
    $approvedLabel = $measurement->isEffectiveRevision() ? 'Aprovado vigente' : 'Aprovado nesta revisão';
    $acknowledgingIds = collect($revisionOverpayments)->flatMap(fn ($position): array => $position->previouslyAcknowledgedByMeasurementIds)->unique()->values()->all();
    $acknowledgingLabels = $acknowledgingIds === []
        ? []
        : \App\Models\Measurement::query()->whereKey($acknowledgingIds)->pluck('revision_number', 'id')->map(fn ($number): string => 'R'.(int) $number)->all();

    $revisionNeedsNoOwnPayment = $isRevision
        && $stage >= \App\Services\MeasurementWorkflow::STAGE_PAYMENT
        && $measurement->payments->isEmpty()
        && $revisions->allowsApprovalWithoutOwnPayment($measurement);
    $revisionNoOwnPaymentNote = match (true) {
        ! $revisionNeedsNoOwnPayment => null,
        // Substituída, a revisão é histórico: nada nela pede ação nem está em
        // aberto -- o que está em aberto hoje é a posição da revisão vigente.
        $isHistoricalRevision => 'Esta revisão não registrou pagamento próprio. Histórico: a posição dela enquanto vigente está abaixo e na seção Revisões da medição, onde também está a posição financeira vigente.',
        collect($revisionPositions)->contains(fn ($position): bool => $position->hasRemainingPayable()) => 'Esta revisão não registrou pagamento próprio. Há saldo a pagar em aberto, que exige justificativa na etapa Pagamento e aceite na Finalização: veja abaixo.',
        $revisionPositions !== [] && collect($revisionPositions)->every(fn ($position): bool => $position->openBalanceAmount !== null && bccomp($position->openBalanceAmount, '0', 2) <= 0) => 'Esta revisão não registrou pagamento próprio: o que a família já pagou antes dela cobre o valor aprovado revisado, ou não há valor a pagar. '
            .($revisionOverpayments !== [] ? 'O valor pago a maior em aberto está abaixo.' : 'A posição financeira por empreendimento está na seção Revisões da medição.'),
        default => 'Esta revisão não registrou pagamento próprio. Há empreendimento sem Fundo de Obra: sem valor aprovado com que comparar, a exposição financeira dele não é mensurável. A posição financeira por empreendimento está na seção Revisões da medição.',
    };

    if ($unpaidPlanSets !== [] || $revisionOverpayments !== []) {
        $paymentApproval = $measurement->reviews()
            ->where('stage', \App\Services\MeasurementWorkflow::STAGE_PAYMENT)
            ->where('status', 'approved')
            ->first();
        $unpaidJustification = trim((string) $paymentApproval?->notes);
        $missingJustification = match (true) {
            in_array($measurement->status, ['finalized', 'superseded'], true) => 'Não registrada.',
            $paymentApproval === null => 'Ainda não registrada: a aprovação da etapa Pagamento precisa justificar a ausência de pagamento.',
            default => 'Não registrada na aprovação da etapa Pagamento: devolva a medição àquela etapa para registrar o pagamento ou justificar a ausência.',
        };

        if ($measurement->status === 'finalized') {
            $finalization = $measurement->activitiesAsSubject()
                ->where('description', 'measurement_finalized')
                ->latest('id')
                ->first();
            $acceptedUnpaidPlanSetIds = collect($finalization?->properties->get('unpaid_plan_sets_accepted') ?? [])
                ->pluck('plan_set_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }
    }

    $overpaymentDecision = match (true) {
        $unpaidJustification !== '' => $unpaidJustification,
        ! $overpaymentNeedsDecision => 'Não exigida nesta revisão: não há valor pago a maior a reconhecer nela.',
        in_array($measurement->status, ['finalized', 'superseded'], true) => 'Não registrada.',
        $paymentApproval === null => 'Ainda não registrada: a aprovação da etapa Pagamento precisa registrar a decisão financeira sobre o valor pago a maior a reconhecer nesta revisão.',
        default => 'Não registrada na aprovação da etapa Pagamento: devolva a medição àquela etapa para registrar a decisão financeira.',
    };
    $divergenceNote = match (true) {
        $measurement->status === 'finalized' => 'Divergência aceita expressamente na finalização. Decisão registrada no histórico de auditoria.',
        $isClosedByRevision => $closedByRevisionNote,
        default => 'Divergência sujeita ao aceite explícito do Finalizador.',
    };
@endphp

<div class="space-y-4 text-sm">
    @forelse ($measurement->payments as $payment)
        @php
            $assessment = $payment->financial_assessment;
            $rule = $assessment['rule'] ?? null;
            $reference = $assessment['reference'] ?? [];
        @endphp
        <section wire:key="financial-assessment-{{ $payment->id }}" class="space-y-3 border-b border-gray-200 pb-4 dark:border-white/10">
            <h4 class="font-semibold">Pagamento #{{ $payment->id }} · {{ $money($payment->amount) }}</h4>
            @if ($assessment === null)
                <p class="text-gray-500 dark:text-gray-400">Registro anterior ao controle de regras financeiras. Não há enquadramento ou aceite financeiro registrado neste formato.</p>
            @else
                <p class="text-gray-500 dark:text-gray-400">{{ $reference['label'] ?? 'Empreendimento' }} · {{ $payment->pay_date->format('d/m/Y') }}</p>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div><dt class="text-gray-500 dark:text-gray-400">Referência aprovada</dt><dd>{{ $money($reference['expected_amount'] ?? null) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Saldo antes deste pagamento</dt><dd>{{ $money($reference['expected_balance'] ?? null) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Diferença deste pagamento</dt><dd>{{ $money($reference['divergence_amount'] ?? null) }} · {{ \App\Services\MeasurementFinancialReconciliationService::formatPercent($reference['divergence_percent'] ?? null) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Enquadramento</dt><dd>{{ $rule ? $rule['name'].' · versão '.$rule['version'] : (($assessment['requires_acceptance'] ?? false) ? 'Exceção sem regra cadastrada' : (($reference['status'] ?? '') === 'reference_unavailable' ? 'Sem referência financeira' : 'Conciliado')) }}</dd></div>
                    @if ($rule)
                        <div><dt class="text-gray-500 dark:text-gray-400">Direção permitida</dt><dd>{{ \App\Models\MeasurementFinancialRule::DIRECTIONS[$rule['direction']] }}</dd></div>
                        <div><dt class="text-gray-500 dark:text-gray-400">Limites da regra</dt><dd>{{ isset($rule['maximum_difference_amount']) ? $money($rule['maximum_difference_amount']) : 'Sem limite em reais' }} · {{ isset($rule['maximum_difference_percent']) ? \App\Services\MeasurementFinancialReconciliationService::formatPercent($rule['maximum_difference_percent']).' do saldo esperado' : 'Sem limite percentual' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Condições da regra aplicada</dt><dd class="whitespace-pre-wrap break-words">{{ $rule['description'] }}</dd></div>
                    @endif
                    @if ($assessment['justification'] ?? null)
                        <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Justificativa</dt><dd class="whitespace-pre-wrap break-words">{{ $assessment['justification'] }}</dd></div>
                    @endif
                </dl>
                @if ($assessment['support'] ?? null)
                    <a class="font-medium text-primary-600 underline dark:text-primary-400" href="{{ route('admin.measurements.financial-support.download', $payment) }}" target="_blank" rel="noopener">Abrir documento de suporte do pagamento #{{ $payment->id }}</a>
                @endif
                @if ($assessment['requires_acceptance'] ?? false)
                    <p class="text-amber-700 dark:text-amber-400">{{ $divergenceNote }}</p>
                @endif
            @endif
        </section>
    @empty
        @if ($isClosedByRevision)
            <p class="text-gray-500 dark:text-gray-400">{{ $closedWithoutPaymentNote }}</p>
        @elseif ($revisionNoOwnPaymentNote !== null)
            <p class="text-gray-500 dark:text-gray-400">{{ $revisionNoOwnPaymentNote }}</p>
        @else
            <p class="text-gray-500 dark:text-gray-400">O enquadramento financeiro aparecerá após o registro dos pagamentos.</p>
        @endif
    @endforelse

    @if ($unpaidPlanSets !== [])
        <section wire:key="financial-unpaid-plan-sets" class="space-y-3 border-b border-gray-200 pb-4 dark:border-white/10">
            <h4 class="font-semibold">Empreendimentos sem pagamento nesta competência</h4>
            <p class="text-gray-500 dark:text-gray-400">A Engenharia aprovou valor a pagar para estes empreendimentos e nenhum pagamento desta medição foi registrado para eles.</p>
            <ul class="space-y-3">
                @foreach ($unpaidPlanSets as $line)
                    <li wire:key="financial-unpaid-plan-set-{{ $line->planSetId }}">
                        <dl class="grid gap-3 sm:grid-cols-2">
                            <div><dt class="text-gray-500 dark:text-gray-400">Empreendimento</dt><dd>{{ $line->label }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-400">Valor esperado</dt><dd>{{ $money($line->expectedAmount) }}</dd></div>
                            @if (bccomp($line->historicalPaidAmount, '0', 2) > 0)
                                <div><dt class="text-gray-500 dark:text-gray-400">Pago antes da revisão</dt><dd>{{ $money($line->historicalPaidAmount) }}</dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">{{ $isHistoricalRevision ? 'Saldo a pagar enquanto vigente' : 'Saldo a pagar em aberto' }}</dt><dd>{{ $money($line->expectedBalance) }}</dd></div>
                            @endif
                        </dl>
                        <p class="mt-1 text-amber-700 dark:text-amber-400">
                            @if ($isClosedByRevision)
                                {{ $closedByRevisionNote }}
                            @elseif ($measurement->status !== 'finalized')
                                Aceite pendente: o Finalizador precisa aceitar expressamente a ausência de pagamento ao finalizar.
                            @elseif (in_array($line->planSetId, $acceptedUnpaidPlanSetIds, true))
                                Ausência de pagamento aceita expressamente na finalização. Decisão registrada no histórico de auditoria.
                            @else
                                Medição finalizada sem aceite registrado para a ausência de pagamento.
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
            <dl>
                <div><dt class="text-gray-500 dark:text-gray-400">Justificativa da etapa Pagamento</dt><dd class="whitespace-pre-wrap break-words">{{ $unpaidJustification !== '' ? $unpaidJustification : $missingJustification }}</dd></div>
            </dl>
        </section>
    @endif

    @if ($revisionOverpayments !== [])
        <section wire:key="financial-revision-overpayments" class="space-y-3 border-b border-gray-200 pb-4 dark:border-white/10">
            @if ($isHistoricalRevision)
                <h4 class="font-semibold">Valor pago a maior enquanto esta revisão valia</h4>
                <p class="text-gray-500 dark:text-gray-400">Histórico: a família tinha pago mais do que esta revisão aprovava. O sistema não registra devolução, estorno nem compensação; o que está em aberto hoje é a posição da revisão vigente, na seção Revisões da medição.</p>
            @else
                <h4 class="font-semibold">Valor pago a maior em aberto</h4>
                <p class="text-gray-500 dark:text-gray-400">A família pagou mais do que esta revisão aprova. O sistema não registra devolução, estorno nem compensação: os pagamentos ficam como estão, e o reconhecimento não regulariza a diferença, que continua em aberto.</p>
            @endif
            <ul class="space-y-3">
                @foreach ($revisionOverpayments as $position)
                    @php
                        $origins = collect($position->previouslyAcknowledgedByMeasurementIds)
                            ->map(fn (int $id): string => $acknowledgingLabels[$id] ?? '#'.$id)
                            ->implode(', ');
                        $ownExcess = bcsub(bcsub((string) $position->outstandingOverpaymentAmount, $position->previouslyAcknowledgedOverpaymentAmount ?? '0.00', 2), $position->unacknowledgedOverpaymentAmount ?? '0.00', 2);
                        $hasOwnExcess = bccomp($ownExcess, '0', 2) > 0;
                        $acknowledgedHere = $measurement->status === 'finalized' && $position->hasUnacknowledgedOverpayment();
                        $overpaymentNote = match (true) {
                            $isClosedByRevision => $closedByRevisionNote,
                            $acknowledgedHere && $isHistoricalRevision => 'Valor pago a maior reconhecido expressamente na finalização desta revisão, sem devolução registrada. Decisão registrada no histórico de auditoria.',
                            $acknowledgedHere => 'Valor pago a maior reconhecido expressamente na finalização — continua em aberto, sem devolução registrada. Decisão registrada no histórico de auditoria.',
                            $isHistoricalRevision && $hasOwnExcess => 'O excedente foi divergência do próprio pagamento desta revisão, tratada no enquadramento dele, acima. Sem devolução registrada.',
                            $isHistoricalRevision => 'Reconhecido anteriormente'.($origins === '' ? '' : ' ('.$origins.')').': esta revisão não pediu nova decisão nem novo aceite. Sem devolução registrada.',
                            $position->hasUnacknowledgedOverpayment() => 'Aceite pendente: o Finalizador precisa aceitar expressamente, ao finalizar, o valor pago a maior a reconhecer nesta revisão. O aceite reconhece a diferença: não registra devolução nem a regulariza, e ela continua em aberto.',
                            $hasOwnExcess => 'O excedente é divergência do próprio pagamento desta revisão, tratada no enquadramento dele, acima. Continua em aberto, sem devolução registrada.',
                            default => 'Reconhecido anteriormente'.($origins === '' ? '' : ' ('.$origins.')').': esta revisão não pede nova decisão nem novo aceite. O valor continua em aberto, sem devolução registrada.',
                        };
                    @endphp
                    <li wire:key="financial-revision-overpayment-{{ $position->planSetId }}">
                        <dl class="grid gap-3 sm:grid-cols-2">
                            <div><dt class="text-gray-500 dark:text-gray-400">Empreendimento</dt><dd>{{ $position->label }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-400">{{ $approvedLabel }}</dt><dd>{{ $money($position->revisedApprovedAmount) }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-400">Pago pela família</dt><dd>{{ $money($position->familyPaidAmount) }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-400">{{ $isHistoricalRevision ? 'Pago a maior enquanto vigente' : 'Pago a maior em aberto' }}</dt><dd>{{ $money($position->outstandingOverpaymentAmount) }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-400">Reconhecido anteriormente{{ $origins === '' ? '' : ' ('.$origins.')' }}</dt><dd>{{ $money($position->previouslyAcknowledgedOverpaymentAmount ?? '0.00') }}</dd></div>
                            <div><dt class="text-gray-500 dark:text-gray-400">{{ $acknowledgedHere ? 'Reconhecido nesta revisão' : ($isHistoricalRevision ? 'Não reconhecido nesta revisão' : 'A reconhecer nesta revisão') }}</dt><dd>{{ $money($position->unacknowledgedOverpaymentAmount ?? '0.00') }}</dd></div>
                            @if ($hasOwnExcess)
                                <div><dt class="text-gray-500 dark:text-gray-400">Excedente do pagamento desta revisão</dt><dd>{{ $money($ownExcess) }}</dd></div>
                            @endif
                        </dl>
                        <p class="mt-1 text-amber-700 dark:text-amber-400">{{ $overpaymentNote }}</p>
                    </li>
                @endforeach
            </ul>
            <dl>
                <div><dt class="text-gray-500 dark:text-gray-400">Decisão financeira da etapa Pagamento</dt><dd class="whitespace-pre-wrap break-words">{{ $overpaymentDecision }}</dd></div>
            </dl>
        </section>
    @endif
</div>
