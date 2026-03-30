<?php

use App\Actions\Proposals\UpdateProposalStatus;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Mail\ProposalContinuationLinkMail;
use App\Mail\ProposalStatusUpdatedMail;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use App\Models\ProposalStatusHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Mail::fake();
});

it('limits the commercial representative to proposals assigned to their queue record', function () {
    $representativeUser = User::factory()->create([
        'email' => 'representante@example.com',
    ]);
    $representativeUser->assignRole('commercial-representative');

    $otherRepresentativeUser = User::factory()->create([
        'email' => 'outro-representante@example.com',
    ]);
    $otherRepresentativeUser->assignRole('commercial-representative');

    $adminUser = User::factory()->create([
        'email' => 'admin@example.com',
    ]);
    $adminUser->assignRole('admin');

    $representative = ProposalRepresentative::factory()->create([
        'user_id' => $representativeUser->id,
        'email' => $representativeUser->email,
        'queue_position' => 1,
    ]);
    $otherRepresentative = ProposalRepresentative::factory()->create([
        'user_id' => $otherRepresentativeUser->id,
        'email' => $otherRepresentativeUser->email,
        'queue_position' => 2,
    ]);

    $assignedProposal = createProposalForRepresentative($representative, Proposal::STATUS_IN_REVIEW);
    $otherProposal = createProposalForRepresentative($otherRepresentative, Proposal::STATUS_AWAITING_INFORMATION);

    $this->actingAs($representativeUser);

    expect(ProposalResource::getEloquentQuery()->pluck('id')->all())
        ->toBe([$assignedProposal->id]);

    $this->actingAs($adminUser);

    expect(ProposalResource::getEloquentQuery()->pluck('id')->all())
        ->toBe([$assignedProposal->id, $otherProposal->id]);
});

it('records the status transition history with the responsible user and note', function () {
    $representativeUser = User::factory()->create([
        'email' => 'analista@example.com',
    ]);
    $representativeUser->assignRole('commercial-representative');

    $representative = ProposalRepresentative::factory()->create([
        'user_id' => $representativeUser->id,
        'email' => $representativeUser->email,
    ]);

    $proposal = createProposalForRepresentative($representative, Proposal::STATUS_IN_REVIEW);

    $history = app(UpdateProposalStatus::class)->handle(
        $proposal,
        Proposal::STATUS_AWAITING_INFORMATION,
        $representativeUser,
        'Solicitar memorial descritivo atualizado ao cliente.',
    );

    expect($proposal->fresh()->status)->toBe(Proposal::STATUS_AWAITING_INFORMATION)
        ->and($history->previous_status)->toBe(Proposal::STATUS_IN_REVIEW)
        ->and($history->new_status)->toBe(Proposal::STATUS_AWAITING_INFORMATION)
        ->and($history->changed_by_user_id)->toBe($representativeUser->id)
        ->and($history->note)->toBe('Solicitar memorial descritivo atualizado ao cliente.')
        ->and($history->changed_at)->not->toBeNull()
        ->and($proposal->fresh()->latestStatusHistory?->is($history))->toBeTrue();

    Mail::assertSent(ProposalContinuationLinkMail::class);
});

it('rejects unauthorized or inconsistent status changes', function () {
    $assignedUser = User::factory()->create([
        'email' => 'titular@example.com',
    ]);
    $assignedUser->assignRole('commercial-representative');

    $otherUser = User::factory()->create([
        'email' => 'nao-autorizado@example.com',
    ]);
    $otherUser->assignRole('commercial-representative');

    $representative = ProposalRepresentative::factory()->create([
        'user_id' => $assignedUser->id,
        'email' => $assignedUser->email,
    ]);
    ProposalRepresentative::factory()->create([
        'user_id' => $otherUser->id,
        'email' => $otherUser->email,
        'queue_position' => 2,
    ]);

    $proposal = createProposalForRepresentative($representative, Proposal::STATUS_AWAITING_COMPLETION);

    expect(fn () => app(UpdateProposalStatus::class)->handle(
        $proposal,
        Proposal::STATUS_IN_REVIEW,
        $otherUser,
        'Tentativa indevida.',
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(UpdateProposalStatus::class)->handle(
        $proposal,
        Proposal::STATUS_APPROVED,
        $assignedUser,
        'Pular etapas não é permitido.',
    ))->toThrow(ValidationException::class);

    expect(fn () => app(UpdateProposalStatus::class)->handle(
        tap($proposal->fresh(), function (Proposal $proposal): void {
            $proposal->forceFill(['status' => Proposal::STATUS_IN_REVIEW])->save();
        }),
        Proposal::STATUS_REJECTED,
        $assignedUser,
        null,
    ))->toThrow(ValidationException::class);

    expect(ProposalStatusHistory::query()->count())->toBe(0);
});

it('notifies the client when the proposal is approved', function () {
    $representativeUser = User::factory()->create([
        'email' => 'aprovacao@example.com',
    ]);
    $representativeUser->assignRole('commercial-representative');

    $representative = ProposalRepresentative::factory()->create([
        'user_id' => $representativeUser->id,
        'email' => $representativeUser->email,
    ]);

    $proposal = createProposalForRepresentative($representative, Proposal::STATUS_IN_REVIEW);

    app(UpdateProposalStatus::class)->handle(
        $proposal,
        Proposal::STATUS_APPROVED,
        $representativeUser,
        'Documentação validada.',
    );

    Mail::assertSent(ProposalStatusUpdatedMail::class, function (ProposalStatusUpdatedMail $mail) use ($proposal): bool {
        return $mail->proposal->is($proposal->fresh())
            && $mail->status === Proposal::STATUS_APPROVED;
    });
});

function createProposalForRepresentative(ProposalRepresentative $representative, string $status): Proposal
{
    $company = ProposalCompany::query()->create([
        'name' => "Empresa {$representative->id} {$status}",
        'cnpj' => fake()->unique()->numerify('##.###.###/####-##'),
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
    ]);

    return Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => $representative->id,
        'status' => $status,
        'distribution_sequence' => fake()->numberBetween(1, 999),
        'distributed_at' => now(),
    ]);
}
