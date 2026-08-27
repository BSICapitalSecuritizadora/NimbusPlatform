<?php

namespace Database\Factories;

use App\Models\IndexRateSourceGovernanceReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndexRateSourceGovernanceReview>
 */
class IndexRateSourceGovernanceReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_code' => 'bcb_sgs_4389',
            'report_checksum' => fake()->sha256(),
            'artifact_disk' => 'local',
            'artifact_path' => 'homologations/index-rate-sources/'.fake()->uuid().'.json',
            'status' => IndexRateSourceGovernanceReview::STATUS_APPROVED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'review_notes' => fake()->sentence(),
        ];
    }
}
