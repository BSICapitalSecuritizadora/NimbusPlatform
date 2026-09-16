<?php

namespace Database\Factories;

use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MeasurementPaymentReceiptEvidence> */
class MeasurementPaymentReceiptEvidenceFactory extends Factory
{
    protected $model = MeasurementPaymentReceiptEvidence::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'measurement_payment_id' => MeasurementPayment::factory(),
            'version' => 1,
            'storage_disk' => 'local',
            'storage_path' => 'nimbus_docs/measurements/receipts/'.fake()->uuid().'.pdf',
            'original_filename' => 'comprovante.pdf',
            'mime_type' => 'application/pdf',
            'review_status' => 'pending',
            'uploaded_at' => now(),
        ];
    }
}
