@php
    $measurement ??= $getRecord();
    $measurement->loadMissing('payments');
    $money = fn ($value) => \App\Services\MeasurementFinancialReconciliationService::formatCurrency($value);
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
</div>
