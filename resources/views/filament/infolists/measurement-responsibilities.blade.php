@php
    use App\Services\MeasurementWorkflow;

    $record = $getRecord();
    $operation = $record->operation;
    $workflow = app(MeasurementWorkflow::class);
    $currentStage = $workflow->unifiedStage($record);
    $reviews = $record->reviews;

    $roles = [
        1 => [
            'stage' => 1,
            'role' => 'Etapa 1 · Engenharia',
            'user' => $operation?->responsibleUser,
        ],
        2 => [
            'stage' => 2,
            'role' => 'Etapa 2 · Gestão',
            'user' => $operation?->stage2Reviewer,
        ],
        3 => [
            'stage' => 3,
            'role' => 'Etapa 3 · Compliance',
            'user' => $operation?->stage3Reviewer,
        ],
        4 => [
            'stage' => 4,
            'role' => 'Etapa 4 · Pagamento',
            'user' => $operation?->paymentManager,
        ],
        5 => [
            'stage' => 5,
            'role' => 'Etapa 5 · Finalização',
            'user' => $operation?->paymentFinalizer,
        ],
    ];
@endphp

<div class="bsi-responsibilities-compact">
    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
        @foreach ($roles as $item)
            @php
                $stageNum = $item['stage'];
                $stageReview = $reviews->firstWhere('stage', $stageNum);
                $isCurrent = ($currentStage === $stageNum && !in_array($record->status, ['finalized', 'rejected'], true));
                $isApproved = ($stageReview && $stageReview->status === 'approved') || ($record->status === 'finalized') || ($stageNum < $currentStage && !in_array($record->status, ['rejected', 'paused'], true));
                $isRejected = ($stageReview && $stageReview->status === 'rejected') || ($record->status === 'rejected' && $currentStage === $stageNum);

                if ($isRejected) {
                    $statusText = 'Recusada';
                    $statusClass = 'text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-500/10 dark:border-red-500/20';
                    $dotClass = 'bg-red-500';
                } elseif ($isApproved) {
                    $statusText = 'Aprovada';
                    $statusClass = 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20';
                    $dotClass = 'bg-emerald-500';
                } elseif ($isCurrent) {
                    $statusText = 'Em análise';
                    $statusClass = 'text-[#A06E28] bg-amber-50 border-amber-200 dark:text-bsi-gold-500 dark:bg-bsi-gold-500/10 dark:border-bsi-gold-500/30';
                    $dotClass = 'bg-[#A06E28] dark:bg-bsi-gold-500 animate-pulse';
                } else {
                    $statusText = 'Aguardando';
                    $statusClass = 'text-gray-600 bg-gray-50 border-gray-200 dark:text-gray-400 dark:bg-white/5 dark:border-white/10';
                    $dotClass = 'bg-gray-400 dark:bg-gray-500';
                }
            @endphp
            <div class="flex items-center justify-between gap-2 rounded-lg border border-gray-200/80 bg-white p-2.5 shadow-xs transition-colors hover:border-bsi-gold-500/30 dark:border-white/10 dark:bg-[#071820]/60 dark:hover:border-bsi-gold-500/30">
                <div class="min-w-0 flex-1">
                    <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ $item['role'] }}
                    </span>
                    <span class="mt-0.5 block truncate text-xs font-semibold text-gray-900 dark:text-white" title="{{ $item['user']?->name ?? 'Não atribuído' }}">
                        {{ $item['user']?->name ?? 'Não atribuído' }}
                    </span>
                </div>
                <div class="shrink-0">
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $statusClass }}">
                        <span class="size-1.5 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
                        {{ $statusText }}
                    </span>
                </div>
            </div>
        @endforeach
    </div>
</div>
