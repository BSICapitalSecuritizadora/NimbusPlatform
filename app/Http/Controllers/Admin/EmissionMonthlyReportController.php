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

        $data = $service->build($emission, $referenceMonth);

        return Pdf::loadView('pdf.emission-monthly-report', $data)
            ->stream($service->fileName($emission, $referenceMonth));
    }

    private function resolveReferenceMonth(mixed $value): CarbonImmutable
    {
        if (is_string($value) && $value !== '') {
            $normalized = preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? $value.'-01' : $value;

            try {
                return CarbonImmutable::parse($normalized)->startOfMonth();
            } catch (\Throwable) {
                // Valor inválido cai no padrão (mês atual) abaixo.
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }
}
