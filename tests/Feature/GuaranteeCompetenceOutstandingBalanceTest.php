<?php

use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\ImportPuHistoriesFromSpreadsheet;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Console\Commands\MarkOutdatedGuaranteeCompetences;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Events\PuCalculator\EmissionPuSourceChanged;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\GuaranteesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Listeners\Guarantees\MarkGuaranteeCompetencesOnPuSourceChange;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Models\IntegralizationHistory;
use App\Models\PuHistory;
use App\Models\SalesBoard;
use App\Models\User;
use App\Services\Guarantees\EmissionGuaranteeCoverageEngine;
use App\Services\Guarantees\GuaranteeAlertBuilder;
use App\Services\Guarantees\GuaranteeSnapshotOutstandingBalanceInvalidator;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

/*
 * A marca "desatualizada pelo saldo devedor": a troca da fonte de PU muda o
 * saldo devedor que a competência de garantias gravou, e a competência precisa
 * dizer isso -- fechada ou não. A comparação é em centavos, contra o que a
 * fonte responde agora.
 */

pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    // Fuso técnico de produção e do CI: os instantes exibidos saem no de negócio.
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Garantia de estoque exigindo 120% do saldo devedor, com 1.000 cotas
 * integralizadas e PU 8.000 no Histórico de PU em julho, agosto e setembro:
 * saldo devedor de R$ 8 mi em cada mês. Os quadros de julho e agosto existem,
 * então as duas competências fecham sem posição parcial.
 *
 * @return array{0: Emission, 1: Construction}
 */
function outstandingBalanceEmission(): array
{
    $emission = Emission::factory()->create(['issued_quantity' => 1000000, 'status' => 'active']);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Alfa',
    ]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-06-01',
        'quantity' => 1000,
        'unit_value' => 1,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);

    foreach (['2026-07-31', '2026-08-31', '2026-09-14'] as $date) {
        PuHistory::query()->create(['emission_id' => $emission->id, 'date' => $date, 'unit_value' => 8000]);
    }

    foreach (['2026-07-01', '2026-08-01'] as $month) {
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
            'reference_month' => $month,
            'stock_units' => 20,
            'stock_value' => 10_000_000,
        ]);
    }

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->requiringPercentage(1.2)
        ->create([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    return [$emission, $construction];
}

/**
 * Uma curva operacional com o PU residual de 31/08/2026: a única data que ela
 * cobre, de modo que julho continua respondido pelo Histórico de PU.
 */
function outstandingBalanceCurve(Emission $emission, string $calculationVersion, string $augustResidual, User $maker, bool $homologated = false): EmissionPuCurveVersion
{
    $factory = EmissionPuCurveVersion::factory();

    $version = ($homologated ? $factory->homologated() : $factory)->create([
        'emission_id' => $emission->id,
        'curve_role' => PuCurveRole::Operational->value,
        'calculation_version' => $calculationVersion,
        'generated_by' => $maker->id,
    ]);

    EmissionPuDailyCurve::factory()->create([
        'emission_id' => $emission->id,
        'curve_version_id' => $version->id,
        'curve_date' => '2026-08-31',
        'residual_unit_value' => $augustResidual,
        'calculation_version' => $calculationVersion,
    ]);

    return $version;
}

function outstandingBalanceSnapshot(Emission $emission, string $referenceMonth): GuaranteeSnapshot
{
    return GuaranteeSnapshot::query()
        ->where('emission_id', $emission->id)
        ->whereDate('reference_month', $referenceMonth)
        ->sole();
}

/**
 * @param  list<list<string>>  $rows
 */
function outstandingBalancePuHistorySpreadsheet(array $rows): string
{
    $path = temporaryTestFilePath('pu-historico-garantias');

    $writer = SimpleExcelWriter::create($path);
    $writer->noHeaderRow()->addRows($rows);
    $writer->close();

    return $path;
}

it('marks the competences whose outstanding balance a homologated curve changes', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $maker = makeAdminUser();
    $checker = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-07-01', $maker);
    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $maker);

    $version = outstandingBalanceCurve($emission, 'v7', '9000.0000000000000000', $maker);

    app(HomologatePuCurve::class)->handle($emission->fresh(), 'v7', $checker->id);

    $july = outstandingBalanceSnapshot($emission, '2026-07-01');
    $august = outstandingBalanceSnapshot($emission, '2026-08-01');

    expect($version->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and($august->isOutstandingBalanceOutdated())->toBeTrue()
        ->and($august->outstanding_balance_outdated_reason)->toBe('Curva de PU v7 homologada')
        // O número fechado não muda sozinho: só a marca aparece.
        ->and((string) $august->outstanding_balance)->toBe('8000000.00')
        ->and($july->isOutstandingBalanceOutdated())->toBeFalse();

    $outdated = Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->sole();

    expect($outdated->log_name)->toBe('guarantee_competences')
        ->and($outdated->subject_id)->toBe($august->id)
        ->and($outdated->causer_id)->toBe($checker->id)
        ->and($outdated->properties['source'])->toBe('outstanding_balance')
        ->and($outdated->properties['reason'])->toBe('Curva de PU v7 homologada')
        ->and($outdated->properties['recorded_outstanding_balance'])->toBe('8000000.00')
        ->and($outdated->properties['current_outstanding_balance'])->toBe('9000000.00')
        ->and($outdated->properties['closed'])->toBeTrue();

    expect(GuaranteesRelationManager::getBadge($emission->fresh(), EditEmission::class))->toBe('Pendente')
        ->and($emission->fresh()->pendingGuaranteeSnapshotReason())
        ->toBe('Saldo devedor alterado depois da apuração de 08/2026. Atualize a competência (ou reabra, se fechada).');

    $alert = app(GuaranteeAlertBuilder::class)
        ->build($emission->fresh(), app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission->fresh()))
        ->firstWhere('title', 'Competência desatualizada pelo saldo devedor');

    expect($alert['description'])->toBe(
        'A competência 08/2026 foi apurada antes de uma alteração no saldo devedor (Curva de PU v7 homologada) '
            .'registrada em 15/09/2026 07:00. Reabra e atualize a competência para refletir o saldo devedor atual.',
    );
});

