<?php

namespace App\Http\Controllers\Admin;

use App\Domain\PuCalculator\Services\PuCurveExportService;
use App\Http\Controllers\Controller;
use App\Models\Emission;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmissionPuCurveExportController extends Controller
{
    public function __invoke(Request $request, Emission $emission, PuCurveExportService $exportService): StreamedResponse
    {
        abort_unless(auth()->user()?->can('emissions.view') ?? false, Response::HTTP_FORBIDDEN);

        $calculationVersion = $request->string('calculation_version')->toString();

        try {
            return $exportService->download(
                $emission,
                filled($calculationVersion) ? $calculationVersion : null,
            );
        } catch (InvalidArgumentException $exception) {
            abort(Response::HTTP_NOT_FOUND, $exception->getMessage());
        }
    }
}
