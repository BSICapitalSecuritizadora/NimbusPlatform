<?php

namespace Database\Factories;

use App\Enums\MalwareScanStatus;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewAttachment;
use App\Services\SalesBoards\SalesBoardBuilderResponseEvidenceStore;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalesBoardBuilderReviewAttachment>
 */
class SalesBoardBuilderReviewAttachmentFactory extends Factory
{
    protected $model = SalesBoardBuilderReviewAttachment::class;

    public function definition(): array
    {
        return [
            'sales_board_builder_review_id' => SalesBoardBuilderReview::factory(),
            'disk' => 'local',
            'path' => 'nimbus_docs/'.SalesBoardBuilderResponseEvidenceStore::STORAGE_DIRECTORY.'/0/'.Str::random(40).'.pdf',
            'original_name' => 'resposta-construtora.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'checksum' => hash('sha256', Str::random(16)),
            'scan_status' => MalwareScanStatus::Clean,
            'uploaded_by_user_id' => null,
        ];
    }

    public function forReview(SalesBoardBuilderReview $review): self
    {
        return $this->state(fn (): array => [
            'sales_board_builder_review_id' => $review->getKey(),
            'path' => 'nimbus_docs/'.SalesBoardBuilderResponseEvidenceStore::STORAGE_DIRECTORY.'/'.$review->sales_board_cycle_id.'/'.Str::random(40).'.pdf',
        ]);
    }

    public function scanStatus(MalwareScanStatus $status): self
    {
        return $this->state(fn (): array => ['scan_status' => $status]);
    }
}
