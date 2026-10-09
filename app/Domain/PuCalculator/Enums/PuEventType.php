<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

use App\Domain\PuCalculator\Services\PuFinancialEffectSupport;

/**
 * Catálogo de eventos contratuais de PU.
 *
 * O evento afeta a curva pelo EFEITO FINANCEIRO declarado, nunca pelo nome: cada
 * caso diz a sua classe de efeito, se tem vigência (início e fim), em que ordem é
 * aplicado numa mesma data e quais engines sabem calculá-lo.
 *
 * Efeitos que a engine calcula: pagamento de juros, amortização ORDINÁRIA (pelo tipo
 * e valor da amortização), alteração de spread no início de um período de
 * capitalização (só CDI) e, desde a Fase 5, amortização EXTRAORDINÁRIA e vencimento
 * antecipado com efeito financeiro declarado (só CDI), além do waiver declarado sem
 * efeito no PU. Amortização extraordinária é tipo próprio: nunca é inferida de uma
 * amortização ordinária grande. Os demais casos existem para que o ciclo de vida --
 * retrato, fingerprint, classificação de impacto, cancelamento -- já os represente
 * sem ambiguidade. Um evento ativo cujo efeito não é suportado BLOQUEIA a geração,
 * com o motivo: nunca é ignorado em silêncio nem vira zero
 * ({@see PuFinancialEffectSupport}).
 *
 * Integralização não é evento desta tabela: tem modelo próprio
 * (`IntegralizationHistory`) e entra no retrato da curva como linha do tempo de
 * quantidade. Cancelamento e estorno também não são tipos: são o ciclo de vida
 * ({@see PuEventStatus}) do evento original.
 */
enum PuEventType: string
{
    case InterestPayment = 'interest_payment';
    case Amortization = 'amortization';
    case ExtraordinaryAmortization = 'extraordinary_amortization';
    case SpreadAmendment = 'spread_amendment';
    case ExtraordinaryInterest = 'extraordinary_interest';
    case Premium = 'premium';
    case ContractualCharge = 'contractual_charge';
    case Waiver = 'waiver';
    case GracePeriod = 'grace_period';
    case Deferral = 'deferral';
    case Default = 'default';
    case Cure = 'cure';
    case EarlyMaturity = 'early_maturity';
    case EarlySettlement = 'early_settlement';
    case IndexerChange = 'indexer_change';
    case ContractualFallback = 'contractual_fallback';
    case MaturityChange = 'maturity_change';
    case ScheduleChange = 'schedule_change';
    case SettlementClosing = 'settlement_closing';

    public function label(): string
    {
        return match ($this) {
            self::InterestPayment => 'Pagamento de juros',
            self::Amortization => 'Amortização',
            self::ExtraordinaryAmortization => 'Amortização extraordinária',
            self::SpreadAmendment => 'Alteração de spread',
            self::ExtraordinaryInterest => 'Juros extraordinários',
            self::Premium => 'Prêmio',
            self::ContractualCharge => 'Encargo contratual',
            self::Waiver => 'Waiver',
            self::GracePeriod => 'Carência',
            self::Deferral => 'Diferimento',
            self::Default => 'Inadimplemento (mora)',
            self::Cure => 'Cura do inadimplemento',
            self::EarlyMaturity => 'Vencimento antecipado',
            self::EarlySettlement => 'Liquidação antecipada',
            self::IndexerChange => 'Troca de indexador',
            self::ContractualFallback => 'Índice substituto contratual',
            self::MaturityChange => 'Alteração de vencimento',
            self::ScheduleChange => 'Alteração de cronograma',
            self::SettlementClosing => 'Encerramento da operação',
        };
    }

