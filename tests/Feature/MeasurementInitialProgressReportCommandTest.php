<?php

use App\Enums\MeasurementInitialProgressClassification as Classification;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Services\MeasurementInitialProgressDiagnosticService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\MeasurementLegacyInitialProgressFixture as Fixture;

/*
 * O relatório somente leitura do avanço físico inicial legado: a decisão que a
 * migração 2026_10_05_170828 toma em cada plano, prevista antes dela e conferida
 * com a trilha depois. A migração real é o oráculo -- a paridade a roda sobre a
 * matriz de cenários e cobra previsão = decisão gravada, no SQLite e, pelo
 * grupo parity, no MySQL.
 */
uses(RefreshDatabase::class);
pest()->group('parity');

it('predicts before the backfill the same decision the migration records for every plan', function (string $scenario) {
    $expected = Fixture::scenario($scenario);

    ['plans' => $before] = Fixture::jsonReport();

    Fixture::migration()->up();

    foreach ($expected as $planSetId => $classification) {
        $prediction = $before[$planSetId]['prediction'];
        $trails = Fixture::trailsOf($planSetId);

        expect($prediction['classification'] ?? $before[$planSetId]['classification'])->toBe($classification->value);

        if ($prediction === null || $prediction['outcome'] === 'skipped') {
            expect($trails)->toBeEmpty();

            continue;
        }

        $trail = $trails->sole();

        expect($trail->description)->toBe('initial_physical_progress_'.$prediction['outcome'])
            ->and(Fixture::decisionOf($prediction))
            ->toBe(Fixture::decisionOf(json_decode($trail->properties, true, flags: JSON_THROW_ON_ERROR)));
    }

    ['exit_code' => $exitCode, 'plans' => $after] = Fixture::jsonReport();

    expect($exitCode)->toBe(0);

    foreach ($expected as $planSetId => $classification) {
        expect($after[$planSetId]['classification'])->toBe($classification->value)
            ->and($after[$planSetId]['issues'])->toBe([])
            ->and($after[$planSetId]['consistent'])->toBeTrue()
            ->and($after[$planSetId]['drift'])->toBeFalse();
    }
})->with(Fixture::scenarioNames());

it('lists operation, plan, first line, legacy initial, current approval, current baseline and classification', function () {
    ['planSet' => $copied, 'lines' => $copiedLines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);
    $approval = Fixture::legacyApproval($copiedLines[1], '35.00', '5.00', '40.00');
    ['planSet' => $unproven, 'lines' => $unprovenLines] = Fixture::planWithLegacyLineInitials([1 => 20]);
    ['planSet' => $withoutLegacy, 'lines' => $withoutLegacyLines] = Fixture::planWithLegacyLineInitials([1 => 0]);
    Fixture::migration()->up();

    $headers = ['Operação', 'Plano', '1ª linha', 'Inicial legado', 'Engenharia vigente', 'Baseline atual', 'Classificação', 'Motivo/medição', 'Confere'];
    $copiedRow = [$copied->operation->code, "{$copied->name} (#{$copied->id})", "nº 1 (#{$copiedLines[1]->id})", '35,00%', 'Sim (1)', '35,00% (sem data)', 'Provado pelo histórico aprovado', "provado por #{$approval->id}", 'Sim'];
    $unprovenRow = [$unproven->operation->code, "{$unproven->name} (#{$unproven->id})", "nº 1 (#{$unprovenLines[1]->id})", '20,00%', 'Não', '0,00% (sem data)', 'Sem aprovação vigente que o prove', '—', 'Sim'];
    $withoutLegacyRow = [$withoutLegacy->operation->code, "{$withoutLegacy->name} (#{$withoutLegacy->id})", "nº 1 (#{$withoutLegacyLines[1]->id})", '0,00%', 'Não', '0,00% (sem data)', 'Sem avanço inicial legado', '—', 'Sim'];

    $this->artisan('measurements:initial-progress-report')
        ->expectsOutputToContain('Depois da migração 2026_10_05_170828')
        ->expectsTable($headers, [$copiedRow, $unprovenRow])
        ->expectsOutputToContain('Planos analisados: 3')
        ->expectsOutputToContain('Provado pelo histórico aprovado: 1')
        ->expectsOutputToContain('Sem aprovação vigente que o prove: 1')
        ->expectsOutputToContain('Sem avanço inicial legado: 1')
        ->expectsOutputToContain('Planos sem avanço inicial legado fora da tabela: 1 (use --all para listá-los).')
        ->expectsOutputToContain('1 plano(s) com "Realiz. inicial" que a migração não copiou: o avanço inicial ficou em 0,00% e a decisão é do dono.')
        ->expectsOutputToContain('Todos os planos conferem com a trilha da migração.')
        ->assertSuccessful();

    $this->artisan('measurements:initial-progress-report', ['--all' => true])
        ->expectsTable($headers, [$copiedRow, $unprovenRow, $withoutLegacyRow])
        ->doesntExpectOutputToContain('fora da tabela')
        ->assertSuccessful();

    Artisan::call('measurements:initial-progress-report');
    $lines = preg_split('/\R/', trim(Artisan::output()));

    expect(end($lines))->toBe('Somente leitura: nenhum registro foi alterado.');

    ['payload' => $payload] = Fixture::jsonReport();

    expect($payload)->toMatchArray([
        'migration' => Fixture::MIGRATION,
        'phase' => 'post_migration',
        'read_only' => true,
        'operation_ids' => [],
        'consistent' => true,
    ])
        ->and($payload['summary'])->toBe([
            'SAFE_BACKFILLED' => 1,
            'DECLARED_INITIAL_WITH_NO_CURRENT_APPROVAL' => 1,
            'APPROVAL_STARTED_BELOW_DECLARED_INITIAL' => 0,
            'INITIAL_PLUS_MEASURED_ABOVE_100' => 0,
            'NO_LEGACY_INITIAL_PROGRESS' => 1,
            'LEGACY_INITIAL_OUT_OF_RANGE' => 0,
            'INITIAL_INFORMED_AT_CREATION' => 0,
            'DECISION_MISSING' => 0,
        ])
        ->and(array_column($payload['plans'], 'plan_set_id'))->toBe([$copied->id, $unproven->id, $withoutLegacy->id]);
});

