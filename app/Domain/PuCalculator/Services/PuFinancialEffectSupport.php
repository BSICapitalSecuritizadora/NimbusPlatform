<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuFinancialEffectSupportLevel;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Models\EmissionPuEvent;
use Carbon\CarbonImmutable;

/**
 * Matriz de suporte financeiro da Fase 5: o que a engine calcula de cada efeito
 * contratual e sob que regra explícita.
 *
 * Um único lugar decide. O validador de insumos, as engines e o construtor de
 * obrigações perguntam aqui -- ninguém infere efeito pelo rótulo do evento, e
 * nenhum efeito vira valor sem a regra declarada no próprio evento
 * (`financial_effect`). O que não é suportado devolve o motivo e bloqueia a
 * geração da curva: nunca é ignorado em silêncio, nunca vira zero.
 *
 * Regras explícitas aceitas:
 *
 *  - amortização extraordinária (só CDI): valor por unidade ou percentual do VNb
 *    (o mesmo resolvedor da amortização ordinária), redução do principal na data
 *    efetiva (`principal_reduction = on_effective_date`) e tratamento declarado dos
 *    juros do período (`accrued_interest`): pagos por um pagamento de juros na
 *    mesma data (`paid_by_interest_payment_event`) ou incorporados ao principal
 *    (`capitalized_into_principal`, a semântica da engine para amortização sem
 *    juros). Redução na data da liquidação, juros só sobre a parcela amortizada,
 *    amortização residual (total) ou valor acima do saldo: não suportados;
 *  - vencimento antecipado (só CDI): principal acelerado = saldo devedor
 *    (`accelerated_principal = outstanding_balance`), juros ordinários pro rata até
 *    a data efetiva (`accrued_interest = ordinary_remuneration_pro_rata`) e nenhum
 *    encargo adicional (`additional_charges = none`). Prêmio, multa e juros
 *    moratórios do vencimento antecipado não têm regra de cálculo: declará-los
 *    bloqueia;
 *  - waiver: só o declarado SEM efeito no PU (`pu_effect = none`), como a dispensa
 *    de um vencimento antecipado pela assembleia. Qualquer outro efeito (taxa de
 *    waiver, suspensão de juros, diferimento...) ou efeito não declarado: ambíguo,
 *    bloqueia. Waiver não significa "parar os juros".
 */
final class PuFinancialEffectSupport
{
    public const KEY_PRINCIPAL_REDUCTION = 'principal_reduction';

    public const PRINCIPAL_REDUCTION_ON_EFFECTIVE_DATE = 'on_effective_date';

    public const KEY_ACCRUED_INTEREST = 'accrued_interest';

    public const ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT = 'paid_by_interest_payment_event';

    public const ACCRUED_INTEREST_CAPITALIZED = 'capitalized_into_principal';

    public const ACCRUED_INTEREST_ORDINARY_PRO_RATA = 'ordinary_remuneration_pro_rata';

    public const KEY_ACCELERATED_PRINCIPAL = 'accelerated_principal';

    public const ACCELERATED_PRINCIPAL_OUTSTANDING_BALANCE = 'outstanding_balance';

    public const KEY_ADDITIONAL_CHARGES = 'additional_charges';

    public const KEY_PU_EFFECT = 'pu_effect';

    public const NONE = 'none';

    /**
     * Problemas que impedem a engine de calcular o efeito deste evento -- vazio
     * quando ela calcula. Motivos em português, prontos para o bloqueio da curva.
     *
     * @param  array<string, mixed>  $event  evento canônico ativo (formato do retrato)
     * @param  list<array<string, mixed>>  $events  todos os eventos canônicos ativos da emissão
     * @return list<string>
     */
    public function issues(array $event, array $events, ?PuCalculationMethod $method): array
    {
        $type = PuEventType::tryFrom((string) ($event['event_type'] ?? ''));
        $label = $this->label($event, $type);

        if (! $type instanceof PuEventType) {
            return [sprintf('O evento %s tem um tipo desconhecido: a curva não pode ser gerada até ele ser corrigido ou cancelado.', $label)];
        }

        if ($method instanceof PuCalculationMethod && ! $type->isSupportedBy($method)) {
            return [sprintf(
                'O evento %s tem efeito financeiro que a engine %s ainda não calcula: a curva não pode ser gerada enquanto ele estiver ativo. Cancele-o ou aguarde a regra de cálculo.',
                $label,
                $method->label(),
            )];
        }

        $problems = match ($type) {
            PuEventType::ExtraordinaryAmortization => $this->extraordinaryAmortizationProblems($event, $events),
            PuEventType::EarlyMaturity => $this->earlyMaturityProblems($event, $events),
            PuEventType::Waiver => $this->waiverProblems($event),
            default => [],
        };

        return array_map(fn (string $problem): string => sprintf(
            'O evento %s tem efeito financeiro que a engine ainda não calcula com segurança: %s A curva não pode ser gerada enquanto ele estiver ativo assim.',
            $label,
            $problem,
        ), $problems);
    }