    public function effectClass(): PuEventEffectClass
    {
        return match ($this) {
            self::InterestPayment,
            self::Amortization,
            self::ExtraordinaryAmortization,
            self::ExtraordinaryInterest,
            self::Premium,
            self::ContractualCharge => PuEventEffectClass::Payment,
            self::SpreadAmendment,
            self::IndexerChange,
            self::ContractualFallback,
            self::MaturityChange,
            self::ScheduleChange => PuEventEffectClass::TermsAmendment,
            self::Waiver,
            self::GracePeriod,
            self::Deferral,
            self::Default,
            self::Cure => PuEventEffectClass::Regime,
            self::EarlyMaturity,
            self::EarlySettlement,
            self::SettlementClosing => PuEventEffectClass::Lifecycle,
        };
    }

    /**
     * Eventos com vigência própria: começam em `effective_date` e, quando
     * informado, terminam em `effective_until` (inclusive). Os demais são
     * instantâneos e recusam data final.
     */
    public function supportsDuration(): bool
    {
        return in_array($this, [self::Waiver, self::GracePeriod, self::Deferral, self::Default], true);
    }

    /**
     * Pagamento do cronograma: entra no grupo da data efetiva e encerra o período
     * de capitalização. É o único grupo que as engines percorrem dia a dia.
     */
    public function isScheduledPayment(): bool
    {
        return $this === self::InterestPayment || $this === self::Amortization;
    }

    /**
     * Evento que reduz o principal na data efetiva e, por isso, encerra o período
     * de capitalização: amortização ordinária, amortização extraordinária e
     * vencimento antecipado (que liquida o saldo inteiro).
     */
    public function reducesPrincipal(): bool
    {
        return in_array($this, [self::Amortization, self::ExtraordinaryAmortization, self::EarlyMaturity], true);
    }

    /**
     * Engines que calculam o efeito financeiro deste tipo. Lista vazia: o tipo é
     * representado no ciclo de vida, mas a geração recusa a curva enquanto houver
     * um evento ativo dele. Estar na lista não basta: o efeito declarado do evento
     * também precisa ser suportado (waiver só sem efeito no PU; amortização
     * extraordinária e vencimento antecipado só com a regra explícita) -- quem decide
     * é {@see PuFinancialEffectSupport}.
     *
     * @return list<PuCalculationMethod>
     */
    public function supportedBy(): array
    {
        return match ($this) {
            self::InterestPayment, self::Amortization, self::Waiver => PuCalculationMethod::cases(),
            self::SpreadAmendment, self::ExtraordinaryAmortization, self::EarlyMaturity => [PuCalculationMethod::CdiSpread],
            default => [],
        };
    }

    public function isSupportedBy(PuCalculationMethod $method): bool
    {
        return in_array($method, $this->supportedBy(), true);
    }

    /**
     * Ordem de aplicação numa mesma data efetiva: os juros do período são apurados
     * sobre o VNb antes de qualquer amortização, e as amortizações seguem a
     * sequência informada. Nunca depende da ordem de inserção no banco.
     */
    public function applicationPriority(): int
    {
        return match ($this) {
            self::InterestPayment => 10,
            self::Amortization => 20,
            self::ExtraordinaryAmortization => 25,
            self::ExtraordinaryInterest => 30,
            self::Premium => 40,
            self::ContractualCharge => 50,
            self::SpreadAmendment => 60,
            self::IndexerChange => 61,
            self::ContractualFallback => 62,
            self::MaturityChange => 63,
            self::ScheduleChange => 64,
            self::Waiver => 70,
            self::GracePeriod => 71,
            self::Deferral => 72,
            self::Default => 73,
            self::Cure => 74,
            self::EarlyMaturity => 80,
            self::EarlySettlement => 81,
            self::SettlementClosing => 82,
        };
    }

    /**
     * Chave canônica de ordenação de eventos de uma emissão: data efetiva,
     * prioridade do tipo, sequência. A mesma em engine, retrato e fingerprint.
     */
    public static function orderingKey(string $effectiveDate, string $eventType, int $sequence): string
    {
        $priority = self::tryFrom($eventType)?->applicationPriority() ?? 999;

        return sprintf('%s|%03d|%010d|%s', $effectiveDate, $priority, $sequence, $eventType);
    }
}
