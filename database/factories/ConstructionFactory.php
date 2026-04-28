<?php

namespace Database\Factories;

use App\Models\Construction;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Construction>
 */
class ConstructionFactory extends Factory
{
    protected $model = Construction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-1 year', '+1 year');

        return [
            'emission_id' => Emission::factory(),
            'development_name' => fake()->company().' Residence',
            'development_cnpj' => fake()->unique()->numerify('##############'),
            'city' => fake()->city(),
            'state' => fake()->randomElement(array_keys(Construction::STATE_OPTIONS)),
            'construction_start_date' => $startDate->format('Y-m-d'),
            'construction_end_date' => fake()->dateTimeBetween($startDate, '+4 years')->format('Y-m-d'),
            'estimated_value' => fake()->randomFloat(2, 100000, 50000000),
            'measurement_company_id' => ExpenseServiceProvider::factory()
                ->for($this->engineeringType(), 'type'),
        ];
    }

    public function withEngineeringMeasurementCompany(): static
    {
        return $this->state(function (): array {
            return [
                'measurement_company_id' => ExpenseServiceProvider::factory()
                    ->for($this->engineeringType(), 'type'),
            ];
        });
    }

    protected function engineeringType(): ExpenseServiceProviderType
    {
        return ExpenseServiceProviderType::query()->firstOrCreate([
            'name' => Construction::MEASUREMENT_COMPANY_TYPE_NAME,
        ]);
    }
}
