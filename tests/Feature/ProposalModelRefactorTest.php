<?php

use App\Actions\Proposals\UpdateProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes translated status and company address accessors', function () {
    $company = ProposalCompany::query()->create([
        'name' => 'Construtora Exemplo',
        'cnpj' => '12.345.678/0001-99',
        'cep' => '04567-000',
        'logradouro' => 'Rua das Palmeiras',
        'numero' => '100',
        'bairro' => 'Jardins',
        'cidade' => 'São Paulo',
        'estado' => 'SP',
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Maria Souza',
        'email' => 'maria@example.com',
    ]);

    $proposal = Proposal::query()->create([
        'company_id' => $company->id,
        'contact_id' => $contact->id,
        'status' => Proposal::STATUS_AWAITING_INFORMATION,
    ])->load('company');

    expect($proposal->company_address)->toBe('Rua das Palmeiras, 100 - Jardins. São Paulo/SP - CEP: 04567-000')
        ->and($proposal->status_label)->toBe(__('proposals.status.'.Proposal::STATUS_AWAITING_INFORMATION))
        ->and($proposal->status_color)->toBe('warning');
});

it('moves phone summary formatting to the proposal contact model', function () {
    $company = ProposalCompany::query()->create([
        'name' => 'Construtora Exemplo',
        'cnpj' => '98.765.432/0001-10',
    ]);

    $contact = ProposalContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Maria Souza',
        'email' => 'maria@example.com',
        'phone_personal' => '(11) 99999-0000',
        'whatsapp' => true,
        'phone_company' => '(11) 4000-0000',
    ]);

    expect($contact->phone_summary)->toBe('Pessoal: (11) 99999-0000 (WhatsApp) | Empresa: (11) 4000-0000');
});

it('keeps status transition rules outside the proposal model', function () {
    $options = app(UpdateProposalStatus::class)->availableStatusOptions(Proposal::STATUS_IN_REVIEW);

    expect($options)->toBe([
        Proposal::STATUS_AWAITING_INFORMATION => __('proposals.status.'.Proposal::STATUS_AWAITING_INFORMATION),
        Proposal::STATUS_APPROVED => __('proposals.status.'.Proposal::STATUS_APPROVED),
        Proposal::STATUS_REJECTED => __('proposals.status.'.Proposal::STATUS_REJECTED),
    ])
        ->and(method_exists(Proposal::class, 'allowedStatusTransitions'))->toBeFalse()
        ->and(method_exists(Proposal::class, 'nextAvailableStatusOptions'))->toBeFalse();
});
