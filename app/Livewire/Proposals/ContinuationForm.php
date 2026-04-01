<?php

namespace App\Livewire\Proposals;

use App\Actions\Proposals\StoreProposalContinuationData;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use App\Models\ProposalProject;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ContinuationForm extends Component
{
    use WithFileUploads;

    public int $accessId;

    public int $proposalId;

    public ?string $successMessage = null;

    public string $nome = '';

    public string $site = '';

    public string $valor_solicitado = '';

    public string $valor_mercado_terreno = '';

    public string $area_terreno = '';

    public string $data_lancamento = '';

    public string $lancamento_vendas = '';

    public string $inicio_obras = '';

    public string $previsao_entrega = '';

    public int|string $prazo_remanescente = '';

    public string $cep = '';

    public string $logradouro = '';

    public string $complemento = '';

    public string $numero = '';

    public string $bairro = '';

    public string $cidade = '';

    public string $estado = '';

    public int|string $blocos = '';

    public int|string $pavimentos = '';

    public int|string $andares_tipo = '';

    public int|string $unidades_por_andar = '';

    public int|string $total_unidades = '';

    /**
     * @var array<int, array{
     *     id: int|string|null,
     *     nome: string,
     *     unidades_permutadas: int|string,
     *     unidades_quitadas: int|string,
     *     unidades_nao_quitadas: int|string,
     *     unidades_estoque: int|string,
     *     unidades_total: int|string,
     *     percentual_vendido: string,
     *     custo_incidido: string,
     *     custo_a_incorrer: string,
     *     custo_total: string,
     *     estagio_obra: string,
     *     valor_quitadas: string,
     *     valor_nao_quitadas: string,
     *     valor_estoque: string,
     *     vgv_total: string,
     *     valor_ja_recebido: string,
     *     valor_ate_chaves: string,
     *     valor_chaves_pos: string
     * }>
     */
    public array $projects = [];

    /**
     * @var array<int, array{
     *     total: int|string,
     *     dormitorios: string,
     *     vagas: string,
     *     area_util: float|int|string,
     *     preco_medio: string,
     *     preco_m2: string
     * }>
     */
    public array $tipos = [];

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

        return view('livewire.proposals.continuation-form', [
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

    public function updated(string $property, mixed $value): void
    {
        if (in_array($property, ['valor_solicitado', 'valor_mercado_terreno'], true)) {
            $this->formatMoneyProperty($property);
        }

        if ($property === 'estado') {
            $this->estado = Str::upper(substr((string) $value, 0, 2));
        }

        if (in_array($property, ['inicio_obras', 'previsao_entrega'], true)) {
            $this->syncPrazoRemanescente();
        }

        if (preg_match('/^projects\.(\d+)\./', $property, $matches) === 1) {
            $projectIndex = (int) $matches[1];

            if (preg_match('/\.(custo_incidido|custo_a_incorrer|valor_quitadas|valor_nao_quitadas|valor_estoque|valor_ja_recebido|valor_ate_chaves|valor_chaves_pos)$/', $property) === 1) {
                $this->formatMoneyProperty($property);
            }

            $this->syncProject($projectIndex);
        }

        if (preg_match('/^tipos\.(\d+)\./', $property, $matches) === 1) {
            $typeIndex = (int) $matches[1];

            if (str_ends_with($property, '.preco_medio')) {
                $this->formatMoneyProperty($property);
            }

            $this->syncTipo($typeIndex);
        }

        if (in_array($property, ['blocos', 'andares_tipo', 'unidades_por_andar'], true)) {
            $this->syncTotalUnidades();
        }
    }

    public function updatedCep(mixed $value): void
    {
        $formattedCep = $this->formatCep((string) $value);

        if ($formattedCep !== $this->cep) {
            $this->cep = $formattedCep;

            return;
        }

        $cep = preg_replace('/\D/', '', $formattedCep);

        if (strlen($cep) !== 8) {
            return;
        }

        $response = Http::get("https://viacep.com.br/ws/{$cep}/json/");

        if (! $response->successful() || $response->json('erro')) {
            return;
        }

        $this->logradouro = (string) ($response->json('logradouro') ?? '');
        $this->bairro = (string) ($response->json('bairro') ?? '');
        $this->cidade = (string) ($response->json('localidade') ?? '');
        $this->estado = Str::upper((string) ($response->json('uf') ?? ''));
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

    public function addTipo(): void
    {
        $this->tipos[] = $this->blankTipo();
    }

    public function removeTipo(int $index): void
    {
        if (count($this->tipos) === 1) {
            return;
        }

        unset($this->tipos[$index]);

        $this->tipos = array_values($this->tipos);

        foreach (array_keys($this->tipos) as $typeIndex) {
            $this->syncTipo($typeIndex);
        }
    }

    public function save(StoreProposalContinuationData $storeProposalContinuationData): void
    {
        $this->syncPrazoRemanescente();
        $this->syncTotalUnidades();

        foreach (array_keys($this->projects) as $projectIndex) {
            $this->syncProject($projectIndex);
        }

        foreach (array_keys($this->tipos) as $typeIndex) {
            $this->syncTipo($typeIndex);
        }

        $payload = $this->normalizeValidationPayload($this->validationPayload());

        $validated = validator($payload, $this->saveRules(), $this->saveMessages())
            ->after(function (Validator $validator) use ($payload): void {
                if (
                    filled($payload['inicio_obras'] ?? null)
                    && filled($payload['previsao_entrega'] ?? null)
                    && ($payload['previsao_entrega'] < $payload['inicio_obras'])
                ) {
                    $validator->errors()->add('previsao_entrega', 'A previsão de entrega deve ser posterior ao início das obras.');
                }
            })->validate();

        $storeProposalContinuationData->handle(
            $this->proposal(),
            $this->storePayload($validated),
            $this->uploads,
        );

        $this->successMessage = 'Empreendimento(s) salvo(s) com sucesso.';
        $this->uploads = [];
        $this->fillFromProposal($this->proposal()->fresh($this->proposalRelations()));
    }

    protected function validationPayload(): array
    {
        return [
            'nome' => $this->nome,
            'site' => $this->site,
            'valor_solicitado' => $this->valor_solicitado,
            'valor_mercado_terreno' => $this->valor_mercado_terreno,
            'area_terreno' => $this->area_terreno,
            'data_lancamento' => $this->data_lancamento,
            'lancamento_vendas' => $this->lancamento_vendas,
            'inicio_obras' => $this->inicio_obras,
            'previsao_entrega' => $this->previsao_entrega,
            'prazo_remanescente' => $this->prazo_remanescente,
            'cep' => $this->cep,
            'logradouro' => $this->logradouro,
            'complemento' => $this->complemento,
            'numero' => $this->numero,
            'bairro' => $this->bairro,
            'cidade' => $this->cidade,
            'estado' => $this->estado,
            'blocos' => $this->blocos,
            'pavimentos' => $this->pavimentos,
            'andares_tipo' => $this->andares_tipo,
            'unidades_por_andar' => $this->unidades_por_andar,
            'total_unidades' => $this->total_unidades,
            'projects' => $this->projects,
            'tipos' => $this->tipos,
            'uploads' => $this->uploads,
        ];
    }

    protected function storePayload(array $validated): array
    {
        return [
            'operation' => [
                'nome' => $validated['nome'],
                'site' => $validated['site'] ?? null,
                'valor_solicitado' => $validated['valor_solicitado'],
                'valor_mercado_terreno' => $validated['valor_mercado_terreno'] ?? null,
                'area_terreno' => $validated['area_terreno'],
                'data_lancamento' => $validated['data_lancamento'],
                'lancamento_vendas' => $validated['lancamento_vendas'],
                'inicio_obras' => $validated['inicio_obras'],
                'previsao_entrega' => $validated['previsao_entrega'],
                'prazo_remanescente' => $validated['prazo_remanescente'] ?? null,
                'cep' => $validated['cep'],
                'logradouro' => $validated['logradouro'],
                'complemento' => $validated['complemento'] ?? null,
                'numero' => $validated['numero'],
                'bairro' => $validated['bairro'],
                'cidade' => $validated['cidade'],
                'estado' => $validated['estado'],
            ],
            'characteristics' => [
                'blocks' => $validated['blocos'],
                'floors' => $validated['pavimentos'],
                'typical_floors' => $validated['andares_tipo'],
                'units_per_floor' => $validated['unidades_por_andar'],
                'total_units' => $validated['total_unidades'] ?? null,
            ],
            'projects' => collect($validated['projects'])
                ->map(function (array $project): array {
                    return [
                        'id' => $project['id'] ?? null,
                        'name' => $project['nome'],
                        'units_exchanged' => $project['unidades_permutadas'] ?? 0,
                        'units_paid' => $project['unidades_quitadas'] ?? 0,
                        'units_unpaid' => $project['unidades_nao_quitadas'] ?? 0,
                        'units_stock' => $project['unidades_estoque'] ?? 0,
                        'cost_incurred' => $project['custo_incidido'] ?? null,
                        'cost_to_incur' => $project['custo_a_incorrer'] ?? null,
                        'value_paid' => $project['valor_quitadas'] ?? null,
                        'value_unpaid' => $project['valor_nao_quitadas'] ?? null,
                        'value_stock' => $project['valor_estoque'] ?? null,
                        'value_received' => $project['valor_ja_recebido'] ?? null,
                        'value_until_keys' => $project['valor_ate_chaves'] ?? null,
                        'value_post_keys' => $project['valor_chaves_pos'] ?? null,
                    ];
                })
                ->values()
                ->all(),
            'unit_types' => collect($validated['tipos'])
                ->map(function (array $tipo): array {
                    return [
                        'total' => $tipo['total'],
                        'bedrooms' => $tipo['dormitorios'],
                        'parking_spaces' => $tipo['vagas'],
                        'useful_area' => $tipo['area_util'],
                        'average_price' => $tipo['preco_medio'],
                    ];
                })
                ->values()
                ->all(),
        ];
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
    protected function saveRules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'site' => ['nullable', 'url', 'max:255'],
            'valor_solicitado' => ['required', 'string', 'max:50'],
            'valor_mercado_terreno' => ['nullable', 'string', 'max:50'],
            'area_terreno' => ['required', 'numeric', 'min:0'],
            'data_lancamento' => ['required', 'date_format:Y-m'],
            'lancamento_vendas' => ['required', 'date_format:Y-m'],
            'inicio_obras' => ['required', 'date_format:Y-m'],
            'previsao_entrega' => ['required', 'date_format:Y-m'],
            'prazo_remanescente' => ['nullable', 'integer', 'min:0'],
            'cep' => ['required', 'string', 'max:9'],
            'logradouro' => ['required', 'string', 'max:255'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'numero' => ['required', 'string', 'max:50'],
            'bairro' => ['required', 'string', 'max:255'],
            'cidade' => ['required', 'string', 'max:255'],
            'estado' => ['required', 'string', 'size:2'],
            'projects' => ['required', 'array', 'min:1'],
            'projects.*.id' => [
                'nullable',
                'integer',
                Rule::exists('proposal_projects', 'id')->where(
                    fn ($query) => $query->where('proposal_id', $this->proposalId),
                ),
            ],
            'projects.*.nome' => ['required', 'string', 'max:255'],
            'projects.*.unidades_permutadas' => ['nullable', 'integer', 'min:0'],
            'projects.*.unidades_quitadas' => ['nullable', 'integer', 'min:0'],
            'projects.*.unidades_nao_quitadas' => ['nullable', 'integer', 'min:0'],
            'projects.*.unidades_estoque' => ['nullable', 'integer', 'min:0'],
            'projects.*.custo_incidido' => ['nullable', 'string', 'max:50'],
            'projects.*.custo_a_incorrer' => ['nullable', 'string', 'max:50'],
            'projects.*.valor_quitadas' => ['nullable', 'string', 'max:50'],
            'projects.*.valor_nao_quitadas' => ['nullable', 'string', 'max:50'],
            'projects.*.valor_estoque' => ['nullable', 'string', 'max:50'],
            'projects.*.valor_ja_recebido' => ['nullable', 'string', 'max:50'],
            'projects.*.valor_ate_chaves' => ['nullable', 'string', 'max:50'],
            'projects.*.valor_chaves_pos' => ['nullable', 'string', 'max:50'],
            'blocos' => ['required', 'integer', 'min:1'],
            'pavimentos' => ['required', 'integer', 'min:1'],
            'andares_tipo' => ['required', 'integer', 'min:1'],
            'unidades_por_andar' => ['required', 'integer', 'min:1'],
            'total_unidades' => ['nullable', 'integer', 'min:1'],
            'tipos' => ['required', 'array', 'min:1'],
            'tipos.*.total' => ['required', 'integer', 'min:1'],
            'tipos.*.dormitorios' => ['required', 'string', 'max:255'],
            'tipos.*.vagas' => ['required', 'string', 'max:255'],
            'tipos.*.area_util' => ['required', 'numeric', 'gt:0'],
            'tipos.*.preco_medio' => ['required', 'string', 'max:50'],
            'uploads' => ['nullable', 'array'],
            'uploads.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function saveMessages(): array
    {
        return [
            'nome.required' => 'A denominação principal do empreendimento é obrigatória.',
            'valor_solicitado.required' => 'O valor solicitado para a operação é obrigatório.',
            'area_terreno.required' => 'A área do terreno é obrigatória.',
            'data_lancamento.required' => 'A data de lançamento do empreendimento é obrigatória.',
            'data_lancamento.date_format' => 'A data de lançamento deve estar no formato mm/aaaa.',
            'lancamento_vendas.required' => 'A data de lançamento comercial é obrigatória.',
            'lancamento_vendas.date_format' => 'A data de lançamento das vendas deve estar no formato mm/aaaa.',
            'inicio_obras.required' => 'A data de início das obras é obrigatória.',
            'inicio_obras.date_format' => 'A data de início das obras deve estar no formato mm/aaaa.',
            'previsao_entrega.required' => 'A previsão de entrega do empreendimento é obrigatória.',
            'previsao_entrega.date_format' => 'A previsão de entrega deve estar no formato mm/aaaa.',
            'projects.required' => 'Informe ao menos um empreendimento vinculado à operação.',
            'projects.*.nome.required' => 'A identificação de cada empreendimento é obrigatória.',
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

        $this->nome = $firstProject?->company_name ?? '';
        $this->site = $firstProject?->site ?? '';
        $this->valor_solicitado = $firstProject?->formatted_value_requested ?? '';
        $this->valor_mercado_terreno = $firstProject?->formatted_land_market_value ?? '';
        $this->area_terreno = (string) ($firstProject?->land_area ?? '');
        $this->data_lancamento = $firstProject?->launch_month ?? '';
        $this->lancamento_vendas = $firstProject?->sales_launch_month ?? '';
        $this->inicio_obras = $firstProject?->construction_start_month ?? '';
        $this->previsao_entrega = $firstProject?->delivery_forecast_month ?? '';
        $this->prazo_remanescente = $firstProject?->remaining_months ?? '';
        $this->cep = $this->formatCep((string) ($firstProject?->cep ?? ''));
        $this->logradouro = $firstProject?->logradouro ?? '';
        $this->complemento = $firstProject?->complemento ?? '';
        $this->numero = $firstProject?->numero ?? '';
        $this->bairro = $firstProject?->bairro ?? '';
        $this->cidade = $firstProject?->cidade ?? '';
        $this->estado = $firstProject?->estado ?? '';

        $this->blocos = $firstProject?->characteristics?->blocks ?? '';
        $this->pavimentos = $firstProject?->characteristics?->floors ?? '';
        $this->andares_tipo = $firstProject?->characteristics?->typical_floors ?? '';
        $this->unidades_por_andar = $firstProject?->characteristics?->units_per_floor ?? '';
        $this->total_unidades = $firstProject?->characteristics?->total_units ?? '';

        $this->projects = $proposal->projects->isNotEmpty()
            ? $proposal->projects
                ->map(fn (ProposalProject $project): array => [
                    'id' => $project->id,
                    'nome' => $project->name,
                    'unidades_permutadas' => $project->units_exchanged,
                    'unidades_quitadas' => $project->units_paid,
                    'unidades_nao_quitadas' => $project->units_unpaid,
                    'unidades_estoque' => $project->units_stock,
                    'unidades_total' => $project->units_total,
                    'percentual_vendido' => number_format((float) $project->sales_percentage, 2, '.', ''),
                    'custo_incidido' => $project->formatted_cost_incurred,
                    'custo_a_incorrer' => $project->formatted_cost_to_incur,
                    'custo_total' => $project->formatted_cost_total,
                    'estagio_obra' => number_format((float) $project->work_stage_percentage, 2, '.', ''),
                    'valor_quitadas' => $project->formatted_value_paid,
                    'valor_nao_quitadas' => $project->formatted_value_unpaid,
                    'valor_estoque' => $project->formatted_value_stock,
                    'vgv_total' => $project->formatted_value_total_sale,
                    'valor_ja_recebido' => $project->formatted_value_received,
                    'valor_ate_chaves' => $project->formatted_value_until_keys,
                    'valor_chaves_pos' => $project->formatted_value_post_keys,
                ])
                ->values()
                ->all()
            : [$this->blankProject()];

        $this->tipos = $firstProject?->characteristics?->unitTypes?->isNotEmpty()
            ? $firstProject->characteristics->unitTypes
                ->sortBy('order')
                ->map(fn ($unitType): array => [
                    'total' => $unitType->total_units,
                    'dormitorios' => $unitType->bedrooms,
                    'vagas' => $unitType->parking_spaces,
                    'area_util' => $unitType->useful_area,
                    'preco_medio' => $unitType->formatted_average_price,
                    'preco_m2' => $unitType->formatted_price_per_m2,
                ])
                ->values()
                ->all()
            : [$this->blankTipo()];

        $this->uploads = [];
        $this->syncPrazoRemanescente();
        $this->syncTotalUnidades();

        foreach (array_keys($this->projects) as $projectIndex) {
            $this->syncProject($projectIndex);
        }

        foreach (array_keys($this->tipos) as $typeIndex) {
            $this->syncTipo($typeIndex);
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    protected function blankProject(): array
    {
        return [
            'id' => null,
            'nome' => '',
            'unidades_permutadas' => '',
            'unidades_quitadas' => '',
            'unidades_nao_quitadas' => '',
            'unidades_estoque' => '',
            'unidades_total' => '',
            'percentual_vendido' => '',
            'custo_incidido' => '',
            'custo_a_incorrer' => '',
            'custo_total' => '',
            'estagio_obra' => '',
            'valor_quitadas' => '',
            'valor_nao_quitadas' => '',
            'valor_estoque' => '',
            'vgv_total' => '',
            'valor_ja_recebido' => '',
            'valor_ate_chaves' => '',
            'valor_chaves_pos' => '',
        ];
    }

    /**
     * @return array<string, float|int|string>
     */
    protected function blankTipo(): array
    {
        return [
            'total' => '',
            'dormitorios' => '',
            'vagas' => '',
            'area_util' => '',
            'preco_medio' => '',
            'preco_m2' => '',
        ];
    }

    protected function syncProject(int $index): void
    {
        $project = $this->projects[$index] ?? null;

        if (! $project) {
            return;
        }

        if ($this->projectIsBlank($project)) {
            $project['unidades_total'] = '';
            $project['percentual_vendido'] = '';
            $project['custo_total'] = '';
            $project['estagio_obra'] = '';
            $project['vgv_total'] = '';
            $this->projects[$index] = $project;

            return;
        }

        $project['unidades_total'] = ProposalProject::calculateUnitsTotal(
            $project['unidades_nao_quitadas'],
            $project['unidades_quitadas'],
            $project['unidades_permutadas'],
            $project['unidades_estoque'],
        );

        $project['percentual_vendido'] = number_format(ProposalProject::calculateSalesPercentage(
            $project['unidades_nao_quitadas'],
            $project['unidades_quitadas'],
            $project['unidades_permutadas'],
            $project['unidades_estoque'],
        ), 2, '.', '');

        $costTotal = ProposalProject::calculateCostTotal(
            $project['custo_incidido'],
            $project['custo_a_incorrer'],
        );

        $project['custo_total'] = ProposalProject::formatCurrencyForDisplay($costTotal);
        $project['estagio_obra'] = number_format(ProposalProject::calculateWorkStagePercentage(
            $project['custo_incidido'],
            $costTotal,
        ), 2, '.', '');

        $project['vgv_total'] = ProposalProject::formatCurrencyForDisplay(
            ProposalProject::calculateSalesValuesTotal(
                $project['valor_quitadas'],
                $project['valor_nao_quitadas'],
                $project['valor_estoque'],
            ),
        );

        $this->projects[$index] = $project;
    }

    protected function syncTipo(int $index): void
    {
        $tipo = $this->tipos[$index] ?? null;

        if (! $tipo) {
            return;
        }

        $averagePrice = ProposalProject::normalizeDecimalValue($tipo['preco_medio'] ?? null);
        $usefulArea = ProposalProject::normalizeDecimalValue($tipo['area_util'] ?? null);

        $tipo['preco_m2'] = $averagePrice > 0 && $usefulArea > 0
            ? ProposalProject::formatCurrencyForDisplay(round($averagePrice / $usefulArea, 2))
            : '';

        $this->tipos[$index] = $tipo;
    }

    protected function syncPrazoRemanescente(): void
    {
        if (! $this->inicio_obras || ! $this->previsao_entrega) {
            $this->prazo_remanescente = '';

            return;
        }

        try {
            $startDate = Carbon::createFromFormat('Y-m', $this->inicio_obras);
            $endDate = Carbon::createFromFormat('Y-m', $this->previsao_entrega);
        } catch (\Throwable) {
            $this->prazo_remanescente = '';

            return;
        }

        $this->prazo_remanescente = $startDate->diffInMonths($endDate);
    }

    protected function syncTotalUnidades(): void
    {
        $blocks = (int) ($this->blocos ?: 0);
        $typicalFloors = (int) ($this->andares_tipo ?: 0);
        $unitsPerFloor = (int) ($this->unidades_por_andar ?: 0);

        $this->total_unidades = ($blocks > 0 && $typicalFloors > 0 && $unitsPerFloor > 0)
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
        return blank($project['nome'])
            && blank($project['unidades_permutadas'])
            && blank($project['unidades_quitadas'])
            && blank($project['unidades_nao_quitadas'])
            && blank($project['unidades_estoque'])
            && blank($project['custo_incidido'])
            && blank($project['custo_a_incorrer'])
            && blank($project['valor_quitadas'])
            && blank($project['valor_nao_quitadas'])
            && blank($project['valor_estoque'])
            && blank($project['valor_ja_recebido'])
            && blank($project['valor_ate_chaves'])
            && blank($project['valor_chaves_pos']);
    }
}
