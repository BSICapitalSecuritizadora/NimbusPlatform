<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Services\DocumentStorageService;
use App\Services\MeasurementReceiptEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeasurementReceiptDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        MeasurementPayment $payment,
        DocumentStorageService $storage,
        MeasurementReceiptEvidenceService $evidences,
        ?MeasurementPaymentReceiptEvidence $evidence = null,
    ): BinaryFileResponse|StreamedResponse {
        $payment->loadMissing('measurement');
        Gate::authorize('view', $payment->measurement);

        abort_unless((int) $payment->operation_id === (int) $payment->measurement->operation_id, 404);

        $evidence ??= $payment->currentReceiptEvidence;

        if ($evidence !== null) {
            abort_unless((int) $evidence->measurement_payment_id === (int) $payment->getKey(), 404);

            try {
                $evidences->ensureIntegrity($evidence, allowMissingLegacyHash: true);
            } catch (ValidationException) {
                abort(404, 'Comprovante ausente ou com integridade inválida.');
            }

            $path = $evidence->storage_path;
            $disk = $evidence->resolved_storage_disk;
            $mime = $evidence->mime_type;
            $hash = $evidence->sha256;
        } else {
            $path = $payment->receipt_path;
            $disk = $payment->resolved_receipt_disk;
            $mime = $payment->receipt_mime_type;
            $hash = $payment->receipt_sha256;
            abort_unless(filled($path) && $storage->isAllowedMeasurementReadDisk($disk)
                && $storage->isSafeStoredPath($path) && $storage->exists($path, $disk), 404);
            if ($hash !== null) {
                $actualHash = $storage->checksum($path, $disk);
                abort_unless(is_string($hash) && mb_strlen($hash) === 64
                    && is_string($actualHash) && hash_equals($hash, $actualHash), 404);
            }
        }

        activity('measurement_file_access')
            ->performedOn($payment->measurement)
            ->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->getKey(), 'evidence_id' => $evidence?->getKey(), 'version' => $evidence?->version, 'sha256' => $hash])
            ->log('measurement_receipt_downloaded');

        return $storage->preview(
            $path,
            $mime,
            'comprovante-pagamento-'.$payment->getKey().'-v'.($evidence?->version ?? 'legado').'.'.pathinfo($path, PATHINFO_EXTENSION),
            $disk,
        );
    }
}
