<?php

use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/*
 * O SQLite ignora `FOR UPDATE`: estes testes conferem só a ORDEM das leituras
 * (a Operation antes da medição, como primeiras instruções da transação), não
 * que elas travam. Um mutante que lê a Operation sem `lockForUpdate()` passa
 * aqui. A prova do lock são os testes do grupo `mysql` --
 * MeasurementLockOrderMysqlConcurrencyTest (pagamento e Finalização) e
 * MeasurementEditLockMysqlConcurrencyTest (edição da medição) --, que rodam no
 * job parity do CI e em `composer test:measurements:mysql`.
 */

/**
 * Tabelas das primeiras leituras da transação do fluxo, na ordem em que saem.
 *
 * A ordem das instruções aparece no SQLite: a leitura da Operation tem de ser
 * a primeira instrução da transação, antes da da medição. A carga ansiosa
 * (`in (?)`) não é lock e por isso não entra na lista -- é ela que denunciava a
 * ordem antiga, em que a Operation só era lida depois da medição travada.
 *
 * @return list<string>
 */
function measurementLockOrderTables(callable $action): array
{
    $callerLevel = DB::transactionLevel();
    $tables = [];

    DB::listen(function (QueryExecuted $query) use ($callerLevel, &$tables): void {
        if (DB::transactionLevel() <= $callerLevel) {
            return;
        }

        if (preg_match('/^select \* from "(operations|measurements)" where "\1"\."id" = \? limit 1$/', $query->sql, $matches) === 1) {
            $tables[] = $matches[1];
        }
    });

    $action();

    return $tables;
}

it('reads the operation before the measurement when registering payments', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::measured($scenario, '2026-05', 10);
    $workflow->approve($may->fresh(), $scenario['actor']);
    $workflow->approve($may->fresh(), $scenario['actor']);

    $tables = measurementLockOrderTables(fn () => $workflow->registerPayment($may->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSet']->id,
        'pay_date' => '2026-05-20',
        'amount' => '100000.00',
    ]));

    expect(array_slice($tables, 0, 2))->toBe(['operations', 'measurements'])
        ->and($may->payments()->pluck('amount')->all())->toBe(['100000.00']);
});

it('reads the operation before the measurement when finalizing', function () {
    $scenario = MeasurementReceiptEvidenceScenario::open();
    $workflow = app(MeasurementWorkflow::class);
    $workflow->attachReceipt($scenario['payment']->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file());
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($scenario['payment'], $scenario['actor']);

    $tables = measurementLockOrderTables(fn () => $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor']));

    expect(array_slice($tables, 0, 2))->toBe(['operations', 'measurements'])
        ->and($scenario['measurement']->fresh()->status)->toBe('finalized');
});

/**
 * Toda instrução da transação aberta por `$action`, na ordem em que sai.
 *
 * @return list<string>
 */
function measurementLockOrderStatements(callable $action): array
{
    $callerLevel = DB::transactionLevel();
    $statements = [];

    DB::listen(function (QueryExecuted $query) use ($callerLevel, &$statements): void {
        if (DB::transactionLevel() > $callerLevel) {
            $statements[] = $query->sql;
        }
    });

    $action();

    return $statements;
}

it('reads the operation and then the measurement before anything else in the transaction of the edit page', function () {
    $scenario = Scenario::plan();
    $may = Scenario::measurement($scenario, '2026-05');
    $asset = $may->assets()->sole();
    $this->actingAs($scenario['actor']);
    $page = Livewire::test(EditMeasurement::class, ['record' => $may->getRouteKey()])
        ->fillForm([
            'notes' => 'Observação editada junto com o arquivo.',
            'assets' => ["record-{$asset->id}" => [
                'plan_set_id' => $asset->plan_set_id,
                'plan_line_id' => $asset->plan_line_id,
                'storage_path' => [UploadedFile::fake()->createWithContent('nova.pdf', '%PDF-1.7 nova versão')],
            ]],
        ]);

    // A página grava os arquivos antes da medição: sem a Operation e a medição
    // à frente, a primeira leitura da validação tirava a fotografia e o
    // arquivo era travado antes da medição -- o contrário da aprovação.
    $statements = measurementLockOrderStatements(fn () => $page->call('save')->assertHasNoFormErrors());

    expect(array_slice($statements, 0, 2))->toBe([
        'select * from "operations" where "operations"."id" = ? limit 1',
        'select * from "measurements" where "measurements"."id" = ? limit 1',
    ])
        ->and($may->fresh()->notes)->toBe('Observação editada junto com o arquivo.')
        ->and($asset->fresh()->storage_path)->not->toBe($asset->storage_path);
});
