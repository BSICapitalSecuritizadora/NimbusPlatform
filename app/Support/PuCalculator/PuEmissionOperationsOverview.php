<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\DTOs\PuEmissionOperationalHealth;
use App\Domain\PuCalculator\DTOs\PuOperationalCondition;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Domain\PuCalculator\Services\PuOperationalHealthService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuOperationalStatusChange;
use App\Models\PuOperationalIncident;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Retrato do PU de uma emissão para a página "Operação do PU" (Fase 8).
 *
 * Junta o que os serviços das Fases 5-7 já decidem -- portões de ativação,
 * certificação do indexador, retrato operacional, leitura oficial, incidentes e
 * o livro de ativação -- sem recalcular nada financeiro. Se o retrato operacional
 * falha, a página diz que não foi possível avaliar; nunca mostra "saudável".
 */
final class PuEmissionOperationsOverview
{
    /** Decisões de ativação mostradas na página (o livro inteiro fica no banco). */
    private const LEDGER_ROWS = 10;

    public function __construct(
        private readonly PuOperationalActivationPolicy $activation,
        private readonly PuOperationalHealthService $health,
        private readonly EmissionPuReader $reader,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Emission $emission, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $emission->loadMissing('puParameter');
        $parameter = $emission->puParameter;
        $indexer = $parameter?->indexer_enum;
        $status = $this->activation->status($emission);
        $globallyEnabled = $this->activation->globallyEnabled();
        $health = $this->emissionHealth($emission, $now);
        $official = $this->reader->officialVersion($emission, fresh: true);
        $latestAttempt = EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->latestAttempt()->with('generatedBy:id,name')->first();
        $incidents = PuOperationalIncident::query()
            ->where('emission_id', $emission->id)
            ->open()
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('last_detected_at')
            ->limit(20)
            ->get();

        return [
            'evaluated_at' => PuDisplay::dateTime($now),
            'configured' => $parameter !== null,
            'global' => [
                'enabled' => $globallyEnabled,
                'label' => $globallyEnabled ? 'Ligadas neste ambiente' : 'Desligadas neste ambiente',
                'text' => $globallyEnabled
                    ? 'Emissões ATIVADAS podem gerar, estender e homologar curvas e registrar liquidações. O interruptor é configuração de infraestrutura, não da tela.'
                    : 'Nenhuma geração, extensão, homologação ou liquidação real roda aqui (PU_OPERATIONS_ENABLED). O interruptor é configuração de infraestrutura e não é alterado pela tela.',
            ],
            'activation' => [
                'status' => $status,
                'label' => $status->label(),
                'color' => PuDisplay::activationColor($status),
                'icon' => PuDisplay::activationIcon($status),
                'latest' => $this->latestDecision($emission),
            ],
            'indexer' => $this->indexer($indexer),
            'official' => $this->official($emission, $official, $health, $now),
            'latest_attempt' => $latestAttempt instanceof EmissionPuCurveVersion && $latestAttempt->id !== $official?->id ? [
                'version' => $latestAttempt->calculation_version,
                'status' => $latestAttempt->status->label(),
                'color' => $latestAttempt->status->color(),
                'generated_at' => PuDisplay::dateTime($latestAttempt->generated_at),
                'generated_by' => $latestAttempt->generatedBy?->name ?? 'rotina automática',
                'error' => $latestAttempt->error_message,
            ] : null,
            'health_available' => $health !== false,
            'obligations' => $health instanceof PuEmissionOperationalHealth ? $health->obligations : [],
            'refresh' => $health instanceof PuEmissionOperationalHealth ? $health->refresh : [],
            'conditions' => $health instanceof PuEmissionOperationalHealth ? $this->conditions($health) : [],
            'incidents' => $incidents,
            'ledger' => EmissionPuOperationalStatusChange::query()
                ->where('emission_id', $emission->id)
                ->with('decidedBy:id,name')
                ->orderByDesc('id')
                ->limit(self::LEDGER_ROWS)
                ->get(),
            'next_action' => $this->nextAction($parameter !== null, $globallyEnabled, $status, $indexer, $official, $health, $incidents->first()),
        ];
    }

