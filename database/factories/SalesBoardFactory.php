<?php

namespace Database\Factories;

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoard>
 */
class SalesBoardFactory extends Factory
{
    protected $model = SalesBoard::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            // O empreendimento nasce na Emissão do quadro: o guard de escrita
            // recusa quadro fora da Emissão atual do empreendimento, porque o
            // leitor da posição o somaria nas duas. A anomalia, quando um teste
            // precisa dela, sai de Tests\Support\SalesBoards\SalesBoardAnomalyFixture.
            'construction_id' => fn (array $attributes): Factory => Construction::factory()->state([
                'emission_id' => $attributes['emission_id'],
            ]),
            'reference_month' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-01'),
            'stock_units' => fake()->numberBetween(0, 50),
            'financed_units' => fake()->numberBetween(0, 50),
            'paid_units' => fake()->numberBetween(0, 50),
            'exchanged_units' => fake()->numberBetween(0, 50),
            'stock_value' => fake()->randomFloat(2, 0, 10000000),
            'financed_value' => fake()->randomFloat(2, 0, 10000000),
            'paid_value' => fake()->randomFloat(2, 0, 10000000),
            'exchanged_value' => fake()->randomFloat(2, 0, 10000000),
        ];
    }

    public function forEmissionAndConstruction(Emission $emission, Construction $construction): static
    {
        return $this->state(fn (): array => [
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
        ]);
    }
}
