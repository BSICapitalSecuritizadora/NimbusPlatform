<?php

namespace App\Concerns;

/**
 * Pure calculation functions for ProposalProject metrics.
 * Extracted from ProposalProject to be usable independently of the Eloquent model.
 */
final class ProjectCalculator
{
    public static function calculateCostTotal(mixed $costIncurred, mixed $costToIncur): float
    {
        return round(
            MoneyFormatter::normalizeDecimalValue($costIncurred) + MoneyFormatter::normalizeDecimalValue($costToIncur),
            2,
        );
    }

    public static function calculateUnitsTotal(
        mixed $unitsUnpaid,
        mixed $unitsPaid,
        mixed $unitsExchanged,
        mixed $unitsStock,
    ): int {
        return MoneyFormatter::normalizeIntegerValue($unitsUnpaid)
            + MoneyFormatter::normalizeIntegerValue($unitsPaid)
            + MoneyFormatter::normalizeIntegerValue($unitsExchanged)
            + MoneyFormatter::normalizeIntegerValue($unitsStock);
    }

    public static function calculateSalesPercentage(
        mixed $unitsUnpaid,
        mixed $unitsPaid,
        mixed $unitsExchanged,
        mixed $unitsStock,
    ): float {
        $unitsUnpaid = MoneyFormatter::normalizeIntegerValue($unitsUnpaid);
        $unitsPaid = MoneyFormatter::normalizeIntegerValue($unitsPaid);
        $unitsExchanged = MoneyFormatter::normalizeIntegerValue($unitsExchanged);
        $unitsStock = MoneyFormatter::normalizeIntegerValue($unitsStock);
        $unitsTotal = self::calculateUnitsTotal($unitsUnpaid, $unitsPaid, $unitsExchanged, $unitsStock);
        $sellableUnits = $unitsTotal - $unitsExchanged;

        if ($sellableUnits <= 0) {
            return 0.0;
        }

        return round((($unitsUnpaid + $unitsPaid) / $sellableUnits) * 100, 2);
    }

    public static function calculateWorkStagePercentage(mixed $costIncurred, mixed $costTotal): float
    {
        $costIncurred = MoneyFormatter::normalizeDecimalValue($costIncurred);
        $costTotal = MoneyFormatter::normalizeDecimalValue($costTotal);

        if ($costTotal <= 0) {
            return 0.0;
        }

        return round(($costIncurred / $costTotal) * 100, 2);
    }

    public static function calculatePaymentFlowTotal(
        mixed $valueReceived,
        mixed $valueUntilKeys,
        mixed $valuePostKeys,
    ): float {
        return round(
            MoneyFormatter::normalizeDecimalValue($valueReceived)
                + MoneyFormatter::normalizeDecimalValue($valueUntilKeys)
                + MoneyFormatter::normalizeDecimalValue($valuePostKeys),
            2,
        );
    }

    public static function calculateSalesValuesTotal(
        mixed $valuePaid,
        mixed $valueUnpaid,
        mixed $valueStock,
    ): float {
        return round(
            MoneyFormatter::normalizeDecimalValue($valuePaid)
                + MoneyFormatter::normalizeDecimalValue($valueUnpaid)
                + MoneyFormatter::normalizeDecimalValue($valueStock),
            2,
        );
    }
}
