<?php

namespace Database\Factories;

use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Enums\GuaranteeValueSource;
use App\Models\Emission;
use App\Models\Guarantee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guarantee>
 */
class GuaranteeFactory extends Factory
{
    protected $model = Guarantee::class;

    /**
     * O padrão reproduz o cadastro manual legado — tipo não classificado e
     * mínimo absoluto — para que os testes anteriores ao módulo continuem
     * descrevendo o mesmo cenário.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'guarantee_type' => fake()->randomElement([
                'Alienacao fiduciaria',
                'Cessao fiduciaria',
                'Fianca',
            ]),
            'minimum_value' => fake()->randomFloat(2, 10000, 5000000),
            'validity_start_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'validity_end_date' => fake()->dateTimeBetween('+1 month', '+5 years')->format('Y-m-d'),
            'description' => fake()->sentence(),
            'evaluation_frequency' => fake()->randomElement([
                'Mensal',
                'Trimestral',
                'Semestral',
                'Anual',
            ]),
            'legal_status' => GuaranteeLegalStatus::Active,
            'requirement_basis' => GuaranteeRequirementBasis::None,
            'counts_toward_coverage' => true,
        ];
    }

    public function ofType(GuaranteeType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'name' => $type->label(),
            'guarantee_type' => $type->label(),
            'value_source' => $type->defaultValueSource(),
        ]);
    }

    /**
     * Janela de vigência explícita.
     *
     * O estado padrão sorteia as datas, o que é útil para listagens mas
     * inutiliza qualquer teste de apuração por competência: uma garantia cujo
     * início caia depois do mês analisado simplesmente não compõe a cobertura.
     */
    public function effectiveBetween(string $start = '2020-01-01', ?string $end = '2035-12-31'): static
    {
        return $this->state(fn (array $attributes): array => [
            'validity_start_date' => $start,
            'validity_end_date' => $end,
            'constituted_at' => $start,
        ]);
    }

    /** Regra contratual de valor absoluto: "R$ 5.000.000". */
    public function requiringAbsolute(float $value): static
    {
        return $this->state(fn (array $attributes): array => [
            'requirement_basis' => GuaranteeRequirementBasis::Absolute,
            'requirement_value' => $value,
            'minimum_value' => $value,
        ]);
    }

    /** Regra contratual percentual: "120% do saldo devedor". */
    public function requiringPercentage(
        float $percentage,
        GuaranteeRequirementBase $base = GuaranteeRequirementBase::OutstandingBalance,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'requirement_basis' => GuaranteeRequirementBasis::Percentage,
            'requirement_percentage' => $percentage,
            'requirement_base' => $base,
        ]);
    }

    /** Regra por contagem: "3 próximas PMTs", "6 meses de juros". */
    public function requiringMultiplier(float $multiplier, GuaranteeRequirementBase $base): static
    {
        return $this->state(fn (array $attributes): array => [
            'requirement_basis' => GuaranteeRequirementBasis::Formula,
            'requirement_multiplier' => $multiplier,
            'requirement_base' => $base,
        ]);
    }

    public function withValueSource(GuaranteeValueSource $source): static
    {
        return $this->state(fn (array $attributes): array => ['value_source' => $source]);
    }

    public function withLegalStatus(GuaranteeLegalStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['legal_status' => $status]);
    }

    public function released(string $releasedAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'legal_status' => GuaranteeLegalStatus::Released,
            'released_at' => $releasedAt,
        ]);
    }

    /** Deságio aplicado ao valor atual antes de compor a cobertura. */
    public function withHaircut(float $factor): static
    {
        return $this->state(fn (array $attributes): array => ['eligibility_factor' => $factor]);
    }
}
