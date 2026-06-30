<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Emission;
use App\Services\Reports\EmissionMonthlyReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmissionMonthlyReportController extends Controller
{
    public function __invoke(
        Request $request,
        Emission $emission,
        EmissionMonthlyReportService $service,
    ): Response {
        abort_unless(auth()->user()?->can('reports.view') ?? false, Response::HTTP_FORBIDDEN);

        $referenceMonth = $this->resolveReferenceMonth($request->query('reference_month'));
        $referenceMonthEnd = $this->resolveOptionalMonth($request->query('reference_month_end'));

        // Multi-mês apenas quando o mês final é informado e difere do inicial.
        if ($referenceMonthEnd instanceof CarbonImmutable && ! $referenceMonthEnd->equalTo($referenceMonth)) {
            $data = $service->buildConsolidated($emission, $referenceMonth, $referenceMonthEnd);

            return Pdf::loadView('pdf.emission-monthly-report-consolidated', $data)
                ->stream($service->consolidatedFileName($emission, $referenceMonth, $referenceMonthEnd));
        }

        $data = $service->build($emission, $referenceMonth);

        return Pdf::loadView('pdf.emission-monthly-report', $data)
            ->stream($service->fileName($emission, $referenceMonth));
    }

    private function resolveReferenceMonth(mixed $value): CarbonImmutable
    {
        return $this->resolveOptionalMonth($value) ?? CarbonImmutable::now()->startOfMonth();
    }

    private function resolveOptionalMonth(mixed $value): ?CarbonImmutable
    {
        if (is_string($value) && $value !== '') {
            $normalized = preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? $value.'-01' : $value;

            try {
                return CarbonImmutable::parse($normalized)->startOfMonth();
            } catch (\Throwable) {
                // Valor inválido é ignorado.
            }
        }

        return null;
    }
}
