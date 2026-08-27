<?php

namespace Database\Factories;

use App\Enums\MeasurementResponsibility;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResponsibilityDelegation>
 */
class ResponsibilityDelegationFactory extends Factory
{
    protected $model = ResponsibilityDelegation::class;

    public function definition(): array
    {
        $starts = fake()->dateTimeBetween('-2 days', '+1 days');
        $ends = (clone $starts)->modify('+'.fake()->numberBetween(2, 14).' days');

        return [
            'delegator_user_id' => User::factory(),
            'delegate_user_id' => User::factory(),
            'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
            'scope_operation_id' => null,
            'scope_stage' => null,
            'scope_responsibility' => null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'reason' => fake()->sentence(8),
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'created_by' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(5),
            'revoked_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(2),
            'revoked_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
            'revoked_by' => User::factory(),
        ]);
    }

    public function forOperation(Operation $operation): static
    {
        return $this->state(fn (): array => [
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $operation->getKey(),
            'scope_stage' => null,
        ]);
    }

    public function forStage(
        int $stage,
        ?Operation $operation = null,
        ?MeasurementResponsibility $responsibility = null,
    ): static {
        return $this->state(fn (): array => [
            'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
            'scope_stage' => $stage,
            'scope_responsibility' => ($responsibility ?? MeasurementResponsibility::primaryForStage($stage))?->value,
            'scope_operation_id' => $operation?->getKey(),
        ]);
    }
}
