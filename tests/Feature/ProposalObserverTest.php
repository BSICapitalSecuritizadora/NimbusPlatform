<?php

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Models\ProposalRepresentative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('notifies the assigned representative with the actual company name and current enum status', function () {
    $user = User::factory()->create();
    $representative = ProposalRepresentative::factory()->create(['user_id' => $user->id]);
    $company = ProposalCompany::query()->create(['name' => 'Empresa Observer', 'cnpj' => validTestCnpj(700)]);
    $contact = ProposalContact::query()->create(['company_id' => $company->id, 'name' => 'Contato', 'email' => 'observer@example.com']);

    $proposal = Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'assigned_representative_id' => $representative->id,
        'status' => ProposalStatus::InReview->value,
    ]);

    expect($user->notifications()->count())->toBe(1)
        ->and(json_encode($user->notifications()->firstOrFail()->data))->toContain('Empresa Observer');

    $proposal->forceFill(['status' => ProposalStatus::AwaitingInformation->value])->save();

    expect($user->notifications()->count())->toBe(2)
        ->and($user->notifications()->get()->contains(
            fn ($notification): bool => str_contains(
                json_encode($notification->data, JSON_UNESCAPED_UNICODE),
                'aguardando informações',
            ),
        ))->toBeTrue();
});
