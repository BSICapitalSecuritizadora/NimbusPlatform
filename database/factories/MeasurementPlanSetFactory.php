<?php

namespace Database\Factories;

use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeasurementPlanSet>
 */
class MeasurementPlanSetFactory extends Factory
{
    protected $model = MeasurementPlanSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_id' => Operation::factory(),
            'construction_id' => null,
            'name' => 'Plano '.fake()->unique()->numberBetween(1, 100000),
            'is_default' => false,
            'initial_incurred_amount' => fake()->randomFloat(2, 0, 1000000),
        ];
    }

    /**
     * Fundo de Obra da V1 (o rascunho com que todo plano nasce). O fundo é da
     * versão, não do plano.
     */
    public function withConstructionFund(string|int|float|null $amount): static
    {
        return $this->afterCreating(function (MeasurementPlanSet $planSet) use ($amount): void {
            MeasurementPlanVersion::query()
                ->where('plan_set_id', $planSet->getKey())
                ->draft()
                ->firstOrFail()
                ->forceFill(['construction_fund_amount' => $amount])
                ->save();
        });
    }

    /**
     * Como antes da versão, o plano de fábrica nasce com um Fundo de Obra --
     * agora na V1, em rascunho. {@see self::withConstructionFund()} troca o
     * valor.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (MeasurementPlanSet $planSet): void {
            MeasurementPlanVersion::query()
                ->where('plan_set_id', $planSet->getKey())
                ->draft()
                ->where('version_number', 1)
                ->whereNull('construction_fund_amount')
                ->first()
                ?->forceFill(['construction_fund_amount' => fake()->randomFloat(2, 50000, 10000000)])
                ->save();
        });
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
            'name' => 'Plano padrão',
        ]);
    }

    /**
     * Obra que entrou no sistema já em andamento.
     */
    public function withInitialPhysicalProgress(string $percent, string $referenceDate = '2026-04-30'): static
    {
        return $this->state(fn (): array => [
            'initial_physical_progress_percent' => $percent,
            'initial_physical_progress_reference_date' => $referenceDate,
        ]);
    }
}
