<?php

namespace Database\Factories;

use App\Models\FundName;
use App\Models\FundType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundName>
 */
class FundNameFactory extends Factory
{
    protected $model = FundName::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fund_type_id' => FundType::factory(),
            'name' => 'Fundo '.fake()->unique()->company(),
        ];
    }
}
