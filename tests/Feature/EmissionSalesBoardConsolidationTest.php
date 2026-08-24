<?php

use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Updates a board whose emission already left elaboration, supplying the
 * justification the governance rule requires.
 */
function updateConsolidatedSalesBoard(SalesBoard $salesBoard, array $attributes, string $reason = 'Conciliação com a incorporadora.'): void
{
    $salesBoard->changeReason = $reason;
    $salesBoard->update($attributes);
}

/**
 * @return array{0: Emission, 1: Construction, 2: SalesBoard}
 */
function draftEmissionWithSalesBoard(array $salesBoardAttributes = []): array
{
    $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Empreendimento A',
    ]);
    $salesBoard = SalesBoard::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-05-01',
        'stock_units' => 30,
        'financed_units' => 70,
        'paid_units' => 0,
        'exchanged_units' => 0,
        ...$salesBoardAttributes,
    ]);

    return [$emission, $construction, $salesBoard];
}

it('records no initial position while the emission is being elaborated', function () {
    [$emission, , $salesBoard] = draftEmissionWithSalesBoard();

    $salesBoard->update(['stock_units' => 25, 'financed_units' => 75]);
    $emission->update(['name' => 'Nome revisado durante a elaboração']);

    expect($emission->refresh()->isInDraft())->toBeTrue()
        ->and($salesBoard->refresh()->hasInitialPosition())->toBeFalse()
        ->and(SalesBoardHistory::query()->initial()->count())->toBe(0)
        ->and($salesBoard->valueHistories()->count())->toBe(2);
});

it('consolidates the current position when the emission leaves elaboration', function () {
    [$emission, , $salesBoard] = draftEmissionWithSalesBoard();

    $emission->update(['status' => 'active']);

    $initialPosition = $salesBoard->refresh()->initialPosition;

    expect($initialPosition)->toBeInstanceOf(SalesBoardHistory::class)
        ->and($initialPosition->is_initial)->toBeTrue()
        ->and($initialPosition->reference_month->toDateString())->toBe('2026-05-01')
        ->and($initialPosition->stock_units)->toBe(30)
        ->and($initialPosition->financed_units)->toBe(70)
        ->and($initialPosition->paid_units)->toBe(0)
        ->and($initialPosition->exchanged_units)->toBe(0)
        ->and($initialPosition->total_units)->toBe(100)
        ->and((float) $initialPosition->stock_value)->toBe((float) $salesBoard->stock_value)
        ->and((float) $initialPosition->financed_value)->toBe((float) $salesBoard->financed_value)
        ->and((float) $initialPosition->paid_value)->toBe((float) $salesBoard->paid_value)
        ->and((float) $initialPosition->exchanged_value)->toBe((float) $salesBoard->exchanged_value);
});

it('keeps the consolidated position untouched by later monthly updates', function () {
    [$emission, , $salesBoard] = draftEmissionWithSalesBoard();

    $emission->update(['status' => 'active']);

    updateConsolidatedSalesBoard($salesBoard, ['stock_units' => 20, 'financed_units' => 80]);

    $salesBoard->refresh();

    expect($salesBoard->stock_units)->toBe(20)
        ->and($salesBoard->financed_units)->toBe(80)
        ->and($salesBoard->total_units)->toBe(100)
        ->and($salesBoard->initialPosition->stock_units)->toBe(30)
        ->and($salesBoard->initialPosition->financed_units)->toBe(70)
        ->and($salesBoard->initialPosition->total_units)->toBe(100);
});

it('does not create a new initial position on further status changes', function () {
    [$emission, , $salesBoard] = draftEmissionWithSalesBoard();

    $emission->update(['status' => 'active']);
    updateConsolidatedSalesBoard($salesBoard, ['stock_units' => 20, 'financed_units' => 80]);

    $emission->update(['status' => 'default']);
    $emission->update(['status' => 'closed']);

    expect($salesBoard->refresh()->valueHistories()->where('is_initial', true)->count())->toBe(1)
        ->and($salesBoard->initialPosition->stock_units)->toBe(30);
});

