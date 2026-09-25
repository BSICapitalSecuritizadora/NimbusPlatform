<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MeasurementPayment;
use App\Services\DocumentStorageService;
use App\Services\MeasurementPaymentFinancialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeasurementPaymentSupportDownloadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, MeasurementPayment $payment, MeasurementPaymentFinancialService $financial, DocumentStorageService $storage): BinaryFileResponse|StreamedResponse
    {
        Gate::authorize('view', $payment->measurement);
        abort_unless((int) $payment->operation_id === (int) $payment->measurement->operation_id, 404);
        $support = $payment->financial_assessment['support'] ?? null;
        abort_unless(is_array($support), 404);
        try {
            $financial->ensureSupportIntegrity($support);
        } catch (ValidationException) {
            abort(404, 'Documento de suporte indisponível.');
        }
        activity('measurement_file_access')->performedOn($payment->measurement)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'sha256' => $support['sha256']])->log('financial_support_downloaded');

        return $storage->preview($support['path'], $support['mime_type'], $support['name'], $support['disk']);
    }
}
