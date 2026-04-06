<?php

namespace App\Livewire\Proposals;

use App\Actions\Proposals\StoreProposalContinuationData;
use App\Livewire\Forms\ContinuationFormObject;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use App\Models\ProposalProject;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('site.layout')]
#[Title('Formulário de Empreendimento')]
class ContinuationForm extends Component
{
    use WithFileUploads;

    public ContinuationFormObject $form;

    public int $accessId;

    public int $proposalId;

    public ?string $successMessage = null;

    public function mount(ProposalContinuationAccess $access): void
    {
        $this->ensureAuthorized(request(), $access);

        $proposal = $this->loadProposal($access);

        $this->accessId = $access->id;
        $this->proposalId = $proposal->id;

        $this->form->fillFromProposal($proposal);
    }

    public function render(): View
    {
        $proposal = $this->proposal();
        $firstProject = $proposal->projects->first();
        $canEditProposal = $proposal->canBeCompletedByRequester();
        $showReadonlySummary = $proposal->projects->isNotEmpty() && ! $canEditProposal;

        return view('livewire.proposals.continuation-form', [
            'access' => $this->access(),
            'proposal' => $proposal,
            'firstProject' => $firstProject,
            'projects' => $this->form->projects,
            'unitTypes' => $this->form->unitTypes,
            'uploads' => $this->form->uploads,
            'projectCount' => count($this->form->projects),
            'fileCount' => $proposal->files->count() + count($this->form->uploads),
            'showReadonlySummary' => $showReadonlySummary,
            'companyAddress' => $this->companyAddress($proposal),
            'companyRegion' => $this->companyRegion($proposal),
            'contactPhones' => $this->contactPhones($proposal),
            'operationDetails' => $this->operationDetails($firstProject),
            'projectSummaries' => $this->projectSummaries($proposal),
            'attachmentSummaries' => $this->attachmentSummaries($proposal->files),
        ]);
    }

    public function addProject(): void
    {
        $this->form->addProject();
    }

    public function removeProject(int $index): void
    {
        $this->form->removeProject($index);
    }

    public function addUnitType(): void
    {
        $this->form->addUnitType();
    }

    public function removeUnitType(int $index): void
    {
        $this->form->removeUnitType($index);
    }

    public function save(StoreProposalContinuationData $storeProposalContinuationData): void
    {
        $access = $this->access();

        $this->ensureAuthorized(request(), $access);

        $proposal = $this->proposal();

        abort_unless($proposal->canBeCompletedByRequester(), 403);

        $this->form->save(
            $proposal,
            $storeProposalContinuationData,
            $this->proposalRelations(),
        );

        $this->successMessage = 'Empreendimento(s) salvo(s) com sucesso.';
    }

    protected function proposal(): Proposal
    {
        return Proposal::query()
            ->with($this->proposalRelations())
            ->findOrFail($this->proposalId);
    }

    protected function access(): ProposalContinuationAccess
    {
        return ProposalContinuationAccess::query()->findOrFail($this->accessId);
    }

