<?php

use App\Actions\Proposals\CalculateProjectIndicators;
use App\Actions\Proposals\StoreProjectIndicatorParameters;
use App\Enums\ProposalStatus;
use App\Jobs\ScanFileForMalware;
use App\Models\Proposal;
use App\Models\ProposalContinuationAccess;
use App\Models\ProposalRepresentative;
use App\Models\ProposalSector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('stores validated continuation submissions through the legacy-compatible public endpoint', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()
        ->with('latestContinuationAccess')
        ->firstOrFail();

    $access = $proposal->latestContinuationAccess;

    expect($access)->not->toBeNull();

    $this->withSession(proposalContinuationSessionState($access))
        ->post(route('site.proposal.continuation.store', $access), controllerContinuationPayload())
        ->assertRedirect(route('site.proposal.continuation.form', $access))
        ->assertSessionHas('success');

    $proposal->refresh();
    $proposal->load('projects');

    expect($proposal->status)->toBe(ProposalStatus::InReview->value)
        ->and($proposal->completed_at)->not->toBeNull()
        ->and($proposal->projects)->toHaveCount(2)
        ->and($proposal->projects->pluck('name')->all())->toBe([
            'Torre Madrid',
            'Torre Manchester',
        ]);
});

it('rejects an invalid direct endpoint payload before the storage action', function () {
    Mail::fake();
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['queue_position' => 1]);
    submitInitialProposalThroughComponent($sector);
    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();

    $this->withSession(proposalContinuationSessionState($proposal->latestContinuationAccess))
        ->post(route('site.proposal.continuation.store', $proposal->latestContinuationAccess), [
            'nome' => '',
            'nome_empreendimento' => [],
            'valor_solicitado' => 'not-money',
        ])
        ->assertSessionHasErrors(['nome', 'nome_empreendimento', 'valor_solicitado', 'previsao_entrega']);

    expect($proposal->projects()->count())->toBe(0)
        ->and($proposal->fresh()->completed_at)->toBeNull();
});

it('authorizes the direct endpoint before validating its payload', function () {
    Mail::fake();
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['queue_position' => 1]);
    submitInitialProposalThroughComponent($sector);
    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();

    $this->post(route('site.proposal.continuation.store', $proposal->latestContinuationAccess), [])
        ->assertForbidden();

    expect($proposal->projects()->count())->toBe(0)
        ->and($proposal->fresh()->completed_at)->toBeNull();
});

it('rejects misaligned parallel arrays in the direct endpoint', function () {
    Mail::fake();
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['queue_position' => 1]);
    submitInitialProposalThroughComponent($sector);
    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();
    $payload = controllerContinuationPayload();
    $payload['tipo_vagas'] = ['1 vaga'];

    $this->withSession(proposalContinuationSessionState($proposal->latestContinuationAccess))
        ->post(route('site.proposal.continuation.store', $proposal->latestContinuationAccess), $payload)
        ->assertSessionHasErrors('tipo_vagas');

    expect($proposal->projects()->count())->toBe(0)
        ->and($proposal->fresh()->completed_at)->toBeNull();
});

