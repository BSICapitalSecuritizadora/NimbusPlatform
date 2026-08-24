<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractInstallment>
 */
class ContractInstallmentFactory extends Factory
{
    protected $model = ContractInstallment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'number' => str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'due_date' => fake()->dateTimeBetween('+1 month', '+4 years')->format('Y-m-d'),
            'expected_value' => fake()->randomFloat(2, 500, 25000),
            'payment_date' => null,
            'paid_value' => null,
            'cancellation_date' => null,
        ];
    }

    public function forContract(Contract $contract): static
    {
        return $this->state(fn (): array => [
            'contract_id' => $contract->id,
        ]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn (): array => [
            'due_date' => $date,
        ]);
    }

    /**
     * Received in full, on the due date. The paid value mirrors the expected one
     * so the installment settles exactly, with no juros to reason about.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_date' => $attributes['due_date'],
            'paid_value' => $attributes['expected_value'],
        ]);
    }

    /**
     * A receipt short of the expected value, leaving a saldo behind.
     */
    public function partiallyPaid(float $ratio = 0.4): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_date' => $attributes['due_date'],
            'paid_value' => round(((float) $attributes['expected_value']) * $ratio, 2),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'due_date' => today()->subMonth()->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'cancellation_date' => today()->toDateString(),
        ]);
    }
}
