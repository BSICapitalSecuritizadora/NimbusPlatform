<?php

use App\Actions\Emissions\RecalculateObligationStatusesAction;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\ObligationHistoryEntry;
use App\Models\User;
use App\Services\Obligations\ObligationWorkflowService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function workflowRelationManager(Emission $emission): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(ObligationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ]);
}

function workflowHistoryEntry(Obligation $obligation, string $eventType): ?ObligationHistoryEntry
{
    return $obligation->historyEntries()
        ->where('event_type', $eventType)
        ->latest('occurred_at')
        ->latest('id')
        ->first();
}

function makeWorkflowUserWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('no longer allows freely editing the obligation status from the main form', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create([
        'status' => 'a_vencer',
        'priority' => 'high',
        'title' => 'Obrigação original',
    ]);

    workflowRelationManager($emission)
        ->callTableAction('edit', $obligation, data: [
            'title' => 'Obrigação atualizada',
            'priority' => 'high',
            'status' => 'concluida',
        ])
        ->assertHasNoTableActionErrors();

    expect($obligation->fresh()->status)->toBe('a_vencer')
        ->and($obligation->fresh()->title)->toBe('Obrigação atualizada');
});

it('submits an eligible obligation for review through the guided action and records history', function () {
    $user = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsSubmitForReview->value,
    ]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create([
        'status' => 'a_vencer',
    ]);

    workflowRelationManager($emission)
        ->assertTableActionVisible('submit_for_review', $obligation)
        ->callTableAction('submit_for_review', $obligation, data: [
            'note' => 'Encaminhada para validação operacional.',
        ])
        ->assertHasNoTableActionErrors();

    $obligation->refresh();
    $history = workflowHistoryEntry($obligation, ObligationHistoryEntry::EVENT_SUBMITTED_FOR_REVIEW);

    expect($obligation->status)->toBe('em_analise')
        ->and($obligation->submitted_for_review_at)->not->toBeNull()
        ->and($obligation->submitted_for_review_by)->toBe($user->id)
        ->and($obligation->review_submission_notes)->toBe('Encaminhada para validação operacional.')
        ->and($history)->not->toBeNull()
        ->and($history->source)->toBe(ObligationHistoryEntry::SOURCE_WORKFLOW)
        ->and($history->old_values['status'])->toBe('a_vencer')
        ->and($history->new_values['status'])->toBe('em_analise');
});

it('concludes an obligation with approved evidence and stores completion metadata', function () {
    $user = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsComplete->value,
    ]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create([
        'status' => 'em_analise',
    ]);
    ObligationEvidence::factory()->approved()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $emission->id,
    ]);

    workflowRelationManager($emission)
        ->callTableAction('complete_obligation', $obligation, data: [
            'completion_notes' => 'Comprovante anexado e obrigação encerrada.',
        ])
        ->assertHasNoTableActionErrors();

    $obligation->refresh();
    $history = workflowHistoryEntry($obligation, ObligationHistoryEntry::EVENT_COMPLETED);

    expect($obligation->status)->toBe('concluida')
        ->and($obligation->completed_at)->not->toBeNull()
        ->and($obligation->completed_by)->toBe($user->id)
        ->and($obligation->completion_notes)->toBe('Comprovante anexado e obrigação encerrada.')
        ->and($history)->not->toBeNull()
        ->and($history->metadata['completed_without_approved_evidence'])->toBeFalse()
        ->and($history->metadata['approved_evidence_count'])->toBe(1);
});

it('allows concluding without evidence only when explicitly confirmed', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsComplete->value,
    ]);
    $obligation = Obligation::factory()->create([
        'status' => 'a_vencer',
    ]);

    try {
        $workflow->complete($obligation, $actor, 'Cumprida sem comprovante formal.', false);
        $this->fail('Expected the workflow to require explicit confirmation without evidence.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('confirm_without_evidence');
    }

    $workflow->complete($obligation->fresh(), $actor, 'Cumprida sem comprovante formal.', true);
    $history = workflowHistoryEntry($obligation->fresh(), ObligationHistoryEntry::EVENT_COMPLETED);

    expect($obligation->fresh()->status)->toBe('concluida')
        ->and($history)->not->toBeNull()
        ->and($history->metadata['completed_without_approved_evidence'])->toBeTrue();
});

it('does not count pending evidence as valid for conclusion', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsComplete->value,
    ]);
    $obligation = Obligation::factory()->create([
        'status' => 'em_analise',
    ]);
    ObligationEvidence::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $obligation->emission_id,
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    expect(fn () => $workflow->complete($obligation, $actor, 'Há apenas evidência pendente.', false))
        ->toThrow(ValidationException::class);
});

it('does not count rejected evidence as valid for conclusion', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsComplete->value,
    ]);
    $obligation = Obligation::factory()->create([
        'status' => 'em_analise',
    ]);
    ObligationEvidence::factory()->rejected()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $obligation->emission_id,
    ]);

    expect(fn () => $workflow->complete($obligation, $actor, 'Há apenas evidência rejeitada.', false))
        ->toThrow(ValidationException::class);
});

