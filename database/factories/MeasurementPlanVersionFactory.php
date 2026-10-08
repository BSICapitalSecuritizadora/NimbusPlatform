<?php

namespace Database\Factories;

use App\Enums\MeasurementPlanVersionStatus;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Todo plano já nasce com a V1 em rascunho ({@see MeasurementPlanSet}); esta
 * fábrica serve às versões seguintes e aos testes que montam o histórico.
 *
 * O estado padrão é uma revisão cancelada: o banco aceita no máximo uma
 * vigente e um rascunho por plano, e o plano novo já ocupa a vaga de rascunho
 * com a V1 -- a cancelada não ocupa vaga nenhuma. O rascunho
 * ({@see self::draft()}) é a revisão da vigente e só cabe num plano sem
 * rascunho; a vigente nasce pela ativação do serviço de versões, não por aqui.
 *
 * @extends Factory<MeasurementPlanVersion>
 */
class MeasurementPlanVersionFactory extends Factory
{
    protected $model = MeasurementPlanVersion::class;

    /**
     * Números já dados por esta fábrica a versões ainda não gravadas: o
     * `count()` monta todas antes de gravar a primeira, e o máximo do banco
     * sozinho repetiria o número.
     *
     * @var array<int, int>
     */
    private array $reservedVersionNumbers = [];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_set_id' => MeasurementPlanSet::factory(),
            'operation_id' => fn (array $attributes): ?int => MeasurementPlanSet::query()->whereKey($attributes['plan_set_id'])->value('operation_id'),
            'version_number' => fn (array $attributes): int => $this->nextVersionNumber((int) $attributes['plan_set_id']),
            'status' => MeasurementPlanVersionStatus::Cancelled,
            'construction_fund_amount' => fake()->randomFloat(2, 50000, 10000000),
            'cancelled_at' => now(),
            'cancellation_reason' => 'Revisão descartada antes de ser ativada.',
        ];
    }

    /**
     * Rascunho de revisão da versão vigente do plano, para um plano que ainda
     * não tem rascunho: parte da vigente, como o da revisão do serviço, e o
     * serviço o aceita na ativação.
     */
    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => MeasurementPlanVersionStatus::Draft,
            'previous_version_id' => fn (array $attributes): ?int => MeasurementPlanVersion::query()
                ->where('plan_set_id', $attributes['plan_set_id'])
                ->active()
                ->value('id'),
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);
    }

    private function nextVersionNumber(int $planSetId): int
    {
        $next = max(
            (int) MeasurementPlanVersion::query()->where('plan_set_id', $planSetId)->max('version_number'),
            $this->reservedVersionNumbers[$planSetId] ?? 0,
        ) + 1;

        $this->reservedVersionNumbers[$planSetId] = $next;

        return $next;
    }
}
