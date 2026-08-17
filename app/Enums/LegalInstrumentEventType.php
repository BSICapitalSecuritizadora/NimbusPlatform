<?php

namespace App\Enums;

/**
 * Eventos jurídicos do instrumento (§13 do escopo).
 *
 * A tabela de campos guarda *o que* mudou; o evento guarda *a história* — é o
 * que alimenta o histórico legível e a linha do tempo da emissão (§29).
 */
enum LegalInstrumentEventType: string
{
    case Constitution = 'constitution';
    case AmountChange = 'amount_change';
    case MaturityChange = 'maturity_change';
    case RemunerationChange = 'remuneration_change';
    case CoverageChange = 'coverage_change';
    case GuaranteeAdded = 'guarantee_added';
    case GuaranteeReinforced = 'guarantee_reinforced';
    case GuaranteeSubstituted = 'guarantee_substituted';
    case GuaranteeReleased = 'guarantee_released';
    case ObligationChange = 'obligation_change';
    case GuarantorAdded = 'guarantor_added';
    case GuarantorRemoved = 'guarantor_removed';
    case Discharge = 'discharge';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Constitution => 'Constituição',
            self::AmountChange => 'Alteração de valor',
            self::MaturityChange => 'Alteração de vencimento',
            self::RemunerationChange => 'Alteração de remuneração',
            self::CoverageChange => 'Alteração de cobertura',
            self::GuaranteeAdded => 'Inclusão de garantia',
            self::GuaranteeReinforced => 'Reforço de garantia',
            self::GuaranteeSubstituted => 'Substituição de garantia',
            self::GuaranteeReleased => 'Liberação de garantia',
            self::ObligationChange => 'Alteração de obrigação',
            self::GuarantorAdded => 'Inclusão de garantidor',
            self::GuarantorRemoved => 'Remoção de garantidor',
            self::Discharge => 'Quitação',
            self::Other => 'Outro evento',
        };
    }

    /**
     * Evento sugerido a partir do campo alterado, para que a narrativa saia
     * automaticamente da comparação em vez de depender de digitação.
     */
    public static function forFieldKey(LegalInstrumentFieldKey $key): self
    {
        return match ($key) {
            LegalInstrumentFieldKey::OriginalAmount,
            LegalInstrumentFieldKey::PrincipalAmount => self::AmountChange,
            LegalInstrumentFieldKey::MaturityDate => self::MaturityChange,
            LegalInstrumentFieldKey::Remuneration,
            LegalInstrumentFieldKey::InterestRate,
            LegalInstrumentFieldKey::Spread,
            LegalInstrumentFieldKey::Indexer => self::RemunerationChange,
            LegalInstrumentFieldKey::MinimumCoverage => self::CoverageChange,
            LegalInstrumentFieldKey::PropertyRegistration => self::GuaranteeSubstituted,
            LegalInstrumentFieldKey::Guarantors,
            LegalInstrumentFieldKey::Avalists => self::GuarantorAdded,
            LegalInstrumentFieldKey::AffirmativeCovenants,
            LegalInstrumentFieldKey::NegativeCovenants,
            LegalInstrumentFieldKey::AccelerationEvents,
            LegalInstrumentFieldKey::InformationObligations => self::ObligationChange,
            default => self::Other,
        };
    }

    /** Eventos que merecem aparecer na linha do tempo da operação. */
    public function isTimelineWorthy(): bool
    {
        return $this !== self::Other;
    }

    public function color(): string
    {
        return match ($this) {
            self::Constitution, self::GuaranteeAdded, self::GuaranteeReinforced => 'success',
            self::AmountChange, self::MaturityChange, self::RemunerationChange,
            self::CoverageChange, self::ObligationChange => 'info',
            self::GuaranteeSubstituted, self::GuarantorAdded, self::GuarantorRemoved => 'warning',
            self::GuaranteeReleased, self::Discharge => 'gray',
            self::Other => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
