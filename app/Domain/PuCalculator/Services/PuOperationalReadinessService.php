<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuOperationalReadinessReport as Report;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Enums\PuIndexerCertification;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Enums\PuOperationalReadinessStatus;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\EmissionPuSettlementConflict;
use App\Models\Payment;
use App\Models\PuOperationalIncident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Avaliação de prontidão operacional do PU de uma emissão (Fase 7) -- só leitura.
 *
 * Responde "o que falta para esta emissão poder ser ativada", reaproveitando quem
 * já decide cada parte: o portão do indexador (certificação), os pré-requisitos
 * da curva (parâmetros, eventos e efeitos financeiros suportados, calendário,
 * integralização, índice realizado), o cronograma informado (prêmio e
 * amortização extraordinária que a curva não calcularia), os incidentes e
 * conflitos abertos e a situação da curva oficial. Não gera curva, não homologa,
 * não muda contrato e não ativa: a avaliação roda dentro de uma transação que é
 * sempre desfeita, então nem o preenchimento automático de calendário que os
 * pré-requisitos fazem fica gravado.
 *
 * O que o sistema não tem como saber -- se o contrato da emissão prevê prêmio,
 * teto de amortização extraordinária, mora, multa, waiver com efeito, carência ou
 * diferimento -- vai para a lista de verificação contratual: é ela que a
 * referência de aprovação da ativação precisa cobrir.
 */
final class PuOperationalReadinessService
{
    /**
     * Verificações que só a leitura do contrato responde. Um efeito previsto no
     * contrato e sem regra no sistema torna a emissão inelegível até a regra
     * existir e ser validada -- nunca vira zero.
     *
     * @var list<string>
     */
    public const CONTRACTUAL_CHECKLIST = [
        'Indexador, spread/taxa, base de dias, calendário, defasagem do índice e vencimento conferidos contra o Termo.',
        'Prêmio: se o contrato prevê prêmio, conferir se ele já é o prêmio de primeiro cupom calculado pela engine ou se é informado à parte -- nunca os dois.',
        'Amortização extraordinária: se o contrato a prevê, a regra (valor/percentual, redução do principal na data efetiva, juros do período) e o teto contratual, se houver, cadastrados no evento (maximum_principal_fraction). Teto previsto e não cadastrado: não ativar.',
        'Vencimento antecipado: só principal = saldo, juros ordinários pro rata e sem encargos adicionais são calculados; prêmio, multa ou juros moratórios do vencimento antecipado não têm regra.',
        'Mora, multa, juros moratórios, encargos contratuais, waiver com efeito financeiro, carência, diferimento, liquidação antecipada e troca de indexador: sem regra de cálculo no sistema. Se o contrato os prevê como aplicáveis, a emissão não está financeiramente pronta.',
        'Integralização inicial comprovada por documento (data e quantidade).',
        'Validação numérica independente da primeira curva (comparação com o sistema de origem ou gabarito) registrada antes da homologação.',
    ];

    public function __construct(
        private readonly PuOperationalActivationPolicy $activation,
        private readonly PuIndexerCapabilityPolicy $indexers,
        private readonly PuCurvePrerequisiteService $prerequisites,
        private readonly BusinessDayCalendarService $calendars,
    ) {}

    public function assess(Emission $emission, ?CarbonImmutable $now = null): Report
    {
        $now ??= CarbonImmutable::now();

        DB::beginTransaction();

        try {
            return $this->evaluate($emission->fresh(['puParameter']) ?? $emission, $now);
        } finally {
            DB::rollBack();
            // O que os pré-requisitos completaram foi desfeito: o cache do calendário
            // não pode guardar o que não existe mais.
            $this->calendars->flushCache();
        }
    }

