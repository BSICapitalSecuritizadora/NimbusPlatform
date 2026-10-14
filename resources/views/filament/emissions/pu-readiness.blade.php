@php
    /** @var \App\Domain\PuCalculator\DTOs\PuOperationalReadinessReport $report */
    /** @var array{status: string|null, groups: list<array{title: string, items: list<array<string, mixed>>}>} $presentation */
    /** @var bool $preflightRan */
    $compact ??= false;
    $blockers = $report->blockers();
    $review = $report->reviewItems();
@endphp

<div class="pu-detail flex flex-col gap-4 text-sm">
    <section class="pu-ops-next" aria-labelledby="pu-readiness-status">
        <p class="pu-ops-card-label">Prontidão da emissão</p>
        <h3 id="pu-readiness-status" class="pu-ops-next-title mt-1">{{ $report->status->label() }}</h3>
        <p class="mt-1 text-secondary">
            Avaliada em {{ \App\Support\PuCalculator\PuDisplay::dateTime($report->evaluatedAt) }}.
            {{ $report->globallyEnabled ? 'Operações do PU ligadas neste ambiente.' : 'Operações do PU DESLIGADAS neste ambiente (PU_OPERATIONS_ENABLED).' }}
            {{ $report->activationStatus->label() }}.
        </p>
        <p class="mt-1 text-secondary"><strong>Isto não é autorização operacional.</strong> A ativação é uma decisão registrada com aprovação documentada, e a homologação continua maker/checker.</p>
    </section>

    @if ($compact)
        @if ($blockers !== [])
            <section class="pu-detail-card" aria-labelledby="pu-readiness-blockers">
                <h3 id="pu-readiness-blockers" class="pu-detail-card-title">Bloqueios — a ativação será recusada</h3>
                <ul class="pu-ops-list">
                    @foreach ($blockers as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
        @if ($review !== [])
            <section class="pu-detail-card" aria-labelledby="pu-readiness-review">
                <h3 id="pu-readiness-review" class="pu-detail-card-title">Pontos a decidir antes de ativar</h3>
                <ul class="pu-ops-list">
                    @foreach ($review as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
        <section class="pu-detail-card" aria-labelledby="pu-readiness-effects">
            <h3 id="pu-readiness-effects" class="pu-detail-card-title">O que a ativação faz e não faz</h3>
            <ul class="pu-ops-list">
                <li>Permite: gerar e estender a curva desta emissão, homologar por maker/checker e registrar liquidações reais.</li>
                <li>Não faz sozinha: não gera curva, não homologa versão, não registra liquidação, não liga o interruptor global.</li>
                <li>Continua governado à parte: certificação do indexador, pré-requisitos contratuais, maker/checker, correções com motivo.</li>
            </ul>
        </section>
    @endif

    @unless ($compact)
        @foreach ($presentation['groups'] as $group)
            <section class="pu-check-group" aria-label="{{ $group['title'] }}">
                <h3 class="pu-check-group-title">{{ $group['title'] }}</h3>
                @foreach ($group['items'] as $item)
                    <div class="pu-check">
                        <span class="pu-check-label">{{ $item['label'] }}</span>
                        <x-filament::badge :color="$item['badge']['color']" :icon="$item['badge']['icon']">{{ $item['badge']['label'] }}</x-filament::badge>
                        <p class="pu-check-details">{{ $item['details'] }}</p>
                        @if (filled($item['action']))
                            <p class="pu-check-details"><strong>Ação:</strong> {{ $item['action'] }}</p>
                        @endif
                    </div>
                @endforeach
            </section>
        @endforeach

        @unless ($preflightRan)
            <p class="text-muted">A verificação do ambiente (agenda, fila, monitor, alertas, retenção, backup) não foi executada para este usuário: os grupos correspondentes aparecem como "não verificado", nunca como aprovados.</p>
        @endunless
    @endunless
</div>
