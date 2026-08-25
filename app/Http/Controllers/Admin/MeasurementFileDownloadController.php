<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Measurement;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeasurementFileDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Measurement $measurement,
        DocumentStorageService $storage,
    ): BinaryFileResponse|StreamedResponse {
        Gate::authorize('view', $measurement);

        abort_unless(
            filled($measurement->storage_path)
                && $storage->isAllowedMeasurementDisk($measurement->resolved_storage_disk)
                && $storage->exists($measurement->storage_path, $measurement->resolved_storage_disk),
            404,
        );

        activity('measurement_file_access')
            ->performedOn($measurement)
            ->causedBy($request->user())
            ->withProperties(['sha256' => $measurement->sha256])
            ->log('measurement_file_downloaded');

        return $storage->preview(
            $measurement->storage_path,
            $measurement->mime_type,
            $measurement->filename ?: basename($measurement->storage_path),
            $measurement->resolved_storage_disk,
        );
    }
}