    protected function loadProposal(ProposalContinuationAccess $access): Proposal
    {
        return $access->proposal()
            ->with($this->proposalRelations())
            ->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    protected function proposalRelations(): array
    {
        return [
            'company.sectors',
            'contact',
            'projects.characteristics.unitTypes',
            'files',
        ];
    }

    protected function ensureAuthorized(Request $request, ProposalContinuationAccess $access): void
    {
        $this->ensureMagicLinkConfirmed($request, $access);

        abort_unless($this->isAuthorized($request, $access), 403);

        $access->markAuthorizedUsage();
    }

    protected function ensureMagicLinkConfirmed(Request $request, ProposalContinuationAccess $access): void
    {
        abort_unless($this->hasSessionKey($request, $access->magicLinkSessionKey()) && $access->isActive(), 403);
    }

    protected function isAuthorized(Request $request, ProposalContinuationAccess $access): bool
    {
        return $this->hasSessionKey($request, $access->verifiedSessionKey()) && $access->isActive();
    }

    protected function hasSessionKey(Request $request, string $key): bool
    {
        if ($request->hasSession()) {
            return $request->session()->has($key);
        }

        return app('session.store')->has($key);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    protected function operationDetails(?ProposalProject $firstProject): array
    {
        if (! $firstProject) {
            return [];
        }

        return [
            ['label' => 'Nome do Empreendimento', 'value' => $firstProject->development_name ?: '—'],
            ['label' => 'Site', 'value' => $firstProject->website_url ?: '—'],
            ['label' => 'Valor Solicitado', 'value' => 'R$ '.$firstProject->formatted_requested_amount],
            ['label' => 'Valor de Mercado do Terreno', 'value' => 'R$ '.$firstProject->formatted_land_market_value],
            ['label' => 'Área do Terreno', 'value' => number_format((float) $firstProject->land_area, 2, ',', '.').' m²'],
            ['label' => 'Lançamento', 'value' => $firstProject->formatted_launch_month],
            ['label' => 'Lançamento das Vendas', 'value' => $firstProject->formatted_sales_launch_month],
            ['label' => 'Início das Obras', 'value' => $firstProject->formatted_construction_start_month],
            ['label' => 'Previsão de Entrega', 'value' => $firstProject->formatted_delivery_forecast_month],
            ['label' => 'Prazo Remanescente', 'value' => ((int) $firstProject->remaining_months).' meses'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function projectSummaries(Proposal $proposal): array
    {
        return $proposal->projects->map(function (ProposalProject $project): array {
            return [
                'name' => $project->name,
                'region' => collect([
                    $project->neighborhood,
                    trim(implode(' - ', array_filter([$project->city, $project->state]))),
                    $project->zip_code ? 'CEP '.$project->zip_code : null,
                ])->filter()->implode(' • ') ?: 'Localização não informada.',
                'address' => collect([
                    trim(implode(', ', array_filter([$project->street, $project->address_number]))),
                    $project->address_complement,
                ])->filter()->implode(', '),
                'site' => $project->website_url ?: '—',
                'metrics' => [
                    ['label' => 'Unidades Totais', 'value' => (string) $project->units_total],
                    ['label' => 'Vendas (%)', 'value' => $project->formatted_sales_percentage],
                    ['label' => 'VGV Total', 'value' => 'R$ '.$project->formatted_gross_sales_value],
                    ['label' => 'Fluxo de Pagamento', 'value' => 'R$ '.$project->formatted_payment_flow_total],
                ],
                'unit_summary' => [
                    ['label' => 'Permutadas', 'value' => (string) $project->exchanged_units],
                    ['label' => 'Quitadas', 'value' => (string) $project->paid_units],
                    ['label' => 'Não Quitadas', 'value' => (string) $project->unpaid_units],
                    ['label' => 'Estoque', 'value' => (string) $project->stock_units],
                    ['label' => 'Total', 'value' => (string) $project->units_total],
                    ['label' => '% Vendidas', 'value' => $project->formatted_sales_percentage],
                ],
                'financial_summary' => [
                    ['label' => 'Custo Incorrido', 'value' => 'R$ '.$project->formatted_incurred_cost],
                    ['label' => 'Custo a Incorrer', 'value' => 'R$ '.$project->formatted_cost_to_incur],
                    ['label' => 'Custo Total', 'value' => 'R$ '.$project->formatted_total_cost],
                    ['label' => 'Estágio da Obra', 'value' => $project->formatted_work_stage_percentage],
                    ['label' => 'VGV Total', 'value' => 'R$ '.$project->formatted_gross_sales_value],
                    ['label' => 'Recebíveis', 'value' => 'R$ '.$project->formatted_payment_flow_total],
                ],
                'sales_values' => [
                    ['label' => 'Quitadas', 'value' => 'R$ '.$project->formatted_paid_sales_value],
                    ['label' => 'Vendidas', 'value' => 'R$ '.$project->formatted_unpaid_sales_value],
                    ['label' => 'Estoque', 'value' => 'R$ '.$project->formatted_stock_sales_value],
                    ['label' => 'VGV Total', 'value' => 'R$ '.$project->formatted_gross_sales_value],
                ],
                'payment_flow' => [
                    ['label' => 'Valor já Recebido', 'value' => 'R$ '.$project->formatted_received_value],
                    ['label' => 'A receber até as chaves', 'value' => 'R$ '.$project->formatted_value_until_keys],
                    ['label' => 'A receber pós chaves', 'value' => 'R$ '.$project->formatted_value_after_keys],
                    ['label' => 'Total', 'value' => 'R$ '.$project->formatted_payment_flow_total],
                ],
                'characteristics' => $project->characteristics ? [
                    'blocks' => $project->characteristics->blocks,
                    'floors' => $project->characteristics->floors,
                    'typical_floors' => $project->characteristics->typical_floors,
                    'units_per_floor' => $project->characteristics->units_per_floor,
                    'total_units' => $project->characteristics->total_units,
                    'unit_types' => $project->characteristics->unitTypes
                        ->sortBy('order')
                        ->values()
                        ->map(fn ($unitType): array => [
                            'order' => $unitType->order,
                            'total_units' => $unitType->total_units,
                            'bedrooms' => $unitType->bedrooms ?: '—',
                            'parking_spaces' => $unitType->parking_spaces ?: '—',
                            'usable_area' => $unitType->formatted_usable_area.' m²',
                            'average_price' => 'R$ '.$unitType->formatted_average_price,
                            'price_per_square_meter' => 'R$ '.$unitType->formatted_price_per_square_meter,
                        ])
                        ->all(),
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, ProposalFile>  $files
     * @return array<int, array{original_name: string, meta: string, url: string}>
     */
    protected function attachmentSummaries($files): array
    {
        return $files->map(function (ProposalFile $file): array {
            return [
                'original_name' => $file->original_name,
                'meta' => collect([
                    $file->file_size ? number_format($file->file_size / 1024, 0, ',', '.').' KB' : null,
                    $file->created_at?->format('d/m/Y H:i'),
                ])->filter()->implode(' • ') ?: 'Disponível para download.',
                'url' => route('site.proposal.continuation.files.download', [$this->access(), $file]),
            ];
        })->values()->all();
    }

    protected function companyAddress(Proposal $proposal): string
    {
        return collect([
            $proposal->company->logradouro,
            $proposal->company->numero,
            $proposal->company->complemento,
        ])->filter()->implode(', ');
    }

    protected function companyRegion(Proposal $proposal): string
    {
        return collect([
            $proposal->company->bairro,
            trim(implode(' - ', array_filter([$proposal->company->cidade, $proposal->company->estado]))),
            $proposal->company->cep ? 'CEP '.$proposal->company->cep : null,
        ])->filter()->implode(' • ');
    }

    protected function contactPhones(Proposal $proposal): string
    {
        return collect([
            $proposal->contact->phone_personal,
            $proposal->contact->phone_company,
        ])->filter()->implode(' • ');
    }
}