    private function evaluate(Emission $emission, CarbonImmutable $now): Report
    {
        $parameter = $emission->puParameter;
        $globallyEnabled = $this->activation->globallyEnabled();
        $activationStatus = $this->activation->status($emission);
        $indexer = $parameter?->indexer_enum;
        $certification = $indexer?->certification();

        if (! $parameter instanceof EmissionPuParameter) {
            return new Report(
                emissionId: (int) $emission->getKey(),
                emissionName: (string) $emission->name,
                status: PuOperationalReadinessStatus::NotConfigured,
                activationStatus: $activationStatus,
                globallyEnabled: $globallyEnabled,
                indexer: null,
                indexerCertification: null,
                checks: [$this->check('pu_parameter', 'Parâmetros de PU', Report::RESULT_BLOCKED, ['A emissão não tem parâmetros de PU configurados.'])],
                contractualChecklist: self::CONTRACTUAL_CHECKLIST,
                evaluatedAt: $now,
            );
        }

        $checks = [
            $this->indexerCheck($emission, $parameter, $certification),
            ...$this->prerequisiteChecks($emission),
            $this->informedScheduleCheck($emission, $parameter),
            $this->governanceCheck($emission),
            $this->officialCurveCheck($emission),
            $this->activationCheck($globallyEnabled, $activationStatus),
        ];

        $results = array_column($checks, 'result');
        $status = match (true) {
            in_array(Report::RESULT_BLOCKED, $results, true) => PuOperationalReadinessStatus::NotReady,
            $globallyEnabled && $activationStatus->isOperational() => PuOperationalReadinessStatus::OperationallyAuthorized,
            in_array(Report::RESULT_REVIEW, $results, true) => PuOperationalReadinessStatus::ReadyForReview,
            default => PuOperationalReadinessStatus::TechnicallyReady,
        };

        return new Report(
            emissionId: (int) $emission->getKey(),
            emissionName: (string) $emission->name,
            status: $status,
            activationStatus: $activationStatus,
            globallyEnabled: $globallyEnabled,
            indexer: $indexer,
            indexerCertification: $certification,
            checks: $checks,
            contractualChecklist: self::CONTRACTUAL_CHECKLIST,
            evaluatedAt: $now,
        );
    }

    /**
     * @return array{key: string, label: string, result: string, details: list<string>}
     */
    private function indexerCheck(Emission $emission, EmissionPuParameter $parameter, ?PuIndexerCertification $certification): array
    {
        $indexer = $parameter->indexer_enum;

        foreach ([PuIndexerCapability::CurveGeneration, PuIndexerCapability::Homologation] as $capability) {
            if (! $this->indexers->allows($indexer, $capability)) {
                return $this->check('indexer_certification', 'Certificação do indexador', Report::RESULT_BLOCKED, [
                    $this->indexers->refusal($indexer, $capability, (int) $emission->getKey())->getMessage(),
                ]);
            }
        }

        return $certification === PuIndexerCertification::ConditionallyCertified
            ? $this->check('indexer_certification', 'Certificação do indexador', Report::RESULT_INFO, [
                sprintf('%s: %s. Condições de implantação (globais, no preflight): %s', $indexer?->value, mb_strtolower($certification->label()), implode(' ', $indexer?->certificationConditions() ?? [])),
            ])
            : $this->check('indexer_certification', 'Certificação do indexador', Report::RESULT_PASS, []);
    }

    /**
     * @return list<array{key: string, label: string, result: string, details: list<string>}>
     */
    private function prerequisiteChecks(Emission $emission): array
    {
        try {
            $result = $this->prerequisites->handle($emission);
        } catch (Throwable $exception) {
            return [$this->check('curve_prerequisites', 'Pré-requisitos da curva', Report::RESULT_BLOCKED, [
                'A verificação dos pré-requisitos falhou: '.$exception->getMessage(),
            ])];
        }

        $blocking = array_map(fn ($issue): string => $issue->message, $result->blockingIssues());
        // O aviso de "só a parte realizada" (CDI futuro ainda não divulgado) é o
        // estado normal de toda emissão em curso: informação, não ponto a decidir.
        $realizedTail = [];
        $warnings = [];

        foreach ($result->warningIssues() as $issue) {
            if ($issue->key === 'index_rates') {
                $realizedTail[] = $issue->message;
            } else {
                $warnings[] = $issue->message;
            }
        }

        return [
            $this->check('curve_prerequisites', 'Pré-requisitos da curva (contrato, eventos e efeitos financeiros, calendário, integralização, índice)', $blocking === [] ? Report::RESULT_PASS : Report::RESULT_BLOCKED, $blocking),
            $this->check('curve_prerequisite_warnings', 'Alertas dos pré-requisitos (calendário, datas efetivas, prêmio)', $warnings === [] ? Report::RESULT_PASS : Report::RESULT_REVIEW, $warnings),
            $this->check('realized_curve_horizon', 'Horizonte realizado da curva', Report::RESULT_INFO, $realizedTail),
        ];
    }

