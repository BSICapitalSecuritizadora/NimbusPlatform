<?php

use App\Actions\Proposals\UpdateProposalStatus;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalCompany;
use App\Models\ProposalContact;
use App\Presenters\ProposalPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes translated status and company address via the presenter', function () {
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
        'status' => ProposalStatus::AwaitingInformation->value,
    ])->load('company');

    $presenter = new ProposalPresenter($proposal);

    expect($presenter->companyAddress())->toBe('Rua das Palmeiras, 100 - Jardins. São Paulo/SP - CEP: 04567-000')
        ->and($presenter->statusLabel())->toBe(__('proposals.status.'.ProposalStatus::AwaitingInformation->value))
        ->and($presenter->statusColor())->toBe('warning');
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
    $options = app(UpdateProposalStatus::class)->availableStatusOptions(ProposalStatus::InReview->value);

    expect($options)->toBe([
        ProposalStatus::AwaitingInformation->value => __('proposals.status.'.ProposalStatus::AwaitingInformation->value),
        ProposalStatus::Approved->value => __('proposals.status.'.ProposalStatus::Approved->value),
        ProposalStatus::Rejected->value => __('proposals.status.'.ProposalStatus::Rejected->value),
    ])
        ->and(method_exists(Proposal::class, 'allowedStatusTransitions'))->toBeFalse()
        ->and(method_exists(Proposal::class, 'nextAvailableStatusOptions'))->toBeFalse();
});
