<?php

use App\Enums\SalesBoardSource;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\Pages\ViewReceivable;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/*
 * A seção "Vendas e Estoque" do Recebível lê a posição da Emissão pelo
 * SalesBoardPositionReader: soma por empreendimento, última posição conhecida
 * de quem não atualizou o quadro e a cobertura ao lado da soma. Antes a tela
 * pegava um único quadro da competência exata com first() e o rotulava como
 * total do empreendimento.
 */

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole('admin');
    $this->actingAs($user);
});

function receivableSalesStockBoard(Construction $construction, string $month, array $values): SalesBoard
{
    return SalesBoard::factory()->forEmissionAndConstruction($construction->emission, $construction)->create([
        'reference_month' => $month,
        'stock_units' => $values['stock_units'] ?? 0,
        'financed_units' => $values['financed_units'] ?? 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => $values['stock_value'] ?? 0,
        'financed_value' => $values['financed_value'] ?? 0,
        'paid_value' => 0,
        'exchanged_value' => 0,
    ]);
}

/**
 * Alfa: 10 unidades em estoque (R$ 1 mi) e 20 vendidas não quitadas (R$ 4 mi).
 * Beta: 50 em estoque (R$ 9 mi) e 50 vendidas não quitadas (R$ 10 mi).
 *
 * @return array{emission: Emission, alfa: Construction, beta: Construction}
 */
function receivableSalesStockEmission(): array
{
    $emission = Emission::factory()->create(['name' => 'CRI Dois Empreendimentos']);

    return [
        'emission' => $emission,
        'alfa' => Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Alfa']),
        'beta' => Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Beta']),
    ];
}

function receivableSalesStockAlfaBoard(Construction $alfa, string $month): SalesBoard
{
    return receivableSalesStockBoard($alfa, $month, [
        'stock_units' => 10, 'stock_value' => 1_000_000,
        'financed_units' => 20, 'financed_value' => 4_000_000,
    ]);
}

function receivableSalesStockBetaBoard(Construction $beta, string $month): SalesBoard
{
    return receivableSalesStockBoard($beta, $month, [
        'stock_units' => 50, 'stock_value' => 9_000_000,
        'financed_units' => 50, 'financed_value' => 10_000_000,
    ]);
}

function receivableSalesStockHtml(Emission $emission, string $month): string
{
    $receivable = Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => $month,
    ]);

    return Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->html();
}

it('sums every construction of the emission instead of showing one board as the total', function () {
    ['emission' => $emission, 'alfa' => $alfa, 'beta' => $beta] = receivableSalesStockEmission();

    receivableSalesStockAlfaBoard($alfa, '2026-08-01');
    receivableSalesStockBetaBoard($beta, '2026-08-01');

    $html = receivableSalesStockHtml($emission, '2026-08-01');

    expect($html)->toContain('TOTAL DA EMISSÃO (VGV)')
        ->and($html)->toContain('R$ 24.000.000,00')
        ->and($html)->not->toContain('R$ 5.000.000,00')
        ->and($html)->toContain('2 de 2')
        ->and($html)->toContain('data-coverage="complete"')
        ->and($html)->toContain('data-construction-id="'.$alfa->id.'"')
        ->and($html)->toContain('data-construction-id="'.$beta->id.'"')
        ->and($html)->toContain('5.000.000,00')
        ->and($html)->toContain('19.000.000,00');
});

it('carries the last known position of a construction without a board in the month', function () {
    ['emission' => $emission, 'alfa' => $alfa, 'beta' => $beta] = receivableSalesStockEmission();

    receivableSalesStockAlfaBoard($alfa, '2026-07-01');
    receivableSalesStockBetaBoard($beta, '2026-08-01');

    $html = receivableSalesStockHtml($emission, '2026-08-01');

    expect($html)->toContain('R$ 24.000.000,00')
        ->and($html)->not->toContain('R$ 19.000.000,00')
        ->and($html)->toContain('data-coverage="partial"')
        ->and($html)->toContain('Última posição conhecida')
        ->and($html)->toContain('entra com o quadro de 07/2026, sem quadro em 08/2026');
});

it('keeps the section visible in a month without any board of its own', function () {
    ['emission' => $emission, 'alfa' => $alfa, 'beta' => $beta] = receivableSalesStockEmission();

    receivableSalesStockAlfaBoard($alfa, '2026-07-01');
    receivableSalesStockBetaBoard($beta, '2026-08-01');

    $html = receivableSalesStockHtml($emission, '2026-09-01');

    expect($html)->toContain('Vendas e Estoque')
        ->and($html)->toContain('R$ 24.000.000,00')
        ->and($html)->toContain('entra com o quadro de 07/2026, sem quadro em 09/2026')
        ->and($html)->toContain('entra com o quadro de 08/2026, sem quadro em 09/2026');
});

it('names the construction that never had a board and leaves it out of the sum', function () {
    ['emission' => $emission, 'alfa' => $alfa] = receivableSalesStockEmission();
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Gama']);

    receivableSalesStockAlfaBoard($alfa, '2026-08-01');

    $html = receivableSalesStockHtml($emission, '2026-08-01');

    expect($html)->toContain('R$ 5.000.000,00')
        ->and($html)->toContain('1 de 3')
        ->and($html)->toContain('Sem quadro de vendas, fora da soma:')
        ->and($html)->toContain('Residencial Beta, Residencial Gama');
});

