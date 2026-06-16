<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Models\Emission;
use App\Models\Payment;
use App\Models\PuHistory;

class LegacyProjectionService
{
    public function __construct(
        private readonly DecimalRounder $rounder,
    ) {}

    public function sync(Emission $emission, PuCurveGenerationResult $result): void
    {
        foreach ($result->rows as $row) {
            PuHistory::query()->updateOrCreate(
                [
                    'emission_id' => $emission->id,
                    'date' => $row->date->toDateString(),
                ],
                [
                    'unit_value' => $this->rounder->round($row->residualUnitValue, DecimalRounder::LEGACY_UNIT_SCALE),
                ],
            );
        }

        foreach ($result->paymentRows() as $row) {
            Payment::query()->updateOrCreate(
                [
                    'emission_id' => $emission->id,
                    'payment_date' => $row->date->toDateString(),
                ],
                [
                    'premium_value' => '0.00',
                    'interest_value' => $this->rounder->round($row->interestPaymentValue, DecimalRounder::LEGACY_MONEY_SCALE),
                    'amortization_value' => $this->rounder->round(
                        bcsub($row->paymentTotalValue, $row->interestPaymentValue, DecimalRounder::INTERNAL_SCALE),
                        DecimalRounder::LEGACY_MONEY_SCALE,
                    ),
                    'extra_amortization_value' => '0.00',
                ],
            );
        }

        $latestRow = $result->rows[array_key_last($result->rows)] ?? null;

        if ($latestRow !== null) {
            $emission->update([
                'current_pu' => $this->rounder->round($latestRow->residualUnitValue, DecimalRounder::LEGACY_UNIT_SCALE),
            ]);
        }
    }
}
