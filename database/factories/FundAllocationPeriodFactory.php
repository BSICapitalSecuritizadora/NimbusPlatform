<?php

namespace Database\Factories;

use App\Models\BankApplication;
use App\Models\FundAllocationPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundAllocationPeriod>
 */
class FundAllocationPeriodFactory extends Factory
{
    protected $model = FundAllocationPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_application_id' => BankApplication::factory(),
            'competence' => now()->startOfMonth()->toDateString(),
            'effective_revision_id' => null,
            'open_revision_id' => null,
        ];
    }
}
