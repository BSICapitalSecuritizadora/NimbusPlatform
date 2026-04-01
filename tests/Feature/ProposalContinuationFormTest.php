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

    $html = Livewire::test(ContinuationForm::class, ['access' => $access, 'proposal' => $proposal])->html();

    expect($html)
        ->not->toContain('<script')
        ->toContain('wire:model.blur="cep"')
        ->toContain('x-mask="99999-999"')
        ->not->toContain('lookupCep')
        ->not->toContain('viacep.com.br/ws/');
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
        ->set('cep', '04567-000')
        ->assertSet('cep', '04567-000')
        ->assertSet('logradouro', 'Rua Faria Lima')
        ->assertSet('bairro', 'Itaim Bibi')
        ->assertSet('cidade', 'São Paulo')
        ->assertSet('estado', 'SP');

    Http::assertSentCount(1);
});

it('recalculates project and unit type metrics reactively', function () {
    Mail::fake();

    [$proposal, $access] = createProposalContinuationContext($this);

    Livewire::test(ContinuationForm::class, ['access' => $access, 'proposal' => $proposal])
        ->set('projects.0.nome', 'Torre Madrid')
        ->set('projects.0.unidades_permutadas', 10)
        ->set('projects.0.unidades_quitadas', 20)
        ->set('projects.0.unidades_nao_quitadas', 15)
        ->set('projects.0.unidades_estoque', 55)
        ->set('projects.0.custo_incidido', '1.000.000,00')
        ->set('projects.0.custo_a_incorrer', '3.000.000,00')
        ->set('projects.0.valor_quitadas', '900.000,00')
        ->set('projects.0.valor_nao_quitadas', '1.500.000,50')
        ->set('projects.0.valor_estoque', '2.500.000,75')
        ->set('tipos.0.area_util', 82.5)
        ->set('tipos.0.preco_medio', '850.000,00')
        ->set('blocos', 2)
        ->set('andares_tipo', 15)
        ->set('unidades_por_andar', 4)
        ->assertSet('projects.0.unidades_total', 100)
        ->assertSet('projects.0.percentual_vendido', '38.89')
        ->assertSet('projects.0.custo_total', '4.000.000,00')
        ->assertSet('projects.0.estagio_obra', '25.00')
        ->assertSet('projects.0.vgv_total', '4.900.001,25')
        ->assertSet('tipos.0.preco_m2', '10.303,03')
        ->assertSet('total_unidades', 120);
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

    $component = Livewire::test(ContinuationForm::class, ['access' => $access, 'proposal' => $proposal]);

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
 * @return array<string, mixed>
 */
function proposalContinuationComponentState(): array
{
    $payload = StoreProposalContinuationData::fromFlatPayload(proposalContinuationPayload());

    return [
        'nome' => $payload['operation']['nome'],
        'site' => $payload['operation']['site'],
        'valor_solicitado' => $payload['operation']['valor_solicitado'],
        'valor_mercado_terreno' => $payload['operation']['valor_mercado_terreno'],
        'area_terreno' => (string) $payload['operation']['area_terreno'],
        'data_lancamento' => $payload['operation']['data_lancamento'],
        'lancamento_vendas' => $payload['operation']['lancamento_vendas'],
        'inicio_obras' => $payload['operation']['inicio_obras'],
        'previsao_entrega' => $payload['operation']['previsao_entrega'],
        'prazo_remanescente' => $payload['operation']['prazo_remanescente'],
        'cep' => $payload['operation']['cep'],
        'logradouro' => $payload['operation']['logradouro'],
        'complemento' => $payload['operation']['complemento'],
        'numero' => $payload['operation']['numero'],
        'bairro' => $payload['operation']['bairro'],
        'cidade' => $payload['operation']['cidade'],
        'estado' => $payload['operation']['estado'],
        'blocos' => $payload['characteristics']['blocks'],
        'pavimentos' => $payload['characteristics']['floors'],
        'andares_tipo' => $payload['characteristics']['typical_floors'],
        'unidades_por_andar' => $payload['characteristics']['units_per_floor'],
        'total_unidades' => $payload['characteristics']['total_units'],
        'projects' => collect($payload['projects'])->map(fn (array $project): array => [
            'id' => $project['id'] ?? null,
            'nome' => $project['name'],
            'unidades_permutadas' => $project['units_exchanged'],
            'unidades_quitadas' => $project['units_paid'],
            'unidades_nao_quitadas' => $project['units_unpaid'],
            'unidades_estoque' => $project['units_stock'],
            'unidades_total' => '',
            'percentual_vendido' => '',
            'custo_incidido' => $project['cost_incurred'] ?? '',
            'custo_a_incorrer' => $project['cost_to_incur'] ?? '',
            'custo_total' => '',
            'estagio_obra' => '',
            'valor_quitadas' => $project['value_paid'] ?? '',
            'valor_nao_quitadas' => $project['value_unpaid'] ?? '',
            'valor_estoque' => $project['value_stock'] ?? '',
            'vgv_total' => '',
            'valor_ja_recebido' => $project['value_received'] ?? '',
            'valor_ate_chaves' => $project['value_until_keys'] ?? '',
            'valor_chaves_pos' => $project['value_post_keys'] ?? '',
        ])->all(),
        'tipos' => collect($payload['unit_types'])->map(fn (array $tipo): array => [
            'total' => $tipo['total'],
            'dormitorios' => $tipo['bedrooms'],
            'vagas' => $tipo['parking_spaces'],
            'area_util' => $tipo['useful_area'],
            'preco_medio' => $tipo['average_price'],
            'preco_m2' => '',
        ])->all(),
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
