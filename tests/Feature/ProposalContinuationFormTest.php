<?php

use App\DTOs\Proposals\StoreProposalContinuationDataDTO;
use App\Livewire\Proposals\ContinuationForm;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the continuation page through the full-page livewire component', function () {
    Mail::fake();

    [$proposal, $access] = createProposalContinuationContext($this);

    $this->withSession(proposalContinuationSessionState($access))
        ->get(route('site.proposal.continuation.form', $access))
        ->assertSuccessful()
        ->assertSeeLivewire(ContinuationForm::class)
        ->assertSee('Formulário de Empreendimento');
});

it('looks up the zip code and hydrates the operation address fields', function () {
    Mail::fake();
    Http::fake([
        'https://viacep.com.br/ws/*' => Http::response([
            'logradouro' => 'Rua Faria Lima',
            'bairro' => 'Itaim Bibi',
            'localidade' => 'São Paulo',
            'uf' => 'SP',
        ]),
    ]);

    [, $access] = createProposalContinuationContext($this);

    seedProposalContinuationSession($access);

    Livewire::test(ContinuationForm::class, ['access' => $access])
        ->set('zipCode', '04567-000')
        ->assertSet('zipCode', '04567-000')
        ->assertSet('street', 'Rua Faria Lima')
        ->assertSet('neighborhood', 'Itaim Bibi')
        ->assertSet('city', 'São Paulo')
        ->assertSet('state', 'SP');

    Http::assertSentCount(3);
});

it('recalculates project and unit type metrics reactively', function () {
    Mail::fake();

    [, $access] = createProposalContinuationContext($this);

    seedProposalContinuationSession($access);

    Livewire::test(ContinuationForm::class, ['access' => $access])
        ->set('projects.0.name', 'Torre Madrid')
        ->set('projects.0.exchangedUnits', 10)
        ->set('projects.0.paidUnits', 20)
        ->set('projects.0.unpaidUnits', 15)
        ->set('projects.0.stockUnits', 55)
        ->set('projects.0.incurredCost', '1.000.000,00')
        ->set('projects.0.costToIncur', '3.000.000,00')
        ->set('projects.0.paidSalesValue', '900.000,00')
        ->set('projects.0.unpaidSalesValue', '1.500.000,50')
        ->set('projects.0.stockSalesValue', '2.500.000,75')
        ->set('unitTypes.0.usableArea', 82.5)
        ->set('unitTypes.0.averagePrice', '850.000,00')
        ->set('blockCount', 2)
        ->set('typicalFloorCount', 15)
        ->set('unitsPerFloor', 4)
        ->assertSet('projects.0.totalUnits', 100)
        ->assertSet('projects.0.salesPercentage', '38.89')
        ->assertSet('projects.0.totalCost', '4.000.000,00')
        ->assertSet('projects.0.workStagePercentage', '25.00')
        ->assertSet('projects.0.grossSalesValue', '4.900.001,25')
        ->assertSet('unitTypes.0.pricePerSquareMeter', '10.303,03')
        ->assertSet('totalUnits', 120);
});

