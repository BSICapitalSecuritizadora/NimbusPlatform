<?php

namespace App\Livewire\Proposals;

use App\Actions\Proposals\StoreProposalContinuationData;
use App\DTOs\Proposals\StoreProposalContinuationDataDTO;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalFile;
use App\Models\ProposalProject;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('site.layout')]
#[Title('Formulário de Empreendimento')]
class ContinuationForm extends Component
{
    use WithFileUploads;

    public int $accessId;

    public int $proposalId;

    public ?string $successMessage = null;

    #[Validate('required|string|max:255')]
    public string $developmentName = '';

    #[Validate('nullable|url|max:255')]
    public string $websiteUrl = '';

    #[Validate('required|string|max:50')]
    public string $requestedAmount = '';

    #[Validate('nullable|string|max:50')]
    public string $landMarketValue = '';

    #[Validate('required|numeric|min:0')]
    public string $landArea = '';

    #[Validate('required|date_format:Y-m')]
    public string $launchDate = '';

    #[Validate('required|date_format:Y-m')]
    public string $salesLaunchDate = '';

    #[Validate('required|date_format:Y-m')]
    public string $constructionStartDate = '';

    #[Validate('required|date_format:Y-m')]
    public string $deliveryForecastDate = '';

    #[Validate('nullable|integer|min:0')]
    public int|string $remainingMonths = '';

    #[Validate('required|string|max:9')]
    public string $zipCode = '';

    #[Validate('required|string|max:255')]
    public string $street = '';

    #[Validate('nullable|string|max:255')]
    public string $addressComplement = '';

    #[Validate('required|string|max:50')]
    public string $addressNumber = '';

    #[Validate('required|string|max:255')]
    public string $neighborhood = '';

    #[Validate('required|string|max:255')]
    public string $city = '';

    #[Validate('required|string|size:2')]
    public string $state = '';

    #[Validate('required|integer|min:1')]
    public int|string $blockCount = '';

    #[Validate('required|integer|min:1')]
    public int|string $floorCount = '';

    #[Validate('required|integer|min:1')]
    public int|string $typicalFloorCount = '';

    #[Validate('required|integer|min:1')]
    public int|string $unitsPerFloor = '';

    #[Validate('nullable|integer|min:1')]
    public int|string $totalUnits = '';

