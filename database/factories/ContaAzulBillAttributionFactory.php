<?php

namespace Database\Factories;

use App\Models\ContaAzulBillAttribution;
use App\Models\ContaAzulImportedBill;
use App\Models\Emission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContaAzulBillAttribution>
 */
class ContaAzulBillAttributionFactory extends Factory
{
    protected $model = ContaAzulBillAttribution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conta_azul_imported_bill_id' => ContaAzulImportedBill::factory(),
            'action' => ContaAzulBillAttribution::ACTION_ATTRIBUTED,
            'from_emission_id' => null,
            'to_emission_id' => Emission::factory(),
            'decided_by_user_id' => User::factory(),
            'decided_at' => now(),
            'reason' => fake()->sentence(8),
            'source_fingerprint' => fake()->sha256(),
            'is_exceptional' => false,
            'idempotency_key' => fake()->unique()->sha256(),
        ];
    }
}