it('stores the continuation payload through the livewire component', function () {
    Mail::fake();
    config()->set('filesystems.disks.tmp-for-tests', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/tmp-for-tests-'.uniqid()),
        'throw' => false,
    ]);
    Storage::set('local', Storage::createLocalDriver([
        'root' => storage_path('framework/testing/disks/local-'.uniqid()),
        'throw' => false,
    ]));

    [$proposal, $access] = createProposalContinuationContext($this);
    $payload = proposalContinuationComponentState();

    seedProposalContinuationSession($access);

    $component = Livewire::test(ContinuationForm::class, ['access' => $access]);

    foreach ($payload as $property => $value) {
        $component->set($property, $value);
    }

    $component
        ->set('uploads', [
            UploadedFile::fake()->create('memorial-descritivo.pdf', 128, 'application/pdf'),
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('successMessage', 'Empreendimento(s) salvo(s) com sucesso.');

    $proposal->refresh();
    $proposal->load([
        'projects.characteristics.unitTypes',
        'files',
        'latestStatusHistory',
    ]);

    expect($proposal->status)->toBe(Proposal::STATUS_IN_REVIEW)
        ->and($proposal->completed_at)->not->toBeNull()
        ->and($proposal->projects)->toHaveCount(2)
        ->and($proposal->projects->pluck('name')->all())->toBe([
            'Torre Madrid',
            'Torre Manchester',
        ])
        ->and($proposal->files)->toHaveCount(1)
        ->and($proposal->latestStatusHistory?->new_status)->toBe(Proposal::STATUS_IN_REVIEW);

    $firstProject = $proposal->projects->first();

    expect((int) $firstProject->units_total)->toBe(100)
        ->and((float) $firstProject->sales_percentage)->toBe(38.89)
        ->and((float) $firstProject->total_cost)->toBe(4000000.0)
        ->and((float) $firstProject->gross_sales_value)->toBe(4900001.25)
        ->and((int) $firstProject->characteristics->total_units)->toBe(120)
        ->and($firstProject->characteristics->unitTypes)->toHaveCount(2);

    Storage::disk('local')->assertExists($proposal->files->first()->file_path);
});

/**
 * @return array{0: Proposal, 1: ProposalContinuationAccess}
 */
function createProposalContinuationContext(\Illuminate\Foundation\Testing\TestCase $testCase): array
{
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()
        ->with(['company', 'latestContinuationAccess'])
        ->firstOrFail();

    return [$proposal, $proposal->latestContinuationAccess];
}

/**
 * @return array<string, mixed>
 */
function proposalContinuationComponentState(): array
{
    $rawPayload = proposalContinuationPayload();
    $payload = StoreProposalContinuationDataDTO::fromFlatPayload($rawPayload);

    return [
        'developmentName' => $payload->overview->developmentName,
        'websiteUrl' => $payload->overview->websiteUrl,
        'requestedAmount' => $rawPayload['valor_solicitado'],
        'landMarketValue' => $rawPayload['valor_mercado_terreno'],
        'landArea' => (string) $payload->overview->landArea,
        'launchDate' => $payload->overview->launchDate,
        'salesLaunchDate' => $payload->overview->salesLaunchDate,
        'constructionStartDate' => $payload->overview->constructionStartDate,
        'deliveryForecastDate' => $payload->overview->deliveryForecastDate,
        'remainingMonths' => $payload->overview->remainingMonths,
        'zipCode' => $payload->overview->zipCode,
        'street' => $payload->overview->street,
        'addressComplement' => $payload->overview->addressComplement,
        'addressNumber' => $payload->overview->addressNumber,
        'neighborhood' => $payload->overview->neighborhood,
        'city' => $payload->overview->city,
        'state' => $payload->overview->state,
        'blockCount' => $payload->characteristics->blocks,
        'floorCount' => $payload->characteristics->floors,
        'typicalFloorCount' => $payload->characteristics->typicalFloors,
        'unitsPerFloor' => $payload->characteristics->unitsPerFloor,
        'totalUnits' => $payload->characteristics->totalUnits,
        'projects' => collect($payload->projects)->map(fn ($project, int $index): array => [
            'id' => $project->id,
            'name' => $project->name,
            'exchangedUnits' => $project->exchangedUnits,
            'paidUnits' => $project->paidUnits,
            'unpaidUnits' => $project->unpaidUnits,
            'stockUnits' => $project->stockUnits,
            'totalUnits' => '',
            'salesPercentage' => '',
            'incurredCost' => $rawPayload['custo_incidido'][$index] ?? '',
            'costToIncur' => $rawPayload['custo_a_incorrer'][$index] ?? '',
            'totalCost' => '',
            'workStagePercentage' => '',
            'paidSalesValue' => $rawPayload['valor_quitadas'][$index] ?? '',
            'unpaidSalesValue' => $rawPayload['valor_nao_quitadas'][$index] ?? '',
            'stockSalesValue' => $rawPayload['valor_estoque'][$index] ?? '',
            'grossSalesValue' => '',
            'receivedValue' => $rawPayload['valor_ja_recebido'][$index] ?? '',
            'valueUntilKeys' => $rawPayload['valor_ate_chaves'][$index] ?? '',
            'valueAfterKeys' => $rawPayload['valor_chaves_pos'][$index] ?? '',
        ])->all(),
        'unitTypes' => collect($payload->unitTypes)->map(fn ($unitType, int $index): array => [
            'totalUnits' => $unitType->totalUnits,
            'bedrooms' => $unitType->bedrooms,
            'parkingSpaces' => $unitType->parkingSpaces,
            'usableArea' => $unitType->usableArea,
            'averagePrice' => $rawPayload['tipo_preco_medio'][$index] ?? '',
            'pricePerSquareMeter' => '',
        ])->all(),
    ];
}

if (! function_exists('proposalContinuationSessionState')) {
    /**
     * @return array<string, bool>
     */
    function proposalContinuationSessionState(ProposalContinuationAccess $access): array
    {
        return [
            "proposal_magic_link.{$access->id}" => true,
            "proposal_verified.{$access->id}" => true,
        ];
    }
}

function seedProposalContinuationSession(ProposalContinuationAccess $access): void
{
    session()->start();

    foreach (proposalContinuationSessionState($access) as $key => $value) {
        session()->put($key, $value);
    }

    app('request')->setLaravelSession(app('session.store'));
}

function proposalContinuationPayload(): array
{
    return [
        'nome' => 'Residencial Atlântico',
        'site' => 'https://residencial-atlantico.example.com',
        'valor_solicitado' => '15.000.000,00',
        'valor_mercado_terreno' => '4.000.000,00',
        'area_terreno' => 5000,
        'data_lancamento' => '2026-03',
        'lancamento_vendas' => '2026-04',
        'inicio_obras' => '2026-05',
        'previsao_entrega' => '2028-06',
        'prazo_remanescente' => 25,
        'cep' => '04567-000',
        'logradouro' => 'Rua das Palmeiras',
        'numero' => '100',
        'complemento' => 'Bloco A',
        'bairro' => 'Jardins',
        'cidade' => 'São Paulo',
        'estado' => 'SP',
        'nome_empreendimento' => [
            'Torre Madrid',
            'Torre Manchester',
        ],
        'unidades_permutadas' => [10, 5],
        'unidades_quitadas' => [20, 30],
        'unidades_nao_quitadas' => [15, 25],
        'unidades_estoque' => [55, 40],
        'custo_incidido' => ['1.000.000,00', '2.000.000,00'],
        'custo_a_incorrer' => ['3.000.000,00', '1.000.000,00'],
        'valor_quitadas' => ['900.000,00', '1.100.000,00'],
        'valor_nao_quitadas' => ['1.500.000,50', '1.700.000,25'],
        'valor_estoque' => ['2.500.000,75', '3.600.000,10'],
        'valor_ja_recebido' => ['400.000,00', '500.000,00'],
        'valor_ate_chaves' => ['600.000,00', '700.000,00'],
        'valor_chaves_pos' => ['800.000,00', '900.000,00'],
        'car_bloco' => 2,
        'car_pavimentos' => 20,
        'car_andares_tipo' => 15,
        'car_unidades_andar' => 4,
        'car_total' => 120,
        'tipo_total' => [60, 60],
        'tipo_dormitorios' => ['3', '2'],
        'tipo_vagas' => ['2', '1'],
        'tipo_area' => [82.5, 58.4],
        'tipo_preco_medio' => ['850.000,00', '520.000,00'],
    ];
}