it('explains in the reason column what the migration read', function () {
    $contradicted = Fixture::scenario('a later line approved from a zero base');
    $aboveLimit = Fixture::scenario('copy would take the plan just above 100%');
    $unreadable = Fixture::scenario('unreadable snapshot entry read as zero');
    Fixture::migration()->up();

    ['plans' => $plans] = Fixture::jsonReport();
    $contradictedPlan = $plans[array_key_first($contradicted)];
    $aboveLimitPlan = $plans[array_key_first($aboveLimit)];
    $unreadablePlan = $plans[array_key_first($unreadable)];
    $unreadableBy = $unreadablePlan['recorded']['contradicted_by_measurement_id'];

    expect($contradictedPlan['recorded']['contradicted_by_measurement_id'])->toBeInt()
        ->and($aboveLimitPlan['recorded']['measured_percent'])->toBe('65.01')
        ->and($unreadablePlan['unreadable_approval_measurement_ids'])->toBe([$unreadableBy])
        ->and($contradictedPlan['unreadable_approval_measurement_ids'])->toBe([]);

    ['exit_code' => $exitCode, 'output' => $output] = Fixture::textReport();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(
            'contradito por #'.$contradictedPlan['recorded']['contradicted_by_measurement_id'],
            'Inicial + medido acima de 100%',
            'inicial + medido: 100,01%',
            "contradito por #{$unreadableBy}; avanço ilegível na medição #{$unreadableBy}",
            'Aprovação partiu de base menor que o inicial',
        );
});

