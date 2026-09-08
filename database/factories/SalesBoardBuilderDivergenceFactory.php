<?php

namespace Database\Factories;

use App\Enums\SalesBoardBuilderDivergenceType;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardBuilderDivergence>
 */
class SalesBoardBuilderDivergenceFactory extends Factory
{
    protected $model = SalesBoardBuilderDivergence::class;

    public function definition(): array
    {
        return [
            'sales_board_builder_review_id' => SalesBoardBuilderReview::factory(),
            'sales_board_builder_review_section_id' => SalesBoardBuilderReviewSection::factory(),
            'type' => SalesBoardBuilderDivergenceType::Other,
            'reason' => 'Divergência de teste.',
        ];
    }
}