it('does not mark a competence whose balance the homologated curve keeps', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $maker = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $maker);

    // A curva responde o mesmo PU do Histórico: trocar a fonte não mudou o saldo.
    outstandingBalanceCurve($emission, 'v8', '8000.0000000000000000', $maker);

    app(HomologatePuCurve::class)->handle($emission->fresh(), 'v8', makeAdminUser()->id);

    expect(outstandingBalanceSnapshot($emission, '2026-08-01')->isOutstandingBalanceOutdated())->toBeFalse()
        ->and(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->exists())->toBeFalse()
        ->and($emission->fresh()->pendingGuaranteeSnapshotReason())->toBeNull();
});

it('marks the competence when the official curve is invalidated and the PU history answers again', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $admin = makeAdminUser();

    outstandingBalanceCurve($emission, 'v3', '9000.0000000000000000', $admin, homologated: true);

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);

    expect((string) outstandingBalanceSnapshot($emission, '2026-08-01')->outstanding_balance)->toBe('9000000.00');

    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v3', $admin->id);

    $august = outstandingBalanceSnapshot($emission, '2026-08-01');

    expect($august->isOutstandingBalanceOutdated())->toBeTrue()
        ->and($august->outstanding_balance_outdated_reason)->toBe('Curva de PU v3 invalidada')
        ->and(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->sole()->properties['current_outstanding_balance'])
        ->toBe('8000000.00');
});

it('marks the competences whose balance an imported PU history changes', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $admin = makeAdminUser();
    $this->actingAs($admin);

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-07-01', $admin);
    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);

    $imported = app(ImportPuHistoriesFromSpreadsheet::class)->handle(
        outstandingBalancePuHistorySpreadsheet([
            ['Data', 'PU'],
            ['31/08/2026', '8100'],
        ]),
        $emission->fresh(),
    );

    $august = outstandingBalanceSnapshot($emission, '2026-08-01');

    expect($imported)->toBe(1)
        ->and($august->isOutstandingBalanceOutdated())->toBeTrue()
        ->and($august->outstanding_balance_outdated_reason)->toBe('Histórico de PU importado')
        ->and(outstandingBalanceSnapshot($emission, '2026-07-01')->isOutstandingBalanceOutdated())->toBeFalse()
        ->and(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->sole()->causer_id)
        ->toBe($admin->id);
});

/**
 * O saldo da competência do mês em curso é o último PU dentro do mês, e ele
 * muda a cada dia novo de PU. Comparado no evento, esse movimento diário seria
 * atribuído ao ato -- "Curva de PU v9 homologada" -- numa competência que a
 * homologação não tocou. Ela fica com o fechamento, que apura de novo, e com a
 * verificação diária depois que o mês termina.
 */
