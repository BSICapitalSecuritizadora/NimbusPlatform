<?php

use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Exceptions\PuGovernedHistoryProtectedException;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\PuGovernedHistoryGuard;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuHistoryAnchor;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuObligationComponent;
use App\Models\EmissionPuParameter;
use App\Models\EmissionPuReconciliation;
use App\Models\EmissionPuSettlement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 7 (P0) -- apagar a emissão não leva o histórico financeiro governado do
 * PU. A primeira homologação grava a âncora (FK RESTRICT): o `delete()` do model
 * recusa com o motivo, e exclusão em massa ou `DELETE` direto param na chave
 * estrangeira -- sem apagar a emissão, nenhuma cascata dispara. A emissão sem
 * histórico governado segue as regras de antes. (A mesma prova no MySQL real está
 * em `PuOperationalSafetyMysqlTest`.)
 */
uses(RefreshDatabase::class);

/**
 * @return array{emission: Emission, official: EmissionPuCurveVersion}
 */
function p7hOfficial(): array
{
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $official = Fx::official($emission);

    return ['emission' => $emission->fresh(), 'official' => $official->fresh()];
}

/**
 * @return array<string, int>
 */
function p7hGovernedCounts(int $emissionId): array
{
    return [
        'versions' => EmissionPuCurveVersion::query()->where('emission_id', $emissionId)->count(),
        'rows' => EmissionPuDailyCurve::query()->where('emission_id', $emissionId)->count(),
        'events' => EmissionPuEvent::query()->where('emission_id', $emissionId)->count(),
        'parameters' => EmissionPuParameter::query()->where('emission_id', $emissionId)->count(),
        'obligations' => EmissionPuObligation::query()->where('emission_id', $emissionId)->count(),
        'calculations' => EmissionPuObligationCalculation::query()->where('emission_id', $emissionId)->count(),
        'components' => EmissionPuObligationComponent::query()->whereIn('calculation_id', EmissionPuObligationCalculation::query()->select('id')->where('emission_id', $emissionId))->count(),
        'reconciliations' => EmissionPuReconciliation::query()->where('emission_id', $emissionId)->count(),
        'settlements' => EmissionPuSettlement::query()->where('emission_id', $emissionId)->count(),
        'anchors' => EmissionPuHistoryAnchor::query()->where('emission_id', $emissionId)->count(),
    ];
}

it('anchors the governed history in the same transaction as the first homologation', function () {
    ['emission' => $emission, 'official' => $official] = p7hOfficial();
    $anchor = EmissionPuHistoryAnchor::query()->where('emission_id', $emission->id)->sole();

    expect($anchor->reason)->toBe(EmissionPuHistoryAnchor::REASON_CURVE_HOMOLOGATED)
        ->and($anchor->curve_version_id)->toBe($official->id)
        ->and($anchor->anchored_by)->toBe($official->homologated_by)
        ->and($anchor->anchored_at)->not->toBeNull();
});

it('lets an emission without governed PU history follow the existing deletion rules', function () {
    $plain = Emission::factory()->create();
    $configured = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);

    expect(app(PuGovernedHistoryGuard::class)->deletionBlockers($configured))->toBe([]);

    $plain->delete();
    $configured->delete();

    expect(Emission::query()->whereKey([$plain->id, $configured->id])->count())->toBe(0)
        ->and(EmissionPuParameter::query()->where('emission_id', $configured->id)->count())->toBe(0);
});

it('refuses the model deletion of an emission with a homologated curve, keeping every governed record', function () {
    ['emission' => $emission] = p7hOfficial();
    $before = p7hGovernedCounts($emission->id);

    expect(fn () => $emission->delete())->toThrow(PuGovernedHistoryProtectedException::class, 'curva de PU homologada')
        ->and(Emission::query()->whereKey($emission->id)->exists())->toBeTrue()
        ->and(p7hGovernedCounts($emission->id))->toBe($before)
        ->and($before['rows'])->toBeGreaterThan(0)
        ->and($before['obligations'])->toBeGreaterThan(0);
});

it('stops mass deletion through the query builder at the database anchor', function () {
    ['emission' => $emission] = p7hOfficial();
    $before = p7hGovernedCounts($emission->id);

    expect(fn () => Emission::query()->whereKey($emission->id)->delete())->toThrow(QueryException::class)
        ->and(p7hGovernedCounts($emission->id))->toBe($before);
});