    /**
     * @var array<int, array{
     *     id: int|string|null,
     *     name: string,
     *     exchangedUnits: int|string,
     *     paidUnits: int|string,
     *     unpaidUnits: int|string,
     *     stockUnits: int|string,
     *     totalUnits: int|string,
     *     salesPercentage: string,
     *     incurredCost: string,
     *     costToIncur: string,
     *     totalCost: string,
     *     workStagePercentage: string,
     *     paidSalesValue: string,
     *     unpaidSalesValue: string,
     *     stockSalesValue: string,
     *     grossSalesValue: string,
     *     receivedValue: string,
     *     valueUntilKeys: string,
     *     valueAfterKeys: string
     * }>
     */
    #[Validate([
        'projects' => ['required', 'array', 'min:1'],
        'projects.*.id' => ['nullable', 'integer'],
        'projects.*.name' => ['required', 'string', 'max:255'],
        'projects.*.exchangedUnits' => ['nullable', 'integer', 'min:0'],
        'projects.*.paidUnits' => ['nullable', 'integer', 'min:0'],
        'projects.*.unpaidUnits' => ['nullable', 'integer', 'min:0'],
        'projects.*.stockUnits' => ['nullable', 'integer', 'min:0'],
        'projects.*.incurredCost' => ['nullable', 'string', 'max:50'],
        'projects.*.costToIncur' => ['nullable', 'string', 'max:50'],
        'projects.*.paidSalesValue' => ['nullable', 'string', 'max:50'],
        'projects.*.unpaidSalesValue' => ['nullable', 'string', 'max:50'],
        'projects.*.stockSalesValue' => ['nullable', 'string', 'max:50'],
        'projects.*.receivedValue' => ['nullable', 'string', 'max:50'],
        'projects.*.valueUntilKeys' => ['nullable', 'string', 'max:50'],
        'projects.*.valueAfterKeys' => ['nullable', 'string', 'max:50'],
    ])]
    public array $projects = [];

    /**
     * @var array<int, array{
     *     totalUnits: int|string,
     *     bedrooms: string,
     *     parkingSpaces: string,
     *     usableArea: float|int|string,
     *     averagePrice: string,
     *     pricePerSquareMeter: string
     * }>
     */
    #[Validate([
        'unitTypes' => ['required', 'array', 'min:1'],
        'unitTypes.*.totalUnits' => ['required', 'integer', 'min:1'],
        'unitTypes.*.bedrooms' => ['required', 'string', 'max:255'],
        'unitTypes.*.parkingSpaces' => ['required', 'string', 'max:255'],
        'unitTypes.*.usableArea' => ['required', 'numeric', 'gt:0'],
        'unitTypes.*.averagePrice' => ['required', 'string', 'max:50'],
    ])]
    public array $unitTypes = [];

    /** @var array<int, TemporaryUploadedFile> */
    #[Validate([
        'uploads' => ['nullable', 'array'],
        'uploads.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:10240'],
    ])]
    public array $uploads = [];

    public function mount(ProposalContinuationAccess $access): void
    {
        $this->ensureAuthorized(request(), $access);

        $proposal = $this->loadProposal($access);

        $this->accessId = $access->id;
        $this->proposalId = $proposal->id;

        $this->fillFromProposal($proposal);
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
        if (in_array($property, ['requestedAmount', 'landMarketValue'], true)) {
            $this->formatMoneyProperty($property);
        }

        if ($property === 'state') {
            $this->state = Str::upper(substr((string) $value, 0, 2));
        }

        if (in_array($property, ['constructionStartDate', 'deliveryForecastDate'], true)) {
            $this->syncRemainingMonths();
        }

        if (preg_match('/^projects\.(\d+)\./', $property, $matches) === 1) {
            $projectIndex = (int) $matches[1];

            if (preg_match('/\.(incurredCost|costToIncur|paidSalesValue|unpaidSalesValue|stockSalesValue|receivedValue|valueUntilKeys|valueAfterKeys)$/', $property) === 1) {
                $this->formatMoneyProperty($property);
            }

            $this->syncProject($projectIndex);
        }

        if (preg_match('/^unitTypes\.(\d+)\./', $property, $matches) === 1) {
            $unitTypeIndex = (int) $matches[1];

            if (str_ends_with($property, '.averagePrice')) {
                $this->formatMoneyProperty($property);
            }

            $this->syncUnitType($unitTypeIndex);
        }

        if (in_array($property, ['blockCount', 'typicalFloorCount', 'unitsPerFloor'], true)) {
            $this->syncTotalUnits();
        }
    }

    public function updatedZipCode(mixed $value): void
    {
        $formattedZipCode = $this->formatZipCode((string) $value);

        if ($formattedZipCode !== $this->zipCode) {
            $this->zipCode = $formattedZipCode;

            return;
        }

        $this->fetchAddress();
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

        foreach (array_keys($this->unitTypes) as $unitTypeIndex) {
            $this->syncUnitType($unitTypeIndex);
        }
    }

    public function save(StoreProposalContinuationData $storeProposalContinuationData): void
    {
        $access = $this->access();

        $this->ensureAuthorized(request(), $access);

        $proposal = $this->proposal();

        abort_unless($proposal->canBeCompletedByRequester(), 403);

        $this->syncRemainingMonths();
        $this->syncTotalUnits();

        foreach (array_keys($this->projects) as $projectIndex) {
            $this->syncProject($projectIndex);
        }

        foreach (array_keys($this->unitTypes) as $unitTypeIndex) {
            $this->syncUnitType($unitTypeIndex);
        }

        $payload = $this->normalizeValidationPayload($this->validationPayload());

        $validated = validator($payload, $this->saveRules(), $this->saveMessages())
            ->after(function (Validator $validator) use ($payload): void {
                if (
                    filled($payload['constructionStartDate'] ?? null)
                    && filled($payload['deliveryForecastDate'] ?? null)
                    && ($payload['deliveryForecastDate'] < $payload['constructionStartDate'])
                ) {
                    $validator->errors()->add('deliveryForecastDate', 'A previsão de entrega deve ser posterior ao início das obras.');
                }
            })->validate();

        $storeProposalContinuationData->handle(
            $proposal,
            $this->continuationData($validated),
            $this->uploads,
        );

        $this->successMessage = 'Empreendimento(s) salvo(s) com sucesso.';
        $this->uploads = [];
        $this->fillFromProposal($proposal->fresh($this->proposalRelations()));
    }

    protected function fetchAddress(): void
    {
        $zipCode = preg_replace('/\D/', '', $this->zipCode);

        if (strlen($zipCode) !== 8) {
            return;
        }

        $response = Http::get("https://viacep.com.br/ws/{$zipCode}/json/");

        if (! $response->successful() || $response->json('erro')) {
            return;
        }

        $this->street = (string) ($response->json('logradouro') ?? '');
        $this->neighborhood = (string) ($response->json('bairro') ?? '');
        $this->city = (string) ($response->json('localidade') ?? '');
        $this->state = Str::upper((string) ($response->json('uf') ?? ''));
    }

    protected function validationPayload(): array
    {
        return [
            'developmentName' => $this->developmentName,
            'websiteUrl' => $this->websiteUrl,
            'requestedAmount' => $this->requestedAmount,
            'landMarketValue' => $this->landMarketValue,
            'landArea' => $this->landArea,
            'launchDate' => $this->launchDate,
            'salesLaunchDate' => $this->salesLaunchDate,
            'constructionStartDate' => $this->constructionStartDate,
            'deliveryForecastDate' => $this->deliveryForecastDate,
            'remainingMonths' => $this->remainingMonths,
            'zipCode' => $this->zipCode,
            'street' => $this->street,
            'addressComplement' => $this->addressComplement,
            'addressNumber' => $this->addressNumber,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'blockCount' => $this->blockCount,
            'floorCount' => $this->floorCount,
            'typicalFloorCount' => $this->typicalFloorCount,
            'unitsPerFloor' => $this->unitsPerFloor,
            'totalUnits' => $this->totalUnits,
            'projects' => $this->projects,
            'unitTypes' => $this->unitTypes,
            'uploads' => $this->uploads,
        ];
    }

    protected function continuationData(array $validated): StoreProposalContinuationDataDTO
    {
        return StoreProposalContinuationDataDTO::fromArray([
            'overview' => [
                'development_name' => $validated['developmentName'],
                'website_url' => $validated['websiteUrl'] ?? null,
                'requested_amount' => $validated['requestedAmount'],
                'land_market_value' => $validated['landMarketValue'] ?? null,
                'land_area' => $validated['landArea'],
                'launch_date' => $validated['launchDate'],
                'sales_launch_date' => $validated['salesLaunchDate'],
                'construction_start_date' => $validated['constructionStartDate'],
                'delivery_forecast_date' => $validated['deliveryForecastDate'],
                'remaining_months' => $validated['remainingMonths'] ?? null,
                'zip_code' => $validated['zipCode'],
                'street' => $validated['street'],
                'address_complement' => $validated['addressComplement'] ?? null,
                'address_number' => $validated['addressNumber'],
                'neighborhood' => $validated['neighborhood'],
                'city' => $validated['city'],
                'state' => $validated['state'],
            ],
            'characteristics' => [
                'blocks' => $validated['blockCount'],
                'floors' => $validated['floorCount'],
                'typical_floors' => $validated['typicalFloorCount'],
                'units_per_floor' => $validated['unitsPerFloor'],
                'total_units' => $validated['totalUnits'] ?? null,
            ],
            'projects' => collect($validated['projects'])
                ->map(function (array $project): array {
                    return [
                        'id' => $project['id'] ?? null,
                        'name' => $project['name'],
                        'exchanged_units' => $project['exchangedUnits'] ?? 0,
                        'paid_units' => $project['paidUnits'] ?? 0,
                        'unpaid_units' => $project['unpaidUnits'] ?? 0,
                        'stock_units' => $project['stockUnits'] ?? 0,
                        'incurred_cost' => $project['incurredCost'] ?? null,
                        'cost_to_incur' => $project['costToIncur'] ?? null,
                        'paid_sales_value' => $project['paidSalesValue'] ?? null,
                        'unpaid_sales_value' => $project['unpaidSalesValue'] ?? null,
                        'stock_sales_value' => $project['stockSalesValue'] ?? null,
                        'received_value' => $project['receivedValue'] ?? null,
                        'value_until_keys' => $project['valueUntilKeys'] ?? null,
                        'value_after_keys' => $project['valueAfterKeys'] ?? null,
                    ];
                })
                ->values()
                ->all(),
            'unit_types' => collect($validated['unitTypes'])
                ->map(function (array $unitType): array {
                    return [
                        'total_units' => $unitType['totalUnits'],
                        'bedrooms' => $unitType['bedrooms'],
                        'parking_spaces' => $unitType['parkingSpaces'],
                        'usable_area' => $unitType['usableArea'],
                        'average_price' => $unitType['averagePrice'],
                    ];
                })
                ->values()
                ->all(),
        ]);
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
            'developmentName' => ['required', 'string', 'max:255'],
            'websiteUrl' => ['nullable', 'url', 'max:255'],
            'requestedAmount' => ['required', 'string', 'max:50'],
            'landMarketValue' => ['nullable', 'string', 'max:50'],
            'landArea' => ['required', 'numeric', 'min:0'],
            'launchDate' => ['required', 'date_format:Y-m'],
            'salesLaunchDate' => ['required', 'date_format:Y-m'],
            'constructionStartDate' => ['required', 'date_format:Y-m'],
            'deliveryForecastDate' => ['required', 'date_format:Y-m'],
            'remainingMonths' => ['nullable', 'integer', 'min:0'],
            'zipCode' => ['required', 'string', 'max:9'],
            'street' => ['required', 'string', 'max:255'],
            'addressComplement' => ['nullable', 'string', 'max:255'],
            'addressNumber' => ['required', 'string', 'max:50'],
            'neighborhood' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
            'projects' => ['required', 'array', 'min:1'],
            'projects.*.id' => [
                'nullable',
                'integer',
                Rule::exists('proposal_projects', 'id')->where(
                    fn ($query) => $query->where('proposal_id', $this->proposalId),
                ),
            ],
            'projects.*.name' => ['required', 'string', 'max:255'],
            'projects.*.exchangedUnits' => ['nullable', 'integer', 'min:0'],
            'projects.*.paidUnits' => ['nullable', 'integer', 'min:0'],
            'projects.*.unpaidUnits' => ['nullable', 'integer', 'min:0'],
            'projects.*.stockUnits' => ['nullable', 'integer', 'min:0'],
            'projects.*.incurredCost' => ['nullable', 'string', 'max:50'],
            'projects.*.costToIncur' => ['nullable', 'string', 'max:50'],
            'projects.*.paidSalesValue' => ['nullable', 'string', 'max:50'],
            'projects.*.unpaidSalesValue' => ['nullable', 'string', 'max:50'],
            'projects.*.stockSalesValue' => ['nullable', 'string', 'max:50'],
            'projects.*.receivedValue' => ['nullable', 'string', 'max:50'],
            'projects.*.valueUntilKeys' => ['nullable', 'string', 'max:50'],
            'projects.*.valueAfterKeys' => ['nullable', 'string', 'max:50'],
            'blockCount' => ['required', 'integer', 'min:1'],
            'floorCount' => ['required', 'integer', 'min:1'],
            'typicalFloorCount' => ['required', 'integer', 'min:1'],
            'unitsPerFloor' => ['required', 'integer', 'min:1'],
            'totalUnits' => ['nullable', 'integer', 'min:1'],
            'unitTypes' => ['required', 'array', 'min:1'],
            'unitTypes.*.totalUnits' => ['required', 'integer', 'min:1'],
            'unitTypes.*.bedrooms' => ['required', 'string', 'max:255'],
            'unitTypes.*.parkingSpaces' => ['required', 'string', 'max:255'],
            'unitTypes.*.usableArea' => ['required', 'numeric', 'gt:0'],
            'unitTypes.*.averagePrice' => ['required', 'string', 'max:50'],
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
            'developmentName.required' => 'A denominação principal do empreendimento é obrigatória.',
            'requestedAmount.required' => 'O valor solicitado para a operação é obrigatório.',
            'landArea.required' => 'A área do terreno é obrigatória.',
            'launchDate.required' => 'A data de lançamento do empreendimento é obrigatória.',
            'launchDate.date_format' => 'A data de lançamento deve estar no formato mm/aaaa.',
            'salesLaunchDate.required' => 'A data de lançamento comercial é obrigatória.',
            'salesLaunchDate.date_format' => 'A data de lançamento das vendas deve estar no formato mm/aaaa.',
            'constructionStartDate.required' => 'A data de início das obras é obrigatória.',
            'constructionStartDate.date_format' => 'A data de início das obras deve estar no formato mm/aaaa.',
            'deliveryForecastDate.required' => 'A previsão de entrega do empreendimento é obrigatória.',
            'deliveryForecastDate.date_format' => 'A previsão de entrega deve estar no formato mm/aaaa.',
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
        abort_unless($this->hasSessionKey($request, $this->magicLinkSessionKey($access)) && $access->isActive(), 403);
    }

    protected function isAuthorized(Request $request, ProposalContinuationAccess $access): bool
    {
        return $this->hasSessionKey($request, $this->verifiedSessionKey($access)) && $access->isActive();
    }

    protected function magicLinkSessionKey(ProposalContinuationAccess $access): string
    {
        return "proposal_magic_link.{$access->id}";
    }

    protected function verifiedSessionKey(ProposalContinuationAccess $access): string
    {
        return "proposal_verified.{$access->id}";
    }

    protected function hasSessionKey(Request $request, string $key): bool
    {
        if ($request->hasSession()) {
            return $request->session()->has($key);
        }

        return app('session.store')->has($key);
    }

    protected function fillFromProposal(Proposal $proposal): void
    {
        $firstProject = $proposal->projects->first();

        $this->developmentName = $firstProject?->development_name ?? '';
        $this->websiteUrl = $firstProject?->website_url ?? '';
        $this->requestedAmount = $firstProject?->formatted_requested_amount ?? '';
        $this->landMarketValue = $firstProject?->formatted_land_market_value ?? '';
        $this->landArea = (string) ($firstProject?->land_area ?? '');
        $this->launchDate = $firstProject?->launch_month ?? '';
        $this->salesLaunchDate = $firstProject?->sales_launch_month ?? '';
        $this->constructionStartDate = $firstProject?->construction_start_month ?? '';
        $this->deliveryForecastDate = $firstProject?->delivery_forecast_month ?? '';
        $this->remainingMonths = $firstProject?->remaining_months ?? '';
        $this->zipCode = $this->formatZipCode((string) ($firstProject?->zip_code ?? ''));
        $this->street = $firstProject?->street ?? '';
        $this->addressComplement = $firstProject?->address_complement ?? '';
        $this->addressNumber = $firstProject?->address_number ?? '';
        $this->neighborhood = $firstProject?->neighborhood ?? '';
        $this->city = $firstProject?->city ?? '';
        $this->state = $firstProject?->state ?? '';

        $this->blockCount = $firstProject?->characteristics?->blocks ?? '';
        $this->floorCount = $firstProject?->characteristics?->floors ?? '';
        $this->typicalFloorCount = $firstProject?->characteristics?->typical_floors ?? '';
        $this->unitsPerFloor = $firstProject?->characteristics?->units_per_floor ?? '';
        $this->totalUnits = $firstProject?->characteristics?->total_units ?? '';

        $this->projects = $proposal->projects->isNotEmpty()
            ? $proposal->projects
                ->map(fn (ProposalProject $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'exchangedUnits' => $project->exchanged_units,
                    'paidUnits' => $project->paid_units,
                    'unpaidUnits' => $project->unpaid_units,
                    'stockUnits' => $project->stock_units,
                    'totalUnits' => $project->units_total,
                    'salesPercentage' => number_format((float) $project->sales_percentage, 2, '.', ''),
                    'incurredCost' => $project->formatted_incurred_cost,
                    'costToIncur' => $project->formatted_cost_to_incur,
                    'totalCost' => $project->formatted_total_cost,
                    'workStagePercentage' => number_format((float) $project->work_stage_percentage, 2, '.', ''),
                    'paidSalesValue' => $project->formatted_paid_sales_value,
                    'unpaidSalesValue' => $project->formatted_unpaid_sales_value,
                    'stockSalesValue' => $project->formatted_stock_sales_value,
                    'grossSalesValue' => $project->formatted_gross_sales_value,
                    'receivedValue' => $project->formatted_received_value,
                    'valueUntilKeys' => $project->formatted_value_until_keys,
                    'valueAfterKeys' => $project->formatted_value_after_keys,
                ])
                ->values()
                ->all()
            : [$this->blankProject()];

        $this->unitTypes = $firstProject?->characteristics?->unitTypes?->isNotEmpty()
            ? $firstProject->characteristics->unitTypes
                ->sortBy('order')
                ->map(fn ($unitType): array => [
                    'totalUnits' => $unitType->total_units,
                    'bedrooms' => $unitType->bedrooms,
                    'parkingSpaces' => $unitType->parking_spaces,
                    'usableArea' => $unitType->usable_area,
                    'averagePrice' => $unitType->formatted_average_price,
                    'pricePerSquareMeter' => $unitType->formatted_price_per_square_meter,
                ])
                ->values()
                ->all()
            : [$this->blankUnitType()];

        $this->uploads = [];
        $this->syncRemainingMonths();
        $this->syncTotalUnits();

        foreach (array_keys($this->projects) as $projectIndex) {
            $this->syncProject($projectIndex);
        }

        foreach (array_keys($this->unitTypes) as $unitTypeIndex) {
            $this->syncUnitType($unitTypeIndex);
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    protected function blankProject(): array
    {
        return [
            'id' => null,
            'name' => '',
            'exchangedUnits' => '',
            'paidUnits' => '',
            'unpaidUnits' => '',
            'stockUnits' => '',
            'totalUnits' => '',
            'salesPercentage' => '',
            'incurredCost' => '',
            'costToIncur' => '',
            'totalCost' => '',
            'workStagePercentage' => '',
            'paidSalesValue' => '',
            'unpaidSalesValue' => '',
            'stockSalesValue' => '',
            'grossSalesValue' => '',
            'receivedValue' => '',
            'valueUntilKeys' => '',
            'valueAfterKeys' => '',
        ];
    }

    /**
     * @return array<string, float|int|string>
     */
    protected function blankUnitType(): array
    {
        return [
            'totalUnits' => '',
            'bedrooms' => '',
            'parkingSpaces' => '',
            'usableArea' => '',
            'averagePrice' => '',
            'pricePerSquareMeter' => '',
        ];
    }

    protected function syncProject(int $index): void
    {
        $project = $this->projects[$index] ?? null;

        if (! $project) {
            return;
        }

        if ($this->projectIsBlank($project)) {
            $project['totalUnits'] = '';
            $project['salesPercentage'] = '';
            $project['totalCost'] = '';
            $project['workStagePercentage'] = '';
            $project['grossSalesValue'] = '';
            $this->projects[$index] = $project;

            return;
        }

        $project['totalUnits'] = ProposalProject::calculateUnitsTotal(
            $project['unpaidUnits'],
            $project['paidUnits'],
            $project['exchangedUnits'],
            $project['stockUnits'],
        );

        $project['salesPercentage'] = number_format(ProposalProject::calculateSalesPercentage(
            $project['unpaidUnits'],
            $project['paidUnits'],
            $project['exchangedUnits'],
            $project['stockUnits'],
        ), 2, '.', '');

        $costTotal = ProposalProject::calculateCostTotal(
            $project['incurredCost'],
            $project['costToIncur'],
        );

        $project['totalCost'] = ProposalProject::formatCurrencyForDisplay($costTotal);
        $project['workStagePercentage'] = number_format(ProposalProject::calculateWorkStagePercentage(
            $project['incurredCost'],
            $costTotal,
        ), 2, '.', '');

        $project['grossSalesValue'] = ProposalProject::formatCurrencyForDisplay(
            ProposalProject::calculateSalesValuesTotal(
                $project['paidSalesValue'],
                $project['unpaidSalesValue'],
                $project['stockSalesValue'],
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

        $averagePrice = ProposalProject::normalizeDecimalValue($unitType['averagePrice'] ?? null);
        $usableArea = ProposalProject::normalizeDecimalValue($unitType['usableArea'] ?? null);

        $unitType['pricePerSquareMeter'] = $averagePrice > 0 && $usableArea > 0
            ? ProposalProject::formatCurrencyForDisplay(round($averagePrice / $usableArea, 2))
            : '';

        $this->unitTypes[$index] = $unitType;
    }

    protected function syncRemainingMonths(): void
    {
        if (! $this->constructionStartDate || ! $this->deliveryForecastDate) {
            $this->remainingMonths = '';

            return;
        }

        try {
            $startDate = Carbon::createFromFormat('Y-m', $this->constructionStartDate);
            $endDate = Carbon::createFromFormat('Y-m', $this->deliveryForecastDate);
        } catch (\Throwable) {
            $this->remainingMonths = '';

            return;
        }

        $this->remainingMonths = $startDate->diffInMonths($endDate);
    }

    protected function syncTotalUnits(): void
    {
        $blockCount = (int) ($this->blockCount ?: 0);
        $typicalFloorCount = (int) ($this->typicalFloorCount ?: 0);
        $unitsPerFloor = (int) ($this->unitsPerFloor ?: 0);

        $this->totalUnits = ($blockCount > 0 && $typicalFloorCount > 0 && $unitsPerFloor > 0)
            ? $blockCount * $typicalFloorCount * $unitsPerFloor
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

    protected function formatZipCode(string $value): string
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
            && blank($project['exchangedUnits'])
            && blank($project['paidUnits'])
            && blank($project['unpaidUnits'])
            && blank($project['stockUnits'])
            && blank($project['incurredCost'])
            && blank($project['costToIncur'])
            && blank($project['paidSalesValue'])
            && blank($project['unpaidSalesValue'])
            && blank($project['stockSalesValue'])
            && blank($project['receivedValue'])
            && blank($project['valueUntilKeys'])
            && blank($project['valueAfterKeys']);
    }
}
