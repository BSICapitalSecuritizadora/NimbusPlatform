<?php

namespace Database\Factories;

use App\Models\Fund;
use App\Models\FundAllocationLine;
use App\Models\FundAllocationRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundAllocationLine>
 */
class FundAllocationLineFactory extends Factory
{
    protected $model = FundAllocationLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fund_allocation_revision_id' => FundAllocationRevision::factory(),
            'fund_id' => function (array $attributes): int {
                $revision = FundAllocationRevision::query()->findOrFail($attributes['fund_allocation_revision_id']);
                $period = $revision->period()->firstOrFail();

                return Fund::factory()->create([
                    'bank_account_id' => $period->bankApplication->bank_account_id,
                    'bank_application_id' => $period->bank_application_id,
                ])->getKey();
            },
            'emission_id' => function (array $attributes): int {
                return Fund::query()->findOrFail($attributes['fund_id'])->emission_id;
            },
            'amount' => '1000.00',
        ];
    }
}
