<?php

namespace Database\Factories;

use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankApplicationBalanceSnapshot>
 */
class BankApplicationBalanceSnapshotFactory extends Factory
{
    protected $model = BankApplicationBalanceSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_application_id' => BankApplication::factory(),
            'competence' => now()->startOfMonth()->toDateString(),
            'amount' => '100000.00',
            'source' => BankApplicationBalanceSnapshot::SOURCE_MANUAL,
            'captured_at' => now(),
            'recorded_by_user_id' => User::factory(),
            'note' => null,
            'version' => 1,
            'supersedes_snapshot_id' => null,
        ];
    }
}