it('hides the section when no construction has a position up to the month', function () {
    ['emission' => $emission, 'alfa' => $alfa] = receivableSalesStockEmission();

    receivableSalesStockAlfaBoard($alfa, '2026-09-01');

    $html = receivableSalesStockHtml($emission, '2026-08-01');

    expect($html)->not->toContain('Vendas e Estoque')
        ->and($html)->not->toContain('TOTAL DA EMISSÃO (VGV)');
});

it('flags a partial publication of an automated competence', function () {
    $emission = Emission::factory()->create([
        'status' => 'active',
        'sales_board_source' => SalesBoardSource::Automated,
        'sales_board_automation_start_reference_month' => '2026-07-01',
    ]);

    $published = ManagementReviewFixture::submittedCycleOn($emission, '1');
    ManagementReviewFixture::submittedCycleOn($emission, '2');

    ManagementReviewFixture::approve(ManagementReviewFixture::open($published['cycle']));

    $html = receivableSalesStockHtml($emission, '2026-07-01');

    expect(SalesBoard::query()->where('emission_id', $emission->id)->count())->toBe(1)
        ->and($html)->toContain('1 de 2')
        ->and($html)->toContain('data-coverage="partial"')
        ->and($html)->toContain('Ciclo automatizado')
        ->and($html)->toContain('Competência produzida pelo ciclo mensal automatizado: ainda não publicada para os empreendimentos acima.')
        ->and($html)->not->toContain('pela Gestão');
});

it('speaks of the expected constructions when a not-yet-positioned one is listed', function () {
    // Beta só tem quadro a partir de 09/2026: em 08/2026 não é esperado, a
    // cobertura fica completa (1 de 1) e a tabela ainda o lista como "Sem quadro
    // até a competência". A caixa fala dos esperados, não de "todos".
    ['emission' => $emission, 'alfa' => $alfa, 'beta' => $beta] = receivableSalesStockEmission();

    receivableSalesStockAlfaBoard($alfa, '2026-08-01');
    receivableSalesStockBetaBoard($beta, '2026-09-01');

    $html = receivableSalesStockHtml($emission, '2026-08-01');

    expect($html)->toContain('data-coverage="complete"')
        ->and($html)->toContain('1 de 1')
        ->and($html)->toContain('Todos os empreendimentos esperados com o quadro da própria competência.')
        ->and($html)->not->toContain('Todos com o quadro da própria competência.')
        ->and($html)->toContain('data-construction-id="'.$beta->id.'"')
        ->and($html)->toContain('Sem quadro até a competência');
});

it('lists the constructions by name, numbered stages included', function () {
    // O Reader "ordenava" com closures de um argumento, que a Collection chama
    // como comparadores: o nome virava o resultado da comparação, convertido no
    // seu número inicial, e 1ª, 2ª, 3ª saíam como 2ª, 3ª, 1ª.
    $emission = Emission::factory()->create();
    $third = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => '3ª Etapa']);
    $first = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => '1ª Etapa']);
    $second = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => '2ª Etapa']);

    foreach ([$third, $first, $second] as $construction) {
        receivableSalesStockAlfaBoard($construction, '2026-08-01');
    }

    $html = receivableSalesStockHtml($emission, '2026-08-01');
    $rowOffset = fn (Construction $construction): int|false => strpos($html, 'data-construction-id="'.$construction->id.'"');

    expect($rowOffset($first))->toBeInt()
        ->and($rowOffset($first))->toBeLessThan($rowOffset($second))
        ->and($rowOffset($second))->toBeLessThan($rowOffset($third));
});

it('marks a manually registered board as such', function () {
    ['emission' => $emission, 'alfa' => $alfa] = receivableSalesStockEmission();

    receivableSalesStockAlfaBoard($alfa, '2026-08-01');

    expect(receivableSalesStockHtml($emission, '2026-08-01'))->toContain('Registro manual')
        ->not->toContain('Ciclo automatizado');
});

it('does not load the sales boards of every emission in the receivables listing', function () {
    ['emission' => $emission, 'alfa' => $alfa, 'beta' => $beta] = receivableSalesStockEmission();

    receivableSalesStockAlfaBoard($alfa, '2026-08-01');
    receivableSalesStockBetaBoard($beta, '2026-08-01');
    Receivable::factory()->create(['emission_id' => $emission->id, 'reference_month' => '2026-08-01']);

    $salesBoardQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$salesBoardQueries): void {
        if (preg_match('/from\s+[`"]?sales_boards[`"]?(\s|$)/i', $query->sql) === 1) {
            $salesBoardQueries++;
        }
    });

    Livewire::test(ListReceivables::class)->assertSuccessful();

    expect(ReceivableResource::getEloquentQuery()->getEagerLoads())->not->toHaveKey('emission.salesBoards')
        ->and($salesBoardQueries)->toBe(0);
});

it('reads the position once per render of the view page', function () {
    ['emission' => $emission, 'alfa' => $alfa] = receivableSalesStockEmission();

    receivableSalesStockAlfaBoard($alfa, '2026-08-01');
    $receivable = Receivable::factory()->create(['emission_id' => $emission->id, 'reference_month' => '2026-08-01']);

    $salesBoardQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$salesBoardQueries): void {
        if (preg_match('/from\s+[`"]?sales_boards[`"]?(\s|$)/i', $query->sql) === 1) {
            $salesBoardQueries++;
        }
    });

    Livewire::test(ViewReceivable::class, ['record' => $receivable->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('TOTAL DA EMISSÃO (VGV)');

    expect($salesBoardQueries)->toBe(1);
});
