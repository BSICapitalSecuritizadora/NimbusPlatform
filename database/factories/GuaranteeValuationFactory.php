<?php

namespace Database\Factories;

use App\Enums\GuaranteeValuationBasis;
use App\Models\Guarantee;
use App\Models\GuaranteeValuation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuaranteeValuation>
 */
class GuaranteeValuationFactory extends Factory
{
    protected $model = GuaranteeValuation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guarantee_id' => Guarantee::factory(),
            'valuation_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'value' => fake()->randomFloat(2, 1000000, 50000000),
            'basis' => GuaranteeValuationBasis::Appraisal,
            'appraiser' => fake()->company(),
        ];
    }

    public function on(string $valuationDate, float $value): static
    {
        return $this->state(fn (array $attributes): array => [
            'valuation_date' => $valuationDate,
            'value' => $value,
        ]);
    }

    public function withBasis(GuaranteeValuationBasis $basis): static
    {
        return $this->state(fn (array $attributes): array => ['basis' => $basis]);
    }
}
