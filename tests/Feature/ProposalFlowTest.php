<?php

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Mail\ProposalContinuationLinkMail;
use App\Models\Proposal;
use App\Models\ProposalAssignment;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalDistributionState;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('distributes incoming proposals in round-robin order without repeating representatives consecutively', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    $representativeOne = ProposalRepresentative::factory()->create([
        'name' => 'Representante 1',
        'queue_position' => 1,
    ]);
    $representativeTwo = ProposalRepresentative::factory()->create([
        'name' => 'Representante 2',
        'queue_position' => 2,
    ]);
    $representativeThree = ProposalRepresentative::factory()->create([
        'name' => 'Representante 3',
        'queue_position' => 3,
    ]);

    foreach (range(1, 4) as $index) {
        $this->post(route('site.proposal.store'), initialProposalPayload($sector, $index))
            ->assertRedirect(route('site.proposal.create'))
            ->assertSessionHas('success');
    }

    $proposals = Proposal::query()
        ->with('representative')
        ->orderBy('id')
        ->get();

    $assignedRepresentativeIds = $proposals->pluck('assigned_representative_id')->all();

    expect($proposals)->toHaveCount(4)
        ->and($assignedRepresentativeIds)->toBe([
            $representativeOne->id,
            $representativeTwo->id,
            $representativeThree->id,
            $representativeOne->id,
        ])
        ->and($proposals->pluck('distribution_sequence')->all())->toBe([1, 2, 3, 4])
        ->and(ProposalAssignment::query()->count())->toBe(4)
        ->and(ProposalDistributionState::query()->findOrFail(1)->last_representative_id)->toBe($representativeOne->id)
        ->and(ProposalDistributionState::query()->findOrFail(1)->last_sequence)->toBe(4);

    foreach ($assignedRepresentativeIds as $index => $representativeId) {
        if ($index === 0) {
            continue;
        }

        expect($representativeId)->not->toBe($assignedRepresentativeIds[$index - 1]);
    }
});

it('requires the signed magic link plus cnpj and emailed code before continuing the Nimbus-style form', function () {
    Mail::fake();
    Storage::fake('local');

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    $this->post(route('site.proposal.store'), initialProposalPayload($sector))
        ->assertRedirect(route('site.proposal.create'))
        ->assertSessionHas('success');

    $proposal = Proposal::query()
        ->with(['company', 'contact', 'latestContinuationAccess'])
        ->firstOrFail();

    expect($proposal->status)->toBe(Proposal::STATUS_AWAITING_COMPLETION)
        ->and($proposal->assigned_representative_id)->not->toBeNull()
        ->and($proposal->latestContinuationAccess)->not->toBeNull()
        ->and($proposal->latestContinuationAccess?->display_code)->not->toBe('Indisponivel')
        ->and($proposal->latestContinuationAccess?->sent_at)->not->toBeNull();

    $mailData = captureContinuationMail();
    $access = $proposal->latestContinuationAccess;

    expect($access->display_code)->toBe($mailData['code'])
        ->and($access->generated_url)->toContain('/proposta/continuar/');

    $this->post(route('site.proposal.continuation.verify', $access), [
        'cnpj' => $proposal->company->cnpj,
        'code' => $mailData['code'],
    ])->assertForbidden();

    $this->get(relativePathFromUrl($mailData['continuation_url']))
        ->assertOk()
        ->assertSee('Continuar Proposta');

    $access->refresh();

    expect($access->first_accessed_at)->not->toBeNull()
        ->and($access->last_accessed_at)->not->toBeNull()
        ->and($access->status_label)->toBe('Acessado');

    $this->post(route('site.proposal.continuation.verify', $access), [
        'cnpj' => '00.000.000/0000-00',
        'code' => $mailData['code'],
    ])->assertSessionHasErrors('cnpj');

    $this->post(route('site.proposal.continuation.verify', $access), [
        'cnpj' => $proposal->company->cnpj,
        'code' => '000000',
    ])->assertSessionHasErrors('code');

    $this->post(route('site.proposal.continuation.verify', $access), [
        'cnpj' => $proposal->company->cnpj,
        'code' => $mailData['code'],
    ])->assertRedirect(route('site.proposal.continuation.form', $access));

    $this->get(route('site.proposal.continuation.form', $access))
        ->assertOk()
        ->assertSee('Formulário de Empreendimento');

    $access->refresh();

    expect($access->verified_at)->not->toBeNull()
        ->and($access->last_used_at)->not->toBeNull()
        ->and($access->status_label)->toBe('Validado');

    $this->post(route('site.proposal.continuation.store', $access), continuationPayload())
        ->assertRedirect(route('site.proposal.continuation.form', $access))
        ->assertSessionHas('success');

    $proposal->refresh();
    $proposal->load([
        'projects.characteristics.unitTypes',
        'files',
        'latestContinuationAccess',
    ]);

    expect($proposal->status)->toBe(Proposal::STATUS_IN_REVIEW)
        ->and($proposal->completed_at)->not->toBeNull()
        ->and($proposal->projects)->toHaveCount(2)
        ->and($proposal->projects->pluck('name')->all())->toBe([
            'Torre Madrid',
            'Torre Manchester',
        ])
        ->and($proposal->files)->toHaveCount(1)
        ->and($proposal->latestContinuationAccess?->verified_at)->not->toBeNull();

    $firstProject = $proposal->projects->first();

    expect($firstProject->company_name)->toBe('Residencial Atlântico')
        ->and((int) $firstProject->units_total)->toBe(100)
        ->and((float) $firstProject->sales_percentage)->toBe(38.89)
        ->and((float) $firstProject->cost_total)->toBe(4000000.0)
        ->and((float) $firstProject->value_total_sale)->toBe(4900001.25)
        ->and($firstProject->characteristics)->not->toBeNull()
        ->and((int) $firstProject->characteristics->total_units)->toBe(120)
        ->and($firstProject->characteristics->unitTypes)->toHaveCount(1)
        ->and((float) $firstProject->characteristics->unitTypes->first()->price_per_m2)->toBe(10303.03);

    Storage::disk('local')->assertExists($proposal->files->first()->file_path);
});

