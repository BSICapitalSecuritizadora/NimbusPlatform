<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MeasurementAsset;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeasurementAssetDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        MeasurementAsset $asset,
        DocumentStorageService $storage,
    ): BinaryFileResponse|StreamedResponse {
        $asset->loadMissing('measurement');
        Gate::authorize('view', $asset->measurement);

        abort_unless(
            filled($asset->storage_path) && $storage->exists($asset->storage_path, $asset->resolved_storage_disk),
            404,
        );

        activity('measurement_file_access')
            ->performedOn($asset->measurement)
            ->causedBy($request->user())
            ->withProperties(['asset_id' => $asset->getKey(), 'sha256' => $asset->sha256])
            ->log('measurement_asset_downloaded');

        return $storage->preview(
            $asset->storage_path,
            $asset->mime_type,
            $asset->filename ?: basename($asset->storage_path),
            $asset->resolved_storage_disk,
        );
    }
}