it('stops a direct SQL delete of the emission from cascading into the governed history', function () {
    ['emission' => $emission] = p7hOfficial();
    $before = p7hGovernedCounts($emission->id);

    expect(fn () => DB::statement('DELETE FROM emissions WHERE id = ?', [$emission->id]))->toThrow(QueryException::class)
        ->and(Emission::query()->whereKey($emission->id)->exists())->toBeTrue()
        ->and(p7hGovernedCounts($emission->id))->toBe($before);
});

it('keeps protecting the history after the official curve is invalidated', function () {
    ['emission' => $emission, 'official' => $official] = p7hOfficial();

    app(InvalidatePuCurve::class)->handle($emission, $official->calculation_version, User::factory()->create()->id, 'Erro no evento de juros.');

    expect(EmissionPuCurveVersion::query()->official()->where('emission_id', $emission->id)->exists())->toBeFalse()
        ->and(fn () => $emission->fresh()->delete())->toThrow(PuGovernedHistoryProtectedException::class)
        ->and(fn () => DB::statement('DELETE FROM emissions WHERE id = ?', [$emission->id]))->toThrow(QueryException::class)
        ->and($official->fresh()->homologated_at)->not->toBeNull();
});

it('protects an emission with settlement evidence', function () {
    ['emission' => $emission] = p7hOfficial();
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-P7H');

    expect(app(PuGovernedHistoryGuard::class)->deletionBlockers($emission))->toContain('tem liquidações do PU registradas')
        ->and(fn () => $emission->delete())->toThrow(PuGovernedHistoryProtectedException::class)
        ->and(fn () => DB::statement('DELETE FROM emissions WHERE id = ?', [$emission->id]))->toThrow(QueryException::class)
        ->and(EmissionPuSettlement::query()->where('emission_id', $emission->id)->count())->toBe(1);
});

it('keeps the anchor itself immutable and undeletable', function () {
    ['emission' => $emission] = p7hOfficial();
    $anchor = EmissionPuHistoryAnchor::query()->where('emission_id', $emission->id)->sole();

    expect(fn () => $anchor->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $anchor->delete())->toThrow(LogicException::class);
});

it('keeps a closed (archived) emission auditable: official curve, obligations and anchor stay readable', function () {
    ['emission' => $emission, 'official' => $official] = p7hOfficial();

    $emission->forceFill(['status' => Emission::STATUS_LIQUIDATED])->save();
    $reader = app(EmissionPuReader::class);

    expect($reader->officialVersion($emission->fresh(), fresh: true)?->id)->toBe($official->id)
        ->and(EmissionPuObligation::query()->where('emission_id', $emission->id)->count())->toBeGreaterThan(0)
        ->and(EmissionPuHistoryAnchor::query()->where('emission_id', $emission->id)->exists())->toBeTrue()
        ->and(fn () => $emission->fresh()->delete())->toThrow(PuGovernedHistoryProtectedException::class);
});

it('protects pre-existing governed history when the anchor migration runs, and refuses to roll the protection back', function () {
    ['emission' => $emission, 'official' => $official] = p7hOfficial();
    $unprotected = Emission::factory()->create();
    $migration = require database_path('migrations/2026_10_13_150100_create_emission_pu_history_anchors_table.php');

    Schema::drop('emission_pu_history_anchors');
    $migration->up();
    $anchor = EmissionPuHistoryAnchor::query()->sole();

    expect($anchor->emission_id)->toBe($emission->id)
        ->and($anchor->reason)->toBe(EmissionPuHistoryAnchor::REASON_PREEXISTING_GOVERNED_HISTORY)
        ->and($anchor->curve_version_id)->toBe($official->id)
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Rollback recusado')
        ->and(Schema::hasTable('emission_pu_history_anchors'))->toBeTrue()
        ->and(EmissionPuHistoryAnchor::query()->where('emission_id', $unprotected->id)->exists())->toBeFalse();
});

it('rolls the anchor table back only when nothing is anchored', function () {
    $migration = require database_path('migrations/2026_10_13_150100_create_emission_pu_history_anchors_table.php');

    $migration->down();
    expect(Schema::hasTable('emission_pu_history_anchors'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('emission_pu_history_anchors'))->toBeTrue()
        ->and(EmissionPuHistoryAnchor::query()->count())->toBe(0);
});
