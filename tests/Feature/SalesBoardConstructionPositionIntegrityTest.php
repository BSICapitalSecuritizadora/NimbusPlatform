<?php

use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use App\Models\SalesBoardPublication;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\SalesBoardAnomalyFixture;

/*
 * Uma posição só por empreendimento e competência: a regra 3 do guard de
 * escrita. O leitor da posição lê por empreendimento, sem olhar a Emissão, e um
 * quadro fora da Emissão do empreendimento -- ou um segundo quadro no mesmo
 * mês -- seria somado por duas Emissões.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{emission: Emission, legacy: Emission, construction: Construction}
 */
function positionIntegrityScenario(): array
{
    $emission = Emission::factory()->create(['status' => 'active']);

    return [
        'emission' => $emission,
        'legacy' => Emission::factory()->create(['status' => 'active']),
        'construction' => Construction::factory()->create([
            'emission_id' => $emission->id,
            'development_name' => 'Residencial Alfa',
        ]),
    ];
}

it('refuses a board filed under an emission other than the construction\'s own', function (): void {
    ['emission' => $emission, 'legacy' => $legacy, 'construction' => $construction] = positionIntegrityScenario();

    expect(fn () => SalesBoard::factory()->forEmissionAndConstruction($legacy, $construction)->create([
        'reference_month' => '2026-07-01',
    ]))->toThrow(
        SalesBoardRolloutException::class,
        'O Quadro de Vendas de Residencial Alfa em 07/2026 seria gravado sob a Emissão '.$legacy->name
            .', mas o empreendimento pertence à Emissão '.$emission->name.'. '
            .'A posição é lida por empreendimento, e um quadro fora da Emissão dele seria somado nas duas.',
    );

    // Nada nasceu: nem o quadro, nem a versão do histórico que o observer grava.
    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardHistory::query()->count())->toBe(0);

    // A mesma escrita sob a Emissão do empreendimento passa.
    $board = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01',
    ]);

    expect(SalesBoard::query()->sole()->is($board))->toBeTrue()
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(1);
});

it('refuses a second board for the same construction and month when one already sits under another emission', function (): void {
    ['emission' => $emission, 'legacy' => $legacy, 'construction' => $construction] = positionIntegrityScenario();

    // Carga feita por fora do model, com o dia que o loader deixou.
    $misplaced = SalesBoardAnomalyFixture::misplacedBoard($legacy, $construction, '2026-07-15', ['stock_units' => 3]);

    expect(fn () => SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01',
    ]))->toThrow(
        SalesBoardRolloutException::class,
        'O empreendimento Residencial Alfa já tem Quadro de Vendas em 07/2026, registrado sob a Emissão '.$legacy->name.'. '
            .'Um segundo quadro para o mesmo mês faria a posição ser lida duas vezes; corrija o registro existente.',
    );

    expect(SalesBoard::query()->where('construction_id', $construction->id)->pluck('id')->all())->toBe([$misplaced->id]);

    // Outro mês do mesmo empreendimento continua livre.
    $august = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-08-01',
    ]);

    expect($august->exists)->toBeTrue();
});

it('keeps maintaining the values of a board recorded outside the guard without moving it', function (): void {
    ['legacy' => $legacy, 'construction' => $construction] = positionIntegrityScenario();

    $misplaced = SalesBoardAnomalyFixture::misplacedBoard($legacy, $construction, '2026-07-01', [
        'stock_units' => 3,
        'stock_value' => '300000.00',
    ]);

    $misplaced->changeReason = 'Estoque corrigido pela construtora.';
    $misplaced->update(['stock_units' => 4, 'stock_value' => '400000.00']);

    $misplaced->refresh();

    expect($misplaced->stock_units)->toBe(4)
        ->and((string) $misplaced->stock_value)->toBe('400000.00')
        ->and($misplaced->emission_id)->toBe($legacy->id);
});

