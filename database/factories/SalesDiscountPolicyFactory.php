<?php

namespace Database\Factories;

use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesDiscountPolicy>
 */
class SalesDiscountPolicyFactory extends Factory
{
    protected $model = SalesDiscountPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'construction_id' => Construction::factory(),
            'maximum_discount_percent' => '5.00',
            'effective_from' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'reason' => 'Política comercial aprovada.',
            'created_by_id' => null,
        ];
    }

    public function forConstruction(Construction $construction): static
    {
        return $this->state(fn (): array => ['construction_id' => $construction->getKey()]);
    }

    public function effectiveFrom(string $date): static
    {
        return $this->state(fn (): array => ['effective_from' => $date]);
    }

    public function allowing(float|int|string $percent): static
    {
        return $this->state(fn (): array => ['maximum_discount_percent' => $percent]);
    }

    public function registeredBy(User $user): static
    {
        return $this->state(fn (): array => ['created_by_id' => $user->getKey()]);
    }
}
