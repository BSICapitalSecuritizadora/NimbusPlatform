<?php

use App\Filament\Resources\BankApplications\Pages\ViewBankApplication;
use App\Filament\Resources\FundAllocations\FundAllocationResource;
use App\Filament\Resources\FundAllocations\Pages\CreateFundAllocation;
use App\Filament\Resources\FundAllocations\Pages\ListFundAllocations;
use App\Filament\Resources\FundAllocations\Pages\ViewFundAllocation;
use App\Filament\Resources\FundAllocations\RelationManagers\FundAllocationRevisionsRelationManager;
use App\Filament\Resources\FundAllocations\Schemas\FundAllocationDraftLines;
use App\Filament\Resources\Funds\Pages\EditFund;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\Fund;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FundAllocationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeAllocationAdminUser(): User
{
    $user = User::factory()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole('admin');

    return $user;
}

function makeAllocationScenario(): array
{
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $snapshot = $service->recordReferenceBalance($maker, $application, '2026-09', '1000.00');
    $draft = $service->createDraft($maker, $application, '2026-09', $snapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '600.00'],
    ]);

    return compact('maker', 'checker', 'application', 'fund', 'snapshot', 'draft');
}

it('sits under Financeiro Fundos after the bank application entry', function () {
    expect(FundAllocationResource::getNavigationGroup())->toBe('Financeiro')
        ->and(FundAllocationResource::getNavigationParentItem())->toBe('Fundos')
        ->and(FundAllocationResource::getNavigationSort())->toBe(17)
        ->and(FundAllocationResource::getNavigationLabel())->toBe('Alocações por Competência');
});

it('renders the allocation list with subheading, empty state and filters', function () {
    $this->actingAs(makeAllocationAdminUser());

    Livewire::test(ListFundAllocations::class)
        ->assertOk()
        ->assertSee('Distribuição mensal do saldo de cada aplicação física')
        ->assertSee('Nenhuma alocação por competência')
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Nova alocação')
        ->assertTableFilterExists('competence')
        ->assertTableFilterExists('bank_account_id')
        ->assertTableFilterExists('bank_application_id')
        ->assertTableFilterExists('emission_id');
});

it('lists periods with status, amounts and the shared indicator', function () {
    $scenario = makeAllocationScenario();
    $this->actingAs(makeAllocationAdminUser());

    // A second emission on the same application turns it shared.
    FundAllocationFixture::linkedFund($scenario['application']);

    Livewire::test(ListFundAllocations::class)
        ->assertOk()
        ->assertSee('09/2026')
        ->assertSee('Rascunho')
        ->assertSee('Compartilhado')
        ->assertSee('R$ 600,00')
        ->assertSee('R$ 400,00');
});

it('refuses the list without the view permission', function () {
    $outsider = FundAllocationFixture::userWithPermissions([]);
    $this->actingAs($outsider);

    expect(FundAllocationResource::canViewAny())->toBeFalse();

    Livewire::test(ListFundAllocations::class)->assertForbidden();
});