/**
 * A carga por fora pode ter deixado o dia diferente de 01. O model normaliza a
 * competência no `saving`, antes do guard, e o dia que muda não é troca de
 * competência: o mês é o mesmo, e a manutenção de valores continua livre.
 */
it('keeps maintaining the values of a board loaded with a day other than 01 without taking it for a change of month', function (): void {
    ['legacy' => $legacy, 'construction' => $construction] = positionIntegrityScenario();

    $misplaced = SalesBoardAnomalyFixture::misplacedBoard($legacy, $construction, '2026-07-15', [
        'stock_units' => 3,
        'stock_value' => '300000.00',
    ]);

    $misplaced->changeReason = 'Estoque corrigido pela construtora.';
    $misplaced->update(['stock_units' => 4, 'stock_value' => '400000.00']);

    $misplaced->refresh();

    expect($misplaced->stock_units)->toBe(4)
        ->and((string) $misplaced->stock_value)->toBe('400000.00')
        ->and($misplaced->emission_id)->toBe($legacy->id)
        ->and($misplaced->reference_month->format('Y-m'))->toBe('2026-07');

    // Mudar o mês continua sendo troca de identidade, e a regra 3 responde.
    $misplaced->changeReason = 'Competência corrigida.';

    expect(fn () => $misplaced->update(['reference_month' => '2026-08-01']))
        ->toThrow(SalesBoardRolloutException::class, 'O Quadro de Vendas de Residencial Alfa em 08/2026 seria gravado sob a Emissão '.$legacy->name);

    expect($misplaced->fresh()->reference_month->format('Y-m-d'))->toBe('2026-07-01');
});

it('moves a misplaced board to the construction\'s emission and marks the guarantee competences of both emissions', function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');
    ['emission' => $emission, 'legacy' => $legacy, 'construction' => $construction] = positionIntegrityScenario();
    $admin = makeAdminUser();

    $misplaced = SalesBoardAnomalyFixture::misplacedBoard($legacy, $construction, '2026-07-01', [
        'stock_units' => 10,
        'stock_value' => '1000000.00',
    ]);

    foreach ([$emission, $legacy] as $guaranteed) {
        Guarantee::factory()
            ->effectiveBetween()
            ->ofType(GuaranteeType::Inventory)
            ->create(['emission_id' => $guaranteed->id, 'legal_status' => GuaranteeLegalStatus::Active]);

        app(GuaranteeSnapshotWriter::class)->persist($guaranteed, '2026-07-01', $admin);
    }

    // As duas Emissões contam a obra em julho: é a dupla contagem que o guard impede de nascer.
    expect(app(SalesBoardPositionReader::class)->forEmission($legacy, CarbonImmutable::parse('2026-07-01'))->stockUnits)->toBe(10);

    Carbon::setTestNow('2026-09-15 10:00:00');

    $misplaced->emission_id = $emission->id;
    $misplaced->save();

    $snapshotOf = fn (Emission $owner): GuaranteeSnapshot => GuaranteeSnapshot::query()
        ->where('emission_id', $owner->id)
        ->whereDate('reference_month', '2026-07-01')
        ->sole();

    expect($misplaced->fresh()->emission_id)->toBe($emission->id)
        ->and($snapshotOf($emission)->isSalesBoardOutdated())->toBeTrue()
        ->and($snapshotOf($legacy)->isSalesBoardOutdated())->toBeTrue()
        ->and(app(SalesBoardPositionReader::class)->forEmission($emission, CarbonImmutable::parse('2026-07-01'))->stockUnits)->toBe(10)
        ->and(app(SalesBoardPositionReader::class)->forEmission($legacy, CarbonImmutable::parse('2026-07-01'))->hasData())->toBeFalse();

    $outdatedEvents = Activity::query()
        ->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)
        ->orderBy('id')
        ->get();

    expect($outdatedEvents)->toHaveCount(2)
        ->and($outdatedEvents->pluck('log_name')->unique()->all())->toBe([GuaranteeSnapshotWriter::LOG_NAME])
        ->and($outdatedEvents->map(fn (Activity $activity): mixed => $activity->properties['sales_board_id'])->unique()->all())->toBe([$misplaced->id])
        ->and($outdatedEvents->map(fn (Activity $activity): mixed => $activity->properties['emission_id'])->sort()->values()->all())
        ->toBe(collect([$emission->id, $legacy->id])->sort()->values()->all());
});

