<?php

use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Filament\Resources\SalesBoards\RelationManagers\SalesBoardHistoriesRelationManager;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function salesBoardForStatus(string $status, array $attributes = []): SalesBoard
{
    $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    $salesBoard = SalesBoard::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-08-01',
        'stock_units' => 30,
        'financed_units' => 70,
        'paid_units' => 0,
        'exchanged_units' => 0,
        ...$attributes,
    ]);

    if ($status !== Emission::STATUS_DRAFT) {
        $emission->update(['status' => $status]);
    }

    return $salesBoard->refresh();
}

it('allows repeated changes in the same competence while the emission is being elaborated', function () {
    $salesBoard = salesBoardForStatus(Emission::STATUS_DRAFT);

    $salesBoard->update(['stock_units' => 25]);
    $salesBoard->update(['stock_units' => 20]);

    expect($salesBoard->refresh()->stock_units)->toBe(20)
        ->and($salesBoard->valueHistories()->count())->toBe(3)
        ->and($salesBoard->valueHistories()->whereNotNull('change_reason')->count())->toBe(0);
});

it('requires a reason for a second change of the same competence once consolidated', function () {
    $salesBoard = salesBoardForStatus('active');

    expect(fn () => $salesBoard->update(['stock_units' => 20]))
        ->toThrow(ValidationException::class, 'Já existe um registro do Quadro de Vendas para esta competência. Informe o motivo da alteração.');

    expect($salesBoard->refresh()->stock_units)->toBe(30);
});

it('requires a reason for every further change of the same competence', function () {
    $salesBoard = salesBoardForStatus('active');

    $salesBoard->changeReason = 'Conciliação com a incorporadora.';
    $salesBoard->update(['stock_units' => 20]);

    expect(fn () => $salesBoard->update(['stock_units' => 15]))
        ->toThrow(ValidationException::class);

    $salesBoard->changeReason = 'Nova conciliação.';
    $salesBoard->update(['stock_units' => 15]);

    $reasons = $salesBoard->valueHistories()->whereNotNull('change_reason')->pluck('change_reason')->all();

    expect($reasons)->toContain('Conciliação com a incorporadora.')
        ->and($reasons)->toContain('Nova conciliação.');
});

it('does not require a reason for the first record of a new competence', function () {
    $salesBoard = salesBoardForStatus('active');

    $salesBoard->update(['reference_month' => '2026-09-01', 'stock_units' => 20]);

    expect($salesBoard->refresh()->reference_month->toDateString())->toBe('2026-09-01')
        ->and($salesBoard->valueHistories()->count())->toBe(2);
});

it('scopes the competence rule to each construction of the operation', function () {
    $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    $constructionA = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'A']);
    $constructionB = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'B']);

    $boardA = SalesBoard::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $constructionA->id,
        'reference_month' => '2026-08-01',
    ]);
    $boardB = SalesBoard::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $constructionB->id,
        'reference_month' => '2026-08-01',
    ]);

    $emission->update(['status' => 'active']);

    $boardA->changeReason = 'Ajuste no empreendimento A.';
    $boardA->update(['stock_units' => 99]);

    // B has never been changed in 08/2026, so its own first change stays free.
    expect(fn () => $boardB->refresh()->update(['stock_units' => 5]))
        ->toThrow(ValidationException::class);

    expect($boardA->valueHistories()->whereNotNull('change_reason')->count())->toBe(1)
        ->and($boardB->valueHistories()->whereNotNull('change_reason')->count())->toBe(0);
});

it('keeps every version of the same competence in the history', function () {
    $salesBoard = salesBoardForStatus('active');

    Carbon::setTestNow('2026-08-10 11:32:00');
    $salesBoard->changeReason = 'Versão 2.';
    $salesBoard->update(['stock_units' => 25]);

    Carbon::setTestNow('2026-08-18 14:35:00');
    $salesBoard->changeReason = 'Versão 3.';
    $salesBoard->update(['stock_units' => 20]);

    $versions = $salesBoard->valueHistories()->orderBy('id')->get();

    expect($versions)->toHaveCount(3)
        ->and($versions->pluck('stock_units')->all())->toBe([30, 25, 20])
        ->and($versions->pluck('reference_month')->map->toDateString()->unique()->all())->toBe(['2026-08-01'])
        ->and($versions->last()->created_at->toDateTimeString())->toBe('2026-08-18 14:35:00');
});

