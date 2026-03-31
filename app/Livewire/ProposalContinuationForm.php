<?php

namespace App\Livewire;

use App\Actions\Proposals\StoreProposalContinuationData;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use App\Models\ProposalProject;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ProposalContinuationForm extends Component
{
    use WithFileUploads;

    public int $accessId;

    public int $proposalId;

    public ?string $successMessage = null;

    /** @var array<string, mixed> */
    public array $operation = [];

    /** @var array<string, mixed> */
    public array $characteristics = [];

    /** @var array<int, array<string, mixed>> */
    public array $projects = [];

    /** @var array<int, array<string, mixed>> */
    public array $unitTypes = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(ProposalContinuationAccess $access, Proposal $proposal): void
    {
        $this->accessId = $access->id;
        $this->proposalId = $proposal->id;

        $this->fillFromProposal($proposal->loadMissing($this->proposalRelations()));
    }

    public function render(): View
    {
        $proposal = $this->proposal();
        $firstProject = $proposal->projects->first();
        $canEditProposal = $proposal->canBeCompletedByRequester();
        $showReadonlySummary = $proposal->projects->isNotEmpty() && ! $canEditProposal;

        return view('livewire.proposal-continuation-form', [
            'access' => $this->access(),
            'proposal' => $proposal,
            'firstProject' => $firstProject,
            'projectCount' => count($this->projects),
            'fileCount' => $proposal->files->count() + count($this->uploads),
            'showReadonlySummary' => $showReadonlySummary,
            'companyAddress' => $this->companyAddress($proposal),
            'companyRegion' => $this->companyRegion($proposal),
            'contactPhones' => $this->contactPhones($proposal),
            'operationDetails' => $this->operationDetails($firstProject),
            'projectSummaries' => $this->projectSummaries($proposal),
            'attachmentSummaries' => $this->attachmentSummaries($proposal->files),
        ]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['operation.valor_solicitado', 'operation.valor_mercado_terreno'], true)) {
            $this->formatMoneyProperty($property);
        }

        if ($property === 'operation.cep') {
            $this->operation['cep'] = $this->formatCep((string) ($this->operation['cep'] ?? ''));
        }

        if (in_array($property, ['operation.inicio_obras', 'operation.previsao_entrega'], true)) {
            $this->syncRemainingMonths();
        }

        if (preg_match('/^projects\.(\d+)\./', $property, $matches) === 1) {
            $projectIndex = (int) $matches[1];

            if (preg_match('/\.(cost_incurred|cost_to_incur|value_paid|value_unpaid|value_stock|value_received|value_until_keys|value_post_keys)$/', $property) === 1) {
                $this->formatMoneyProperty($property);
            }

            $this->syncProject($projectIndex);
        }

        if (preg_match('/^unitTypes\.(\d+)\./', $property, $matches) === 1) {
            $typeIndex = (int) $matches[1];

            if (str_ends_with($property, '.average_price')) {
                $this->formatMoneyProperty($property);
            }

            $this->syncUnitType($typeIndex);
        }

        if (in_array($property, [
            'characteristics.blocks',
            'characteristics.typical_floors',
            'characteristics.units_per_floor',
        ], true)) {
            $this->syncCharacteristicsTotal();
        }
    }

    public function addProject(): void
    {
        $this->projects[] = $this->blankProject();
    }

    public function removeProject(int $index): void
    {
        if (count($this->projects) === 1) {
            return;
        }

        unset($this->projects[$index]);

        $this->projects = array_values($this->projects);
    }

    public function addUnitType(): void
    {
        $this->unitTypes[] = $this->blankUnitType();
    }

    public function removeUnitType(int $index): void
    {
        if (count($this->unitTypes) === 1) {
            return;
        }

        unset($this->unitTypes[$index]);

        $this->unitTypes = array_values($this->unitTypes);

        foreach (array_keys($this->unitTypes) as $typeIndex) {
            $this->syncUnitType($typeIndex);
        }
    }

    public function lookupCep(): void
    {
        $cep = preg_replace('/\D/', '', (string) ($this->operation['cep'] ?? ''));

        $this->operation['cep'] = $this->formatCep($cep);

        if (strlen($cep) !== 8) {
            return;
        }

        $response = Http::get("https://viacep.com.br/ws/{$cep}/json/");

        if (! $response->ok() || $response->json('erro')) {
            return;
        }

        $this->operation['logradouro'] = (string) ($response->json('logradouro') ?? '');
        $this->operation['bairro'] = (string) ($response->json('bairro') ?? '');
        $this->operation['cidade'] = (string) ($response->json('localidade') ?? '');
        $this->operation['estado'] = (string) ($response->json('uf') ?? '');
    }

    public function save(StoreProposalContinuationData $storeProposalContinuationData): void
    {
        $payload = [
            'operation' => $this->normalizeValidationPayload($this->operation),
            'characteristics' => $this->normalizeValidationPayload($this->characteristics),
            'projects' => $this->normalizeValidationPayload($this->projects),
            'unitTypes' => $this->normalizeValidationPayload($this->unitTypes),
            'uploads' => $this->uploads,
        ];

        $validated = validator($payload, $this->rules(), $this->messages())
            ->after(function (Validator $validator) use ($payload): void {
                $operation = $payload['operation'];

                if (
                    filled($operation['inicio_obras'] ?? null)
                    && filled($operation['previsao_entrega'] ?? null)
                    && ($operation['previsao_entrega'] < $operation['inicio_obras'])
                ) {
                    $validator->errors()->add('operation.previsao_entrega', 'A previsão de entrega deve ser posterior ao início das obras.');
                }
            })->validate();

        $storeProposalContinuationData->handle(
            $this->proposal(),
            [
                'operation' => $validated['operation'],
                'characteristics' => $validated['characteristics'],
                'projects' => $validated['projects'],
                'unit_types' => $validated['unitTypes'],
            ],
            $this->uploads,
        );

        $this->successMessage = 'Empreendimento(s) salvo(s) com sucesso.';
        $this->uploads = [];
        $this->fillFromProposal($this->proposal()->fresh($this->proposalRelations()));
    }

    protected function normalizeValidationPayload(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeValidationPayload($item), $value);
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'operation.nome' => ['required', 'string', 'max:255'],
            'operation.site' => ['nullable', 'url', 'max:255'],
            'operation.valor_solicitado' => ['required', 'string', 'max:50'],
            'operation.valor_mercado_terreno' => ['nullable', 'string', 'max:50'],
            'operation.area_terreno' => ['required', 'numeric', 'min:0'],
            'operation.data_lancamento' => ['required', 'date_format:Y-m'],
            'operation.lancamento_vendas' => ['required', 'date_format:Y-m'],
            'operation.inicio_obras' => ['required', 'date_format:Y-m'],
            'operation.previsao_entrega' => ['required', 'date_format:Y-m'],
            'operation.prazo_remanescente' => ['nullable', 'integer', 'min:0'],
            'operation.cep' => ['required', 'string', 'max:9'],
            'operation.logradouro' => ['required', 'string', 'max:255'],
            'operation.complemento' => ['nullable', 'string', 'max:255'],
            'operation.numero' => ['required', 'string', 'max:50'],
            'operation.bairro' => ['required', 'string', 'max:255'],
            'operation.cidade' => ['required', 'string', 'max:255'],
            'operation.estado' => ['required', 'string', 'size:2'],
            'projects' => ['required', 'array', 'min:1'],
            'projects.*.id' => [
                'nullable',
                'integer',
                Rule::exists('proposal_projects', 'id')->where(
                    fn ($query) => $query->where('proposal_id', $this->proposalId),
                ),
            ],
            'projects.*.name' => ['required', 'string', 'max:255'],
            'projects.*.units_exchanged' => ['nullable', 'integer', 'min:0'],
            'projects.*.units_paid' => ['nullable', 'integer', 'min:0'],
            'projects.*.units_unpaid' => ['nullable', 'integer', 'min:0'],
            'projects.*.units_stock' => ['nullable', 'integer', 'min:0'],
            'projects.*.cost_incurred' => ['nullable', 'string', 'max:50'],
            'projects.*.cost_to_incur' => ['nullable', 'string', 'max:50'],
            'projects.*.value_paid' => ['nullable', 'string', 'max:50'],
            'projects.*.value_unpaid' => ['nullable', 'string', 'max:50'],
            'projects.*.value_stock' => ['nullable', 'string', 'max:50'],
            'projects.*.value_received' => ['nullable', 'string', 'max:50'],
            'projects.*.value_until_keys' => ['nullable', 'string', 'max:50'],
            'projects.*.value_post_keys' => ['nullable', 'string', 'max:50'],
            'characteristics.blocks' => ['required', 'integer', 'min:1'],
            'characteristics.floors' => ['required', 'integer', 'min:1'],
            'characteristics.typical_floors' => ['required', 'integer', 'min:1'],
            'characteristics.units_per_floor' => ['required', 'integer', 'min:1'],
            'characteristics.total_units' => ['nullable', 'integer', 'min:1'],
            'unitTypes' => ['required', 'array', 'min:1'],
            'unitTypes.*.total' => ['required', 'integer', 'min:1'],
            'unitTypes.*.bedrooms' => ['required', 'string', 'max:255'],
            'unitTypes.*.parking_spaces' => ['required', 'string', 'max:255'],
            'unitTypes.*.useful_area' => ['required', 'numeric', 'gt:0'],
            'unitTypes.*.average_price' => ['required', 'string', 'max:50'],
            'uploads' => ['nullable', 'array'],
            'uploads.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'operation.nome.required' => 'A denominação principal do empreendimento é obrigatória.',
            'operation.valor_solicitado.required' => 'O valor solicitado para a operação é obrigatório.',
            'operation.area_terreno.required' => 'A área do terreno é obrigatória.',
            'operation.data_lancamento.required' => 'A data de lançamento do empreendimento é obrigatória.',
            'operation.data_lancamento.date_format' => 'A data de lançamento deve estar no formato mm/aaaa.',
            'operation.lancamento_vendas.required' => 'A data de lançamento comercial é obrigatória.',
            'operation.lancamento_vendas.date_format' => 'A data de lançamento das vendas deve estar no formato mm/aaaa.',
            'operation.inicio_obras.required' => 'A data de início das obras é obrigatória.',
            'operation.inicio_obras.date_format' => 'A data de início das obras deve estar no formato mm/aaaa.',
            'operation.previsao_entrega.required' => 'A previsão de entrega do empreendimento é obrigatória.',
            'operation.previsao_entrega.date_format' => 'A previsão de entrega deve estar no formato mm/aaaa.',
            'projects.required' => 'Informe ao menos um empreendimento vinculado à operação.',
            'projects.*.name.required' => 'A identificação de cada empreendimento é obrigatória.',
            'uploads.*.mimes' => 'Os arquivos anexados devem estar nos formatos PDF, DOC, DOCX, XLS, XLSX, PNG, JPG ou JPEG.',
            'uploads.*.max' => 'Cada arquivo não pode exceder 10 MB.',
        ];
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

    protected function fillFromProposal(Proposal $proposal): void
    {
        $firstProject = $proposal->projects->first();

        $this->operation = [
            'nome' => $firstProject?->company_name ?? '',
            'site' => $firstProject?->site ?? '',
            'valor_solicitado' => $firstProject?->formatted_value_requested ?? '',
            'valor_mercado_terreno' => $firstProject?->formatted_land_market_value ?? '',
            'area_terreno' => $firstProject?->land_area ?? '',
            'data_lancamento' => $firstProject?->launch_month ?? '',
            'lancamento_vendas' => $firstProject?->sales_launch_month ?? '',
            'inicio_obras' => $firstProject?->construction_start_month ?? '',
            'previsao_entrega' => $firstProject?->delivery_forecast_month ?? '',
            'prazo_remanescente' => $firstProject?->remaining_months ?? '',
            'cep' => $this->formatCep((string) ($firstProject?->cep ?? '')),
            'logradouro' => $firstProject?->logradouro ?? '',
            'complemento' => $firstProject?->complemento ?? '',
            'numero' => $firstProject?->numero ?? '',
            'bairro' => $firstProject?->bairro ?? '',
            'cidade' => $firstProject?->cidade ?? '',
            'estado' => $firstProject?->estado ?? '',
        ];

        $this->characteristics = [
            'blocks' => $firstProject?->characteristics?->blocks ?? '',
            'floors' => $firstProject?->characteristics?->floors ?? '',
            'typical_floors' => $firstProject?->characteristics?->typical_floors ?? '',
            'units_per_floor' => $firstProject?->characteristics?->units_per_floor ?? '',
            'total_units' => $firstProject?->characteristics?->total_units ?? '',
        ];

        $this->projects = $proposal->projects->isNotEmpty()
            ? $proposal->projects
                ->map(fn (ProposalProject $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'units_exchanged' => $project->units_exchanged,
                    'units_paid' => $project->units_paid,
                    'units_unpaid' => $project->units_unpaid,
                    'units_stock' => $project->units_stock,
                    'units_total' => $project->units_total,
                    'sales_percentage' => number_format((float) $project->sales_percentage, 2, '.', ''),
                    'cost_incurred' => $project->formatted_cost_incurred,
                    'cost_to_incur' => $project->formatted_cost_to_incur,
                    'cost_total' => $project->formatted_cost_total,
                    'work_stage_percentage' => number_format((float) $project->work_stage_percentage, 2, '.', ''),
                    'value_paid' => $project->formatted_value_paid,
                    'value_unpaid' => $project->formatted_value_unpaid,
                    'value_stock' => $project->formatted_value_stock,
                    'value_total_sale' => $project->formatted_value_total_sale,
                    'value_received' => $project->formatted_value_received,
                    'value_until_keys' => $project->formatted_value_until_keys,
                    'value_post_keys' => $project->formatted_value_post_keys,
                ])
                ->values()
                ->all()
            : [$this->blankProject()];

        $this->unitTypes = $firstProject?->characteristics?->unitTypes?->isNotEmpty()
            ? $firstProject->characteristics->unitTypes
                ->sortBy('order')
                ->map(fn ($unitType): array => [
                    'total' => $unitType->total_units,
                    'bedrooms' => $unitType->bedrooms,
                    'parking_spaces' => $unitType->parking_spaces,
                    'useful_area' => $unitType->useful_area,
                    'average_price' => $unitType->formatted_average_price,
                    'price_per_m2' => $unitType->formatted_price_per_m2,
                ])
                ->values()
                ->all()
            : [$this->blankUnitType()];

        $this->uploads = [];
        $this->syncRemainingMonths();
        $this->syncCharacteristicsTotal();

        foreach (array_keys($this->projects) as $projectIndex) {
            $this->syncProject($projectIndex);
        }

        foreach (array_keys($this->unitTypes) as $typeIndex) {
            $this->syncUnitType($typeIndex);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankProject(): array
    {
        return [
            'id' => null,
            'name' => '',
            'units_exchanged' => '',
            'units_paid' => '',
            'units_unpaid' => '',
            'units_stock' => '',
            'units_total' => '',
            'sales_percentage' => '',
            'cost_incurred' => '',
            'cost_to_incur' => '',
            'cost_total' => '',
            'work_stage_percentage' => '',
            'value_paid' => '',
            'value_unpaid' => '',
            'value_stock' => '',
            'value_total_sale' => '',
            'value_received' => '',
            'value_until_keys' => '',
            'value_post_keys' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankUnitType(): array
    {
        return [
            'total' => '',
            'bedrooms' => '',
            'parking_spaces' => '',
            'useful_area' => '',
            'average_price' => '',
            'price_per_m2' => '',
        ];
    }

    protected function syncProject(int $index): void
    {
        $project = $this->projects[$index] ?? null;

        if (! $project) {
            return;
        }

        if ($this->projectIsBlank($project)) {
            $project['units_total'] = '';
            $project['sales_percentage'] = '';
            $project['cost_total'] = '';
            $project['work_stage_percentage'] = '';
            $project['value_total_sale'] = '';
            $this->projects[$index] = $project;

            return;
        }

        $project['units_total'] = ProposalProject::calculateUnitsTotal(
            $project['units_unpaid'],
            $project['units_paid'],
            $project['units_exchanged'],
            $project['units_stock'],
        );

        $project['sales_percentage'] = number_format(ProposalProject::calculateSalesPercentage(
            $project['units_unpaid'],
            $project['units_paid'],
            $project['units_exchanged'],
            $project['units_stock'],
        ), 2, '.', '');

        $costTotal = ProposalProject::calculateCostTotal(
            $project['cost_incurred'],
            $project['cost_to_incur'],
        );

        $project['cost_total'] = ProposalProject::formatCurrencyForDisplay($costTotal);
        $project['work_stage_percentage'] = number_format(ProposalProject::calculateWorkStagePercentage(
            $project['cost_incurred'],
            $costTotal,
        ), 2, '.', '');

        $project['value_total_sale'] = ProposalProject::formatCurrencyForDisplay(
            ProposalProject::calculateSalesValuesTotal(
                $project['value_paid'],
                $project['value_unpaid'],
                $project['value_stock'],
            ),
        );

        $this->projects[$index] = $project;
    }

    protected function syncUnitType(int $index): void
    {
        $unitType = $this->unitTypes[$index] ?? null;

        if (! $unitType) {
            return;
        }

        $averagePrice = ProposalProject::normalizeDecimalValue($unitType['average_price'] ?? null);
        $usefulArea = ProposalProject::normalizeDecimalValue($unitType['useful_area'] ?? null);

        $unitType['price_per_m2'] = $averagePrice > 0 && $usefulArea > 0
            ? ProposalProject::formatCurrencyForDisplay(round($averagePrice / $usefulArea, 2))
            : '';

        $this->unitTypes[$index] = $unitType;
    }

    protected function syncRemainingMonths(): void
    {
        $start = $this->operation['inicio_obras'] ?? null;
        $end = $this->operation['previsao_entrega'] ?? null;

        if (! $start || ! $end) {
            $this->operation['prazo_remanescente'] = '';

            return;
        }

        $startDate = Carbon::createFromFormat('Y-m', (string) $start);
        $endDate = Carbon::createFromFormat('Y-m', (string) $end);

        if (! $startDate || ! $endDate) {
            return;
        }

        $this->operation['prazo_remanescente'] = $startDate->diffInMonths($endDate);
    }

    protected function syncCharacteristicsTotal(): void
    {
        $blocks = (int) ($this->characteristics['blocks'] ?: 0);
        $typicalFloors = (int) ($this->characteristics['typical_floors'] ?: 0);
        $unitsPerFloor = (int) ($this->characteristics['units_per_floor'] ?: 0);

        $this->characteristics['total_units'] = ($blocks > 0 && $typicalFloors > 0 && $unitsPerFloor > 0)
            ? $blocks * $typicalFloors * $unitsPerFloor
            : '';
    }

    protected function formatMoneyProperty(string $property): void
    {
        $value = data_get($this, $property);

        data_set(
            $this,
            $property,
            blank($value) ? '' : ProposalProject::formatCurrencyForDisplay($value),
        );
    }

    protected function formatCep(string $value): string
    {
        $digits = substr(preg_replace('/\D/', '', $value), 0, 8);

        if (strlen($digits) <= 5) {
            return $digits;
        }

        return substr($digits, 0, 5).'-'.substr($digits, 5);
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
            ['label' => 'Nome do Empreendimento', 'value' => $firstProject->company_name ?: '—'],
            ['label' => 'Site', 'value' => $firstProject->site ?: '—'],
            ['label' => 'Valor Solicitado', 'value' => 'R$ '.$firstProject->formatted_value_requested],
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
                    $project->bairro,
                    trim(implode(' - ', array_filter([$project->cidade, $project->estado]))),
                    $project->cep ? 'CEP '.$project->cep : null,
                ])->filter()->implode(' • ') ?: 'Localização não informada.',
                'address' => collect([
                    trim(implode(', ', array_filter([$project->logradouro, $project->numero]))),
                    $project->complemento,
                ])->filter()->implode(', '),
                'site' => $project->site ?: '—',
                'metrics' => [
                    ['label' => 'Unidades Totais', 'value' => (string) $project->units_total],
                    ['label' => 'Vendas (%)', 'value' => $project->formatted_sales_percentage],
                    ['label' => 'VGV Total', 'value' => 'R$ '.$project->formatted_value_total_sale],
                    ['label' => 'Fluxo de Pagamento', 'value' => 'R$ '.$project->formatted_payment_flow_total],
                ],
                'unit_summary' => [
                    ['label' => 'Permutadas', 'value' => (string) $project->units_exchanged],
                    ['label' => 'Quitadas', 'value' => (string) $project->units_paid],
                    ['label' => 'Não Quitadas', 'value' => (string) $project->units_unpaid],
                    ['label' => 'Estoque', 'value' => (string) $project->units_stock],
                    ['label' => 'Total', 'value' => (string) $project->units_total],
                    ['label' => '% Vendidas', 'value' => $project->formatted_sales_percentage],
                ],
                'financial_summary' => [
                    ['label' => 'Custo Incorrido', 'value' => 'R$ '.$project->formatted_cost_incurred],
                    ['label' => 'Custo a Incorrer', 'value' => 'R$ '.$project->formatted_cost_to_incur],
                    ['label' => 'Custo Total', 'value' => 'R$ '.$project->formatted_cost_total],
                    ['label' => 'Estágio da Obra', 'value' => $project->formatted_work_stage_percentage],
                    ['label' => 'VGV Total', 'value' => 'R$ '.$project->formatted_value_total_sale],
                    ['label' => 'Recebíveis', 'value' => 'R$ '.$project->formatted_payment_flow_total],
                ],
                'sales_values' => [
                    ['label' => 'Quitadas', 'value' => 'R$ '.$project->formatted_value_paid],
                    ['label' => 'Vendidas', 'value' => 'R$ '.$project->formatted_value_unpaid],
                    ['label' => 'Estoque', 'value' => 'R$ '.$project->formatted_value_stock],
                    ['label' => 'VGV Total', 'value' => 'R$ '.$project->formatted_value_total_sale],
                ],
                'payment_flow' => [
                    ['label' => 'Valor já Recebido', 'value' => 'R$ '.$project->formatted_value_received],
                    ['label' => 'A receber até as chaves', 'value' => 'R$ '.$project->formatted_value_until_keys],
                    ['label' => 'A receber pós chaves', 'value' => 'R$ '.$project->formatted_value_post_keys],
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
                            'useful_area' => $unitType->formatted_useful_area.' m²',
                            'average_price' => 'R$ '.$unitType->formatted_average_price,
                            'price_per_m2' => 'R$ '.$unitType->formatted_price_per_m2,
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
            trim(implode(', ', array_filter([$proposal->company->logradouro, $proposal->company->numero]))),
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
            $proposal->contact->phone_personal
                ? 'Pessoal: '.$proposal->contact->phone_personal.($proposal->contact->whatsapp ? ' (WhatsApp)' : '')
                : null,
            $proposal->contact->phone_company ? 'Empresa: '.$proposal->contact->phone_company : null,
        ])->filter()->implode(' • ');
    }

    /**
     * @param  array<string, mixed>  $project
     */
    protected function projectIsBlank(array $project): bool
    {
        return blank($project['name'])
            && blank($project['units_exchanged'])
            && blank($project['units_paid'])
            && blank($project['units_unpaid'])
            && blank($project['units_stock'])
            && blank($project['cost_incurred'])
            && blank($project['cost_to_incur'])
            && blank($project['value_paid'])
            && blank($project['value_unpaid'])
            && blank($project['value_stock'])
            && blank($project['value_received'])
            && blank($project['value_until_keys'])
            && blank($project['value_post_keys']);
    }
}
