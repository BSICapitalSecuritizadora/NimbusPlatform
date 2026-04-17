<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\Emission;
use App\Models\Fund;
use App\Models\FundApplication;
use App\Models\FundName;
use App\Models\FundType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fund>
 */
class FundFactory extends Factory
{
    protected $model = Fund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'fund_type_id' => FundType::factory(),
            'fund_name_id' => function (array $attributes): int {
                return FundName::factory()
                    ->create(['fund_type_id' => $attributes['fund_type_id']])
                    ->getKey();
            },
            'fund_application_id' => FundApplication::factory(),
            'bank_id' => Bank::factory(),
            'agency' => fake()->numerify('####-#'),
            'account' => fake()->unique()->numerify('#####-#'),
        ];
    }
}
