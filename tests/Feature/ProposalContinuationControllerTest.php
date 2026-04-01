<?php

use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('stores continuation submissions through the legacy public endpoint without a form request', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    $this->post(route('site.proposal.store'), initialProposalPayload($sector))
        ->assertRedirect(route('site.proposal.create'));

    $proposal = Proposal::query()
        ->with('latestContinuationAccess')
        ->firstOrFail();

    $access = $proposal->latestContinuationAccess;

    expect($access)->not->toBeNull();

    $this->withSession(proposalContinuationSessionState($access))
        ->post(route('site.proposal.continuation.store', $access), continuationPayload())
        ->assertRedirect(route('site.proposal.continuation.form', $access))
        ->assertSessionHas('success');

    $proposal->refresh();
    $proposal->load('projects');

    expect($proposal->status)->toBe(Proposal::STATUS_IN_REVIEW)
        ->and($proposal->completed_at)->not->toBeNull()
        ->and($proposal->projects)->toHaveCount(2)
        ->and($proposal->projects->pluck('name')->all())->toBe([
            'Torre Madrid',
            'Torre Manchester',
        ]);
});

/**
 * @return array<string, mixed>
 */
function initialProposalPayload(ProposalSector $sector, int $index = 1): array
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

/**
 * @return array<string, mixed>
 */
function continuationPayload(): array
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
        'custo_incidido' => ['1.000.000,00', '1.200.000,00'],
        'custo_a_incorrer' => ['3.000.000,00', '2.800.000,00'],
        'valor_quitadas' => ['900.000,00', '1.100.000,00'],
        'valor_nao_quitadas' => ['1.500.000,50', '1.400.000,00'],
        'valor_estoque' => ['2.500.000,75', '2.100.000,00'],
        'valor_ja_recebido' => ['350.000,00', '400.000,00'],
        'valor_ate_chaves' => ['1.100.000,00', '1.000.000,00'],
        'valor_chaves_pos' => ['650.000,00', '700.000,00'],
        'car_bloco' => 2,
        'car_pavimentos' => 18,
        'car_andares_tipo' => 15,
        'car_unidades_andar' => 4,
        'car_total' => 120,
        'tipo_total' => [60, 60],
        'tipo_dormitorios' => ['2 dormitórios', '3 dormitórios'],
        'tipo_vagas' => ['1 vaga', '2 vagas'],
        'tipo_area' => [82.5, 107.8],
        'tipo_preco_medio' => ['850.000,00', '960.000,00'],
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