it('treats a plan without lines and an initial typed after the first line as without legacy initial', function () {
    ['planSet' => $laterLine, 'lines' => $laterLines] = Fixture::planWithLegacyLineInitials([1 => 0, 2 => 20]);
    ['planSet' => $withoutLines] = Fixture::planWithLegacyLineInitials([]);
    Fixture::migration()->up();

    ['exit_code' => $exitCode, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(0)
        ->and($plans[$laterLine->id]['classification'])->toBe('NO_LEGACY_INITIAL_PROGRESS')
        ->and($plans[$laterLine->id]['first_line'])->toBe(['id' => $laterLines[1]->id, 'sequence_number' => 1, 'initial_percent' => '0.00'])
        ->and($plans[$laterLine->id]['other_lines_with_initial'])->toBe([['id' => $laterLines[2]->id, 'sequence_number' => 2, 'initial_percent' => '20.00']])
        ->and($plans[$withoutLines->id]['classification'])->toBe('NO_LEGACY_INITIAL_PROGRESS')
        ->and($plans[$withoutLines->id]['first_line'])->toBeNull()
        ->and($plans[$withoutLines->id]['other_lines_with_initial'])->toBe([]);

    $this->artisan('measurements:initial-progress-report')
        ->doesntExpectOutputToContain("(#{$laterLine->id})")
        ->doesntExpectOutputToContain("(#{$withoutLines->id})")
        ->expectsOutputToContain('Planos sem avanço inicial legado fora da tabela: 2 (use --all para listá-los).')
        ->assertSuccessful();

    ['exit_code' => $exitCode, 'output' => $output] = Fixture::textReport(['--all' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(
            "{$laterLine->name} (#{$laterLine->id})",
            'inicial também na linha nº 2',
            "{$withoutLines->name} (#{$withoutLines->id})",
            'plano sem linhas',
        );
});

it('classifies the initial progress informed at creation apart from the migration', function () {
    $informedId = array_key_first(Fixture::scenario('initial progress informed at creation'));
    Fixture::migration()->up();

    ['exit_code' => $exitCode, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(0)
        ->and($plans[$informedId]['classification'])->toBe('INITIAL_INFORMED_AT_CREATION')
        ->and($plans[$informedId]['baseline'])->toBe(['percent' => '10.00', 'reference_date' => '2026-04-30'])
        ->and($plans[$informedId]['prediction'])->toBeNull()
        ->and($plans[$informedId]['recorded'])->toBeNull()
        ->and($plans[$informedId]['consistent'])->toBeTrue();

    ['exit_code' => $exitCode, 'output' => $output] = Fixture::textReport();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('10,00% em 30/04/2026', 'Informado na criação do plano');
});

it('fails naming the eligible plan whose decision is missing', function () {
    $planSetId = array_key_first(Fixture::scenario('not measured yet'));

    ['exit_code' => $exitCode, 'payload' => $payload, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(1)
        ->and($payload['consistent'])->toBeFalse()
        ->and($plans[$planSetId]['classification'])->toBe('DECISION_MISSING')
        ->and($plans[$planSetId]['prediction']['classification'])->toBe('DECLARED_INITIAL_WITH_NO_CURRENT_APPROVAL')
        ->and($plans[$planSetId]['issues'])->toBe(['decision_missing'])
        ->and($plans[$planSetId]['consistent'])->toBeFalse();

    ['exit_code' => $exitCode, 'output' => $output] = Fixture::textReport();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(
            'Decisão não registrada',
            'previsto: Sem aprovação vigente que o prove',
            'Não: a migração não registrou decisão',
            '1 plano(s) não conferem com a trilha da migração: veja a coluna Confere.',
            'Somente leitura: nenhum registro foi alterado.',
        );

    Fixture::migration()->up();

    $this->artisan('measurements:initial-progress-report')
        ->expectsOutputToContain('Todos os planos conferem com a trilha da migração.')
        ->assertSuccessful();
});

it('fails when the baseline or the trail no longer agree', function (string $case) {
    ['planSet' => $copied, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);
    Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
    ['planSet' => $refused] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);
    ['planSet' => $withoutLegacy] = Fixture::planWithLegacyLineInitials([1 => 0]);
    Fixture::migration()->up();

    $copiedTrail = Fixture::trailsOf($copied->id)->sole();
    $copiedProperties = json_decode($copiedTrail->properties, true, flags: JSON_THROW_ON_ERROR);
    $refusedTrail = Fixture::trailsOf($refused->id)->sole();
    $refusedProperties = json_decode($refusedTrail->properties, true, flags: JSON_THROW_ON_ERROR);
    $updatePlan = fn (MeasurementPlanSet $planSet, array $values): int => DB::table('measurement_plan_sets')->where('id', $planSet->id)->update($values);
    $updateTrail = fn (array $values): int => DB::table('activity_log')->where('id', $copiedTrail->id)->update($values);

    [$subject, $issues, $tamper] = match ($case) {
        'baseline zeroed after the copy' => [$copied, ['baseline_differs_from_trail'], fn () => $updatePlan($copied, ['initial_physical_progress_percent' => 0])],
        'reference date added after the copy' => [$copied, ['baseline_differs_from_trail'], fn () => $updatePlan($copied, ['initial_physical_progress_reference_date' => '2026-04-30'])],
        'baseline given to a plan not copied' => [$refused, ['baseline_differs_from_trail'], fn () => $updatePlan($refused, ['initial_physical_progress_percent' => 35])],
        'second identical trail' => [$copied, ['duplicate_trail'], fn () => DB::table('activity_log')->insert(Arr::except((array) $copiedTrail, ['id']))],
        'copied trail carrying a reason' => [$copied, ['trail_inconsistent'], fn () => $updateTrail(['properties' => json_encode(['reason' => 'no_current_approval'] + $copiedProperties)])],
        'copied trail without the proving approval' => [$copied, ['trail_inconsistent'], fn () => $updateTrail(['properties' => json_encode(['proven_by_measurement_id' => null] + $copiedProperties)])],
        'refusal trail naming a proving approval' => [$refused, ['trail_inconsistent'], fn () => DB::table('activity_log')->where('id', $refusedTrail->id)
            ->update(['properties' => json_encode(['proven_by_measurement_id' => $copiedProperties['proven_by_measurement_id']] + $refusedProperties)])],
        'trail moved out of the protected log' => [$copied, ['trail_inconsistent'], fn () => $updateTrail(['log_name' => 'default'])],
        'trail properties wiped' => [$copied, ['trail_inconsistent', 'baseline_differs_from_trail'], fn () => $updateTrail(['properties' => '[]'])],
        'baseline written without a trail' => [$withoutLegacy, ['baseline_without_trail'], fn () => $updatePlan($withoutLegacy, ['initial_physical_progress_percent' => 20])],
    };
    $tamper();

    ['exit_code' => $exitCode, 'payload' => $payload, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(1)
        ->and($payload['consistent'])->toBeFalse()
        ->and($plans[$subject->id]['issues'])->toBe($issues)
        ->and($plans[$subject->id]['consistent'])->toBeFalse()
        ->and(collect($plans)->except([$subject->id])->pluck('consistent')->unique()->values()->all())->toBe([true]);

    $this->artisan('measurements:initial-progress-report', ['--all' => true])
        ->expectsOutputToContain('1 plano(s) não conferem com a trilha da migração: veja a coluna Confere.')
        ->expectsOutputToContain('Somente leitura: nenhum registro foi alterado.')
        ->assertFailed();
})->with([
    'baseline zeroed after the copy',
    'reference date added after the copy',
    'baseline given to a plan not copied',
    'second identical trail',
    'copied trail carrying a reason',
    'copied trail without the proving approval',
    'refusal trail naming a proving approval',
    'trail moved out of the protected log',
    'trail properties wiped',
    'baseline written without a trail',
]);

it('names every integrity divergence in the agreement column', function () {
    ['planSet' => $copied, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);
    Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
    ['planSet' => $missing] = Fixture::planWithLegacyLineInitials([1 => 20]);
    ['planSet' => $withoutLegacy] = Fixture::planWithLegacyLineInitials([1 => 0]);
    Fixture::migration()->up();
    $copiedTrail = Fixture::trailsOf($copied->id)->sole();
    DB::table('activity_log')->insert(Arr::except((array) $copiedTrail, ['id']));
    DB::table('activity_log')->where('id', $copiedTrail->id)->update(['log_name' => 'default']);
    DB::table('measurement_plan_sets')->where('id', $copied->id)->update(['initial_physical_progress_percent' => 30]);
    DB::table('activity_log')->where('subject_type', 'App\\Models\\MeasurementPlanSet')->where('subject_id', $missing->id)->where('description', 'initial_physical_progress_not_backfilled')->delete();
    DB::table('measurement_plan_sets')->where('id', $withoutLegacy->id)->update(['initial_physical_progress_percent' => 20]);

    $this->artisan('measurements:initial-progress-report', ['--all' => true])
        ->expectsOutputToContain('Não: mais de uma decisão registrada; registro da decisão incoerente; avanço inicial diferente da decisão registrada')
        ->expectsOutputToContain('Não: a migração não registrou decisão')
        ->expectsOutputToContain('Não: avanço inicial sem trilha e sem data')
        ->expectsOutputToContain('3 plano(s) não conferem com a trilha da migração: veja a coluna Confere.')
        ->assertFailed();
});

it('keeps the recorded decision and flags a plan that changed since it', function (string $case) {
    ['planSet' => $planSet, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);
    $approval = Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
    Fixture::migration()->up();

    [$reevaluated, $drift, $change] = match ($case) {
        'nothing changed' => ['SAFE_BACKFILLED', false, fn () => null],
        'proving approval returned to Engineering' => ['DECLARED_INITIAL_WITH_NO_CURRENT_APPROVAL', true, fn () => DB::table('measurement_reviews')
            ->where('measurement_id', $approval->id)->where('stage', 1)->update(['status' => 'pending'])],
        'first line initial edited' => ['SAFE_BACKFILLED', true, fn () => DB::table('measurement_plan_lines')
            ->where('id', $lines[1]->id)->update(['initial_realized_cumulative_percent' => 30])],
        'line inserted before the first one' => ['SAFE_BACKFILLED', true, fn () => MeasurementPlanLine::factory()->create([
            'plan_set_id' => $planSet->id,
            'operation_id' => $planSet->operation_id,
            'sequence_number' => 0,
            'measurement_date' => '2026-04-01',
            'initial_realized_cumulative_percent' => 35,
        ])],
    };
    $change();

    ['exit_code' => $exitCode, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(0)
        ->and($plans[$planSet->id]['classification'])->toBe('SAFE_BACKFILLED')
        ->and($plans[$planSet->id]['reevaluated']['classification'])->toBe($reevaluated)
        ->and($plans[$planSet->id]['drift'])->toBe($drift)
        ->and($plans[$planSet->id]['consistent'])->toBeTrue();

    $report = $this->artisan('measurements:initial-progress-report');

    if ($drift) {
        $report->expectsOutputToContain('Sim (mudou desde a decisão)')
            ->expectsOutputToContain('1 plano(s) mudaram desde a decisão');
    } else {
        $report->doesntExpectOutputToContain('mudou desde a decisão');
    }

    $report->assertSuccessful();
})->with([
    'nothing changed',
    'proving approval returned to Engineering',
    'first line initial edited',
    'line inserted before the first one',
]);

it('re-evaluates as of the decision, leaving out approvals reviewed after it', function (string $case) {
    ['planSet' => $planSet, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);
    Fixture::migration()->up();
    $decidedAt = CarbonImmutable::parse(Fixture::trailsOf($planSet->id)->sole()->created_at);

    $later = Fixture::legacyApproval($lines[2], '0.00', '10.00', '10.00');
    DB::table('measurement_reviews')->where('measurement_id', $later->id)->where('stage', 1)->update([
        'reviewed_at' => match ($case) {
            'reviewed after the decision' => $decidedAt->addMinute(),
            'reviewed in the same second as the decision' => $decidedAt,
            'reviewed before the decision' => $decidedAt->subMinute(),
        },
    ]);

    ['plans' => $plans] = Fixture::jsonReport();
    $changed = $case !== 'reviewed after the decision';

    expect($plans[$planSet->id]['classification'])->toBe('DECLARED_INITIAL_WITH_NO_CURRENT_APPROVAL')
        ->and($plans[$planSet->id]['reevaluated']['classification'])->toBe($changed ? 'APPROVAL_STARTED_BELOW_DECLARED_INITIAL' : 'DECLARED_INITIAL_WITH_NO_CURRENT_APPROVAL')
        ->and($plans[$planSet->id]['drift'])->toBe($changed)
        ->and($plans[$planSet->id]['current_approvals'])->toBe(1);
})->with([
    'reviewed after the decision',
    'reviewed in the same second as the decision',
    'reviewed before the decision',
]);

it('writes nothing to the database', function () {
    Fixture::scenario('proven on the first line');
    Fixture::scenario('two plans in the same operation');
    Fixture::scenario('legacy initial outside 0% to 100%');
    Fixture::migration()->up();
    Fixture::scenario('not measured yet');

    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower(ltrim($query->sql));
    });

    Artisan::call('measurements:initial-progress-report', ['--all' => true]);
    Artisan::call('measurements:initial-progress-report', ['--json' => true]);

    $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|alter|create|drop|truncate)\b/', $sql) === 1
        || str_contains($sql, 'for update')
        || str_contains($sql, 'lock in share mode'));

    expect($statements)->not->toBeEmpty()
        ->and($writes)->toBe([]);
});

/**
 * Um erro de digitação no filtro não pode virar o relatório de outra operação,
 * nem um "nada a conferir" com código de sucesso.
 */
it('refuses an operation that is not the id of a registered one', function (string $value) {
    Fixture::scenario('proven on the first line');

    $message = $value === '999999'
        ? 'Operação não encontrada: 999999. Informe o id de uma operação cadastrada.'
        : sprintf('Operação inválida: "%s". Informe o id numérico.', $value);

    $this->artisan('measurements:initial-progress-report', ['--operation' => [$value]])
        ->expectsOutputToContain($message)
        ->doesntExpectOutputToContain('Planos analisados')
        ->assertFailed();
})->with(['abc', '12a', '0', '', '-1', '1.5', '999999']);

it('limits the report to the operations asked for', function () {
    $askedId = array_key_first(Fixture::scenario('proven on the first line'));
    Fixture::migration()->up();
    Fixture::scenario('not measured yet');
    $asked = MeasurementPlanSet::query()->findOrFail($askedId);
    $withoutPlans = Operation::factory()->create();

    ['exit_code' => $exitCode, 'payload' => $payload, 'plans' => $plans] = Fixture::jsonReport([
        '--operation' => [$asked->operation_id, (string) $asked->operation_id],
    ]);

    expect($exitCode)->toBe(0)
        ->and(array_keys($plans))->toBe([$asked->id])
        ->and($payload['operation_ids'])->toBe([$asked->operation_id]);

    $this->artisan('measurements:initial-progress-report', ['--operation' => [(string) $withoutPlans->id]])
        ->expectsOutputToContain('Nenhum plano de medição encontrado.')
        ->expectsOutputToContain('Planos analisados: 0')
        ->assertSuccessful();
});

it('predicts from the legacy data before the new columns exist', function () {
    $unprovenScenario = Fixture::scenario('not measured yet');
    $expected = $unprovenScenario;

    foreach (['proven on the first line', 'a later line approved from a zero base', 'copy would take the plan just above 100%', 'legacy initial outside 0% to 100%', 'no legacy initial on the first line'] as $scenario) {
        $expected += Fixture::scenario($scenario);
    }

    $unproven = MeasurementPlanSet::query()->findOrFail(array_key_first($unprovenScenario));
    $unprovenLine = $unproven->lines()->where('sequence_number', 1)->firstOrFail();

    Fixture::migration()->down();

    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower(ltrim($query->sql));
    });

    ['exit_code' => $exitBefore, 'payload' => $before, 'plans' => $predicted] = Fixture::jsonReport();

    $this->artisan('measurements:initial-progress-report', ['--operation' => [(string) $unproven->operation_id]])
        ->expectsOutputToContain('Antes da migração 2026_10_05_170828')
        ->expectsTable(
            ['Operação', 'Plano', '1ª linha', 'Inicial legado', 'Engenharia vigente', 'Baseline atual', 'Classificação', 'Motivo/medição', 'Confere'],
            [[$unproven->operation->code, "{$unproven->name} (#{$unproven->id})", "nº 1 (#{$unprovenLine->id})", '35,00%', 'Não', '—', 'Sem aprovação vigente que o prove', '—', '—']],
        )
        ->expectsOutputToContain('1 plano(s) com "Realiz. inicial" que a migração não vai copiar: o avanço inicial fica em 0,00% e a decisão é do dono.')
        ->doesntExpectOutputToContain('conferem com a trilha')
        ->assertSuccessful();

    $writes = array_filter($statements, fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|alter|create|drop|truncate)\b/', $sql) === 1);

    Fixture::migration()->up();

    ['exit_code' => $exitAfter, 'payload' => $after, 'plans' => $recorded] = Fixture::jsonReport();

    expect($exitBefore)->toBe(0)
        ->and($before['phase'])->toBe('pre_migration')
        ->and($before['consistent'])->toBeNull()
        ->and($writes)->toBe([])
        ->and($exitAfter)->toBe(0)
        ->and($after['phase'])->toBe('post_migration')
        ->and($after['consistent'])->toBeTrue();

    foreach ($expected as $planSetId => $classification) {
        $prediction = $predicted[$planSetId]['prediction'];

        expect($predicted[$planSetId]['classification'])->toBe($classification->value)
            ->and($predicted[$planSetId]['baseline'])->toBeNull()
            ->and($predicted[$planSetId]['consistent'])->toBeNull()
            ->and($recorded[$planSetId]['classification'])->toBe($classification->value);

        if ($prediction['outcome'] === 'skipped') {
            expect(Fixture::trailsOf($planSetId))->toBeEmpty();

            continue;
        }

        expect(Fixture::decisionOf($prediction))
            ->toBe(Fixture::decisionOf(json_decode(Fixture::trailsOf($planSetId)->sole()->properties, true, flags: JSON_THROW_ON_ERROR)));
    }
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'No MySQL o DDL faz commit implícito; a previsão antes das colunas é coberta pelo grupo mysql.');

it('shows the decision recorded by a rolled back migration before the columns exist again', function () {
    $planSetId = array_key_first(Fixture::scenario('not measured yet'));
    Fixture::migration()->up();
    $trail = Fixture::trailsOf($planSetId)->sole();
    Fixture::migration()->down();

    ['exit_code' => $exitCode, 'payload' => $payload, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(0)
        ->and($payload['phase'])->toBe('pre_migration')
        ->and($plans[$planSetId]['classification'])->toBe('DECLARED_INITIAL_WITH_NO_CURRENT_APPROVAL')
        ->and($plans[$planSetId]['prediction'])->toBeNull()
        ->and($plans[$planSetId]['recorded']['activity_id'])->toBe($trail->id);

    Fixture::migration()->up();

    expect(Fixture::trailsOf($planSetId))->toHaveCount(1)
        ->and(Fixture::jsonReport()['plans'][$planSetId]['consistent'])->toBeTrue();
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'No MySQL o DDL faz commit implícito e escaparia do RefreshDatabase.');

it('classifies what the migration skips without a trail', function (string $case) {
    [$initial, $expected] = match ($case) {
        'zero' => ['0.00', Classification::NoLegacyInitialProgress],
        'empty' => [null, Classification::NoLegacyInitialProgress],
        'truncated to zero' => ['0.004', Classification::NoLegacyInitialProgress],
        'negative' => ['-5.00', Classification::LegacyInitialOutOfRange],
        'just above 100%' => ['100.01', Classification::LegacyInitialOutOfRange],
        'unreadable' => ['abc', Classification::LegacyInitialOutOfRange],
    };

    $decision = app(MeasurementInitialProgressDiagnosticService::class)->decide(
        ['id' => 7, 'sequence_number' => 1, 'initial' => $initial],
        [['measurement_id' => 3, 'plan_line_id' => 7, 'monthly' => '5.00', 'cumulative' => '40.00']],
    );

    expect($decision['outcome'])->toBe('skipped')
        ->and($decision['classification'])->toBe($expected)
        ->and($decision['reason'])->toBeNull()
        ->and($decision['measured_percent'])->toBeNull()
        ->and($decision['proven_by_measurement_id'])->toBeNull();
})->with(['zero', 'empty', 'truncated to zero', 'negative', 'just above 100%', 'unreadable']);

it('decides a plan without lines as without legacy initial', function () {
    $decision = app(MeasurementInitialProgressDiagnosticService::class)->decide(null, []);

    expect($decision['outcome'])->toBe('skipped')
        ->and($decision['classification'])->toBe(Classification::NoLegacyInitialProgress)
        ->and($decision['plan_line_id'])->toBeNull();
});

/**
 * Um valor não escalar no snapshot -- uma lista, por exemplo -- quebra a
 * migração ("Array to string conversion"), que converte cada valor em texto.
 * A previsão não pode quebrar junto nem tratá-lo como o ilegível ('n/d', vazio),
 * que a migração lê como 0 sem quebrar: aponta a medição. O resto da conta lê
 * o valor como 0 só para o plano ter classificação. Plano que a migração pula
 * não lê aprovação nenhuma, e nada quebra nele.
 */
it('flags the approval whose snapshot value would break the migration instead of reading it as zero', function () {
    $diagnostic = app(MeasurementInitialProgressDiagnosticService::class);
    $entries = [
        ['measurement_id' => 3, 'plan_line_id' => 7, 'monthly' => ['5.00'], 'cumulative' => '40.00'],
        ['measurement_id' => 4, 'plan_line_id' => 8, 'monthly' => '1.00', 'cumulative' => ['41.00']],
        ['measurement_id' => 5, 'plan_line_id' => 9, 'monthly' => 'n/d', 'cumulative' => null],
    ];

    $decided = $diagnostic->decide(['id' => 7, 'sequence_number' => 1, 'initial' => '35.00'], $entries);
    $skipped = $diagnostic->decide(['id' => 7, 'sequence_number' => 1, 'initial' => '0.00'], $entries);

    expect($decided['migration_would_fail_measurement_ids'])->toBe([3, 4])
        ->and($decided['measured_percent'])->toBe('1.00')
        ->and($skipped['outcome'])->toBe('skipped')
        ->and($skipped['migration_would_fail_measurement_ids'])->toBe([]);
});

/**
 * Antes do deploy, o plano em que a migração vai quebrar é o que mais importa
 * saber: o `migrate --force` do startup para nele. O relatório o aponta e sai
 * com falha, em vez de prever uma cópia que não vai acontecer.
 */
it('fails before the migration naming the plan where it would break', function () {
    ['planSet' => $breaking, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);
    $approval = Fixture::legacyApprovalWithUnconvertibleProgress($lines[1], '35.00', '5.00', '40.00');
    $provenId = array_key_first(Fixture::scenario('proven on the first line'));
    Fixture::migration()->down();

    ['exit_code' => $exitCode, 'payload' => $payload, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(1)
        ->and($payload['phase'])->toBe('pre_migration')
        ->and($payload['consistent'])->toBeNull()
        ->and($payload['migration_would_fail_plan_ids'])->toBe([$breaking->id])
        ->and($plans[$breaking->id]['migration_would_fail_measurement_ids'])->toBe([$approval->id])
        ->and($plans[$breaking->id]['unreadable_approval_measurement_ids'])->toBe([])
        ->and($plans[$provenId]['migration_would_fail_measurement_ids'])->toBe([]);

    ['exit_code' => $exitCode, 'output' => $output] = Fixture::textReport();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(
            "a migração vai falhar neste plano (medição #{$approval->id})",
            'A migração vai falhar em 1 plano(s)',
            'Somente leitura: nenhum registro foi alterado.',
        )
        ->and($output)->not->toContain('avanço ilegível');
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'No MySQL o DDL faz commit implícito; o caminho depois das colunas cobre o MySQL.');

/**
 * A migração real é o oráculo: no plano apontado ela quebra e não grava
 * decisão. Com as colunas já criadas -- um deploy que parou no meio --, o plano
 * segue sem decisão, e o relatório diz por quê.
 */
it('flags the plan where the real migration breaks, and the migration does break there', function () {
    ['planSet' => $breaking, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);
    $approval = Fixture::legacyApprovalWithUnconvertibleProgress($lines[1], '35.00', '5.00', '40.00');

    ['exit_code' => $exitCode, 'payload' => $payload, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(1)
        ->and($payload['migration_would_fail_plan_ids'])->toBe([$breaking->id])
        ->and($plans[$breaking->id]['classification'])->toBe('DECISION_MISSING')
        ->and($plans[$breaking->id]['migration_would_fail_measurement_ids'])->toBe([$approval->id])
        ->and($plans[$breaking->id]['prediction']['migration_would_fail_measurement_ids'])->toBe([$approval->id]);

    expect(fn () => Fixture::migration()->up())->toThrow(ErrorException::class, 'Array to string conversion')
        ->and(Fixture::trailsOf($breaking->id))->toBeEmpty();
});

/**
 * Só quebra o plano que a migração ainda vai decidir. Já decidido, ou com
 * avanço informado na criação, ela o pula sem ler aprovação nenhuma: acusar
 * falha ali seria alarme falso. A migração real confirma passando por ele.
 */
it('does not flag a plan the migration no longer decides', function (string $case) {
    ['planSet' => $planSet, 'lines' => $lines] = Fixture::planWithLegacyLineInitials([1 => 35, 2 => 0]);

    if ($case === 'decided before the approval arrived') {
        Fixture::legacyApproval($lines[1], '35.00', '5.00', '40.00');
        Fixture::migration()->up();
        Fixture::legacyApprovalWithUnconvertibleProgress($lines[2], '0.00', '4.00', '44.00');
    } else {
        DB::table('measurement_plan_sets')->where('id', $planSet->id)->update([
            'initial_physical_progress_percent' => '10.00',
            'initial_physical_progress_reference_date' => '2026-04-30',
        ]);
        Fixture::legacyApprovalWithUnconvertibleProgress($lines[1], '35.00', '5.00', '40.00');
    }

    ['exit_code' => $exitCode, 'payload' => $payload, 'plans' => $plans] = Fixture::jsonReport();

    expect($exitCode)->toBe(0)
        ->and($payload['migration_would_fail_plan_ids'])->toBe([])
        ->and($plans[$planSet->id]['migration_would_fail_measurement_ids'])->toBe([]);

    Fixture::migration()->up();

    expect(Fixture::trailsOf($planSet->id))->toHaveCount($case === 'decided before the approval arrived' ? 1 : 0);
})->with(['decided before the approval arrived', 'informed at creation']);

/**
 * A saída de antes vira a previsão de uma decisão irreversível, então precisa
 * dizer de qual banco saiu. Com configuração em cache, as variáveis DB_* do
 * shell são ignoradas sem aviso; a conexão impressa é a prova.
 */
it('names the database it read in the JSON and in the text header', function () {
    Fixture::scenario('proven on the first line');
    $settings = config('database.connections.'.config('database.default'));
    $host = $settings['host'] ?? null;
    $port = isset($settings['port']) ? (string) $settings['port'] : null;

    ['payload' => $payload] = Fixture::jsonReport();
    ['output' => $output] = Fixture::textReport();

    expect($payload['connection'])->toBe([
        'driver' => DB::getDriverName(),
        'host' => $host,
        'port' => $port,
        'database' => $settings['database'],
    ])
        ->and(preg_split('/\R/', trim($output))[1])->toBe($host === null
            ? sprintf('Banco lido: %s, base %s.', DB::getDriverName(), $settings['database'])
            : sprintf('Banco lido: %s em %s:%s, base %s.', DB::getDriverName(), $host, $port, $settings['database']));
});

/**
 * O JSON é o arquivo que se guarda e se compara plano a plano: ele sai cru,
 * sem passar pelo formatador do console, que comeria uma marcação de estilo no
 * nome do plano e deixaria um escape inválido no lugar de '\<'.
 */
it('writes the JSON verbatim even when a plan name looks like console markup', function (string $name) {
    ['planSet' => $planSet] = Fixture::planWithLegacyLineInitials([1 => 35]);
    DB::table('measurement_plan_sets')->where('id', $planSet->id)->update(['name' => $name]);

    ['plans' => $plans] = Fixture::jsonReport();

    expect($plans[$planSet->id]['plan_name'])->toBe($name);
})->with([
    'style tag' => 'Torre <info>A</info>',
    'escaped angle bracket' => 'Bloco C\<1>',
]);
