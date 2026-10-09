<?php

use App\Models\Measurement;
use App\Models\MeasurementRevisionDifference;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);

/**
 * A identidade da revisão contra o próprio banco: uniques, FKs compostas,
 * RESTRICT e, no MySQL, os CHECKs. As escritas recusadas são cruas -- o
 * domínio recusaria antes --, para provar que um script, uma correção manual
 * ou uma migration futura ainda esbarram no banco. Roda também no MySQL (job
 * parity): as colunas geradas e os CHECKs só são de verdade lá.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * A linha crua de uma medição, sem o id e sem as colunas geradas, para
 * inserir uma cópia alterada.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function revisionSchemaRow(Measurement $measurement, array $changes = []): array
{
    return array_merge(
        collect((array) DB::table('measurements')->where('id', $measurement->id)->first())
            ->except(['id', 'effective_revision_family_id', 'pending_revision_family_id'])
            ->all(),
        $changes,
    );
}

/**
 * @return array{scenario: array<string, mixed>, may: Measurement, revision: Measurement}
 */
function revisionSchemaFamily(): array
{
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);

    return ['scenario' => $scenario, 'may' => $may->fresh(), 'revision' => $revision->fresh()];
}

it('backfills every original measurement as the effective R0 of its own family', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $row = DB::table('measurements')->where('id', $may->id)->first();

    expect((int) $row->revision_family_id)->toBe($may->id)
        ->and((int) $row->revision_number)->toBe(0)
        ->and($row->revision_status)->toBe('effective')
        ->and($row->revision_root_id)->toBeNull()
        ->and($row->previous_revision_id)->toBeNull()
        ->and((int) $row->effective_revision_family_id)->toBe($may->id)
        ->and($row->pending_revision_family_id)->toBeNull();
});

it('numbers each revision of a family once', function () {
    ['may' => $may, 'revision' => $revision] = revisionSchemaFamily();

    expect(fn () => DB::table('measurements')->insert(revisionSchemaRow($revision, [
        'revision_status' => 'superseded',
        'status' => 'superseded',
    ])))->toThrow(QueryException::class);
});

it('keeps one effective revision per family', function () {
    ['may' => $may] = revisionSchemaFamily();

    expect(fn () => DB::table('measurements')->where('id', $may->id)->update(['revision_status' => 'effective']))
        ->toThrow(QueryException::class);
});

it('keeps one pending revision per family', function () {
    ['scenario' => $scenario, 'revision' => $revision] = revisionSchemaFamily();
    $draft = Scenario::revise($scenario, $revision);

    expect(fn () => DB::table('measurements')->insert(revisionSchemaRow($draft, [
        'revision_number' => 3,
        'revision_status' => 'under_review',
        'status' => 'in_review',
    ])))->toThrow(QueryException::class);
});

it('refuses a revision chained to a measurement of another family or operation', function () {
    ['scenario' => $scenario, 'revision' => $revision] = revisionSchemaFamily();
    $june = Scenario::finalized($scenario, '2026-06', 5);
    $otherScenario = Scenario::plan();
    $foreign = Scenario::finalized($otherScenario, '2026-05', 5);

    // A revisão anterior precisa ser da mesma família, com o número gravado.
    expect(fn () => DB::table('measurements')->insert(revisionSchemaRow($revision, [
        'revision_number' => 5,
        'revision_status' => 'cancelled',
        'status' => 'cancelled',
        'previous_revision_id' => $june->id,
        'previous_revision_number' => 0,
    ])))->toThrow(QueryException::class)
        // A raiz precisa ser da mesma operação.
        ->and(fn () => DB::table('measurements')->insert(revisionSchemaRow($revision, [
            'revision_number' => 6,
            'revision_status' => 'cancelled',
            'status' => 'cancelled',
            'revision_root_id' => $foreign->id,
        ])))->toThrow(QueryException::class);
});

it('refuses to delete an original measurement that already has revisions', function () {
    ['may' => $may] = revisionSchemaFamily();

    expect(fn () => DB::table('measurements')->where('id', $may->id)->delete())->toThrow(QueryException::class);
    expect(DB::table('measurements')->where('id', $may->id)->exists())->toBeTrue();
});

