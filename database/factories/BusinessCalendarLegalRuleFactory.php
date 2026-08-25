<?php

namespace Database\Factories;

use App\Models\BusinessCalendarLegalRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessCalendarLegalRule>
 */
class BusinessCalendarLegalRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
            'rule_key' => fake()->unique()->slug(3),
            'rule_type' => BusinessCalendarLegalRule::TYPE_FIXED_NATIONAL_HOLIDAY,
            'name' => fake()->sentence(3),
            'month' => fake()->numberBetween(1, 12),
            'day' => fake()->numberBetween(1, 28),
            'effective_from' => '1991-01-01',
            'effective_until' => null,
            'norm_identification' => 'Lei federal de teste',
            'article_reference' => 'Art. 1º',
            'source_url' => 'https://www.planalto.gov.br/',
            'source_fingerprint' => fake()->sha256(),
            'verified_at' => now(),
            'verified_by' => null,
            'notes' => 'Regra jurídica de teste.',
        ];
    }
}
