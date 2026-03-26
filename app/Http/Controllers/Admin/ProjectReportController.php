<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProposalProject;
use Barryvdh\DomPDF\Facade\Pdf;

class ProjectReportController extends Controller
{
    public function generateReport(ProposalProject $project)
    {
        $project->load(['characteristics.unitTypes']);

        $pdf = Pdf::loadView('pdf.project-report', compact('project'));

        return $pdf->download("relatorio-empreendimento-{$project->id}.pdf");
    }

    public function analyticalReport(ProposalProject $project)
    {
        $project->load(['characteristics.unitTypes', 'indicators']);

        // Calculations based on NimbusForms logic
        $total_unidades = max(1, (int) $project->units_total);
        $total_recebiveis = ProposalProject::calculatePaymentFlowTotal(
            $project->value_received,
            $project->value_until_keys,
            $project->value_post_keys,
        );
        $total_recebiveis = max(0.01, $total_recebiveis);

        $data = [
            'project' => $project,
            'percent_vendidas' => ($project->units_unpaid / $total_unidades) * 100,
            'percent_quitadas' => ($project->units_paid / $total_unidades) * 100,
            'percent_estoque' => ($project->units_stock / $total_unidades) * 100,
            'percent_permutadas' => ($project->units_exchanged / $total_unidades) * 100,

            'valor_total_total' => ProposalProject::calculateSalesValuesTotal(
                $project->value_paid,
                $project->value_unpaid,
                $project->value_stock,
            ),
            'valor_total_recebiveis' => $total_recebiveis,

            'percent_recebido' => ($project->value_received / $total_recebiveis) * 100,
            'percent_obra' => ($project->value_until_keys / $total_recebiveis) * 100,
            'percent_chaves' => ($project->value_post_keys / $total_recebiveis) * 100,

            'valor_terreno_m2' => $project->land_area > 0 ? $project->land_market_value / $project->land_area : 0,
            'custo_construcao_m2' => $project->land_area > 0 ? $project->cost_total / $project->land_area : 0,

            'financiamento_custo_obra' => $project->cost_total > 0 ? ($project->value_requested / $project->cost_total) * 100 : 0,
            // (More calculations can be added as needed following the analytical.php logic)
        ];

        $pdf = Pdf::loadView('pdf.project-analytical', $data);

        return $pdf->download("relatorio-analitico-{$project->id}.pdf");
    }
}