it('records the author of each version', function () {
    $user = makeSalesBoardAdminUser();
    $this->actingAs($user);

    $salesBoard = salesBoardForStatus('active');

    $salesBoard->changeReason = 'Correção pontual.';
    $salesBoard->update(['stock_units' => 20]);

    expect($salesBoard->valueHistories()->latest('id')->first()->changedBy?->id)->toBe($user->id);
});

it('requires the reason through the new update form and stores it on the version', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus('active');

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->assertFormFieldVisible('change_reason')
        ->fillForm(['stock_units' => 20])
        ->call('create')
        ->assertHasFormErrors(['change_reason' => 'Informe o motivo da alteração.']);

    expect($salesBoard->refresh()->stock_units)->toBe(30);

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->fillForm([
            'stock_units' => 20,
            'change_reason' => 'Correção dos valores após conciliação com a incorporadora.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $version = $salesBoard->refresh()->valueHistories()->latest('id')->first();

    expect($salesBoard->stock_units)->toBe(20)
        ->and($version->change_reason)->toBe('Correção dos valores após conciliação com a incorporadora.')
        ->and($version->stock_units)->toBe(20);
});

it('opens the new update prefilled with the current position', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus(Emission::STATUS_DRAFT, [
        'stock_units' => 10,
        'financed_units' => 30,
        'paid_units' => 5,
        'exchanged_units' => 2,
        'stock_value' => 1000000,
        'financed_value' => 3500000,
        'paid_value' => 500000,
        'exchanged_value' => 200000,
    ]);

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->assertFormSet([
            'emission_id' => $salesBoard->emission_id,
            'construction_id' => $salesBoard->construction_id,
            'reference_month' => '08/2026',
            'stock_units' => 10,
            'financed_units' => 30,
            'paid_units' => 5,
            'exchanged_units' => 2,
            'total_units' => 47,
            'stock_value' => '1.000.000,00',
            'financed_value' => '3.500.000,00',
            'paid_value' => '500.000,00',
            'exchanged_value' => '200.000,00',
        ]);
});

it('creates a separate position when the competence changes', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus('active');

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->assertFormFieldVisible('change_reason')
        ->fillForm(['reference_month' => '09/2026', 'stock_units' => 8])
        ->assertFormFieldHidden('change_reason')
        ->call('create')
        ->assertHasNoFormErrors();

    $boards = SalesBoard::query()->where('construction_id', $salesBoard->construction_id)->orderBy('reference_month')->get();

    expect($boards)->toHaveCount(2)
        ->and($boards->first()->reference_month->toDateString())->toBe('2026-08-01')
        ->and($boards->first()->stock_units)->toBe(30)
        ->and($boards->last()->reference_month->toDateString())->toBe('2026-09-01')
        ->and($boards->last()->stock_units)->toBe(8);
});

it('records a new version instead of touching the previous one for the same competence', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus('active');
    $versionsBefore = $salesBoard->valueHistories()->orderBy('id')->pluck('stock_units')->all();

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->assertFormFieldVisible('change_reason')
        ->fillForm([
            'stock_units' => 8,
            'change_reason' => 'Correção após conciliação.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $salesBoard->refresh();

    expect(SalesBoard::query()->count())->toBe(1)
        ->and($salesBoard->stock_units)->toBe(8)
        ->and($versionsBefore)->toBe([30])
        ->and($salesBoard->valueHistories()->orderBy('id')->pluck('stock_units')->all())->toBe([30, 8])
        ->and($salesBoard->valueHistories()->latest('id')->first()->change_reason)->toBe('Correção após conciliação.')
        ->and($salesBoard->initialPosition->stock_units)->toBe(30);
});

it('blocks a same competence update submitted without a reason', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus('active');

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->fillForm(['stock_units' => 8])
        ->call('create')
        ->assertHasFormErrors(['change_reason' => 'Informe o motivo da alteração.']);

    expect($salesBoard->refresh()->stock_units)->toBe(30)
        ->and($salesBoard->valueHistories()->count())->toBe(1);
});