it('does not consolidate again when an emission returns to elaboration and leaves it', function () {
    [$emission, , $salesBoard] = draftEmissionWithSalesBoard();

    $emission->update(['status' => 'active']);
    updateConsolidatedSalesBoard($salesBoard, ['stock_units' => 20, 'financed_units' => 80]);

    $emission->update(['status' => Emission::STATUS_DRAFT]);
    $emission->update(['status' => 'active']);

    expect($salesBoard->refresh()->valueHistories()->where('is_initial', true)->count())->toBe(1)
        ->and($salesBoard->initialPosition->stock_units)->toBe(30);
});

it('consolidates every construction of the emission', function () {
    [$emission, , $salesBoardA] = draftEmissionWithSalesBoard();

    $constructionB = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Empreendimento B',
    ]);
    $salesBoardB = SalesBoard::factory()->create([
        'emission_id' => $emission->id,
        'construction_id' => $constructionB->id,
        'reference_month' => '2026-05-01',
        'stock_units' => 5,
        'financed_units' => 1,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    $emission->update(['status' => 'active']);

    expect($salesBoardA->refresh()->initialPosition?->total_units)->toBe(100)
        ->and($salesBoardB->refresh()->initialPosition?->total_units)->toBe(6);
});

it('blocks leaving elaboration while a construction has no sales board', function () {
    [$emission] = draftEmissionWithSalesBoard();

    Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Empreendimento Sem Quadro',
    ]);

    expect(fn () => $emission->update(['status' => 'active']))
        ->toThrow(
            ValidationException::class,
            'Não é possível alterar o status da emissão. Existem empreendimentos sem o Quadro de Vendas preenchido: Empreendimento Sem Quadro.',
        );

    expect($emission->refresh()->status)->toBe(Emission::STATUS_DRAFT)
        ->and(SalesBoardHistory::query()->initial()->count())->toBe(0);
});

it('names every construction that is missing its sales board', function () {
    [$emission] = draftEmissionWithSalesBoard();

    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Empreendimento Z']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Empreendimento M']);

    try {
        $emission->update(['status' => 'active']);
    } catch (ValidationException $exception) {
        expect($exception->validator->errors()->first('status'))
            ->toContain('Empreendimento M, Empreendimento Z');

        return;
    }

    $this->fail('A mudança de status deveria ter sido bloqueada.');
});

it('keeps other emission fields editable while in elaboration', function () {
    [$emission] = draftEmissionWithSalesBoard();

    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Sem Quadro']);

    $emission->update(['name' => 'Ainda em estruturação']);

    expect($emission->refresh()->name)->toBe('Ainda em estruturação');
});

it('preserves manual history entries alongside the consolidated position', function () {
    [$emission, , $salesBoard] = draftEmissionWithSalesBoard();

    $salesBoard->snapshotTrackedValues();

    $emission->update(['status' => 'active']);

    expect($salesBoard->refresh()->valueHistories()->count())->toBe(2)
        ->and($salesBoard->valueHistories()->where('is_initial', false)->count())->toBe(1)
        ->and($salesBoard->initialPosition)->not->toBeNull();
});

it('announces the pending consolidation while the emission is in elaboration', function () {
    $this->actingAs(makeAdminUser());

    [, , $salesBoard] = draftEmissionWithSalesBoard();

    Livewire::test(ViewSalesBoard::class, ['record' => $salesBoard->getRouteKey()])
        ->assertSee('Início da Operação')
        ->assertSee('Será consolidada quando a emissão deixar "Em Elaboração".');
});

it('shows the consolidated position once the emission leaves elaboration', function () {
    $this->actingAs(makeAdminUser());

    [$emission, , $salesBoard] = draftEmissionWithSalesBoard();

    $emission->update(['status' => 'active']);
    updateConsolidatedSalesBoard($salesBoard, ['stock_units' => 20, 'financed_units' => 80]);

    Livewire::test(ViewSalesBoard::class, ['record' => $salesBoard->getRouteKey()])
        ->assertSee('Início da Operação')
        ->assertSee('Posição inicial da operação, consolidada quando a emissão deixou o status "Em Elaboração". Imutável.');
});
