<?php

namespace Database\Factories;

use App\Models\Emission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Receivable>
 */
class ReceivableFactory extends Factory
{
    public function definition(): array
    {
        $referenceMonth = fake()->dateTimeBetween('-6 months', 'now');

        return [
            'emission_id' => Emission::factory(),
            'reference_month' => $referenceMonth->format('Y-m-01'),
            'portfolio_id' => (string) fake()->numberBetween(1, 9999),
            'active_contracts_count' => fake()->numberBetween(50, 500),
            'expected_interest_amount' => fake()->randomFloat(2, 1000, 100000),
            'expected_amortization_amount' => fake()->randomFloat(2, 100000, 2000000),
            'received_installment_interest_amount' => fake()->randomFloat(2, 1000, 100000),
            'received_installment_amortization_amount' => fake()->randomFloat(2, 100000, 2000000),
            'received_prepayment_interest_amount' => fake()->randomFloat(2, 0, 50000),
            'received_prepayment_amortization_amount' => fake()->randomFloat(2, 0, 500000),
            'received_default_interest_amount' => fake()->randomFloat(2, 0, 50000),
            'received_default_amortization_amount' => fake()->randomFloat(2, 0, 500000),
            'received_interest_and_penalty_amount' => fake()->randomFloat(2, 0, 50000),
            'performing_balance_pre_event_amount' => fake()->randomFloat(2, 1000000, 50000000),
            'non_performing_balance_pre_event_amount' => fake()->randomFloat(2, 100000, 10000000),
            'performing_balance_post_event_amount' => fake()->randomFloat(2, 1000000, 50000000),
            'non_performing_balance_post_event_amount' => fake()->randomFloat(2, 100000, 10000000),
            'monthly_default_balance_amount' => fake()->randomFloat(2, 0, 1000000),
            'total_default_balance_amount' => fake()->randomFloat(2, 0, 2000000),
            'linked_credits_current_amount' => fake()->randomFloat(2, 1000000, 50000000),
            'overdue_up_to_30_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'overdue_31_to_60_days_amount' => fake()->randomFloat(2, 0, 500000),
            'overdue_61_to_90_days_amount' => fake()->randomFloat(2, 0, 500000),
            'overdue_91_to_120_days_amount' => fake()->randomFloat(2, 0, 500000),
            'overdue_121_to_150_days_amount' => fake()->randomFloat(2, 0, 500000),
            'overdue_151_to_180_days_amount' => fake()->randomFloat(2, 0, 500000),
            'overdue_181_to_360_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'overdue_over_360_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'prepaid_up_to_30_days_amount' => fake()->randomFloat(2, 0, 100000),
            'prepaid_31_to_60_days_amount' => fake()->randomFloat(2, 0, 100000),
            'prepaid_61_to_90_days_amount' => fake()->randomFloat(2, 0, 100000),
            'prepaid_91_to_120_days_amount' => fake()->randomFloat(2, 0, 100000),
            'prepaid_121_to_150_days_amount' => fake()->randomFloat(2, 0, 100000),
            'prepaid_151_to_180_days_amount' => fake()->randomFloat(2, 0, 100000),
            'prepaid_181_to_360_days_amount' => fake()->randomFloat(2, 0, 100000),
            'prepaid_over_360_days_amount' => fake()->randomFloat(2, 0, 100000),
            'linked_credits_up_to_30_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'linked_credits_31_to_60_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'linked_credits_61_to_90_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'linked_credits_91_to_120_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'linked_credits_121_to_150_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'linked_credits_151_to_180_days_amount' => fake()->randomFloat(2, 0, 1000000),
            'linked_credits_181_to_360_days_amount' => fake()->randomFloat(2, 0, 15000000),
            'linked_credits_over_360_days_amount' => fake()->randomFloat(2, 0, 15000000),
            'guarantees_value_amount' => fake()->optional()->randomFloat(2, 0, 5000000),
            'total_prepayment_amount' => fake()->randomFloat(2, 0, 500000),
            'top_five_debtors_concentration_ratio' => fake()->optional()->randomFloat(6, 0, 2),
            'total_outstanding_balance_amount' => fake()->randomFloat(2, 1000000, 50000000),
            'portfolio_ltv_ratio' => fake()->optional()->randomFloat(6, 0, 2),
            'sale_ltv_ratio' => fake()->optional()->randomFloat(6, 0, 2),
            'portfolio_duration_years' => fake()->randomFloat(6, 0, 5),
            'portfolio_duration_months' => fake()->randomFloat(6, 0, 60),
            'average_rate_details' => implode(PHP_EOL, [
                'INCC-DI - 11.33% a.a',
                'IPCA - 10.28% a.a',
            ]),
            'summary_payload' => [
                [
                    'line' => 1,
                    'label' => 'Mes ano referencia',
                    'normalized_label' => 'mes_ano_referencia',
                    'value' => $referenceMonth->format('Y-m-d'),
                    'note' => null,
                ],
            ],
        ];
    }
}
