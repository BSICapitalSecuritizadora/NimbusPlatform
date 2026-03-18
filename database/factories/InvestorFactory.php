<?php

namespace Database\Factories;

use App\Models\Investor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Investor>
 */
class InvestorFactory extends Factory
{
    protected $model = Investor::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numerify('(##) ####-####'),
            'mobile' => fake()->numerify('(##) #####-####'),
            'cpf' => fake()->numerify('###.###.###-##'),
            'rg' => fake()->numerify('##.###.###-#'),
            'is_active' => true,
            'last_login_at' => null,
            'last_portal_seen_at' => null,
            'notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
