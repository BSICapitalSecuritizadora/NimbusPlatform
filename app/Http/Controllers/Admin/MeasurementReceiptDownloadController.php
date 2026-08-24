<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MeasurementPayment;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeasurementReceiptDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        MeasurementPayment $payment,
        DocumentStorageService $storage,
    ): BinaryFileResponse|StreamedResponse {
        $payment->loadMissing('measurement');
        Gate::authorize('view', $payment->measurement);

        abort_unless(
            filled($payment->receipt_path)
                && $storage->exists($payment->receipt_path, $payment->resolved_receipt_disk),
            404,
        );

        activity('measurement_file_access')
            ->performedOn($payment->measurement)
            ->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->getKey(), 'sha256' => $payment->receipt_sha256])
            ->log('measurement_receipt_downloaded');

        return $storage->preview(
            $payment->receipt_path,
            $payment->receipt_mime_type,
            'comprovante-pagamento-'.$payment->getKey().'.'.pathinfo($payment->receipt_path, PATHINFO_EXTENSION),
            $payment->resolved_receipt_disk,
        );
    }
}