it('creates a draft recording the reference balance inline', function () {
    $this->actingAs(makeAllocationAdminUser());

    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);

    Livewire::test(CreateFundAllocation::class)
        ->fillForm([
            'bank_account_id' => $application->bank_account_id,
            'bank_application_id' => $application->id,
            'competence' => '09/2026',
            'reference_amount' => '1.000,00',
            'reference_captured_at' => '2026-09-30',
            'reference_note' => 'Fechamento mensal.',
            'lines' => [
                ['fund_id' => (string) $fund->id, 'amount' => '600,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Rascunho salvo com sucesso.');

    $snapshot = BankApplicationBalanceSnapshot::query()->firstOrFail();

    expect($snapshot->version)->toBe(1)
        ->and((string) $snapshot->amount)->toBe('1000.00')
        ->and($snapshot->note)->toBe('Fechamento mensal.');

    $period = FundAllocationPeriod::query()->firstOrFail();
    $revision = $period->openRevision()->firstOrFail();

    expect($revision->status)->toBe(FundAllocationRevision::STATUS_DRAFT)
        ->and((string) $revision->total_amount)->toBe('600.00')
        ->and((string) $revision->unallocated_amount)->toBe('400.00');
});

it('reuses the existing period instead of duplicating it', function () {
    $scenario = makeAllocationScenario();
    $this->actingAs(makeAllocationAdminUser());

    Livewire::test(CreateFundAllocation::class)
        ->fillForm([
            'bank_account_id' => $scenario['application']->bank_account_id,
            'bank_application_id' => $scenario['application']->id,
            'competence' => '09/2026',
            'lines' => [],
        ])
        ->call('create')
        ->assertNotified('Rascunho não salvo.');

    expect(FundAllocationPeriod::query()->count())->toBe(1)
        ->and(FundAllocationRevision::query()->count())->toBe(1);
});

it('routes a second proposal as a governed correction', function () {
    $scenario = makeAllocationScenario();
    $service = FundAllocationFixture::service();
    $service->approve(
        $scenario['checker'],
        $service->submitForReview($scenario['maker'], $scenario['draft']),
        'filament-correction-1',
    );

    $this->actingAs(makeAllocationAdminUser());

    Livewire::test(CreateFundAllocation::class)
        ->fillForm([
            'bank_account_id' => $scenario['application']->bank_account_id,
            'bank_application_id' => $scenario['application']->id,
            'competence' => '09/2026',
            'correction_reason' => 'Ajuste de fechamento.',
            'lines' => [
                ['fund_id' => (string) $scenario['fund']->id, 'amount' => '500,00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Rascunho salvo com sucesso.');

    $correction = FundAllocationRevision::query()
        ->where('status', FundAllocationRevision::STATUS_DRAFT)
        ->firstOrFail();

    expect($correction->isCorrection())->toBeTrue()
        ->and($correction->correction_reason)->toBe('Ajuste de fechamento.')
        ->and((int) $correction->supersedes_revision_id)->toBe($scenario['draft']->id);
});

it('fills empty lines from the approved prior competence suggestion', function () {
    $maker = FundAllocationFixture::maker();
    $checker = FundAllocationFixture::checker();
    $application = FundAllocationFixture::application();
    $fund = FundAllocationFixture::linkedFund($application);
    $service = FundAllocationFixture::service();

    $priorSnapshot = $service->recordReferenceBalance($maker, $application, '2026-08', '1000.00');
    $priorDraft = $service->createDraft($maker, $application, '2026-08', $priorSnapshot->id, [
        ['fund_id' => $fund->id, 'amount' => '400.00'],
    ]);
    $service->approve($checker, $service->submitForReview($maker, $priorDraft), 'filament-suggest-1');
    $service->recordReferenceBalance($maker, $application, '2026-09', '2000.00');

    $this->actingAs(makeAllocationAdminUser());

    $component = Livewire::test(CreateFundAllocation::class)
        ->fillForm([
            'bank_account_id' => $application->bank_account_id,
            'bank_application_id' => $application->id,
            'competence' => '09/2026',
        ])
        ->callAction(TestAction::make('fill_suggestion'))
        ->assertNotified('Sugestão aplicada ao rascunho.');

    // A suggestion alone persists nothing: still a single (August) revision.
    expect(FundAllocationRevision::query()->count())->toBe(1);

    // Saving the suggested lines materializes the exact derived amounts.
    $component
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Rascunho salvo com sucesso.');

    $draft = FundAllocationRevision::query()
        ->whereHas('period', fn ($query) => $query->whereDate('competence', '2026-09-01'))
        ->firstOrFail();

    expect((string) $draft->total_amount)->toBe('800.00')
        ->and((string) $draft->unallocated_amount)->toBe('1200.00');
});

it('never overwrites typed lines with a suggestion', function () {
    $scenario = makeAllocationScenario();
    $this->actingAs(makeAllocationAdminUser());

    Livewire::test(CreateFundAllocation::class)
        ->fillForm([
            'bank_account_id' => $scenario['application']->bank_account_id,
            'bank_application_id' => $scenario['application']->id,
            'competence' => '09/2026',
            'lines' => [
                ['fund_id' => (string) $scenario['fund']->id, 'amount' => '100,00'],
            ],
        ])
        ->callAction(TestAction::make('fill_suggestion'))
        ->assertNotified('Sugestão não aplicada.')
        ->assertFormSet([
            'lines' => [
                ['fund_id' => (string) $scenario['fund']->id, 'amount' => '100,00'],
            ],
        ]);
});

it('renders the period view with reference, summary and lines', function () {
    $scenario = makeAllocationScenario();
    $period = FundAllocationPeriod::query()->firstOrFail();
    $this->actingAs(makeAllocationAdminUser());

    Livewire::test(ViewFundAllocation::class, ['record' => $period->getRouteKey()])
        ->assertOk()
        ->assertSee('Saldo de referência manual')
        ->assertSee('R$ 1.000,00')
        ->assertSee('Resumo financeiro')
        ->assertSee('R$ 400,00')
        ->assertSee('Revisão em aberto')
        ->assertSee('Rascunho');
});

it('runs submit and approve through the governed actions', function () {
    $scenario = makeAllocationScenario();
    $period = FundAllocationPeriod::query()->firstOrFail();

    $this->actingAs($scenario['maker']);

    Livewire::test(ViewFundAllocation::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('submit')
        ->assertActionHidden('approve')
        ->callAction(TestAction::make('submit'))
        ->assertNotified('Revisão enviada para análise.');

    expect($scenario['draft']->refresh()->status)->toBe(FundAllocationRevision::STATUS_PENDING_REVIEW);

    $this->actingAs($scenario['checker']);

    Livewire::test(ViewFundAllocation::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('approve')
        ->callAction(TestAction::make('approve'))
        ->assertNotified('Distribuição aprovada.');

    expect($scenario['draft']->refresh()->status)->toBe(FundAllocationRevision::STATUS_APPROVED)
        ->and((int) $period->refresh()->effective_revision_id)->toBe($scenario['draft']->id);
});

it('hides financial actions from unauthorized users', function () {
    $scenario = makeAllocationScenario();
    $period = FundAllocationPeriod::query()->firstOrFail();

    $viewer = FundAllocationFixture::userWithPermissions(['fund-allocations.view']);
    $this->actingAs($viewer);

    Livewire::test(ViewFundAllocation::class, ['record' => $period->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('record_reference')
        ->assertActionHidden('propose')
        ->assertActionHidden('edit_draft')
        ->assertActionHidden('submit')
        ->assertActionHidden('approve')
        ->assertActionHidden('discard');
});

it('edits and discards drafts through the view actions', function () {
    $scenario = makeAllocationScenario();
    $period = FundAllocationPeriod::query()->firstOrFail();
    $this->actingAs($scenario['maker']);

    Livewire::test(ViewFundAllocation::class, ['record' => $period->getRouteKey()])
        ->callAction(TestAction::make('edit_draft'), [
            'lines' => [
                ['fund_id' => (string) $scenario['fund']->id, 'amount' => '250,00'],
            ],
        ])
        ->assertNotified('Rascunho atualizado.');

    expect((string) $scenario['draft']->refresh()->total_amount)->toBe('250.00');

    Livewire::test(ViewFundAllocation::class, ['record' => $period->getRouteKey()])
        ->callAction(TestAction::make('discard'), ['reason' => 'Refazer.'])
        ->assertNotified('Revisão descartada.');

    expect($scenario['draft']->refresh()->status)->toBe(FundAllocationRevision::STATUS_DISCARDED)
        ->and($period->refresh()->open_revision_id)->toBeNull();
});

it('shows revision history with line drill-down', function () {
    $scenario = makeAllocationScenario();
    $period = FundAllocationPeriod::query()->firstOrFail();
    $this->actingAs(makeAllocationAdminUser());

    expect(FundAllocationResource::getRelations())
        ->toContain(FundAllocationRevisionsRelationManager::class);

    Livewire::test(
        FundAllocationRevisionsRelationManager::class,
        ['ownerRecord' => $period, 'pageClass' => ViewFundAllocation::class],
    )
        ->assertOk()
        ->assertSee('#1')
        ->assertSee('Rascunho')
        ->assertTableActionExists('view_lines');
});

it('summarizes allocations on the bank application view', function () {
    $scenario = makeAllocationScenario();
    $this->actingAs(makeAllocationAdminUser());

    Livewire::test(ViewBankApplication::class, ['record' => $scenario['application']->getRouteKey()])
        ->assertOk()
        ->assertSee('Alocação por competência')
        ->assertSee('R$ 1.000,00')
        ->assertSee('Alocações por Competência');
});

it('scopes emission visibility by permission', function () {
    $scenario = makeAllocationScenario();
    [$named] = FundAllocationFixture::classification('Operação Visível');
    // Direct update: the test only renders listings, so the draft staleness
    // this bypass introduces is irrelevant here (and the linkage guard would
    // rightly refuse an ordinary save while the draft is open).
    Fund::query()->whereKey($scenario['fund']->getKey())->update(['emission_id' => $named->id]);

    $viewer = FundAllocationFixture::userWithPermissions(['fund-allocations.view']);
    $this->actingAs($viewer);

    Livewire::test(ListFundAllocations::class)
        ->assertOk()
        ->assertDontSee('Operação');

    expect(FundAllocationDraftLines::eligibleFundOptions($scenario['application']->id))
        ->toHaveKey('Fundos vinculados');

    $privileged = FundAllocationFixture::userWithPermissions(['fund-allocations.view', 'emissions.view']);
    $this->actingAs($privileged);

    Livewire::test(ListFundAllocations::class)
        ->assertOk()
        ->assertSee('Operação');

    expect(FundAllocationDraftLines::eligibleFundOptions($scenario['application']->id))
        ->toHaveKey('Operação Visível');
});

it('shows the approved slice on the fund form without touching legacy balances', function () {
    $scenario = makeAllocationScenario();
    $service = FundAllocationFixture::service();
    $service->approve(
        $scenario['checker'],
        $service->submitForReview($scenario['maker'], $scenario['draft']),
        'filament-fund-note-1',
    );

    $balanceBefore = $scenario['fund']->refresh()->balance;

    $this->actingAs(makeAllocationAdminUser());

    Livewire::test(EditFund::class, ['record' => $scenario['fund']->getRouteKey()])
        ->assertOk()
        ->assertSee('Alocação aprovada')
        ->assertSee('09/2026')
        ->assertSee('R$ 600,00');

    expect($scenario['fund']->refresh()->balance)->toBe($balanceBefore);
});
