<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\Proposals\ProposalResource;
use App\Http\Controllers\Controller;
use App\Models\ProposalProject;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProjectReportController extends Controller
{
    public function generateReport(ProposalProject $project): Response
    {
        Gate::authorize('proposals.view');

        $proposal = $project->proposal()->firstOrFail();
        abort_unless(ProposalResource::canView($proposal), Response::HTTP_FORBIDDEN);

        $project->load(['characteristics.unitTypes']);

        $pdf = Pdf::loadView('pdf.project-report', compact('project'));

        return $pdf->download("relatorio-empreendimento-{$project->id}.pdf");
    }

    public function analyticalReport(ProposalProject $project): Response
    {
        Gate::authorize('proposals.view');

        $proposal = $project->proposal()->firstOrFail();
        abort_unless(ProposalResource::canView($proposal), Response::HTTP_FORBIDDEN);

        $project->load(['characteristics.unitTypes', 'indicators']);

        // Calculations based on NimbusForms logic
        $total_unidades = max(1, (int) $project->units_total);
        $total_recebiveis = ProposalProject::calculatePaymentFlowTotal(
            $project->received_value,
            $project->value_until_keys,
            $project->value_after_keys,
        );
        $total_recebiveis = max(0.01, $total_recebiveis);

        $data = [
            'project' => $project,
            'percent_vendidas' => ($project->unpaid_units / $total_unidades) * 100,
            'percent_quitadas' => ($project->paid_units / $total_unidades) * 100,
            'percent_estoque' => ($project->stock_units / $total_unidades) * 100,
            'percent_permutadas' => ($project->exchanged_units / $total_unidades) * 100,

            'valor_total_total' => ProposalProject::calculateSalesValuesTotal(
                $project->paid_sales_value,
                $project->unpaid_sales_value,
                $project->stock_sales_value,
            ),
            'valor_total_recebiveis' => $total_recebiveis,

            'percent_recebido' => ($project->received_value / $total_recebiveis) * 100,
            'percent_obra' => ($project->value_until_keys / $total_recebiveis) * 100,
            'percent_chaves' => ($project->value_after_keys / $total_recebiveis) * 100,

            'valor_terreno_m2' => $project->land_area > 0 ? $project->land_market_value / $project->land_area : 0,
            'custo_construcao_m2' => $project->land_area > 0 ? $project->total_cost / $project->land_area : 0,

            'financiamento_custo_obra' => $project->total_cost > 0 ? ($project->requested_amount / $project->total_cost) * 100 : 0,
        ];

        $pdf = Pdf::loadView('pdf.project-analytical', $data);

        return $pdf->download("relatorio-analitico-{$project->id}.pdf");
    }
}