it('does not blame a source change for the daily PU of the open competence of the current month', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $maker = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $maker);
    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-09-01', $maker);

    expect((string) outstandingBalanceSnapshot($emission, '2026-09-01')->outstanding_balance)->toBe('8000000.00');

    // O PU de um dia novo, como a extensão diária gravaria: o saldo de setembro muda sem ato de ninguém.
    PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-09-15', 'unit_value' => 8010]);

    // A curva responde em 31/08 o mesmo PU do Histórico e não cobre setembro.
    outstandingBalanceCurve($emission, 'v9', '8000.0000000000000000', $maker);

    app(HomologatePuCurve::class)->handle($emission->fresh(), 'v9', makeAdminUser()->id);

    expect(outstandingBalanceSnapshot($emission, '2026-09-01')->isOutstandingBalanceOutdated())->toBeFalse()
        ->and(outstandingBalanceSnapshot($emission, '2026-08-01')->isOutstandingBalanceOutdated())->toBeFalse()
        ->and(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->exists())->toBeFalse()
        ->and($emission->fresh()->pendingGuaranteeSnapshotReason())->toBeNull();

    // Encerrado o mês, a verificação diária confere setembro como qualquer competência passada.
    Carbon::setTestNow('2026-10-02 10:00:00');

    $this->artisan('guarantees:mark-outdated-competences')
        ->expectsOutputToContain('Competências marcadas: 1.')
        ->assertSuccessful();

    expect(outstandingBalanceSnapshot($emission, '2026-09-01')->outstanding_balance_outdated_reason)
        ->toBe(MarkOutdatedGuaranteeCompetences::REASON);
});

it('clears the mark when the competence is computed again and carries it in the reopening trail', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $admin = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);

    outstandingBalanceCurve($emission, 'v7', '9000.0000000000000000', $admin);
    app(HomologatePuCurve::class)->handle($emission->fresh(), 'v7', makeAdminUser()->id);

    // Instância nova: o leitor de PU memoriza a versão oficial por instância, e
    // um writer resolvido antes da homologação apuraria com a curva antiga.
    $writer = app(GuaranteeSnapshotWriter::class);

    $writer->reopen($emission, '2026-08-01', $admin, 'Curva homologada depois do fechamento.');

    $reopening = Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_REOPENED)->sole();

    expect($reopening->properties['outstanding_balance_outdated_reason'])->toBe('Curva de PU v7 homologada')
        ->and($reopening->properties['outstanding_balance_outdated_at'])->not->toBeNull()
        ->and(outstandingBalanceSnapshot($emission, '2026-08-01')->isOutstandingBalanceOutdated())->toBeTrue();

    $writer->persist($emission, '2026-08-01', $admin);

    $august = outstandingBalanceSnapshot($emission, '2026-08-01');

    expect($august->isOutstandingBalanceOutdated())->toBeFalse()
        ->and($august->outstanding_balance_outdated_reason)->toBeNull()
        ->and((string) $august->outstanding_balance)->toBe('9000000.00')
        ->and($august->reopen_reason)->toBe('Curva homologada depois do fechamento.');
});

it('keeps the homologation when marking the competences fails', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $admin = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);
    $version = outstandingBalanceCurve($emission, 'v2', '9000.0000000000000000', $admin);

    Exceptions::fake();

    $this->mock(GuaranteeSnapshotOutstandingBalanceInvalidator::class, function (MockInterface $mock): void {
        $mock->shouldReceive('markDrifted')->once()->andThrow(new RuntimeException('Falha simulada ao marcar as competências.'));
    });

    app(HomologatePuCurve::class)->handle($emission->fresh(), 'v2', makeAdminUser()->id);

    expect($version->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and(outstandingBalanceSnapshot($emission, '2026-08-01')->isOutstandingBalanceOutdated())->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Falha simulada ao marcar as competências.');
});

it('marks nothing when the homologation transaction is rolled back', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $admin = makeAdminUser();
    $checker = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);
    $version = outstandingBalanceCurve($emission, 'v7', '9000.0000000000000000', $admin);

    expect(fn () => DB::transaction(function () use ($emission, $checker): void {
        app(HomologatePuCurve::class)->handle($emission->fresh(), 'v7', $checker->id);

        throw new RuntimeException('Homologação desfeita antes do commit.');
    }))->toThrow(RuntimeException::class, 'Homologação desfeita antes do commit.');

    expect($version->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and(outstandingBalanceSnapshot($emission, '2026-08-01')->isOutstandingBalanceOutdated())->toBeFalse()
        ->and(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->exists())->toBeFalse();

    // A mesma homologação, commitada, marca -- depois do commit.
    DB::transaction(fn () => app(HomologatePuCurve::class)->handle($emission->fresh(), 'v7', $checker->id));

    expect(outstandingBalanceSnapshot($emission, '2026-08-01')->isOutstandingBalanceOutdated())->toBeTrue();
});

