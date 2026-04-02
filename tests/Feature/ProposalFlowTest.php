<?php

use App\Actions\Proposals\SendProposalContinuationLink;
use App\Actions\Proposals\UpdateProposalStatus;
use App\Mail\ProposalContinuationLinkMail;
use App\Models\Proposal;
use App\Models\ProposalAssignment;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalDistributionState;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use App\Models\User;
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
        submitInitialProposalThroughComponent($sector, $index);
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

it('stores each proposal company as an immutable snapshot even when the cnpj repeats', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    $firstPayload = proposalCreateFormState($sector, 1);
    $secondPayload = proposalCreateFormState($sector, 2);

    $secondPayload['cnpj'] = $firstPayload['cnpj'];
    $secondPayload['companyName'] = 'Construtora HistÃ³rico 2';
    $secondPayload['nome_empresa'] = 'Construtora Histórico 2';
    $secondPayload['website'] = 'https://historico-2.example.com';

    submitProposalCreateForm($firstPayload);
    submitProposalCreateForm($secondPayload);

    $proposals = Proposal::query()
        ->with('company')
        ->orderBy('id')
        ->get();

    expect($proposals)->toHaveCount(2)
        ->and($proposals[0]->company_id)->not->toBe($proposals[1]->company_id)
        ->and($proposals[0]->company->name)->toBe($firstPayload['companyName'])
        ->and($proposals[0]->company->site)->toBe($firstPayload['website'])
        ->and($proposals[1]->company->name)->toBe($secondPayload['companyName'])
        ->and($proposals[1]->company->site)->toBe($secondPayload['website']);
});

it('requires the signed magic link plus cnpj and emailed code before continuing the Nimbus-style form', function () {
    Mail::fake();
    config()->set('filesystems.disks.local.root', storage_path('framework/testing/disks/local-'.uniqid()));
    Storage::set('local', Storage::createLocalDriver([
        'root' => config('filesystems.disks.local.root'),
        'throw' => false,
    ]));

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()
        ->with(['company', 'contact', 'latestContinuationAccess', 'statusHistories'])
        ->firstOrFail();

    expect($proposal->status)->toBe(Proposal::STATUS_AWAITING_COMPLETION)
        ->and($proposal->assigned_representative_id)->not->toBeNull()
        ->and($proposal->latestContinuationAccess)->not->toBeNull()
        ->and($proposal->latestContinuationAccess?->display_code)->not->toBe('Indisponivel')
        ->and($proposal->latestContinuationAccess?->sent_at)->not->toBeNull()
        ->and($proposal->statusHistories)->toHaveCount(1)
        ->and($proposal->statusHistories->first()->previous_status)->toBeNull()
        ->and($proposal->statusHistories->first()->new_status)->toBe(Proposal::STATUS_AWAITING_COMPLETION);

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

    $this->get(route('site.proposal.continuation.form', $access))
        ->assertOk()
        ->assertSee('Cadastro Inicial')
        ->assertSee('Dados Gerais da Operação')
        ->assertSee('Contato 1')
        ->assertSee('Observações iniciais.')
        ->assertSee('Rua das Torres')
        ->assertSee('Residencial Atlântico')
        ->assertSee('Torre Madrid')
        ->assertSee('Tipo 2')
        ->assertSee('Fluxo de Pagamento')
        ->assertSee('Arquivos Anexados')
        ->assertDontSee('Indicadores Avançados')
        ->assertDontSee('Salvar Indicadores')
        ->assertDontSee('Analítico')
        ->assertDontSee('Representante:');

    $proposal->refresh();
    $proposal->load([
        'projects.characteristics.unitTypes',
        'files',
        'latestContinuationAccess',
        'statusHistories',
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
        ->and($proposal->latestContinuationAccess?->verified_at)->not->toBeNull()
        ->and($proposal->statusHistories)->toHaveCount(2)
        ->and($proposal->latestStatusHistory?->previous_status)->toBe(Proposal::STATUS_AWAITING_COMPLETION)
        ->and($proposal->latestStatusHistory?->new_status)->toBe(Proposal::STATUS_IN_REVIEW)
        ->and($proposal->latestStatusHistory?->note)->toBe('Informações complementares enviadas pelo proponente.');

    $firstProject = $proposal->projects->first();

    expect($firstProject->development_name)->toBe('Residencial Atlântico')
        ->and((int) $firstProject->units_total)->toBe(100)
        ->and((float) $firstProject->sales_percentage)->toBe(38.89)
        ->and((float) $firstProject->total_cost)->toBe(4000000.0)
        ->and((float) $firstProject->gross_sales_value)->toBe(4900001.25)
        ->and($firstProject->characteristics)->not->toBeNull()
        ->and((int) $firstProject->characteristics->total_units)->toBe(120)
        ->and($firstProject->characteristics->unitTypes)->toHaveCount(2)
        ->and((float) $firstProject->characteristics->unitTypes->first()->price_per_square_meter)->toBe(10303.03)
        ->and((float) $firstProject->characteristics->unitTypes->last()->price_per_square_meter)->toBe(8904.11);

    Storage::disk('local')->assertExists($proposal->files->first()->file_path);
});

it('rate limits repeated continuation code attempts on the public flow', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()->with(['company', 'latestContinuationAccess'])->firstOrFail();
    $access = $proposal->latestContinuationAccess;
    $mailData = captureContinuationMail();

    $this->get(relativePathFromUrl($mailData['continuation_url']))->assertOk();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('site.proposal.continuation.verify', $access), [
            'cnpj' => $proposal->company->cnpj,
            'code' => '000000',
        ])->assertSessionHasErrors('code');
    }

    $this->post(route('site.proposal.continuation.verify', $access), [
        'cnpj' => $proposal->company->cnpj,
        'code' => '000000',
    ])->assertTooManyRequests();
});

