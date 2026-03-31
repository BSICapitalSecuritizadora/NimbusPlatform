<?php

use App\Actions\Proposals\StoreProposalContinuationData;
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

it('renders the continuation page through the livewire component', function () {
    Mail::fake();

    [$proposal, $access] = createProposalContinuationContext($this);

    $this->withSession(proposalContinuationSessionState($access))
        ->get(route('site.proposal.continuation.form', $access))
        ->assertSuccessful()
        ->assertSeeLivewire(ContinuationForm::class)
        ->assertSee('Formulário de Empreendimento');
});

it('looks up the cep and hydrates the operation address fields', function () {
    Mail::fake();
    Http::fake([
        'https://viacep.com.br/ws/*' => Http::response([
            'logradouro' => 'Rua Faria Lima',
            'bairro' => 'Itaim Bibi',
            'localidade' => 'São Paulo',
            'uf' => 'SP',
        ]),
    ]);

    [$proposal, $access] = createProposalContinuationContext($this);

    Livewire::test(ContinuationForm::class, ['access' => $access, 'proposal' => $proposal])
        ->set('operation.cep', '04567-000')
        ->call('lookupCep')
        ->assertSet('operation.cep', '04567-000')
        ->assertSet('operation.logradouro', 'Rua Faria Lima')
        ->assertSet('operation.bairro', 'Itaim Bibi')
        ->assertSet('operation.cidade', 'São Paulo')
        ->assertSet('operation.estado', 'SP');

    Http::assertSentCount(1);
});

it('recalculates project and unit type metrics reactively', function () {
    Mail::fake();

    [$proposal, $access] = createProposalContinuationContext($this);

    Livewire::test(ContinuationForm::class, ['access' => $access, 'proposal' => $proposal])
        ->set('projects.0.name', 'Torre Madrid')
        ->set('projects.0.units_exchanged', 10)
        ->set('projects.0.units_paid', 20)
        ->set('projects.0.units_unpaid', 15)
        ->set('projects.0.units_stock', 55)
        ->set('projects.0.cost_incurred', '1.000.000,00')
        ->set('projects.0.cost_to_incur', '3.000.000,00')
        ->set('projects.0.value_paid', '900.000,00')
        ->set('projects.0.value_unpaid', '1.500.000,50')
        ->set('projects.0.value_stock', '2.500.000,75')
        ->set('unitTypes.0.useful_area', 82.5)
        ->set('unitTypes.0.average_price', '850.000,00')
        ->set('characteristics.blocks', 2)
        ->set('characteristics.typical_floors', 15)
        ->set('characteristics.units_per_floor', 4)
        ->assertSet('projects.0.units_total', 100)
        ->assertSet('projects.0.sales_percentage', '38.89')
        ->assertSet('projects.0.cost_total', '4.000.000,00')
        ->assertSet('projects.0.work_stage_percentage', '25.00')
        ->assertSet('projects.0.value_total_sale', '4.900.001,25')
        ->assertSet('unitTypes.0.price_per_m2', '10.303,03')
        ->assertSet('characteristics.total_units', 120);
});

it('stores the continuation payload through the livewire component', function () {
    Mail::fake();
    Storage::fake('local');

    [$proposal, $access] = createProposalContinuationContext($this);
    $payload = proposalContinuationComponentState();

    Livewire::test(ContinuationForm::class, ['access' => $access, 'proposal' => $proposal])
        ->set('operation', $payload['operation'])
        ->set('characteristics', $payload['characteristics'])
        ->set('projects', $payload['projects'])
        ->set('unitTypes', $payload['unitTypes'])
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
        ->and((float) $firstProject->cost_total)->toBe(4000000.0)
        ->and((float) $firstProject->value_total_sale)->toBe(4900001.25)
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

    $testCase->post(route('site.proposal.store'), proposalInitialPayload($sector))
        ->assertRedirect(route('site.proposal.create'));

    $proposal = Proposal::query()
        ->with(['company', 'latestContinuationAccess'])
        ->firstOrFail();

    return [$proposal, $proposal->latestContinuationAccess];
}

/**
 * @return array{operation: array<string, mixed>, characteristics: array<string, mixed>, projects: array<int, array<string, mixed>>, unitTypes: array<int, array<string, mixed>>}
 */
function proposalContinuationComponentState(): array
{
    $payload = StoreProposalContinuationData::fromFlatPayload(proposalContinuationPayload());

    return [
        'operation' => $payload['operation'],
        'characteristics' => $payload['characteristics'],
        'projects' => $payload['projects'],
        'unitTypes' => $payload['unit_types'],
    ];
}

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

function proposalInitialPayload(ProposalSector $sector, int $index = 1): array
{
    return [
        'cnpj' => sprintf('12.345.67%d/0001-%02d', $index, $index),
        'nome_empresa' => "Construtora {$index}",
        'ie' => "12345{$index}",
        'site' => "https://construtora{$index}.example.com",
        'setores' => [$sector->id],
        'cep' => '04567-000',
        'logradouro' => 'Rua das Torres',
        'numero' => (string) (100 + $index),
        'complemento' => 'Sala 10',
        'bairro' => 'Centro',
        'cidade' => 'São Paulo',
        'estado' => 'SP',
        'nome_contato' => "Contato {$index}",
        'email' => "contato{$index}@example.com",
        'telefone_pessoal' => '(11) 99999-0000',
        'whatsapp' => '1',
        'telefone_empresa' => '(11) 4000-0000',
        'cargo' => 'Diretor',
        'observacoes' => 'Observações iniciais.',
    ];
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
