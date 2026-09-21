@php
    use App\Services\MeasurementReceiptEvidenceService;
    use App\Services\MeasurementFinancialReconciliationService;
    use App\Enums\MeasurementReceiptReviewStatus;

    $record = $getRecord();
    $payments = $record->payments()
        ->with([
            'planSet.construction',
            'currentReceiptEvidence.uploadedByUser',
            'currentReceiptEvidence.reviewer',
            'receiptEvidences.uploadedByUser',
            'receiptEvidences.reviewer',
            'receiptEvidences.supersededBy',
        ])
        ->orderBy('id')
        ->get();

    $docStatus = $record->status === 'finalized'
        ? app(MeasurementReceiptEvidenceService::class)->documentaryStatus($record)
        : null;

    $docStatusStyles = [
        'Correção documental pendente' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        'Correção documental rejeitada' => 'text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-500/10 dark:border-red-500/20',
        'Correção documental regularizada' => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
    ];
@endphp

<div class="bsi-payments-list space-y-3">
    {{-- Banner de Status Documental se a Medição for Finalizada --}}
    @if ($docStatus)
        <div class="flex items-center justify-between rounded-lg border p-2.5 text-xs font-medium {{ $docStatusStyles[$docStatus] ?? 'text-gray-700 bg-gray-50 border-gray-200 dark:text-gray-300 dark:bg-white/5 dark:border-white/10' }}">
            <div class="flex items-center gap-2">
                <x-heroicon-o-document-check class="size-4 shrink-0" />
                <span>Documentação após finalização:</span>
            </div>
            <span class="font-semibold">{{ $docStatus }}</span>
        </div>
    @endif

    @if ($payments->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-lg border border-dashed border-gray-200 p-6 text-center text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            <x-heroicon-o-banknotes class="size-8 text-gray-400 dark:text-gray-500" />
            <span class="mt-2 font-medium">Nenhum pagamento registrado nesta medição.</span>
            <span class="mt-0.5 text-gray-400 dark:text-gray-500">Os pagamentos são lançados na Etapa 4 de Pagamentos.</span>
        </div>
    @else
        <div class="space-y-2.5">
            @foreach ($payments as $payment)
                @php
                    $currentEvidence = $payment->currentReceiptEvidence;
                    $evidencesCount = $payment->receiptEvidences->count();
                    $developmentName = $payment->planSet?->construction?->development_name ?? $payment->planSet?->name ?? 'Empreendimento';

                    $statusColor = $currentEvidence ? $currentEvidence->review_status->color() : 'warning';
                    $statusLabel = $currentEvidence ? $currentEvidence->review_status->label() : 'Sem comprovante';

                    $badgeStyles = [
                        'success' => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
                        'danger' => 'text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-500/10 dark:border-red-500/20',
                        'warning' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
                        'gray' => 'text-gray-600 bg-gray-50 border-gray-200 dark:text-gray-400 dark:bg-white/5 dark:border-white/10',
                    ];
                @endphp

                <div
                    x-data="{ open: false }"
                    class="rounded-lg border border-gray-200/80 bg-white transition-all hover:border-bsi-gold-500/30 dark:border-white/10 dark:bg-[#071820]/60 dark:hover:border-bsi-gold-500/30"
                >
                    {{-- Cabeçalho Principal do Pagamento --}}
                    <div class="p-3.5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1.5">
                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                        #{{ $payment->id }}
                                    </span>
                                    <h4 class="truncate text-xs font-semibold text-gray-900 dark:text-white" title="{{ $developmentName }}">
                                        {{ $developmentName }}
                                    </h4>
                                </div>

                                <div class="flex items-center gap-x-3 text-[11px] text-gray-500 dark:text-gray-400">
                                    <span>
                                        <strong class="font-medium text-gray-700 dark:text-gray-300">Data:</strong>
                                        {{ $payment->pay_date ? $payment->pay_date->format('d/m/Y') : '—' }}
                                    </span>
                                    <span class="text-gray-300 dark:text-white/20">•</span>
                                    <span>
                                        <strong class="font-medium text-gray-700 dark:text-gray-300">Método:</strong>
                                        {{ $payment->method ?: 'Não informado' }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center justify-between gap-3 text-right sm:justify-end">
                                <span class="font-mono text-sm font-bold tabular-nums text-gray-950 dark:text-white">
                                    R$ {{ number_format((float) $payment->amount, 2, ',', '.') }}
                                </span>

                                <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-medium {{ $badgeStyles[$statusColor] ?? $badgeStyles['gray'] }}">
                                    {{ $statusLabel }}
                                </span>

                                @if ($currentEvidence)
                                    <a
                                        href="{{ route('admin.measurements.receipt-evidences.download', ['payment' => $payment, 'evidence' => $currentEvidence]) }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 rounded border border-gray-200/80 bg-gray-50 px-2.5 py-1 text-[11px] font-medium text-gray-700 transition-colors hover:border-bsi-gold-500/50 hover:text-bsi-gold-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:border-bsi-gold-500/50 dark:hover:text-bsi-gold-500"
                                        title="Abrir comprovante atual"
                                    >
                                        <x-heroicon-o-arrow-down-tray class="size-3" />
                                        <span>Comprovante</span>
                                    </a>
                                @endif
                            </div>
                        </div>

                        {{-- Observações do Pagamento se existirem --}}
                        @if (filled($payment->notes))
                            <div class="mt-2.5 max-w-4xl rounded bg-gray-50/75 px-2.5 py-1.5 text-[11px] text-gray-600 dark:bg-white/[0.02] dark:text-gray-400">
                                <strong class="font-medium text-gray-700 dark:text-gray-300">Obs:</strong> {{ $payment->notes }}
                            </div>
                        @endif

                        {{-- Gatilho Expansível para Auditoria de Comprovantes (Opção A) --}}
                        @if ($evidencesCount > 0)
                            <div class="mt-2.5 flex items-center justify-between border-t border-gray-100 pt-2 dark:border-white/5">
                                <button
                                    type="button"
                                    x-on:click="open = !open"
                                    class="inline-flex items-center gap-1.5 text-[11px] font-medium text-gray-500 transition-colors hover:text-bsi-gold-600 dark:text-gray-400 dark:hover:text-bsi-gold-500"
                                >
                                    <x-heroicon-m-chevron-right
                                        class="size-3.5 transition-transform duration-200"
                                        x-bind:class="{ 'rotate-90': open }"
                                    />
                                    <span>Auditoria e histórico de comprovantes ({{ $evidencesCount }} {{ $evidencesCount === 1 ? 'versão' : 'versões' }})</span>
                                </button>
                                <span class="text-[10px] text-gray-400 dark:text-gray-500">
                                    {{ $currentEvidence ? 'v'.$currentEvidence->version.' ativa' : 'Sem versão ativa' }}
                                </span>
                            </div>
                        @endif
                    </div>

                    {{-- Gaveta Expansível com Histórico Completo de Comprovantes --}}
                    @if ($evidencesCount > 0)
                        <div
                            x-show="open"
                            x-cloak
                            x-collapse
                            class="border-t border-gray-100 bg-gray-50/50 p-3 dark:border-white/5 dark:bg-[#06151c]/70"
                        >
                            <div class="space-y-2.5">
                                @foreach ($payment->receiptEvidences->sortByDesc('version') as $evidence)
                                    @php
                                        $evColor = $evidence->review_status->color();
                                        $versionLabel = 'v'.$evidence->version.($evidence->supersededBy === null ? ' · Atual' : ' · Substituída por v'.$evidence->supersededBy->version);
                                    @endphp
                                    <div class="rounded-lg border border-gray-200/60 bg-white p-2.5 shadow-2xs dark:border-white/5 dark:bg-[#071820]/90">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-xs font-bold text-gray-900 dark:text-white">
                                                    {{ $versionLabel }}
                                                </span>
                                                <span class="inline-flex items-center rounded-full border px-1.5 py-0.2 text-[10px] font-medium {{ $badgeStyles[$evColor] ?? $badgeStyles['gray'] }}">
                                                    {{ $evidence->review_status->label() }}
                                                </span>
                                            </div>

                                            <a
                                                href="{{ route('admin.measurements.receipt-evidences.download', ['payment' => $payment, 'evidence' => $evidence]) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center gap-1 text-[11px] font-medium text-bsi-gold-600 hover:underline dark:text-bsi-gold-500"
                                            >
                                                <x-heroicon-o-arrow-down-tray class="size-3" />
                                                <span>Baixar arquivo</span>
                                            </a>
                                        </div>

                                        <div class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
                                            <div>
                                                <span class="text-gray-400 dark:text-gray-500">Arquivo original:</span>
                                                <span class="font-mono text-gray-700 dark:text-gray-300">
                                                    {{ $evidence->original_filename ?: 'Nome original não registrado no fluxo legado' }}
                                                </span>
                                            </div>
                                            <div>
                                                <span class="text-gray-400 dark:text-gray-500">Upload:</span>
                                                <span class="text-gray-700 dark:text-gray-300">
                                                    {{ $evidence->uploadedByUser?->name ?: 'Não registrado' }}
                                                    ({{ $evidence->uploaded_at ? $evidence->uploaded_at->format('d/m/Y H:i:s') : '—' }})
                                                </span>
                                            </div>
                                            <div>
                                                <span class="text-gray-400 dark:text-gray-500">Conferência:</span>
                                                <span class="text-gray-700 dark:text-gray-300">
                                                    {{ $evidence->reviewer?->name ?: 'Sem decisão individual' }}
                                                    @if ($evidence->reviewed_at)
                                                        ({{ $evidence->reviewed_at->format('d/m/Y H:i:s') }})
                                                    @endif
                                                </span>
                                            </div>
                                            <div>
                                                <span class="text-gray-400 dark:text-gray-500">SHA-256:</span>
                                                <span class="font-mono text-[10px] text-gray-500 dark:text-gray-400" title="{{ $evidence->sha256 }}">
                                                    {{ $evidence->sha256 ? \Illuminate\Support\Str::limit($evidence->sha256, 16) : '—' }}
                                                </span>
                                            </div>
                                        </div>

                                        {{-- Motivos e Observações Detalhadas --}}
                                        @if (filled($evidence->rejection_reason))
                                            <div class="mt-2 max-w-4xl rounded border border-red-200/60 bg-red-50/60 p-2 text-[11px] text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-400">
                                                <strong>Motivo da rejeição:</strong> {{ $evidence->rejection_reason }}
                                            </div>
                                        @endif

                                        @if (filled($evidence->correction_reason))
                                            <div class="mt-2 max-w-4xl rounded border border-amber-200/60 bg-amber-50/60 p-2 text-[11px] text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-400">
                                                <strong>Motivo da substituição:</strong> {{ $evidence->correction_reason }}
                                            </div>
                                        @endif

                                        @if (filled($evidence->review_notes))
                                            <div class="mt-2 max-w-4xl rounded border border-gray-200/60 bg-gray-50 p-2 text-[11px] text-gray-700 dark:border-white/5 dark:bg-white/[0.02] dark:text-gray-300">
                                                <strong>Observação do conferente:</strong> {{ $evidence->review_notes }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
