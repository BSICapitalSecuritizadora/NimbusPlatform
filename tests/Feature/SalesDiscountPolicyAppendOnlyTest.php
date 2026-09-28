<?php

use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * A política responde "o que a BSI autorizava quando aquela venda aconteceu".
 * Editar ou apagar a linha reescreveria a resposta de vendas já julgadas, e
 * nenhuma tela é o único caminho até o banco: a regra vive no model.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00'));
});

it('refuses to edit a registered policy', function (array $changes) {
    $policy = SalesDiscountPolicy::factory()->during('2026-01-01', '2026-12-31')->allowing('5.00')->create();

    expect(fn () => $policy->update($changes))
        ->toThrow(LogicException::class, 'Sales discount policies are append-only');

    $stored = $policy->fresh();

    expect($stored->maximum_discount_percent)->toBe('5.00')
        ->and($stored->effective_from->toDateString())->toBe('2026-01-01')
        ->and($stored->effective_until->toDateString())->toBe('2026-12-31');
})->with([
    'limite' => [['maximum_discount_percent' => '50.00']],
    'fim' => [['effective_until' => '2027-12-31']],
    'início' => [['effective_from' => '2025-01-01']],
]);

it('refuses to edit a policy through forceFill as well', function () {
    $policy = SalesDiscountPolicy::factory()->during('2026-01-01', '2026-12-31')->create();

    expect(fn () => $policy->forceFill(['reason' => 'Motivo reescrito'])->save())
        ->toThrow(LogicException::class);

    expect($policy->fresh()->reason)->toBe('Política comercial aprovada.');
});

it('refuses to delete a registered policy', function () {
    $policy = SalesDiscountPolicy::factory()->during('2026-01-01', '2026-12-31')->create();

    expect(fn () => $policy->delete())
        ->toThrow(LogicException::class, 'Sales discount policies are append-only');

    expect(SalesDiscountPolicy::query()->whereKey($policy->id)->exists())->toBeTrue();
});

it('records the registration in the protected sales board audit trail', function () {
    $construction = Construction::factory()->create();

    Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ])
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '4.50',
            'effective_from' => '2026-10-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Revisão de margem',
        ])
        ->assertHasNoActionErrors();

    $policy = SalesDiscountPolicy::sole();

    $activity = Activity::query()
        ->where('subject_type', $policy->getMorphClass())
        ->where('subject_id', $policy->id)
        ->sole();

    expect($activity->log_name)->toBe('sales_board')
        ->and($activity->event)->toBe('created')
        ->and($activity->causer_id)->toBe(auth()->id())
        ->and($activity->properties['attributes'])->toMatchArray([
            'construction_id' => $construction->id,
            'maximum_discount_percent' => '4.50',
            'reason' => 'Revisão de margem',
        ]);
});