it('registers the guarantee listener exactly once', function (): void {
    $listeners = collect(Event::getRawListeners()[EmissionPuSourceChanged::class] ?? [])
        ->map(fn (mixed $listener): string => is_array($listener)
            ? (is_object($listener[0]) ? $listener[0]::class : (string) $listener[0])
            : (is_string($listener) ? explode('@', $listener)[0] : 'closure'))
        ->values()
        ->all();

    expect($listeners)->toBe([MarkGuaranteeCompetencesOnPuSourceChange::class]);
});

it('marks in the daily check an ended competence whose balance moved without an event', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $admin = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);
    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-09-01', $admin);

    // Correção de linhas do Histórico de PU fora da importação: nenhum evento sai daqui.
    PuHistory::query()->where('emission_id', $emission->id)->update(['unit_value' => 8100]);

    $this->artisan('guarantees:mark-outdated-competences')
        ->expectsOutputToContain('Emissões verificadas: 1. Competências marcadas: 1.')
        ->assertSuccessful();

    $august = outstandingBalanceSnapshot($emission, '2026-08-01');

    expect($august->outstanding_balance_outdated_reason)->toBe(MarkOutdatedGuaranteeCompetences::REASON)
        // O mês de negócio em curso fica de fora: o saldo dele muda a cada dia novo de PU.
        ->and(outstandingBalanceSnapshot($emission, '2026-09-01')->isOutstandingBalanceOutdated())->toBeFalse()
        ->and(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->sole()->causer_id)->toBeNull();

    $this->artisan('guarantees:mark-outdated-competences')
        ->expectsOutputToContain('Competências marcadas: 0.')
        ->assertSuccessful();

    expect(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->count())->toBe(1);
});

it('lists without writing in --dry-run', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();
    $admin = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);

    PuHistory::query()->where('emission_id', $emission->id)->update(['unit_value' => 8100]);

    $this->artisan('guarantees:mark-outdated-competences', ['--dry-run' => true])
        ->expectsTable(
            ['Emissão', 'Competência', 'Gravado', 'Apurado hoje', 'Fechada'],
            [[$emission->id, '08/2026', 'R$ 8.000.000,00', 'R$ 8.100.000,00', 'Sim']],
        )
        ->expectsOutputToContain('Competências que seriam marcadas: 1. Nada foi gravado (--dry-run).')
        ->assertSuccessful();

    expect(outstandingBalanceSnapshot($emission, '2026-08-01')->isOutstandingBalanceOutdated())->toBeFalse()
        ->and(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_OUTDATED)->exists())->toBeFalse();
});

it('refuses an unreadable emission filter instead of checking the whole base', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = outstandingBalanceEmission();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', makeAdminUser());

    PuHistory::query()->where('emission_id', $emission->id)->update(['unit_value' => 8100]);

    $this->artisan('guarantees:mark-outdated-competences', ['--emission' => ['abc']])
        ->expectsOutputToContain('Emissão inválida: "abc". Informe o id numérico.')
        ->assertFailed();

    expect(outstandingBalanceSnapshot($emission, '2026-08-01')->isOutstandingBalanceOutdated())->toBeFalse();

    $this->artisan('guarantees:mark-outdated-competences', ['--emission' => [(string) $emission->id]])
        ->expectsOutputToContain('Emissões verificadas: 1. Competências marcadas: 1.')
        ->assertSuccessful();
});

it('schedules the daily check after the daily curve extension', function (): void {
    $events = collect(app(Schedule::class)->events());

    $check = $events->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'guarantees:mark-outdated-competences'));
    $extension = $events->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'pu:curves:generate-realized'));

    expect($check)->toHaveCount(1)
        ->and($check->first()->expression)->toBe('30 8 * * *')
        ->and($check->first()->description)->toBe('guarantees-mark-outdated-competences')
        ->and($check->first()->withoutOverlapping)->toBeTrue()
        ->and($extension->expression)->toBe('15 7 * * *');
});
