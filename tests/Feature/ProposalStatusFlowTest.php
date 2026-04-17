<?php

use App\Actions\Proposals\UpdateProposalStatus;
use App\DTOs\Proposals\UpdateProposalStatusDTO;
use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\Pages\ListProposals;
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

    $assignedProposal = createProposalForRepresentative($representative, ProposalStatus::InReview->value);
    $otherProposal = createProposalForRepresentative($otherRepresentative, ProposalStatus::AwaitingInformation->value);

    $this->actingAs($representativeUser);

    expect(ProposalResource::getEloquentQuery()->pluck('id')->all())
        ->toBe([$assignedProposal->id]);

    $this->actingAs($adminUser);

    expect(ProposalResource::getEloquentQuery()->pluck('id')->all())
        ->toBe([$assignedProposal->id, $otherProposal->id]);
});

it('renders the proposals list page for admin users with legacy proposal statuses', function () {
    $adminUser = User::factory()->withTwoFactor()->create([
        'email' => 'admin-list@example.com',
    ]);
    $adminUser->assignRole('admin');

    $representativeUser = User::factory()->create([
        'email' => 'representante-list@example.com',
    ]);
    $representativeUser->assignRole('commercial-representative');

    $representative = ProposalRepresentative::factory()->create([
        'user_id' => $representativeUser->id,
        'email' => $representativeUser->email,
    ]);

    createProposalForRepresentative($representative, 'pending');

    $this->actingAs($adminUser)
        ->get(ListProposals::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Propostas')
        ->assertSee('Pending');
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

    $proposal = createProposalForRepresentative($representative, ProposalStatus::InReview->value);

    $history = app(UpdateProposalStatus::class)->handle(
        $proposal,
        UpdateProposalStatusDTO::fromArray([
            'status' => ProposalStatus::AwaitingInformation->value,
            'user' => $representativeUser,
            'note' => 'Solicitar memorial descritivo atualizado ao cliente.',
        ]),
    );

    expect($proposal->fresh()->status)->toBe(ProposalStatus::AwaitingInformation->value)
        ->and($history->previous_status)->toBe(ProposalStatus::InReview->value)
        ->and($history->new_status)->toBe(ProposalStatus::AwaitingInformation->value)
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

    $proposal = createProposalForRepresentative($representative, ProposalStatus::AwaitingCompletion->value);

    expect(fn () => app(UpdateProposalStatus::class)->handle(
        $proposal,
        UpdateProposalStatusDTO::fromArray([
            'status' => ProposalStatus::InReview->value,
            'user' => $otherUser,
            'note' => 'Tentativa indevida.',
        ]),
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(UpdateProposalStatus::class)->handle(
        $proposal,
        UpdateProposalStatusDTO::fromArray([
            'status' => ProposalStatus::Approved->value,
            'user' => $assignedUser,
            'note' => 'Pular etapas não é permitido.',
        ]),
    ))->toThrow(ValidationException::class);

    expect(fn () => app(UpdateProposalStatus::class)->handle(
        tap($proposal->fresh(), function (Proposal $proposal): void {
            $proposal->forceFill(['status' => ProposalStatus::InReview->value])->save();
        }),
        UpdateProposalStatusDTO::fromArray([
            'status' => ProposalStatus::Rejected->value,
            'user' => $assignedUser,
            'note' => null,
        ]),
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

    $proposal = createProposalForRepresentative($representative, ProposalStatus::InReview->value);

    app(UpdateProposalStatus::class)->handle(
        $proposal,
        UpdateProposalStatusDTO::fromArray([
            'status' => ProposalStatus::Approved->value,
            'user' => $representativeUser,
            'note' => 'Documentação validada.',
        ]),
    );

    Mail::assertSent(ProposalStatusUpdatedMail::class, function (ProposalStatusUpdatedMail $mail) use ($proposal): bool {
        return $mail->proposal->is($proposal->fresh())
            && $mail->status === ProposalStatus::Approved->value;
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
