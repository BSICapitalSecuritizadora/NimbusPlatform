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
     * Sem fim por padrão: é a forma das linhas registradas antes do fim
     * explícito, e é a que os cenários do quadro de vendas usam para ter uma
     * política valendo em qualquer data depois do início. Registros com período
     * fechado usam {@see self::effectiveUntil()} ou {@see self::during()}.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'construction_id' => Construction::factory(),
            'maximum_discount_percent' => '5.00',
            'effective_from' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'effective_until' => null,
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

    public function effectiveUntil(?string $date): static
    {
        return $this->state(fn (): array => ['effective_until' => $date]);
    }

    public function during(string $from, string $until): static
    {
        return $this->effectiveFrom($from)->effectiveUntil($until);
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