it('refuses an inherited file whose plan context differs from the file it inherits', function () {
    ['scenario' => $scenario, 'revision' => $revision] = revisionSchemaFamily();
    $asset = $revision->assets()->sole();

    expect(fn () => DB::table('measurement_assets')->where('id', $asset->id)->update([
        'plan_line_id' => $scenario['lines']['2026-06']->id,
    ]))->toThrow(QueryException::class);
});

it('keeps a single current difference per revision and development and numbers each computation once', function () {
    ['revision' => $revision] = revisionSchemaFamily();
    $current = MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->sole();
    $row = collect((array) DB::table('measurement_revision_differences')->where('id', $current->id)->first())
        ->except(['id', 'current_measurement_id'])
        ->all();

    expect(fn () => DB::table('measurement_revision_differences')->insert([...$row, 'computation' => 2]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('measurement_revision_differences')->insert([...$row, 'superseded_at' => now()]))
        ->toThrow(QueryException::class);

    DB::table('measurement_revision_differences')->insert([...$row, 'computation' => 2, 'superseded_at' => now()]);

    expect(MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->count())->toBe(2);
});

describe('MySQL CHECK constraints', function () {
    beforeEach(function () {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Os CHECKs das revisões só existem no MySQL: o SQLite dos testes não aceita ADD CONSTRAINT, e lá a regra fica nos modelos. Rode pelo scripts/parity-check.sh.');
        }
    });

    it('refuses a revision status the application does not know, compared in binary', function () {
        ['may' => $may] = revisionSchemaFamily();
        $column = collect(Schema::getColumns('measurements'))->firstWhere('name', 'revision_status');

        expect($column['collation'])->toEndWith('_bin')
            ->and(fn () => DB::table('measurements')->where('id', $may->id)->update(['revision_status' => 'archived']))
            ->toThrow(QueryException::class, "Check constraint 'm_rev_status_check' is violated")
            ->and(fn () => DB::table('measurements')->where('id', $may->id)->update(['revision_status' => 'Superseded']))
            ->toThrow(QueryException::class, "Check constraint 'm_rev_status_check' is violated");
    });

    it('refuses a revision without its identity or its reason', function () {
        ['may' => $may, 'revision' => $revision] = revisionSchemaFamily();

        expect(fn () => DB::table('measurements')->where('id', $revision->id)->update(['revision_reason' => '   ']))
            ->toThrow(QueryException::class, "Check constraint 'm_rev_identity_check' is violated")
            ->and(fn () => DB::table('measurements')->where('id', $may->id)->update(['previous_revision_number' => 0]))
            ->toThrow(QueryException::class, "Check constraint 'm_rev_identity_check' is violated");
    });

    it('ties the workflow status to the revision status', function () {
        ['scenario' => $scenario, 'may' => $may, 'revision' => $revision] = revisionSchemaFamily();
        $draft = Scenario::revise($scenario, $revision);

        expect(fn () => DB::table('measurements')->where('id', $draft->id)->update(['status' => 'awaiting_payment']))
            ->toThrow(QueryException::class, "Check constraint 'm_rev_workflow_check' is violated")
            ->and(fn () => DB::table('measurements')->where('id', $revision->id)->update(['status' => 'cancelled']))
            ->toThrow(QueryException::class, "Check constraint 'm_rev_workflow_check' is violated")
            ->and(fn () => DB::table('measurements')->where('id', $revision->id)->update(['status' => 'superseded']))
            ->toThrow(QueryException::class, "Check constraint 'm_rev_workflow_check' is violated");
    });

    it('refuses a difference type the application does not know', function () {
        ['revision' => $revision] = revisionSchemaFamily();
        $difference = MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->sole();

        expect(fn () => DB::table('measurement_revision_differences')->where('id', $difference->id)->update(['difference_type' => 'refund']))
            ->toThrow(QueryException::class, "Check constraint 'mrd_difference_type_check' is violated");
    });
});
