<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\ContaAzulImportBatch;
use App\Models\ContaAzulImportedBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContaAzulImportedBill>
 */
class ContaAzulImportedBillFactory extends Factory
{
    protected $model = ContaAzulImportedBill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conta_azul_import_batch_id' => ContaAzulImportBatch::factory(),
            'last_seen_batch_id' => null,
            'bank_account_id' => BankAccount::factory(),
            'conta_azul_bill_id' => fake()->unique()->uuid(),
            'conta_azul_account_id' => fake()->uuid(),
            'description' => fake()->sentence(3),
            'total_amount' => fake()->randomFloat(2, 10, 50000),
            'paid_amount' => null,
            'unpaid_amount' => null,
            'due_date' => fake()->date(),
            'payment_date' => null,
            'competence_date' => null,
            'external_created_at' => null,
            'external_updated_at' => null,
            'external_status' => 'PENDING',
            'external_status_translated' => null,
            'categories' => null,
            'mapped_category' => null,
            'cost_centers' => null,
            'supplier_name' => null,
            'supplier_external_id' => null,
            'payload_fingerprint' => fake()->sha256(),
            'source_payload' => null,
            'import_status' => ContaAzulImportedBill::IMPORT_STAGED,
            'last_import_error' => null,
            'reconciliation_status' => ContaAzulImportedBill::RECONCILIATION_PENDING,
            'attributed_emission_id' => null,
        ];
    }
}