it('opens an empty form when there is no position to start from', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    Livewire::test(CreateSalesBoard::class)
        ->assertFormSet([
            'emission_id' => null,
            'construction_id' => null,
            'stock_units' => 0,
            'financed_units' => 0,
        ]);

    expect((new CreateSalesBoard)->getTitle())->toBe('Adicionar Quadro de Vendas');
});

it('hides the reason field while the emission is being elaborated', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus(Emission::STATUS_DRAFT);

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->assertFormFieldHidden('change_reason')
        ->fillForm(['stock_units' => 20])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($salesBoard->refresh()->stock_units)->toBe(20);
});

it('marks the initial position and the version in force on the history table', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus('active');

    $salesBoard->changeReason = 'Ajuste.';
    $salesBoard->update(['stock_units' => 20]);

    Livewire::test(SalesBoardHistoriesRelationManager::class, [
        'ownerRecord' => $salesBoard->refresh(),
        'pageClass' => ViewSalesBoard::class,
    ])
        ->assertSee('Início da Operação')
        ->assertSee('Vigente');
});

it('opens the change reason details from the history table', function () {
    $user = makeSalesBoardAdminUser();
    $this->actingAs($user);

    $salesBoard = salesBoardForStatus('active');

    Carbon::setTestNow('2026-08-18 14:35:00');
    $salesBoard->changeReason = 'Correção dos valores após conciliação com a incorporadora.';
    $salesBoard->update(['stock_units' => 20]);

    $version = $salesBoard->valueHistories()->latest('id')->first();
    $initialVersion = $salesBoard->valueHistories()->orderBy('id')->first();

    Livewire::test(SalesBoardHistoriesRelationManager::class, [
        'ownerRecord' => $salesBoard->refresh(),
        'pageClass' => ViewSalesBoard::class,
    ])
        ->assertActionHidden(TestAction::make('viewChangeReason')->table($initialVersion))
        ->assertActionVisible(TestAction::make('viewChangeReason')->table($version))
        ->mountAction(TestAction::make('viewChangeReason')->table($version))
        ->assertActionMounted(TestAction::make('viewChangeReason')->table($version));

    // The modal body is rendered lazily by Filament, so the detail block is
    // asserted through the data it is built from.
    expect($version->changedBy?->name)->toBe($user->name)
        ->and($version->created_at->format('d/m/Y').' às '.$version->created_at->format('H:i'))->toBe('18/08/2026 às 14:35')
        ->and(SalesBoard::formatReferenceMonthForDisplay($version->reference_month))->toBe('08/2026')
        ->and($version->change_reason)->toBe('Correção dos valores após conciliação com a incorporadora.');
});

it('shows the initial and current positions side by side on the view page', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus('active');

    $salesBoard->changeReason = 'Ajuste.';
    $salesBoard->update(['stock_units' => 20, 'financed_units' => 80]);

    Livewire::test(ViewSalesBoard::class, ['record' => $salesBoard->getRouteKey()])
        ->assertSee('Dados da Operação')
        ->assertSee('Início da Operação')
        ->assertSee('Posição inicial da operação, consolidada quando a emissão deixou o status "Em Elaboração". Imutável.')
        ->assertSee('Posição Atual')
        ->assertSee('Última posição registrada para este empreendimento.');

    expect($salesBoard->refresh()->initialPosition->stock_units)->toBe(30)
        ->and($salesBoard->stock_units)->toBe(20);
});

it('announces that the initial position is still pending while in elaboration', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = salesBoardForStatus(Emission::STATUS_DRAFT);

    Livewire::test(ViewSalesBoard::class, ['record' => $salesBoard->getRouteKey()])
        ->assertSee('Será consolidada quando a emissão deixar "Em Elaboração".');
});
