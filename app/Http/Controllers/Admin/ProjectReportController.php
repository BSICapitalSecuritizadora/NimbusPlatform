<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Proposals\CalculateProjectIndicators;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\ProposalProject;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProjectReportController extends Controller
{
    public function generateReport(ProposalProject $project, CalculateProjectIndicators $calculator): Response
    {
        Gate::authorize('proposals.view');

        $proposal = $project->proposal()->firstOrFail();
        abort_unless(ProposalResource::canView($proposal), Response::HTTP_FORBIDDEN);

        $project->load(['proposal.company.sectors', 'proposal.contact', 'characteristics.unitTypes', 'indicators']);
        $analysis = $calculator->handle($project);

        $pdf = Pdf::loadView('pdf.project-report', compact('project', 'analysis'));

        return $pdf->download("relatorio-empreendimento-{$project->id}.pdf");
    }

    public function analyticalReport(ProposalProject $project, CalculateProjectIndicators $calculator): Response
    {
        Gate::authorize('proposals.view');

        $proposal = $project->proposal()->firstOrFail();
        abort_unless(ProposalResource::canView($proposal), Response::HTTP_FORBIDDEN);

        $project->load(['proposal.company.sectors', 'proposal.contact', 'characteristics.unitTypes', 'indicators']);
        $analysis = $calculator->handle($project);

        $pdf = Pdf::loadView('pdf.project-analytical', compact('project', 'analysis'));

        return $pdf->download("relatorio-analitico-{$project->id}.pdf");
    }

    public function proposalReport(Proposal $proposal, CalculateProjectIndicators $calculator): Response
    {
        Gate::authorize('proposals.view');
        abort_unless(ProposalResource::canView($proposal), Response::HTTP_FORBIDDEN);

        $proposal->load([
            'company.sectors',
            'contact',
            'representative',
            'projects.characteristics.unitTypes',
            'projects.indicators',
            'files',
        ]);

        $projectAnalyses = $proposal->projects
            ->mapWithKeys(fn (ProposalProject $project): array => [$project->id => $calculator->handle($project)]);

        $pdf = Pdf::loadView('pdf.proposal-report', compact('proposal', 'projectAnalyses'));

        return $pdf->download("relatorio-geral-proposta-{$proposal->id}.pdf");
    }
}
