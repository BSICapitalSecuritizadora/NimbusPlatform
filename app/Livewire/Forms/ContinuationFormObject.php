<?php

namespace App\Livewire\Forms;

use App\Actions\Proposals\StoreProposalContinuationData;
use App\DTOs\Proposals\StoreProposalContinuationDataDTO;
use App\Models\Proposal;
use App\Models\ProposalProject;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Validate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

class ContinuationFormObject extends Form
{
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

    /**
     * @param  array<int, string>  $relations
     */
    public function save(
        Proposal $proposal,
        StoreProposalContinuationData $storeProposalContinuationData,
        array $relations,
    ): void {
        $this->syncRemainingMonths();
        $this->syncTotalUnits();

        foreach (array_keys($this->projects) as $projectIndex) {
            $this->syncProject($projectIndex);
        }

        foreach (array_keys($this->unitTypes) as $unitTypeIndex) {
            $this->syncUnitType($unitTypeIndex);
        }

        $validated = $this
            ->withValidator(function (Validator $validator): void {
                if (
                    filled($this->constructionStartDate)
                    && filled($this->deliveryForecastDate)
                    && ($this->deliveryForecastDate < $this->constructionStartDate)
                ) {
                    $validator->errors()->add('deliveryForecastDate', 'A previsão de entrega deve ser posterior ao início das obras.');
                }
            })
            ->validate($this->saveRules($proposal->id), $this->saveMessages());

        $storeProposalContinuationData->handle(
            $proposal,
            $this->continuationData($validated),
            $this->uploads,
        );

        $this->uploads = [];
        $this->fillFromProposal($proposal->fresh($relations));
    }

    public function fillFromProposal(Proposal $proposal): void
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

    protected function fetchAddress(): void
    {
        $zipCode = Str::digitsOnly($this->zipCode);

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

    protected function prepareForValidation($attributes): array
    {
        return $this->normalizeEmptyStrings($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function saveRules(int $proposalId): array
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
                    fn ($query) => $query->where('proposal_id', $proposalId),
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

    protected function projectIsBlank(array $project): bool
    {
        $fields = [
            'exchangedUnits',
            'paidUnits',
            'unpaidUnits',
            'stockUnits',
            'incurredCost',
            'costToIncur',
            'paidSalesValue',
            'unpaidSalesValue',
            'stockSalesValue',
            'receivedValue',
            'valueUntilKeys',
            'valueAfterKeys',
        ];

        foreach ($fields as $field) {
            if (filled($project[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    protected function normalizeEmptyStrings(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeEmptyStrings($item), $value);
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }
}
