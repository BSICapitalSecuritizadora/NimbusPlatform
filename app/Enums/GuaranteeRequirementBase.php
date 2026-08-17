<?php

namespace App\Enums;

/**
 * Grandeza sobre a qual o percentual/fórmula contratual incide (§13 do escopo).
 */
enum GuaranteeRequirementBase: string
{
    case OutstandingBalance = 'outstanding_balance';
    case IssuedVolume = 'issued_volume';
    case IntegralizedValue = 'integralized_value';
    case NextInstallments = 'next_installments';
    case InterestMonths = 'interest_months';
    case GuaranteeCurrentValue = 'guarantee_current_value';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::OutstandingBalance => 'Saldo devedor',
            self::IssuedVolume => 'Volume emitido',
            self::IntegralizedValue => 'Valor integralizado',
            self::NextInstallments => 'Próximas PMTs',
            self::InterestMonths => 'Meses de juros',
            self::GuaranteeCurrentValue => 'Valor atual da garantia',
            self::Custom => 'Base específica (manual)',
        };
    }

    /**
     * Bases que consomem um multiplicador em vez de um percentual —
     * "3 próximas PMTs" e "6 meses de juros" contam quantidades, não frações.
     */
    public function usesCountMultiplier(): bool
    {
        return $this === self::NextInstallments || $this === self::InterestMonths;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $base) {
            $options[$base->value] = $base->label();
        }

        return $options;
    }
}
