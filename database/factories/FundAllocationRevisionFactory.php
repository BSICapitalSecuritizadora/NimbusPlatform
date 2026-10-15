<?php

namespace Database\Factories;

use App\Models\BankApplicationBalanceSnapshot;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundAllocationRevision>
 */
class FundAllocationRevisionFactory extends Factory
{
    protected $model = FundAllocationRevision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fund_allocation_period_id' => FundAllocationPeriod::factory(),
            'revision_number' => 1,
            'status' => FundAllocationRevision::STATUS_DRAFT,
            'bank_application_balance_snapshot_id' => function (array $attributes): int {
                $period = FundAllocationPeriod::query()->findOrFail($attributes['fund_allocation_period_id']);

                return BankApplicationBalanceSnapshot::factory()->create([
                    'bank_application_id' => $period->bank_application_id,
                    'competence' => $period->competence->toDateString(),
                ])->getKey();
            },
            'reference_amount' => '100000.00',
            'total_amount' => '0.00',
            'unallocated_amount' => '0.00',
            'proposed_by_user_id' => User::factory(),
            'submitted_by_user_id' => null,
            'submitted_at' => null,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approval_idempotency_key' => null,
            'supersedes_revision_id' => null,
            'correction_reason' => null,
        ];
    }
}
