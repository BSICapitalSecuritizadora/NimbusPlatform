<?php

namespace Database\Factories;

use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitRetirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Grava a baixa direto, sem passar pelo serviço: serve aos cenários que
 * precisam de uma baixa já existente -- inclusive as que o serviço recusaria,
 * como a de uma unidade ocupada, que a derivação ainda precisa saber tratar.
 *
 * @extends Factory<ConstructionUnitRetirement>
 */
class ConstructionUnitRetirementFactory extends Factory
{
    protected $model = ConstructionUnitRetirement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'construction_unit_id' => ConstructionUnit::factory(),
            'retired_on' => '2026-07-01',
            'reason' => 'Unidade cadastrada em duplicidade na carga inicial.',
            'retired_by_id' => null,
            'reactivated_on' => null,
            'reactivated_at' => null,
            'reactivated_by_id' => null,
            'reactivation_reason' => null,
        ];
    }

    public function forUnit(ConstructionUnit $constructionUnit): static
    {
        return $this->state(fn (): array => ['construction_unit_id' => $constructionUnit->getKey()]);
    }

    public function retiredOn(string $date): static
    {
        return $this->state(fn (): array => ['retired_on' => $date]);
    }

    public function reactivatedOn(string $date, string $reason = 'Baixa registrada por engano.'): static
    {
        return $this->state(fn (): array => [
            'reactivated_on' => $date,
            'reactivated_at' => now(),
            'reactivation_reason' => $reason,
        ]);
    }

    public function retiredBy(User $user): static
    {
        return $this->state(fn (): array => ['retired_by_id' => $user->getKey()]);
    }
}
