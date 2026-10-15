<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\BankApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankApplication>
 */
class BankApplicationFactory extends Factory
{
    protected $model = BankApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'codigo_produto' => str_pad((string) fake()->unique()->randomNumber(6), 6, '0', STR_PAD_LEFT),
            'nome_produto' => fake()->optional()->words(3, true),
        ];
    }
}