    /**
     * Cronograma informado com efeito que a curva não calcularia: amortização
     * extraordinária sem evento contratual bloqueia (o principal não seria
     * reduzido); prêmio informado numa emissão em que a engine aplica o prêmio de
     * primeiro cupom pede decisão (risco de contar duas vezes -- a obrigação da
     * mesma data fica sem cálculo até a decisão).
     *
     * @return array{key: string, label: string, result: string, details: list<string>}
     */
    private function informedScheduleCheck(Emission $emission, EmissionPuParameter $parameter): array
    {
        $payments = Payment::query()
            ->where('emission_id', $emission->getKey())
            ->where(fn ($query) => $query->where('premium_value', '>', 0)->orWhere('extra_amortization_value', '>', 0))
            ->orderBy('payment_date')
            ->get(['id', 'payment_date', 'premium_value', 'extra_amortization_value']);
        $extraordinaryDates = EmissionPuEvent::query()
            ->where('emission_id', $emission->getKey())
            ->active()
            ->where('event_type', PuEventType::ExtraordinaryAmortization->value)
            ->get(['effective_date'])
            ->map(fn (EmissionPuEvent $event): ?string => $event->effective_date?->toDateString())
            ->filter()
            ->flip();
        $blocked = [];
        $review = [];

        foreach ($payments as $payment) {
            $date = $payment->payment_date?->toDateString();

            if (bccomp((string) $payment->extra_amortization_value, '0', 2) === 1 && ! $extraordinaryDates->has($date)) {
                $blocked[] = sprintf('O cronograma informa amortização extraordinária em %s sem evento contratual de amortização extraordinária: a curva não reduziria o principal por ela.', $payment->payment_date?->format('d/m/Y'));
            }

            if (bccomp((string) $payment->premium_value, '0', 2) === 1 && $parameter->hasFirstCouponPreIntegralizationPremium()) {
                $review[] = sprintf('O cronograma informa prêmio em %s e a engine aplica o prêmio de primeiro cupom: confirme qual dos dois é o prêmio contratual (nunca os dois).', $payment->payment_date?->format('d/m/Y'));
            }
        }

        return match (true) {
            $blocked !== [] => $this->check('informed_schedule_effects', 'Efeitos do cronograma informado', Report::RESULT_BLOCKED, [...$blocked, ...$review]),
            $review !== [] => $this->check('informed_schedule_effects', 'Efeitos do cronograma informado', Report::RESULT_REVIEW, $review),
            default => $this->check('informed_schedule_effects', 'Efeitos do cronograma informado', Report::RESULT_PASS, []),
        };
    }

    /**
     * @return array{key: string, label: string, result: string, details: list<string>}
     */
    private function governanceCheck(Emission $emission): array
    {
        $details = [];
        $criticalIncidents = PuOperationalIncident::query()
            ->where('emission_id', $emission->getKey())
            ->open()
            ->where('severity', PuOperationalSeverity::Critical->value)
            ->count();
        $openConflicts = EmissionPuSettlementConflict::query()
            ->where('emission_id', $emission->getKey())
            ->open()
            ->count();

        if ($criticalIncidents > 0) {
            $details[] = sprintf('%d incidente(s) operacional(is) crítico(s) aberto(s) para a emissão.', $criticalIncidents);
        }

        if ($openConflicts > 0) {
            $details[] = sprintf('%d conflito(s) de liquidação aguardando decisão.', $openConflicts);
        }

        return $this->check('governance', 'Incidentes críticos e conflitos de liquidação', $details === [] ? Report::RESULT_PASS : Report::RESULT_BLOCKED, $details);
    }

    /**
     * @return array{key: string, label: string, result: string, details: list<string>}
     */
    private function officialCurveCheck(Emission $emission): array
    {
        $official = EmissionPuCurveVersion::query()->where('emission_id', $emission->getKey())->official()->first();

        if (! $official instanceof EmissionPuCurveVersion) {
            return $this->check('official_curve', 'Curva oficial', Report::RESULT_INFO, ['Sem curva oficial: a primeira só nasce depois da ativação, pela geração e pela homologação maker/checker.']);
        }

        $details = [];

        if ($official->extension_diverged_at !== null) {
            $details[] = sprintf('A curva oficial %s precisa de reprocessamento (o passado gravado mudou).', $official->calculation_version);
        }

        if ($official->extension_failed_at !== null) {
            $details[] = sprintf('A última extensão da curva oficial %s falhou.', $official->calculation_version);
        }

        if ($official->contractual_change_detected_at !== null) {
            $details[] = sprintf('Há mudança contratual ainda não homologada depois do último dia da curva oficial %s.', $official->calculation_version);
        }

        return $this->check('official_curve', 'Curva oficial', $details === [] ? Report::RESULT_PASS : Report::RESULT_REVIEW, $details);
    }

    /**
     * @return array{key: string, label: string, result: string, details: list<string>}
     */
    private function activationCheck(bool $globallyEnabled, PuOperationalActivationStatus $status): array
    {
        return $this->check('operational_activation', 'Ativação operacional', Report::RESULT_INFO, [
            $globallyEnabled ? 'Operações do PU ligadas neste ambiente.' : 'Operações do PU DESLIGADAS neste ambiente (PU_OPERATIONS_ENABLED).',
            $status->label().'.',
        ]);
    }

    /**
     * @param  list<string>  $details
     * @return array{key: string, label: string, result: string, details: list<string>}
     */
    private function check(string $key, string $label, string $result, array $details): array
    {
        return ['key' => $key, 'label' => $label, 'result' => $result, 'details' => array_values($details)];
    }
}