    /**
     * Retrato operacional só desta emissão; `false` quando a avaliação falhou e
     * `null` quando a emissão não é acompanhada (sem parâmetros).
     */
    private function emissionHealth(Emission $emission, CarbonImmutable $now): PuEmissionOperationalHealth|false|null
    {
        try {
            return $this->health->snapshot($now, [(int) $emission->id])->emission((int) $emission->id);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestDecision(Emission $emission): ?array
    {
        $change = $this->activation->latestChange((int) $emission->id);

        if (! $change instanceof EmissionPuOperationalStatusChange) {
            return null;
        }

        $change->loadMissing('decidedBy:id,name');

        return [
            'label' => $change->status->label(),
            'reason' => $change->reason,
            'approval_reference' => $change->approval_reference,
            'decided_by' => $change->decidedBy?->name ?? ($change->decided_via !== 'ui' && $change->decided_via !== 'console' ? $change->decided_via : '—'),
            'decided_at' => PuDisplay::dateTime($change->decided_at),
            'via' => $change->decided_via,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function indexer(?PuIndexer $indexer): array
    {
        if (! $indexer instanceof PuIndexer) {
            return ['configured' => false, 'name' => 'Não configurado', 'certification' => null, 'color' => 'gray', 'official_use' => false, 'conditions' => []];
        }

        $certification = $indexer->certification();

        return [
            'configured' => true,
            'name' => PuDisplay::indexerName($indexer),
            'certification' => $certification->label(),
            'color' => PuDisplay::certificationColor($certification),
            'official_use' => $certification->allowsOperationalUse(),
            'conditions' => $indexer->certificationConditions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function official(Emission $emission, ?EmissionPuCurveVersion $official, PuEmissionOperationalHealth|false|null $health, CarbonImmutable $now): array
    {
        if (! $official instanceof EmissionPuCurveVersion) {
            return ['exists' => false];
        }

        $status = $health instanceof PuEmissionOperationalHealth ? $health->officialStatus : null;
        $today = CarbonImmutable::parse(BusinessTime::dateString($now))->startOfDay();
        $reading = $this->reader->readingOn($emission, $today, $now);
        $position = $reading?->fromOfficialCurve() ? $reading : null;

        return [
            'exists' => true,
            'version' => $official->calculation_version,
            'homologated_at' => PuDisplay::dateTime($official->homologated_at),
            'freshness' => $status?->freshness,
            'freshness_label' => $status?->freshness->label() ?? 'Atualidade não avaliada',
            'freshness_color' => PuDisplay::freshnessColor($status?->freshness),
            'freshness_icon' => PuDisplay::freshnessIcon($status?->freshness),
            'freshness_reason' => $status?->reason,
            'realized_through' => PuDisplay::date($status?->realizedThrough),
            'expected_realized_through' => PuDisplay::date($status?->expectedRealizedThrough),
            'curve_end' => PuDisplay::date($status?->curveEndDate),
            'reprocessing_from' => PuDisplay::date($status?->reprocessingFrom),
            'contractual_change_from' => PuDisplay::date($status?->contractualChangeFrom),
            'pu' => $position !== null ? PuDisplay::unitValue($position->unitValue) : null,
            'pu_date' => $position !== null ? PuDisplay::date($position->date) : null,
            'pu_carried_forward' => $position?->isCarriedForward() ?? false,
            'pu_stands_for_today' => $position?->standsForRequestedDate() ?? false,
            'today' => PuDisplay::date($today),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conditions(PuEmissionOperationalHealth $health): array
    {
        $conditions = $health->conditions;
        usort($conditions, fn (PuOperationalCondition $a, PuOperationalCondition $b): int => $b->severity->rank() <=> $a->severity->rank());

        return array_map(fn (PuOperationalCondition $condition): array => [
            'label' => $condition->type->label(),
            'severity' => $condition->severity,
            'severity_label' => $condition->severity->label(),
            'color' => PuDisplay::severityColor($condition->severity),
            'icon' => PuDisplay::severityIcon($condition->severity),
            'reason' => $condition->reason,
            'guidance' => PuIncidentGuidance::recommendedAction($condition->type),
        ], $conditions);
    }

    /**
     * A próxima ação, na ordem em que os portões se aplicam.
     *
     * @return array{title: string, text: string}
     */
    private function nextAction(
        bool $configured,
        bool $globallyEnabled,
        PuOperationalActivationStatus $status,
        ?PuIndexer $indexer,
        ?EmissionPuCurveVersion $official,
        PuEmissionOperationalHealth|false|null $health,
        ?PuOperationalIncident $topIncident,
    ): array {
        if (! $configured) {
            return ['title' => 'Configurar o cálculo de PU', 'text' => 'A emissão ainda não tem parâmetros de PU. Sem eles nada é acompanhado nem calculado.'];
        }

        if ($indexer instanceof PuIndexer && ! $indexer->certification()->allowsOperationalUse()) {
            return ['title' => 'Indexador só para simulação', 'text' => sprintf('%s não é certificado para uso oficial: a emissão pode ser simulada, mas não gera curva oficial nem pode ser ativada até uma certificação financeira separada.', PuDisplay::indexerName($indexer))];
        }

        if ($health === false) {
            return ['title' => 'Retrato operacional indisponível', 'text' => 'Não foi possível avaliar a situação operacional agora. Isto não significa operação saudável: tente de novo e, persistindo, verifique o log.'];
        }

        if (! $globallyEnabled) {
            return ['title' => 'Operações do PU desligadas neste ambiente', 'text' => 'Nada é gerado, estendido, homologado ou liquidado até a liberação formal pela infraestrutura (PU_OPERATIONS_ENABLED). A prontidão pode ser avaliada e a simulação continua disponível.'];
        }

        if ($status === PuOperationalActivationStatus::Inactive) {
            return ['title' => 'Avaliar a prontidão e decidir a ativação', 'text' => 'A emissão está configurada, mas a operação do PU não foi ativada. Ativar exige aprovação documentada; ativar não homologa curva.'];
        }

        if ($status === PuOperationalActivationStatus::Suspended) {
            return ['title' => 'Operação suspensa', 'text' => 'Tratar o motivo da suspensão registrado no livro de ativação e, se autorizado, reativar. Liquidações reais continuam registráveis.'];
        }

        if ($topIncident instanceof PuOperationalIncident && $topIncident->severity->atLeast(PuOperationalSeverity::Warning)) {
            return ['title' => $topIncident->type->label(), 'text' => PuIncidentGuidance::recommendedAction($topIncident->type)];
        }

        if ($health instanceof PuEmissionOperationalHealth) {
            foreach ($this->conditions($health) as $condition) {
                if ($condition['severity']->isActionable()) {
                    return ['title' => $condition['label'], 'text' => $condition['guidance']];
                }
            }
        }

        if (! $official instanceof EmissionPuCurveVersion) {
            return ['title' => 'Gerar e homologar a primeira curva', 'text' => 'Gerar a curva, validá-la contra a referência e homologá-la por outra pessoa (maker/checker). Só a homologada é oficial.'];
        }

        $freshness = $health instanceof PuEmissionOperationalHealth ? $health->freshness() : null;

        if ($freshness instanceof PuOfficialCurveFreshness && ! $freshness->isUpToDate()) {
            return ['title' => $freshness->label(), 'text' => 'A curva oficial não responde por todo o índice realizado: veja as condições abaixo.'];
        }

        return ['title' => 'Nenhuma ação pendente identificada', 'text' => 'O monitor não encontrou condição que peça ação nesta emissão no momento da avaliação.'];
    }
}
