<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesDiscountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

it('requires a construction or an emission', function () {
    $this->artisan('sales-boards:generate-cycle')
        ->expectsOutputToContain('Informe --construction ou --emission.')
        ->assertExitCode(1);
});

it('rejects an invalid reference month', function () {
    [$construction] = CycleFixture::readyConstruction(1);

    $this->artisan('sales-boards:generate-cycle', [
        '--construction' => $construction->id,
        '--reference-month' => '13/2026',
    ])
        ->expectsOutputToContain('Competência inválida.')
        ->assertExitCode(1);
});

it('freezes a construction into a cycle', function () {
    [$construction] = CycleFixture::readyConstruction(2);

    $this->artisan('sales-boards:generate-cycle', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
    ])
        ->expectsOutputToContain('GERADO')
        ->assertExitCode(0);

    expect(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1);
});

it('writes nothing on a dry run', function () {
    [$construction] = CycleFixture::readyConstruction(2);

    $this->artisan('sales-boards:generate-cycle', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Simulação')
        ->expectsOutputToContain('SERIA GERADO')
        ->assertExitCode(0);

    expect(SalesBoardCycle::query()->count())->toBe(0)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(0);
});

it('reports an already existing cycle instead of recalculating it', function () {
    [$construction] = CycleFixture::readyConstruction(1);
    CycleFixture::generate($construction);

    $this->artisan('sales-boards:generate-cycle', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
    ])
        ->expectsOutputToContain('JÁ EXISTE')
        ->expectsOutputToContain('recálculo, que é ação própria')
        ->assertExitCode(0);

    expect(SalesBoardCycleBaseline::query()->count())->toBe(1);
});

it('generates the ready constructions of an emission and reports the blocked one', function () {
    $emission = Emission::factory()->create(['status' => 'active']);

    $ready = [];

    foreach (['A', 'C'] as $name) {
        $construction = Construction::factory()->create([
            'emission_id' => $emission->id,
            'development_name' => 'Empreendimento '.$name,
        ]);
        SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2020-01-01')->allowing('10.00')->create();
        DerivationFixture::unit($construction, '101');
        $ready[] = $construction;
    }

    $blocked = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Empreendimento B',
    ]);
    DerivationFixture::unit($blocked, '101', baseValue: null);

    $this->artisan('sales-boards:generate-cycle', [
        '--emission' => $emission->id,
        '--reference-month' => '07/2026',
    ])
        ->expectsOutputToContain('BLOQUEADO')
        ->expectsOutputToContain('2 gerado(s)')
        ->assertExitCode(0);

    expect(SalesBoardCycle::query()->pluck('construction_id')->sort()->values()->all())
        ->toBe(collect($ready)->pluck('id')->sort()->values()->all());
});

it('refuses to freeze a competência of an emission still in draft', function () {
    [$construction] = CycleFixture::readyConstruction(1, Emission::STATUS_DRAFT);

    $this->artisan('sales-boards:generate-cycle', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
    ])
        ->expectsOutputToContain('BLOQUEADO')
        ->assertExitCode(0);

    expect(SalesBoardCycle::query()->count())->toBe(0);
});

it('returns the result as json', function () {
    [$construction] = CycleFixture::readyConstruction(1);

    $this->artisan('sales-boards:generate-cycle', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
        '--json' => true,
    ])->assertExitCode(0);

    expect(SalesBoardCycle::query()->count())->toBe(1);
});
