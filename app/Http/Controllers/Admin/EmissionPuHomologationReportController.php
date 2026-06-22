<?php

namespace App\Http\Controllers\Admin;

use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuHomologationReportService;
use App\Http\Controllers\Controller;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class EmissionPuHomologationReportController extends Controller
{
    public function __invoke(
        Emission $emission,
        EmissionPuCurveVersion $version,
        PuHomologationReportService $reportService,
        PuAuditLogService $auditLogService,
    ): Response {
        abort_unless(auth()->user()?->can('pu.curve.export') ?? false, Response::HTTP_FORBIDDEN);
        abort_unless($version->emission_id === $emission->id, Response::HTTP_NOT_FOUND);

        $data = $reportService->build($version);

        $auditLogService->logHomologationReportDownloaded($emission, $version->calculation_version, auth()->id());

        return Pdf::loadView('pdf.pu-homologation', $data)
            ->download($reportService->fileName($version));
    }
}