it('marks an obligation as not applicable with a required reason', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsMarkNotApplicable->value,
    ]);
    $obligation = Obligation::factory()->create([
        'status' => 'vencida',
    ]);

    $workflow->markNotApplicable($obligation, $actor, 'A condição contratual não se materializou.');
    $obligation->refresh();
    $history = workflowHistoryEntry($obligation, ObligationHistoryEntry::EVENT_MARKED_NOT_APPLICABLE);

    expect($obligation->status)->toBe('nao_aplicavel')
        ->and($obligation->not_applicable_at)->not->toBeNull()
        ->and($obligation->not_applicable_by)->toBe($actor->id)
        ->and($obligation->not_applicable_reason)->toBe('A condição contratual não se materializou.')
        ->and($history)->not->toBeNull()
        ->and($history->old_values['status'])->toBe('vencida')
        ->and($history->new_values['status'])->toBe('nao_aplicavel');
});

it('reopens a completed obligation and recalculates the operational status from the due date', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsReopen->value,
    ]);
    $obligation = Obligation::factory()->create([
        'status' => 'concluida',
        'due_date' => now()->subDays(2),
        'completed_at' => now()->subDay(),
        'completed_by' => $actor->id,
        'completion_notes' => 'Concluída anteriormente.',
    ]);

    $workflow->reopen($obligation, $actor, 'Foi identificada pendência documental.');
    $obligation->refresh();
    $history = workflowHistoryEntry($obligation, ObligationHistoryEntry::EVENT_REOPENED);

    expect($obligation->status)->toBe('vencida')
        ->and($obligation->reopened_at)->not->toBeNull()
        ->and($obligation->reopened_by)->toBe($actor->id)
        ->and($obligation->reopen_reason)->toBe('Foi identificada pendência documental.')
        ->and($history)->not->toBeNull()
        ->and($history->metadata['reopened_to_status'])->toBe('vencida');
});

it('reopens a not-applicable obligation to a vencer when the due date is today or in the future', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsReopen->value,
    ]);
    $obligation = Obligation::factory()->create([
        'status' => 'nao_aplicavel',
        'due_date' => now()->addDay(),
    ]);

    $workflow->reopen($obligation, $actor, 'A obrigação voltou a ser aplicável.');

    expect($obligation->fresh()->status)->toBe('a_vencer');
});

it('reopens a terminal obligation to em_dia when no due date exists', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsReopen->value,
    ]);
    $obligation = Obligation::factory()->create([
        'status' => 'concluida',
        'due_date' => null,
    ]);

    $workflow->reopen($obligation, $actor, 'Sem vencimento fixo, retorna ao estado ativo padrão.');

    expect($obligation->fresh()->status)->toBe('em_dia');
});

it('blocks invalid workflow transitions', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsSubmitForReview->value,
        AccessPermission::ObligationsComplete->value,
    ]);
    $concluded = Obligation::factory()->create(['status' => 'concluida']);
    $notApplicable = Obligation::factory()->create(['status' => 'nao_aplicavel']);

    expect(fn () => $workflow->submitForReview($concluded, $actor, 'Tentativa inválida.'))
        ->toThrow(ValidationException::class);

    expect(fn () => $workflow->complete($notApplicable, $actor, 'Tentativa inválida.', true))
        ->toThrow(ValidationException::class);
});

it('blocks workflow transitions when the actor lacks the required granular permission', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = User::factory()->create();

    expect(fn () => $workflow->submitForReview(
        Obligation::factory()->create(['status' => 'a_vencer']),
        $actor,
        'Sem permissão específica.',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $workflow->complete(
        Obligation::factory()->create(['status' => 'em_analise']),
        $actor,
        'Sem permissão específica.',
        true,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $workflow->markNotApplicable(
        Obligation::factory()->create(['status' => 'vencida']),
        $actor,
        'Sem permissão específica.',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $workflow->reopen(
        Obligation::factory()->create(['status' => 'concluida']),
        $actor,
        'Sem permissão específica.',
    ))->toThrow(AuthorizationException::class);
});

it('keeps workflow-managed statuses protected from automatic recalculation', function () {
    $workflow = app(ObligationWorkflowService::class);
    $actor = makeWorkflowUserWithPermissions([
        AccessPermission::ObligationsSubmitForReview->value,
        AccessPermission::ObligationsComplete->value,
        AccessPermission::ObligationsMarkNotApplicable->value,
    ]);

    $completed = Obligation::factory()->create([
        'status' => 'a_vencer',
        'due_date' => now()->subDays(5),
    ]);
    ObligationEvidence::factory()->approved()->create([
        'obligation_id' => $completed->id,
        'emission_id' => $completed->emission_id,
    ]);
    $workflow->complete($completed, $actor, 'Encerrada com comprovante.');

    $reviewing = Obligation::factory()->create([
        'status' => 'a_vencer',
        'due_date' => now()->subDays(5),
    ]);
    $workflow->submitForReview($reviewing, $actor, 'Aguardando validação.');

    $waived = Obligation::factory()->create([
        'status' => 'vencida',
        'due_date' => now()->subDays(5),
    ]);
    $workflow->markNotApplicable($waived, $actor, 'Evento contratual não ocorreu.');

    app(RecalculateObligationStatusesAction::class)->handle();

    expect($completed->fresh()->status)->toBe('concluida')
        ->and($reviewing->fresh()->status)->toBe('em_analise')
        ->and($waived->fresh()->status)->toBe('nao_aplicavel');
});