it('preserves project level internal analysis when the proposer resubmits requested information', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    $representativeUser = User::factory()->create([
        'email' => 'representante-retorno@example.com',
    ]);
    $representativeUser->assignRole('commercial-representative');

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
        'user_id' => $representativeUser->id,
        'email' => $representativeUser->email,
    ]);

    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()
        ->with(['company', 'latestContinuationAccess'])
        ->firstOrFail();
    $initialMailData = captureContinuationMail();

    $this->get(relativePathFromUrl($initialMailData['continuation_url']))->assertOk();
    $this->post(route('site.proposal.continuation.verify', $proposal->latestContinuationAccess), [
        'cnpj' => $proposal->company->cnpj,
        'code' => $initialMailData['code'],
    ])->assertRedirect(route('site.proposal.continuation.form', $proposal->latestContinuationAccess));

    $this->post(route('site.proposal.continuation.store', $proposal->latestContinuationAccess), continuationPayload())
        ->assertRedirect(route('site.proposal.continuation.form', $proposal->latestContinuationAccess));

    $proposal->refresh();
    $proposal->load('projects.indicators');

    $firstProject = $proposal->projects()->oldest('id')->firstOrFail();
    $indicator = $firstProject->indicators()->create([
        'financiamento_custo_obra_ideal' => 70.0,
        'financiamento_custo_obra_limite' => 75.0,
        'ltv_ideal' => 55.0,
        'ltv_limite' => 60.0,
    ]);

    app(UpdateProposalStatus::class)->handle(
        $proposal->fresh(),
        Proposal::STATUS_AWAITING_INFORMATION,
        $representativeUser,
        'Atualizar vendas da Torre Madrid.',
    );

    $proposal->refresh();
    $proposal->load(['company', 'latestContinuationAccess', 'projects']);

    expect($proposal->status)->toBe(Proposal::STATUS_AWAITING_INFORMATION)
        ->and($proposal->continuationAccesses()->count())->toBe(2)
        ->and($proposal->latestContinuationAccess?->id)->not->toBeNull();

    $latestAccess = $proposal->latestContinuationAccess;

    $this->get(relativePathFromUrl($latestAccess->generated_url))
        ->assertOk();

    $this->post(route('site.proposal.continuation.verify', $latestAccess), [
        'cnpj' => $proposal->company->cnpj,
        'code' => $latestAccess->display_code,
    ])->assertRedirect(route('site.proposal.continuation.form', $latestAccess));

    $this->get(route('site.proposal.continuation.form', $latestAccess))
        ->assertOk()
        ->assertSee('Atualize as informações solicitadas')
        ->assertSee('Torre Madrid');

    $resubmissionPayload = continuationPayload();
    $resubmissionPayload['project_id'] = $proposal->projects()->orderBy('id')->pluck('id')->all();
    $resubmissionPayload['valor_quitadas'][0] = '950.000,00';

    $this->post(route('site.proposal.continuation.store', $latestAccess), $resubmissionPayload)
        ->assertRedirect(route('site.proposal.continuation.form', $latestAccess))
        ->assertSessionHas('success');

    $proposal->refresh();
    $proposal->load(['projects.indicators', 'latestStatusHistory']);

    $updatedFirstProject = $proposal->projects()->oldest('id')->firstOrFail();

    expect($proposal->status)->toBe(Proposal::STATUS_IN_REVIEW)
        ->and($updatedFirstProject->id)->toBe($firstProject->id)
        ->and((float) $updatedFirstProject->paid_sales_value)->toBe(950000.0)
        ->and($updatedFirstProject->indicators)->not->toBeNull()
        ->and($updatedFirstProject->indicators?->id)->toBe($indicator->id)
        ->and((float) $updatedFirstProject->indicators?->ltv_ideal)->toBe(55.0)
        ->and($proposal->latestStatusHistory?->new_status)->toBe(Proposal::STATUS_IN_REVIEW);
});

it('revokes the previous access and records a new send when the link is resent', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

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

it('keeps internal indicators and reports unavailable on the proposer flow', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

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

    $project = $proposal->fresh()->projects()->oldest('id')->firstOrFail();
    $basePath = '/proposta/continuar/'.$access->getRouteKey();

    $this->get(route('site.proposal.continuation.form', $access))
        ->assertOk()
        ->assertDontSee('Indicadores Avançados')
        ->assertDontSee('Salvar Indicadores')
        ->assertDontSee("/proposta/continuar/{$access->getRouteKey()}/empreendimentos/{$project->id}/relatorio")
        ->assertDontSee("/proposta/continuar/{$access->getRouteKey()}/empreendimentos/{$project->id}/analitico")
        ->assertDontSee('Analítico');

    $this->post("{$basePath}/indicadores", [
        'financiamento_custo_obra_ideal' => 55.5,
        'financiamento_custo_obra_limite' => 60.0,
        'ltv_ideal' => 65.0,
        'ltv_limite' => 75.0,
    ])->assertNotFound();

    $this->get("{$basePath}/empreendimentos/{$project->id}/relatorio")
        ->assertNotFound();

    $this->get("{$basePath}/empreendimentos/{$project->id}/analitico")
        ->assertNotFound();

    expect($project->fresh()->indicators)->toBeNull();
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

if (! function_exists('continuationPayload')) {
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
            'tipo_total' => [60, 60],
            'tipo_dormitorios' => ['3', '2'],
            'tipo_vagas' => ['2', '1'],
            'tipo_area' => [82.5, 58.4],
            'tipo_preco_medio' => ['850.000,00', '520.000,00'],
            'arquivos' => [
                UploadedFile::fake()->create('memorial-descritivo.pdf', 128, 'application/pdf'),
            ],
        ];
    }
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
