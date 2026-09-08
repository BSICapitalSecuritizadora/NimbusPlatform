<?php

namespace Database\Factories;

use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardManagementNonconformity>
 */
class SalesBoardManagementNonconformityFactory extends Factory
{
    protected $model = SalesBoardManagementNonconformity::class;

    /**
     * O padrão é a origem do sistema, porque ela é a que exige menos montagem:
     * um movimento congelado basta. A âncora nunca fica vazia -- o model recusa
     * uma pendência sem exatamente a referência que a origem pede.
     */
    public function definition(): array
    {
        return [
            'sales_board_management_review_id' => SalesBoardManagementReview::factory(),
            'origin' => SalesBoardNonconformityOrigin::SystemSaleNonConform,
            'sales_board_builder_divergence_id' => null,
            'sales_board_cycle_movement_id' => SalesBoardCycleMovement::factory(),
            'decision' => SalesBoardNonconformityDecision::Pending,
        ];
    }

    public function forDivergence(SalesBoardBuilderDivergence $divergence): self
    {
        return $this->state(fn (): array => [
            'origin' => SalesBoardNonconformityOrigin::BuilderDeclared,
            'sales_board_builder_divergence_id' => $divergence->getKey(),
            'sales_board_cycle_movement_id' => null,
        ]);
    }

    public function forMovement(SalesBoardCycleMovement $movement): self
    {
        return $this->state(fn (): array => [
            'origin' => SalesBoardNonconformityOrigin::SystemSaleNonConform,
            'sales_board_builder_divergence_id' => null,
            'sales_board_cycle_movement_id' => $movement->getKey(),
        ]);
    }
}