    /**
     * Evento ativo sem efeito nenhum no PU, no cronograma ou nas obrigações: só o
     * waiver declarado `pu_effect = none`. A engine o pula; o retrato o guarda.
     *
     * @param  array<string, mixed>  $event
     */
    public function isInert(array $event): bool
    {
        return PuEventType::tryFrom((string) ($event['event_type'] ?? '')) === PuEventType::Waiver
            && $this->waiverProblems($event) === [];
    }

    /**
     * Forma canônica mínima de um evento em memória (o que este serviço lê).
     *
     * @return array<string, mixed>
     */
    public static function fromModel(EmissionPuEvent $event): array
    {
        return [
            'event_type' => (string) $event->event_type,
            'effective_date' => $event->effective_date !== null ? CarbonImmutable::instance($event->effective_date)->toDateString() : null,
            'sequence' => (int) $event->sequence,
            'amortization_type' => $event->amortization_type,
            'amortization_value' => $event->amortization_value !== null ? (string) $event->amortization_value : null,
            'financial_effect' => is_array($event->financial_effect) ? $event->financial_effect : null,
        ];
    }

    /**
     * A matriz completa, para relatório, auditoria e para o monitoramento da Fase 6.
     *
     * @return list<array{effect: string, label: string, event_type: ?string, level: string, obligation_type: ?string, components: list<string>, rule: string}>
     */
    public function matrix(): array
    {
        $row = fn (string $effect, string $label, ?PuEventType $type, PuFinancialEffectSupportLevel $level, ?PuObligationType $obligation, array $components, string $rule): array => [
            'effect' => $effect,
            'label' => $label,
            'event_type' => $type?->value,
            'level' => $level->value,
            'obligation_type' => $obligation?->value,
            'components' => array_map(fn (PuObligationComponent $component): string => $component->value, $components),
            'rule' => $rule,
        ];

        return [
            $row('integralization', 'Integralização', null, PuFinancialEffectSupportLevel::NoFinancialObligation, null, [], 'Linha do tempo de quantidade da curva; não é obrigação da emissora perante o investidor.'),
            $row('ordinary_interest', 'Juros ordinários', PuEventType::InterestPayment, PuFinancialEffectSupportLevel::FullyCalculated, PuObligationType::ScheduledPayment, [PuObligationComponent::OrdinaryInterest], 'Calculados pela curva oficial (fórmula validada nas Fases 1-4).'),
            $row('ordinary_amortization', 'Amortização ordinária', PuEventType::Amortization, PuFinancialEffectSupportLevel::FullyCalculated, PuObligationType::ScheduledPayment, [PuObligationComponent::OrdinaryAmortization], 'Calculada pela curva oficial pelo tipo e valor do evento de amortização.'),
            $row('extraordinary_amortization', 'Amortização extraordinária', PuEventType::ExtraordinaryAmortization, PuFinancialEffectSupportLevel::FullyCalculated, PuObligationType::ExtraordinaryAmortization, [PuObligationComponent::ExtraordinaryAmortization], 'Só CDI e só com redução na data efetiva e tratamento dos juros declarados; valor informado no cronograma sem evento é recusado.'),
            $row('extraordinary_interest', 'Juros extraordinários', PuEventType::ExtraordinaryInterest, PuFinancialEffectSupportLevel::LifecycleOnly, null, [PuObligationComponent::ExtraordinaryInterest], 'Sem fórmula contratual: evento ativo bloqueia a curva.'),
            $row('waiver', 'Waiver', PuEventType::Waiver, PuFinancialEffectSupportLevel::NoFinancialObligation, null, [], 'Só o declarado sem efeito no PU (pu_effect = none); qualquer outro efeito ou efeito ausente bloqueia.'),
            $row('grace_period', 'Carência', PuEventType::GracePeriod, PuFinancialEffectSupportLevel::LifecycleOnly, null, [], 'Regime não calculado: evento ativo bloqueia. A carência de juros contratual já se expressa no cronograma (sem pagamento de juros).'),
            $row('deferral', 'Diferimento', PuEventType::Deferral, PuFinancialEffectSupportLevel::LifecycleOnly, null, [], 'Regime não calculado: evento ativo bloqueia. Data efetiva de pagamento deslocada continua suportada pelo próprio evento de pagamento.'),
            $row('default', 'Inadimplemento (mora)', PuEventType::Default, PuFinancialEffectSupportLevel::LifecycleOnly, null, [PuObligationComponent::DefaultInterest, PuObligationComponent::Penalty], 'Sem taxa, base, início e capitalização contratuais: evento ativo bloqueia.'),
            $row('cure', 'Cura do inadimplemento', PuEventType::Cure, PuFinancialEffectSupportLevel::LifecycleOnly, null, [], 'Encerra um regime que a Fase 5 não calcula: evento ativo bloqueia.'),
            $row('early_maturity', 'Vencimento antecipado', PuEventType::EarlyMaturity, PuFinancialEffectSupportLevel::FullyCalculated, PuObligationType::EarlyMaturity, [PuObligationComponent::AcceleratedPrincipal, PuObligationComponent::OrdinaryInterest], 'Só CDI, com principal acelerado = saldo, juros ordinários pro rata e sem encargos adicionais declarados; obrigações ordinárias posteriores ficam superadas.'),
            $row('early_settlement', 'Liquidação antecipada', PuEventType::EarlySettlement, PuFinancialEffectSupportLevel::Deferred, null, [], 'Prêmio e regra de resgate contratuais não modelados: evento ativo bloqueia.'),
            $row('spread_amendment', 'Alteração de spread', PuEventType::SpreadAmendment, PuFinancialEffectSupportLevel::NoFinancialObligation, null, [], 'Muda a regra de juros a partir do início de um período (Fase 4); não é obrigação.'),
            $row('indexer_change', 'Troca de indexador', PuEventType::IndexerChange, PuFinancialEffectSupportLevel::Deferred, null, [], 'Evento ativo bloqueia.'),
            $row('contractual_fallback', 'Índice substituto contratual', PuEventType::ContractualFallback, PuFinancialEffectSupportLevel::Deferred, null, [], 'Evento ativo bloqueia.'),
            $row('maturity_change', 'Alteração de vencimento', PuEventType::MaturityChange, PuFinancialEffectSupportLevel::Deferred, null, [], 'Evento ativo bloqueia; vencimento se altera pelos termos de base (reprocessamento).'),
            $row('schedule_change', 'Alteração de cronograma', PuEventType::ScheduleChange, PuFinancialEffectSupportLevel::Deferred, null, [], 'Evento ativo bloqueia; o cronograma se altera pelos próprios eventos de pagamento.'),
            $row('settlement_closing', 'Encerramento da operação', PuEventType::SettlementClosing, PuFinancialEffectSupportLevel::Deferred, null, [], 'Evento ativo bloqueia.'),
            $row('premium', 'Prêmio', PuEventType::Premium, PuFinancialEffectSupportLevel::InformedOnly, PuObligationType::ScheduledPayment, [PuObligationComponent::Premium], 'Esperado vem do Cronograma de Pagamentos informado; a curva não o calcula. Evento de prêmio ativo bloqueia (sem regra de cálculo).'),
            $row('penalty_charge', 'Multa / encargo contratual', PuEventType::ContractualCharge, PuFinancialEffectSupportLevel::LifecycleOnly, null, [PuObligationComponent::Penalty, PuObligationComponent::OtherCharges], 'Sem regra de cálculo: evento ativo bloqueia. Divergência de liquidação nunca gera multa.'),
            $row('cancellation_reversal', 'Cancelamento / estorno de evento', null, PuFinancialEffectSupportLevel::NoFinancialObligation, null, [], 'Ciclo de vida do evento: o cancelado sai do retrato e a obrigação que só ele sustentava fica superada.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function extraordinaryAmortizationProblems(array $event, array $events): array
    {
        $problems = [];
        $effect = $this->effect($event);
        $type = PuAmortizationType::tryFrom((string) ($event['amortization_type'] ?? ''));
        $value = $event['amortization_value'] ?? null;

        if (! in_array($type, [PuAmortizationType::UnitValue, PuAmortizationType::Percentage], true)) {
            $problems[] = 'a amortização extraordinária precisa de valor por unidade ou percentual do saldo (amortização total é liquidação antecipada, não suportada).';
        } elseif (! is_numeric($value) || bccomp((string) $value, '0', 16) <= 0) {
            $problems[] = 'a amortização extraordinária não informa um valor positivo.';
        }

        if (($effect[self::KEY_PRINCIPAL_REDUCTION] ?? null) !== self::PRINCIPAL_REDUCTION_ON_EFFECTIVE_DATE) {
            $problems[] = 'falta declarar que o principal é reduzido na data efetiva do evento (principal_reduction = on_effective_date); redução na data da liquidação ou em outra data não é suportada.';
        }

        $interest = $effect[self::KEY_ACCRUED_INTEREST] ?? null;
        $hasInterestPayment = $this->hasActiveEventOn($events, PuEventType::InterestPayment, (string) ($event['effective_date'] ?? ''));

        if ($interest === self::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT && ! $hasInterestPayment) {
            $problems[] = 'os juros do período foram declarados pagos junto, mas não há pagamento de juros ativo na mesma data efetiva.';
        } elseif ($interest === self::ACCRUED_INTEREST_CAPITALIZED && $hasInterestPayment) {
            $problems[] = 'os juros do período foram declarados incorporados ao principal, mas há pagamento de juros ativo na mesma data efetiva.';
        } elseif (! in_array($interest, [self::ACCRUED_INTEREST_PAID_BY_INTEREST_PAYMENT, self::ACCRUED_INTEREST_CAPITALIZED], true)) {
            $problems[] = 'falta declarar o tratamento dos juros do período (accrued_interest = paid_by_interest_payment_event ou capitalized_into_principal); juros só sobre a parcela amortizada não têm regra de cálculo.';
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function earlyMaturityProblems(array $event, array $events): array
    {
        $problems = [];
        $effect = $this->effect($event);

        if (($effect[self::KEY_ACCELERATED_PRINCIPAL] ?? null) !== self::ACCELERATED_PRINCIPAL_OUTSTANDING_BALANCE) {
            $problems[] = 'falta declarar o principal acelerado como o saldo devedor (accelerated_principal = outstanding_balance).';
        }

        if (($effect[self::KEY_ACCRUED_INTEREST] ?? null) !== self::ACCRUED_INTEREST_ORDINARY_PRO_RATA) {
            $problems[] = 'falta declarar os juros ordinários pro rata até a data efetiva (accrued_interest = ordinary_remuneration_pro_rata).';
        }

        if (($effect[self::KEY_ADDITIONAL_CHARGES] ?? null) !== self::NONE) {
            $problems[] = 'prêmio, multa, juros moratórios ou outros encargos do vencimento antecipado não têm regra de cálculo; declare additional_charges = none ou aguarde a regra.';
        }

        $earlyMaturities = array_values(array_filter(
            $events,
            fn (array $other): bool => PuEventType::tryFrom((string) ($other['event_type'] ?? '')) === PuEventType::EarlyMaturity,
        ));

        if (count($earlyMaturities) > 1) {
            $problems[] = 'há mais de um vencimento antecipado ativo; a operação vence antecipadamente uma vez só.';
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function waiverProblems(array $event): array
    {
        $effect = $this->effect($event);

        if ($effect === [] || ! array_key_exists(self::KEY_PU_EFFECT, $effect)) {
            return ['o waiver não declara o efeito financeiro; sem efeito declarado ele é ambíguo (só pu_effect = none é suportado).'];
        }

        if ($effect[self::KEY_PU_EFFECT] !== self::NONE || $this->hasFinancialKeys($effect)) {
            return ['o efeito declarado do waiver (suspensão de juros, diferimento, taxa de waiver...) não tem regra de cálculo; só pu_effect = none é suportado.'];
        }

        return [];
    }

    /**
     * Chaves além da descrição que indicariam efeito financeiro escondido ao lado
     * de `pu_effect = none`.
     *
     * @param  array<string, mixed>  $effect
     */
    private function hasFinancialKeys(array $effect): bool
    {
        return array_diff(array_keys($effect), [self::KEY_PU_EFFECT, 'description', 'kind', 'document_reference']) !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function hasActiveEventOn(array $events, PuEventType $type, string $date): bool
    {
        foreach ($events as $event) {
            if (PuEventType::tryFrom((string) ($event['event_type'] ?? '')) === $type && (string) ($event['effective_date'] ?? '') === $date) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function effect(array $event): array
    {
        return is_array($event['financial_effect'] ?? null) ? $event['financial_effect'] : [];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function label(array $event, ?PuEventType $type): string
    {
        $date = (string) ($event['effective_date'] ?? '');

        return sprintf(
            '%s de %s',
            $type?->label() ?? (string) ($event['event_type'] ?? ''),
            $date !== '' ? CarbonImmutable::parse($date)->format('d/m/Y') : '—',
        );
    }
}