it('revokes the previous access and records a new send when the link is resent', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    $this->post(route('site.proposal.store'), initialProposalPayload($sector))
        ->assertRedirect(route('site.proposal.create'));

    $proposal = Proposal::query()
        ->with(['company', 'contact', 'continuationAccesses'])
        ->firstOrFail();

    $firstAccess = $proposal->continuationAccesses()->latest('id')->firstOrFail();
    $firstMailData = captureContinuationMail();

    app(SendProposalContinuationLink::class)->handle(
        $proposal->loadMissing(['company', 'contact']),
    );

    Mail::assertSent(ProposalContinuationLinkMail::class, 2);

    $proposal->refresh();

    /** @var ProposalContinuationAccess $latestAccess */
    $latestAccess = $proposal->continuationAccesses()->latest('id')->firstOrFail();

    expect($proposal->continuationAccesses()->count())->toBe(2)
        ->and($latestAccess->id)->not->toBe($firstAccess->id)
        ->and($latestAccess->sent_at)->not->toBeNull()
        ->and($latestAccess->revoked_at)->toBeNull()
        ->and($latestAccess->display_code)->not->toBe('Indisponivel');

    expect($firstAccess->fresh()->revoked_at)->not->toBeNull();

    $this->get(relativePathFromUrl($firstMailData['continuation_url']))->assertForbidden();
});

it('stores advanced indicators after the continuation flow is authorized', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    $this->post(route('site.proposal.store'), initialProposalPayload($sector))
        ->assertRedirect(route('site.proposal.create'));

    $proposal = Proposal::query()
        ->with(['company', 'latestContinuationAccess'])
        ->firstOrFail();
    $access = $proposal->latestContinuationAccess;
    $mailData = captureContinuationMail();

    $this->get(relativePathFromUrl($mailData['continuation_url']))->assertOk();
    $this->post(route('site.proposal.continuation.verify', $access), [
        'cnpj' => $proposal->company->cnpj,
        'code' => $mailData['code'],
    ])->assertRedirect(route('site.proposal.continuation.form', $access));

    $this->post(route('site.proposal.continuation.store', $access), continuationPayload())
        ->assertRedirect(route('site.proposal.continuation.form', $access));

    $this->post(route('site.proposal.continuation.indicators', $access), [
        'financiamento_custo_obra_ideal' => 55.5,
        'financiamento_custo_obra_limite' => 60.0,
        'ltv_ideal' => 65.0,
        'ltv_limite' => 75.0,
    ])->assertRedirect(route('site.proposal.continuation.form', $access))
        ->assertSessionHas('success');

    $project = $proposal->fresh()->projects()->oldest('id')->firstOrFail();

    expect($project->indicators)->not->toBeNull()
        ->and((float) $project->indicators->financiamento_custo_obra_ideal)->toBe(55.5)
        ->and((float) $project->indicators->financiamento_custo_obra_limite)->toBe(60.0)
        ->and((float) $project->indicators->ltv_ideal)->toBe(65.0)
        ->and((float) $project->indicators->ltv_limite)->toBe(75.0);
});

it('flags legacy accesses that do not have a recoverable code', function () {
    $access = new ProposalContinuationAccess([
        'token' => 'legacy-token',
        'sent_to_email' => 'legacy@example.com',
        'expires_at' => now()->addDay(),
    ]);

    expect($access->display_code)->toBe('Codigo legado - reenviar acesso')
        ->and($access->status_label)->toBe('Enviado');
});

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
        'tipo_total' => [120],
        'tipo_dormitorios' => ['3'],
        'tipo_vagas' => ['2'],
        'tipo_area' => [82.5],
        'tipo_preco_medio' => ['850.000,00'],
        'arquivos' => [
            UploadedFile::fake()->create('memorial-descritivo.pdf', 128, 'application/pdf'),
        ],
    ];
}

/**
 * @return array{continuation_url: string, code: string}
 */
function captureContinuationMail(): array
{
    $mailData = [
        'continuation_url' => '',
        'code' => '',
    ];

    Mail::assertSent(ProposalContinuationLinkMail::class, function (ProposalContinuationLinkMail $mail) use (&$mailData): bool {
        $mailData = [
            'continuation_url' => $mail->continuationUrl,
            'code' => $mail->code,
        ];

        return true;
    });

    return $mailData;
}

function relativePathFromUrl(string $url): string
{
    $parts = parse_url($url);

    return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
}
