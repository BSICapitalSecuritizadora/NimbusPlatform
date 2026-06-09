<?php

namespace Database\Factories;

use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeasurementPayment>
 */
class MeasurementPaymentFactory extends Factory
{
    protected $model = MeasurementPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_id' => Operation::factory(),
            'measurement_id' => Measurement::factory(),
            'plan_set_id' => null,
            'pay_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'amount' => fake()->randomFloat(2, 1000, 5000000),
            'method' => fake()->randomElement(['TED', 'PIX', 'Boleto']),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
