<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\ContaAzulImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContaAzulImportBatch>
 */
class ContaAzulImportBatchFactory extends Factory
{
    protected $model = ContaAzulImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'conta_azul_account_id' => fake()->uuid(),
            'integration_context' => 'conta_azul',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'status' => ContaAzulImportBatch::STATUS_PENDING,
            'pages_requested' => 0,
            'pages_completed' => 0,
            'records_received' => 0,
            'bills_staged' => 0,
            'bills_updated' => 0,
            'bills_skipped' => 0,
            'bills_reobserved' => 0,
            'error_code' => null,
            'error_message' => null,
            'retries_batch_id' => null,
            'requested_by_user_id' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
