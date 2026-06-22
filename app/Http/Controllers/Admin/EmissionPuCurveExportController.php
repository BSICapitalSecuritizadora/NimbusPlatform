<?php

namespace App\Http\Controllers\Admin;

use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveExportService;
use App\Http\Controllers\Controller;
use App\Models\Emission;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmissionPuCurveExportController extends Controller
{
    public function __invoke(
        Request $request,
        Emission $emission,
        PuCurveExportService $exportService,
        PuAuditLogService $auditLogService,
    ): StreamedResponse {
        abort_unless(auth()->user()?->can('pu.curve.export') ?? false, Response::HTTP_FORBIDDEN);

        $calculationVersion = $request->string('calculation_version')->toString();
        $resolvedVersion = filled($calculationVersion) ? $calculationVersion : null;

        try {
            $response = $exportService->download($emission, $resolvedVersion);
        } catch (InvalidArgumentException $exception) {
            abort(Response::HTTP_NOT_FOUND, $exception->getMessage());
        }

        $auditLogService->logExport($emission, $resolvedVersion, auth()->id());

        return $response;
    }
}