it('refuses moving a board onto a construction and month that already has a position', function (): void {
    ['emission' => $emission, 'construction' => $construction] = positionIntegrityScenario();
    $neighbour = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Beta']);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-07-01']);
    SalesBoard::factory()->forEmissionAndConstruction($emission, $neighbour)->create(['reference_month' => '2026-08-01']);
    $august = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-08-01']);

    expect(fn () => $august->update(['reference_month' => '2026-07-01']))
        ->toThrow(SalesBoardRolloutException::class, 'O empreendimento Residencial Alfa já tem Quadro de Vendas em 07/2026');

    expect(fn () => $august->fresh()->update(['construction_id' => $neighbour->id]))
        ->toThrow(SalesBoardRolloutException::class, 'O empreendimento Residencial Beta já tem Quadro de Vendas em 08/2026');

    $august->refresh();

    expect($august->reference_month->toDateString())->toBe('2026-08-01')
        ->and($august->construction_id)->toBe($construction->id);
});

it('refuses publishing a cycle whose construction now belongs to another emission with the Gestão message', function (): void {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle']->fresh();
    $review = ManagementReviewFixture::open($cycle);

    // Carga feita por fora: a tela não deixa trocar a Emissão de uma obra com ciclo.
    $other = Emission::factory()->create(['status' => 'active']);
    DB::table('constructions')->where('id', $cycle->construction_id)->update(['emission_id' => $other->id]);

    expect(fn () => ManagementReviewFixture::approve($review))->toThrow(
        SalesBoardManagementReviewException::class,
        '['.SalesBoardManagementReviewException::CONSTRUCTION_OUTSIDE_CYCLE_EMISSION.'] O empreendimento '
            .$scenario['construction']->development_name.' pertence hoje a outra Emissão; a posição de '
            .$cycle->reference_month->format('m/Y').' não pode ser publicada sob a Emissão do ciclo.',
    );

    expect(SalesBoardPublication::query()->count())->toBe(0)
        ->and(SalesBoard::query()->where('construction_id', $cycle->construction_id)->exists())->toBeFalse()
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('refuses publishing over a board loaded for the same month with another day, with the Gestão message', function (): void {
    $scenario = ManagementReviewFixture::submittedCycle();
    $cycle = $scenario['cycle']->fresh();
    $review = ManagementReviewFixture::open($cycle);

    // Carga feita por fora: o mesmo mês, gravado com o dia 15.
    $loaded = SalesBoardAnomalyFixture::misplacedBoard(
        Emission::query()->findOrFail($cycle->emission_id),
        $scenario['construction'],
        $cycle->reference_month->format('Y-m').'-15',
    );

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, SalesBoardManagementReviewException::LEGACY_POSITION_EXISTS);

    expect(SalesBoardPublication::query()->count())->toBe(0)
        ->and(SalesBoard::query()->where('construction_id', $cycle->construction_id)->pluck('id')->all())->toBe([$loaded->id])
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('creates the construction of a factory board under the board\'s emission', function (): void {
    $board = SalesBoard::factory()->create([
        'emission_id' => Emission::factory()->active(),
        'reference_month' => '2026-07-01',
    ]);

    $emission = Emission::factory()->create(['status' => 'draft']);
    $pinned = SalesBoard::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-07-01',
    ]);

    expect($board->construction->emission_id)->toBe($board->emission_id)
        ->and($pinned->construction->emission_id)->toBe($emission->id)
        ->and($pinned->construction_id)->not->toBe($board->construction_id);
});
