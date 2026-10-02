<?php

use App\Exceptions\SalesBoardRolloutException;
use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardWriteGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\SalesBoardAnomalyFixture;

/**
 * O registro manual avisa antes o que a gravação recusaria.
 *
 * O guard de escrita continua sendo a autoridade: quadro publicado pelo ciclo
 * mensal não recebe versão manual, competência automatizada não é registrada
 * à mão, e o empreendimento não ganha quadro fora da Emissão dele nem uma
 * segunda posição no mesmo mês. A tela só pergunta antes, pela mesma regra --
 * "Nova Atualização" desabilitada com o motivo, e a recusa no próprio campo da
 * competência.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * Um quadro publicado pelo caminho normal da governança.
 */
function publishedManualBoard(): SalesBoard
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $result = ManagementReviewFixture::approve(ManagementReviewFixture::open($scenario['cycle']));

    return $result->salesBoard->fresh();
}

/**
 * Uma Emissão automatizada a partir de 08/2026, com uma obra.
 *
 * @return array{emission: Emission, construction: Construction}
 */
function automatedFromAugust(): array
{
    $emission = Emission::factory()->withAutomatedSalesBoard('2026-08-01')->create(['status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    return ['emission' => $emission, 'construction' => $construction];
}

/**
 * @return array<string, mixed>
 */
function manualBoardFormData(Emission $emission, Construction $construction, string $referenceMonth): array
{
    return [
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => $referenceMonth,
        'stock_units' => 2,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => '1.000.000,00',
        'financed_value' => '0,00',
        'paid_value' => '0,00',
        'exchanged_value' => '0,00',
    ];
}

it('disables Nova Atualização on a board published by the cycle, with the reason', function () {
    $board = publishedManualBoard();

    Livewire::test(ViewSalesBoard::class, ['record' => $board->getKey()])
        ->assertActionVisible('newUpdate')
        ->assertActionDisabled('newUpdate')
        ->assertActionExists('newUpdate', fn (Action $action): bool => $action->getTooltip() === 'Este quadro foi publicado pelo fluxo de governança: não recebe nova versão manual.');
});

it('keeps Nova Atualização enabled on a manually recorded board', function () {
    $board = SalesBoard::factory()->create();

    Livewire::test(ViewSalesBoard::class, ['record' => $board->getKey()])
        ->assertActionEnabled('newUpdate')
        ->assertActionExists('newUpdate', fn (Action $action): bool => $action->getTooltip() === 'Registra uma nova posição a partir da atual, preservando o histórico.');
});

it('disables the row action on the list without a query per row', function () {
    $published = publishedManualBoard();
    $manual = SalesBoard::factory()->count(3)->create();

    $publicationQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$publicationQueries): void {
        if (str_contains(strtolower($query->sql), 'sales_board_publications')) {
            $publicationQueries++;
        }
    });

    $page = Livewire::test(ListSalesBoards::class)
        ->assertCanSeeTableRecords([$published, ...$manual]);

    expect($publicationQueries)->toBe(1);

    $page->assertActionDisabled(TestAction::make('newUpdate')->table($published))
        ->assertActionExists(
            TestAction::make('newUpdate')->table($published),
            fn (Action $action): bool => $action->getTooltip() === ViewSalesBoard::PUBLISHED_BOARD_TOOLTIP,
        )
        ->assertActionEnabled(TestAction::make('newUpdate')->table($manual[0]));
});

it('refuses an automated competence next to the month field before saving', function () {
    ['emission' => $emission, 'construction' => $construction] = automatedFromAugust();
    $refusal = app(SalesBoardWriteGuard::class)->manualWriteRefusal($emission->id, $construction->id, '08/2026');

    $page = Livewire::test(CreateSalesBoard::class)
        ->fillForm(manualBoardFormData($emission, $construction, '08/2026'))
        ->assertSee($refusal)
        ->call('create')
        ->assertHasFormErrors(['reference_month'])
        ->assertNotNotified('Registro manual recusado');

    expect($refusal)->toStartWith('A Emissão está no modo automatizado a partir desta competência.')
        ->and($page->errors()->get('data.reference_month'))->toContain($refusal)
        ->and(SalesBoard::query()->count())->toBe(0);
});

