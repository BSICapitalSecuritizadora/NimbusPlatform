@php
    use App\Enums\MeasurementRevisionStatus;
    use App\DTOs\Measurements\MeasurementPhysicalProgress;
    use App\Services\MeasurementFinancialReconciliationService;
    use App\Services\MeasurementRevisionService;

    $record = $getRecord();
    $overview = app(MeasurementRevisionService::class)->overview($record);

    $badges = [
        'gray' => 'text-gray-700 bg-gray-50 border-gray-200 dark:text-gray-300 dark:bg-white/5 dark:border-white/10',
        'warning' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        'success' => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        'info' => 'text-sky-700 bg-sky-50 border-sky-200 dark:text-sky-400 dark:bg-sky-500/10 dark:border-sky-500/20',
        'danger' => 'text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-500/10 dark:border-red-500/20',
    ];

    $money = fn (?string $value): string => $value === null ? 'Sem referência' : MeasurementFinancialReconciliationService::formatCurrency($value);
    $percent = fn (string $value): string => MeasurementPhysicalProgress::format((int) MeasurementPhysicalProgress::basisPoints($value));
    $points = fn (int $basisPoints): string => ($basisPoints > 0 ? '+' : ($basisPoints < 0 ? '−' : '')).str_replace('%', '', MeasurementPhysicalProgress::format(abs($basisPoints))).' p.p.';
@endphp

<div class="bsi-revision-overview space-y-4">
    @if ($overview['has_unresolved_difference'])
        <div role="alert" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 size-4 shrink-0" />
            <span class="font-medium">Esta revisão possui diferença financeira não resolvida. Os pagamentos históricos não foram modificados.</span>
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

    @if ($overview['is_revision'] && filled($overview['reason']))
        <div class="rounded-md border-l-2 border-[#A06E28] bg-gray-50/90 px-3 py-2 text-[11px] text-gray-600 dark:border-bsi-gold-500 dark:bg-[#071820]/90 dark:text-gray-300">
            <span class="font-semibold text-[#A06E28] dark:text-bsi-gold-400">Motivo da {{ mb_strtolower($overview['label']) }}{{ $overview['previous_label'] ? ' (corrige a '.$overview['previous_label'].')' : '' }}:</span>
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

    @if ($overview['is_revision'])
        @if ($overview['positions'] === [])
            <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50/50 p-3 text-xs text-gray-500 dark:border-white/10 dark:bg-white/[0.02] dark:text-gray-400">
                <x-heroicon-o-information-circle class="size-4 shrink-0 text-gray-400" />
                <span>A comparação com a {{ $overview['previous_label'] ?? 'revisão anterior' }} aparece depois da aprovação da Engenharia desta revisão.</span>
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#071820]/40">
                <table class="w-full text-left text-xs">
                    <caption class="sr-only">Comparação com a revisão anterior e posição financeira por empreendimento</caption>
                    <thead>
                        <tr class="border-b border-gray-200/80 bg-gray-50/75 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                            <th scope="col" class="px-3 py-2.5">Empreendimento</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Realizado {{ $overview['previous_label'] }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Realizado revisado</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Aprovado {{ $overview['previous_label'] }}</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Aprovado revisado</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Diferença</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Pago antes da revisão</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Pago nesta revisão</th>
                            <th scope="col" class="px-3 py-2.5 text-right">Saldo em aberto</th>
                            <th scope="col" class="px-3 py-2.5">Ajuste</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($overview['positions'] as $position)
                            <tr>
                                <td class="px-3 py-2.5 font-medium text-gray-900 dark:text-white">
                                    {{ $position->label }}
                                    @if ($position->planVersionNumber)
                                        <span class="block text-[10px] font-normal text-gray-400">Versão do plano: V{{ $position->planVersionNumber }} → V{{ $position->planVersionNumber }} (mantida)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ $percent($position->previousRealizedMonthlyPercent) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">
                                    {{ $percent($position->revisedRealizedMonthlyPercent) }}
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
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ MeasurementFinancialReconciliationService::formatCurrency($position->historicalPaidAmount) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ MeasurementFinancialReconciliationService::formatCurrency($position->ownPaidAmount) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ $money($position->openBalanceAmount) }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium {{ $badges[$position->adjustmentStatus->color()] ?? $badges['gray'] }}">{{ $position->adjustmentStatus->label() }}</span>
                                    @if ($position->hasUnresolvedOverpayment())
                                        <span class="mt-0.5 block text-[10px] text-red-700 dark:text-red-400">Pago a maior: {{ MeasurementFinancialReconciliationService::formatCurrency($position->unresolvedOverpaymentAmount) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">
                O sistema não tem estorno, devolução nem compensação: os pagamentos registrados nas revisões anteriores ficam como estão, e a diferença é resolvida pelo pagamento complementar desta revisão ou registrada expressamente na etapa Pagamento e aceita na Finalização.
            </p>
        @endif
    @endif
</div>
