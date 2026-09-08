<?php

namespace Database\Factories;

use App\Enums\SalesBoardRolloutRecipientRole;
use App\Models\Emission;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardRolloutRecipient>
 */
class SalesBoardRolloutRecipientFactory extends Factory
{
    protected $model = SalesBoardRolloutRecipient::class;

    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'role' => SalesBoardRolloutRecipientRole::Operational,
            'user_id' => User::factory(),
        ];
    }
}