it('accepts a competence before the automation start of the same emission', function () {
    ['emission' => $emission, 'construction' => $construction] = automatedFromAugust();

    Livewire::test(CreateSalesBoard::class)
        ->fillForm(manualBoardFormData($emission, $construction, '07/2026'))
        ->assertDontSee('A Emissão está no modo automatizado a partir desta competência.')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SalesBoard::query()->sole()->reference_month->toDateString())->toBe('2026-07-01');
});

it('answers manualWriteRefusal with the same message the guard throws', function (string $case) {
    $guard = app(SalesBoardWriteGuard::class);

    [$emissionId, $constructionId, $month, $candidate] = match ($case) {
        'publicado' => (function (): array {
            $board = publishedManualBoard();

            return [$board->emission_id, $board->construction_id, $board->reference_month->format('m/Y'), $board];
        })(),
        'automatizado' => (function (): array {
            ['emission' => $emission, 'construction' => $construction] = automatedFromAugust();

            return [$emission->id, $construction->id, '09/2026', new SalesBoard([
                'emission_id' => $emission->id,
                'construction_id' => $construction->id,
                'reference_month' => '2026-09-01',
            ])];
        })(),
        'livre' => (function (): array {
            $construction = CycleFixture::construction();

            return [$construction->emission_id, $construction->id, '06/2025', new SalesBoard([
                'emission_id' => $construction->emission_id,
                'construction_id' => $construction->id,
                'reference_month' => '2025-06-01',
            ])];
        })(),
        'fora da Emissão' => (function (): array {
            $construction = Construction::factory()->create([
                'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
            ]);
            $other = Emission::factory()->create(['status' => 'active']);

            return [$other->id, $construction->id, '07/2026', new SalesBoard([
                'emission_id' => $other->id,
                'construction_id' => $construction->id,
                'reference_month' => '2026-07-01',
            ])];
        })(),
        'mês ocupado' => (function (): array {
            $emission = Emission::factory()->create(['status' => 'active']);
            $construction = Construction::factory()->create(['emission_id' => $emission->id]);

            // Carga feita por fora do model, com o dia 15: a tela procura o dia 01.
            SalesBoardAnomalyFixture::misplacedBoard($emission, $construction, '2026-07-15');

            return [$emission->id, $construction->id, '07/2026', new SalesBoard([
                'emission_id' => $emission->id,
                'construction_id' => $construction->id,
                'reference_month' => '2026-07-01',
            ])];
        })(),
    };

    $thrown = null;

    try {
        $guard->assertCanWrite($candidate);
    } catch (SalesBoardRolloutException $exception) {
        $thrown = $exception->getMessage();
    }

    expect($guard->manualWriteRefusal($emissionId, $constructionId, $month))->toBe($thrown);

    match ($case) {
        'publicado' => expect($thrown)->toBe(SalesBoardRolloutException::publishedBoardIsImmutable()->getMessage()),
        'automatizado' => expect($thrown)->toStartWith('A Emissão está no modo automatizado'),
        'livre' => expect($thrown)->toBeNull(),
        'fora da Emissão' => expect($thrown)->toContain('um quadro fora da Emissão dele seria somado nas duas'),
        'mês ocupado' => expect($thrown)->toContain('já tem Quadro de Vendas em 07/2026'),
    };
})->with([
    'publicado',
    'automatizado',
    'livre',
    'fora da Emissão',
    'mês ocupado',
]);

it('refuses a second position of the construction next to the month field before saving', function () {
    $emission = Emission::factory()->create(['status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    // Uma carga feita por fora do model deixou o mês com o dia 15.
    $loaded = SalesBoardAnomalyFixture::misplacedBoard($emission, $construction, '2026-07-15');
    $refusal = app(SalesBoardWriteGuard::class)->manualWriteRefusal($emission->id, $construction->id, '07/2026');

    $page = Livewire::test(CreateSalesBoard::class)
        ->fillForm(manualBoardFormData($emission, $construction, '07/2026'))
        ->assertSee($refusal)
        ->call('create')
        ->assertHasFormErrors(['reference_month'])
        ->assertNotNotified('Registro manual recusado');

    expect($refusal)->toStartWith(sprintf('O empreendimento %s já tem Quadro de Vendas em 07/2026', $construction->development_name))
        ->and($page->errors()->get('data.reference_month'))->toContain($refusal)
        ->and(SalesBoard::query()->pluck('id')->all())->toBe([$loaded->id]);
});
