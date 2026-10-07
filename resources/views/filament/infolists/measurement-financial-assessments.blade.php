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
     */
    $workflow = app(\App\Services\MeasurementWorkflow::class);
    $unpaidPlanSets = $workflow->unifiedStage($measurement) >= \App\Services\MeasurementWorkflow::STAGE_PAYMENT
        ? app(\App\Services\MeasurementPaymentFinancialService::class)->unpaidRequiredPlanSets($measurement)
        : [];
    $unpaidJustification = '';
    $missingJustification = '';
    $acceptedUnpaidPlanSetIds = [];

    if ($unpaidPlanSets !== []) {
        $paymentApproval = $measurement->reviews()
            ->where('stage', \App\Services\MeasurementWorkflow::STAGE_PAYMENT)
            ->where('status', 'approved')
            ->first();
        $unpaidJustification = trim((string) $paymentApproval?->notes);
        $missingJustification = match (true) {
            $measurement->status === 'finalized' => 'Não registrada.',
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
                    <p class="text-amber-700 dark:text-amber-400">{{ $measurement->status === 'finalized' ? 'Divergência aceita expressamente na finalização. Decisão registrada no histórico de auditoria.' : 'Divergência sujeita ao aceite explícito do Finalizador.' }}</p>
                @endif
            @endif
        </section>
    @empty
        <p class="text-gray-500 dark:text-gray-400">O enquadramento financeiro aparecerá após o registro dos pagamentos.</p>
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
                        </dl>
                        <p class="mt-1 text-amber-700 dark:text-amber-400">
                            @if ($measurement->status !== 'finalized')
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
</div>