it('synchronizes project removals without duplicating retained projects', function () {
    Mail::fake();
    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['queue_position' => 1]);
    submitInitialProposalThroughComponent($sector);
    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();
    $access = $proposal->latestContinuationAccess;

    $this->withSession(proposalContinuationSessionState($access))
        ->post(route('site.proposal.continuation.store', $access), controllerContinuationPayload())
        ->assertSessionHas('success');

    $projectIds = $proposal->projects()->orderBy('id')->pluck('id')->all();
    $retainedProject = $proposal->projects()->findOrFail($projectIds[0]);
    app(StoreProjectIndicatorParameters::class)->handle($retainedProject, [
        'financiamento_custo_obra_ideal' => 70,
        'financiamento_custo_obra_limite' => 90,
    ]);
    $valueBeforeResubmission = collect(app(CalculateProjectIndicators::class)->handle($retainedProject->fresh())['indicators'])
        ->firstWhere('key', 'financiamento_custo_obra')['value'];
    $proposal->forceFill(['status' => ProposalStatus::AwaitingInformation->value])->save();
    $payload = controllerContinuationPayload();
    $payload['valor_solicitado'] = '25.000.000,00';

    foreach ([
        'nome_empreendimento',
        'unidades_permutadas',
        'unidades_quitadas',
        'unidades_nao_quitadas',
        'unidades_estoque',
        'custo_incidido',
        'custo_a_incorrer',
        'valor_quitadas',
        'valor_nao_quitadas',
        'valor_estoque',
        'valor_ja_recebido',
        'valor_ate_chaves',
        'valor_chaves_pos',
    ] as $field) {
        $payload[$field] = array_slice($payload[$field], 0, 1);
    }

    $payload['project_id'] = [$projectIds[0]];

    $this->withSession(proposalContinuationSessionState($access))
        ->post(route('site.proposal.continuation.store', $access), $payload)
        ->assertSessionHas('success');

    $retainedProject->refresh()->load('indicators');
    $valueAfterResubmission = collect(app(CalculateProjectIndicators::class)->handle($retainedProject)['indicators'])
        ->firstWhere('key', 'financiamento_custo_obra')['value'];

    expect($proposal->projects()->pluck('id')->all())->toBe([$projectIds[0]])
        ->and((float) $retainedProject->indicators->financiamento_custo_obra_ideal)->toBe(70.0)
        ->and((float) $retainedProject->indicators->financiamento_custo_obra_limite)->toBe(90.0)
        ->and($valueAfterResubmission)->not->toBe($valueBeforeResubmission);
});

it('rejects file uploads with disallowed MIME types in the continuation store', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();
    $access = $proposal->latestContinuationAccess;

    expect($access)->not->toBeNull();

    $this->withSession(proposalContinuationSessionState($access))
        ->post(route('site.proposal.continuation.store', $access), array_merge(
            controllerContinuationPayload(),
            ['arquivos' => [UploadedFile::fake()->create('exploit.exe', 100, 'application/x-msdownload')]],
        ))
        ->assertSessionHasErrors('arquivos.0');
});

it('stores a checksum on uploaded proposal files (M-3)', function () {
    Mail::fake();
    Queue::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['name' => 'Representante Comercial', 'queue_position' => 1]);
    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();
    $access = $proposal->latestContinuationAccess;

    $this->withSession(proposalContinuationSessionState($access))
        ->post(route('site.proposal.continuation.store', $access), array_merge(
            controllerContinuationPayload(),
            ['arquivos' => [UploadedFile::fake()->create('planta.pdf', 100, 'application/pdf')]],
        ))
        ->assertSessionHas('success');

    $file = $proposal->fresh()->files->first();
    expect($file)->not->toBeNull()
        ->and($file->checksum)->not->toBeNull()
        ->and(strlen($file->checksum))->toBe(64);
});

it('dispatches ScanFileForMalware job after storing proposal files (M-8)', function () {
    Mail::fake();
    Queue::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);
    ProposalRepresentative::factory()->create(['name' => 'Representante Comercial', 'queue_position' => 1]);
    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();
    $access = $proposal->latestContinuationAccess;

    $this->withSession(proposalContinuationSessionState($access))
        ->post(route('site.proposal.continuation.store', $access), array_merge(
            controllerContinuationPayload(),
            ['arquivos' => [UploadedFile::fake()->create('planta.pdf', 100, 'application/pdf')]],
        ))
        ->assertSessionHas('success');

    Queue::assertPushed(ScanFileForMalware::class, 1);
});

it('rejects file uploads that exceed the maximum allowed size', function () {
    Mail::fake();

    $sector = ProposalSector::query()->create(['name' => 'Incorporação']);

    ProposalRepresentative::factory()->create([
        'name' => 'Representante Comercial',
        'queue_position' => 1,
    ]);

    submitInitialProposalThroughComponent($sector);

    $proposal = Proposal::query()->with('latestContinuationAccess')->firstOrFail();
    $access = $proposal->latestContinuationAccess;

    $this->withSession(proposalContinuationSessionState($access))
        ->post(route('site.proposal.continuation.store', $access), array_merge(
            controllerContinuationPayload(),
            // 21 MB PDF — over the 20 MB limit
            ['arquivos' => [UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf')]],
        ))
        ->assertSessionHasErrors('arquivos.0');
});

/**
 * @return array<string, mixed>
 */
function controllerContinuationPayload(): array
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
