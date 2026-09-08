<?php

namespace Database\Factories;

use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardBuilderReviewSection>
 */
class SalesBoardBuilderReviewSectionFactory extends Factory
{
    protected $model = SalesBoardBuilderReviewSection::class;

    public function definition(): array
    {
        return [
            'sales_board_builder_review_id' => SalesBoardBuilderReview::factory(),
            'section' => SectionEnum::PositionStock,
            'status' => SalesBoardBuilderReviewSectionStatus::Pending,
        ];
    }
}
