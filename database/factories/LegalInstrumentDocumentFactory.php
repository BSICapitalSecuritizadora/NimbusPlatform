<?php

namespace Database\Factories;

use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentDocumentStatus;
use App\Models\Document;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalInstrumentDocument>
 */
class LegalInstrumentDocumentFactory extends Factory
{
    protected $model = LegalInstrumentDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_instrument_id' => LegalInstrument::factory(),
            'document_id' => Document::factory(),
            'role' => LegalInstrumentDocumentRole::Original,
            'document_date' => '2024-01-10',
            'processing_status' => LegalInstrumentDocumentStatus::Pending,
        ];
    }

    public function original(string $documentDate = '2024-01-10'): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => LegalInstrumentDocumentRole::Original,
            'sequence' => null,
            'document_date' => $documentDate,
        ]);
    }

    public function amendment(int $sequence, string $documentDate): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => LegalInstrumentDocumentRole::Amendment,
            'sequence' => $sequence,
            'document_date' => $documentDate,
        ]);
    }

    public function withRole(LegalInstrumentDocumentRole $role, ?string $documentDate = null): static
    {
        return $this->state(fn (array $attributes): array => array_filter([
            'role' => $role,
            'document_date' => $documentDate,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processing_status' => LegalInstrumentDocumentStatus::Processed,
            'processed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processing_status' => LegalInstrumentDocumentStatus::Failed,
            'error_message' => 'Falha ao processar o documento.',
        ]);
    }
}
