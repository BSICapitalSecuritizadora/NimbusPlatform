<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Emission;
use App\Services\Reports\EmissionMonthlyReportService;
use App\Support\SalesBoards\CompetenceCalendar;
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

    /**
     * Sem competência informada, vale o mês de negócio anterior -- o mesmo
     * padrão da tela de Relatórios e das garantias: o quadro de um mês só
     * existe no seguinte.
     */
    private function resolveReferenceMonth(mixed $value): CarbonImmutable
    {
        return $this->resolveOptionalMonth($value) ?? CompetenceCalendar::lastClosedMonth();
    }

    /**
     * Omissão cai no padrão; valor ilegível falha visível.
     *
     * Antes, um texto que não era mês virava o mês corrente em silêncio, e o
     * PDF saía de uma competência que ninguém pediu com o rótulo de que a
     * escolha foi respeitada. Aceita `AAAA-MM` e `AAAA-MM-DD`, com data válida;
     * o resto é 422.
     */
    private function resolveOptionalMonth(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', $value, $matches) !== 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Competência inválida. Use o formato AAAA-MM.');
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = isset($matches[3]) ? (int) $matches[3] : 1;

        if (! checkdate($month, $day, $year)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Competência inválida. Use o formato AAAA-MM.');
        }

        return CarbonImmutable::create($year, $month, 1);
    }
}
