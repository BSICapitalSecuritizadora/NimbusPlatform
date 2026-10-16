@php
    use App\Enums\MeasurementReceiptReviewStatus;

    /** @var \App\Models\Measurement $measurement */
    $payments = $measurement->payments()
        ->with([
            'planSet.construction',
            'createdByUser:id,name',
            'currentReceiptEvidence.uploadedByUser:id,name',
            'currentReceiptEvidence.reviewer:id,name',
        ])
        ->withCount('receiptEvidences')
        ->orderBy('id')
        ->get();

    $badgeStyles = [
        'success' => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        'danger' => 'text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-500/10 dark:border-red-500/20',
        'warning' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        'gray' => 'text-gray-600 bg-gray-50 border-gray-200 dark:text-gray-400 dark:bg-white/5 dark:border-white/10',
    ];
@endphp

<div class="space-y-2" data-finalization-evidences>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Cada comprovante é conferido em "Conferir comprovante" (aprovar ou rejeitar com o motivo). Finalizar não aprova comprovante nenhum, e o comprovante rejeitado não altera o pagamento.
    </p>

    <ul class="divide-y divide-gray-200/70 rounded-lg border border-gray-200/80 dark:divide-white/10 dark:border-white/10">
        @foreach ($payments as $payment)
            @php
                $evidence = $payment->currentReceiptEvidence;
                $status = $evidence?->review_status;
                $assessment = $payment->financial_assessment;
                $requiresAcceptance = (bool) ($assessment['requires_acceptance'] ?? false);
                $referenceStatus = $assessment['reference']['status'] ?? null;
            @endphp
            <li class="flex flex-col gap-1.5 p-3 text-xs sm:flex-row sm:items-start sm:justify-between" wire:key="finalization-evidence-{{ $payment->id }}">
                <div class="min-w-0 space-y-0.5">
                    <p class="font-semibold text-gray-900 dark:text-white">
                        Pagamento #{{ $payment->id }} · {{ $payment->planSet?->construction?->development_name ?? $payment->planSet?->name ?? 'Empreendimento' }}
                    </p>
                    <p class="text-gray-600 dark:text-gray-300">
                        R$ {{ number_format((float) $payment->amount, 2, ',', '.') }} · {{ $payment->pay_date?->format('d/m/Y') ?? '—' }}
                        · registrado por {{ $payment->createdByUser?->name ?? 'usuário não identificado' }}
                    </p>
                    <p class="text-gray-500 dark:text-gray-400">
                        Enquadramento:
                        @if (! is_array($assessment))
                            sem enquadramento registrado
                        @elseif (! $requiresAcceptance)
                            pagamento comum, sem divergência
                        @elseif ($referenceStatus === 'over')
                            acima do saldo a pagar — exige aceite independente
                        @else
                            parcial, com saldo aprovado em aberto — exige aceite independente
                        @endif
                    </p>
                    @if ($evidence)
                        <p class="text-gray-500 dark:text-gray-400">
                            Comprovante v{{ $evidence->version }} ({{ $payment->receipt_evidences_count }} {{ $payment->receipt_evidences_count === 1 ? 'versão' : 'versões' }})
                            · enviado por {{ $evidence->uploadedByUser?->name ?? 'usuário não identificado' }}
                            @if ($evidence->reviewer)
                                · conferido por {{ $evidence->reviewer->name }}
                            @endif
                        </p>
                        @if ($status === MeasurementReceiptReviewStatus::Rejected && filled($evidence->rejection_reason))
                            <p class="text-red-700 dark:text-red-400">Motivo da rejeição: {{ $evidence->rejection_reason }}</p>
                        @endif
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium {{ $badgeStyles[$status?->color() ?? 'warning'] ?? $badgeStyles['gray'] }}">
                        {{ $status?->label() ?? 'Sem comprovante' }}
                    </span>
                    @if ($evidence)
                        <a
                            href="{{ route('admin.measurements.receipt-evidences.download', ['payment' => $payment, 'evidence' => $evidence]) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded border border-gray-200/80 bg-gray-50 px-2 py-1 text-[11px] font-medium text-gray-700 hover:text-primary-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300"
                        >
                            <x-heroicon-o-arrow-down-tray class="size-3" aria-hidden="true" />
                            <span>Abrir</span>
                        </a>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
