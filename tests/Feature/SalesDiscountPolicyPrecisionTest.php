<?php

use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Models\Construction;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * O arredondamento que se quer evitar é o da coluna `decimal(5,2)` do MySQL: o
 * arquivo roda também lá.
 */
pest()->group('parity');

/**
 * O limite de desconto é gravado em `decimal(5,2)`. Um percentual com mais
 * casas seria arredondado pelo banco -- 4,255% viraria 4,26%, e uma venda com
 * 4,26% de desconto passaria a ser conforme sem que ninguém tivesse aprovado
 * esse limite. A recusa acontece antes de gravar, na tela e no servidor.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00'));
});

function precisionTestRegister(Construction $construction, mixed $percent): SalesDiscountPolicy
{
    return app(SalesDiscountPolicyRegistrar::class)->register(
        $construction,
        [
            'maximum_discount_percent' => $percent,
            'effective_from' => '2026-10-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Aprovação comercial',
        ],
        null,
        auth()->user(),
    );
}

it('refuses percentages with more than two decimal places instead of rounding them', function (mixed $percent) {
    expect(IntegerMoney::basisPoints($percent))->toBeNull();
})->with([
    'três casas com ponto' => ['4.255'],
    'três casas com vírgula' => ['4,255'],
    'arredondaria para cima' => ['4.999'],
    'quatro casas' => ['4.2551'],
    'float com três casas' => [4.255],
]);

it('keeps accepting two decimal places and zeros beyond them', function (mixed $percent, int $expected) {
    expect(IntegerMoney::basisPoints($percent))->toBe($expected);
})->with([
    'duas casas' => ['4.25', 425],
    'zero à direita' => ['4.250', 425],
    'float' => [4.25, 425],
    'vírgula' => ['4,25%', 425],
    'inteiro' => [5, 500],
]);

it('refuses a third decimal place in the form', function (string $percent) {
    $construction = Construction::factory()->create();

    Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ])
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => $percent,
            'effective_from' => '2026-10-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Aprovação comercial',
        ])
        ->assertHasActionErrors(['maximum_discount_percent'])
        ->assertMountedActionModalSee('Informe o desconto com no máximo duas casas decimais.');

    expect(SalesDiscountPolicy::count())->toBe(0);
})->with([['4.255'], ['4.999']]);

it('refuses a third decimal place in the registrar, whatever the caller', function (mixed $percent) {
    $construction = Construction::factory()->create();

    expect(fn () => precisionTestRegister($construction, $percent))
        ->toThrow(SalesDiscountPolicyPeriodException::class, 'O desconto máximo precisa estar entre 0% e 100%, com no máximo duas casas decimais.')
        ->and(SalesDiscountPolicy::count())->toBe(0);
})->with([['4.255'], ['4.999'], [4.255], ['100.01'], ['-1'], ['abc'], ['']]);

it('stores the percentage the registrar validated, in the column format', function (mixed $percent, string $stored) {
    $policy = precisionTestRegister(Construction::factory()->create(), $percent);

    expect($policy->fresh()->maximum_discount_percent)->toBe($stored);
})->with([
    'ponto' => ['4.25', '4.25'],
    'vírgula' => ['4,25', '4.25'],
    'inteiro' => ['5', '5.00'],
    'extremo inferior' => ['0', '0.00'],
    'extremo superior' => ['100', '100.00'],
]);
