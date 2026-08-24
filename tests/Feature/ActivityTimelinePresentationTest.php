<?php

use App\Enums\ProposalStatus;
use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Emission;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use App\Models\User;
use App\Support\ActivityLog\ActivityPresenter;
use App\Support\ActivityLog\ActivityTimelineItem;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function makeProposalForTimeline(array $attributes = []): Proposal
{
    $company = ProposalCompany::query()->create(['name' => 'HEADINVEST ASSET MANAGEMENT LTDA', 'cnpj' => validTestCnpj(900)]);
    $contact = ProposalContact::query()->create(['company_id' => $company->id, 'name' => 'Thiago Rodrigo Bryan Fogaça', 'email' => 'thiago@example.com']);

    return Proposal::query()->create(array_merge([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'submission_token' => 'token-tecnico-123',
        'status' => ProposalStatus::AwaitingCompletion->value,
    ], $attributes));
}

function timelineItemFor(Proposal $proposal, string $event): ActivityTimelineItem
{
    $activity = $proposal->activities()->where('event', $event)->latest('id')->firstOrFail();

    return ActivityPresenter::present($activity);
}

it('humanizes the proposal creation event and resolves internal ids to names', function () {
    $proposal = makeProposalForTimeline();

    $item = timelineItemFor($proposal, 'created');

    expect($item->title)->toBe('Proposta criada')
        ->and(collect($item->changes)->firstWhere('key', 'company_id')->new)->toBe('HEADINVEST ASSET MANAGEMENT LTDA')
        ->and(collect($item->changes)->firstWhere('key', 'contact_id')->new)->toBe('Thiago Rodrigo Bryan Fogaça')
        ->and(collect($item->changes)->pluck('key'))->not->toContain('submission_token');
});

it('renders a real status transition with human labels and badge colors', function () {
    $proposal = makeProposalForTimeline();
    $proposal->update(['status' => ProposalStatus::InReview->value]);

    $item = timelineItemFor($proposal, 'updated');
    $status = collect($item->changes)->firstWhere('key', 'status');

    expect($item->title)->toBe('Status alterado')
        ->and($status->old)->toBe('Aguardando Documentação Complementar')
        ->and($status->new)->toBe('Em Análise Técnica')
        ->and($status->hasTransition())->toBeTrue()
        ->and($status->oldColor)->toBe('warning')
        ->and($status->newColor)->toBe('info');
});

it('does not present unchanged values as changes', function () {
    $proposal = makeProposalForTimeline();

    $activity = Activity::query()->create([
        'log_name' => 'default',
        'subject_type' => $proposal->getMorphClass(),
        'subject_id' => $proposal->getKey(),
        'event' => 'updated',
        'description' => 'updated',
        'properties' => [
            'attributes' => ['status' => 'em_analise'],
            'old' => ['status' => 'em_analise'],
        ],
    ]);

    $item = ActivityPresenter::present($activity);

    expect($item->changes)->toBe([])
        ->and($item->title)->toBe('Registro atualizado');
});

it('presents distribution events with the representative name and queue position', function () {
    $representative = ProposalRepresentative::factory()->create(['name' => 'Thales Elias Juan Freitas']);
    $proposal = makeProposalForTimeline();

    $proposal->update([
        'assigned_representative_id' => $representative->id,
        'distribution_sequence' => 2,
        'distributed_at' => '2026-08-17 20:57:49',
    ]);

    $item = timelineItemFor($proposal, 'updated');
    $changes = collect($item->changes)->keyBy('key');

    expect($item->title)->toBe('Proposta distribuída')
        ->and($changes->get('assigned_representative_id')->new)->toBe('Thales Elias Juan Freitas')
        ->and($changes->get('distribution_sequence')->new)->toBe('#2')
        ->and($changes->get('distributed_at')->new)->toBe('17/08/2026 às 20:57');
});

it('flags long observations and internal notes for clamped rendering', function () {
    $proposal = makeProposalForTimeline();
    $proposal->update(['observations' => str_repeat('Observação extensa. ', 40)]);

    $item = timelineItemFor($proposal, 'updated');

    expect($item->title)->toBe('Observação atualizada')
        ->and(collect($item->changes)->firstWhere('key', 'observations')->isLongText)->toBeTrue();

    $proposal->update(['internal_notes' => 'Parecer interno.']);

    $internal = timelineItemFor($proposal, 'updated');

    expect($internal->title)->toBe('Nota interna atualizada')
        ->and(collect($internal->changes)->firstWhere('key', 'internal_notes')->isInternal)->toBeTrue();
});

it('keeps the full technical payload available for audit', function () {
    $representative = ProposalRepresentative::factory()->create(['name' => 'Roberto Sérgio Henrique Gonçalves']);
    $proposal = makeProposalForTimeline(['assigned_representative_id' => $representative->id]);

    $item = timelineItemFor($proposal, 'created');
    $technical = collect($item->technicalDetails);

    expect($technical->firstWhere('label', 'Novo valor · submission_token')['value'])->toBe('token-tecnico-123')
        ->and($technical->firstWhere('label', 'Novo valor · company_id')['value'])->toBe((string) $proposal->company_id)
        ->and($technical->firstWhere('label', 'Evento')['value'])->toBe('created')
        ->and($item->technicalDetailsAsText)->toContain('submission_token: token-tecnico-123');
});

it('renders the relation manager timeline with humanized content', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create(['name' => 'Anderson Cavalcante']);
    $user->assignRole('super-admin');
    $this->actingAs($user);

    $proposal = makeProposalForTimeline();
    $proposal->update(['status' => ProposalStatus::InReview->value]);

    Livewire::test(ActivitiesRelationManager::class, [
        'ownerRecord' => $proposal,
        'pageClass' => ViewProposal::class,
    ])
        ->assertSuccessful()
        ->assertSee('Proposta criada')
        ->assertSee('Status alterado')
        ->assertSee('Aguardando Documentação Complementar')
        ->assertSee('Em Análise Técnica')
        ->assertSee('Anderson Cavalcante')
        ->assertSee('Ver detalhes técnicos');
});

it('humanizes emission lifecycle events and includes formatted json payload', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Residencial Jardins',
        'bsi_code' => 'BSI-2026-001',
    ]);

    $item = ActivityPresenter::present($emission->activities()->latest('id')->firstOrFail());

    expect($item->title)->toBe('Emissão criada')
        ->and($item->rawJson)->not->toBeNull()
        ->and($item->rawJson)->toContain('CRI Residencial Jardins')
        ->and($item->eventType)->toBe('created')
        ->and($item->eventLabel)->toBe('Criação');
});

it('identifies system actions and provides system badge representation', function () {
    $proposal = makeProposalForTimeline();

    $activity = Activity::query()->create([
        'log_name' => 'default',
        'subject_type' => $proposal->getMorphClass(),
        'subject_id' => $proposal->getKey(),
        'event' => 'updated',
        'description' => 'Rotina automática de verificação',
        'causer_id' => null,
        'causer_type' => null,
        'properties' => [
            'attributes' => ['status' => ProposalStatus::InReview->value],
            'old' => ['status' => ProposalStatus::AwaitingCompletion->value],
        ],
    ]);

    $item = ActivityPresenter::present($activity);

    expect($item->author)->toBe('Sistema')
        ->and($item->isSystem())->toBeTrue()
        ->and($item->authorInitials())->toBe('SI')
        ->and($item->statusChange())->not->toBeNull()
        ->and($item->statusChange()->hasTransition())->toBeTrue();
});
