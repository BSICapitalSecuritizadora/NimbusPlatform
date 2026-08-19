<?php

use App\Actions\Emissions\GenerateObligationOccurrencesAction;
use App\Actions\Emissions\SendObligationDueNotificationsAction;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Enums\AccessPermission;
use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInvalidDayPolicy;
use App\Enums\ObligationSeriesStatus;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationSeriesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\Obligation;
use App\Models\ObligationComment;
use App\Models\ObligationEvidence;
use App\Models\ObligationHistoryEntry;
use App\Models\ObligationNotification;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use App\Models\User;
use App\Services\Obligations\ObligationDashboardData;
use App\Services\Obligations\ObligationScheduleCalculator;
use App\Services\Obligations\ObligationSeriesService;
use App\Services\Obligations\ObligationSuggestionReviewService;
use App\Services\Obligations\ObligationWorkflowService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    CarbonImmutable::setTestNow('2026-08-18 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $seriesOverrides
 * @param  array<string, mixed>  $ruleOverrides
 * @return array{0: ObligationSeries, 1: ObligationSeriesRule}
 */
function createTestObligationSeries(array $seriesOverrides = [], array $ruleOverrides = []): array
{
    $series = ObligationSeries::factory()->create(array_merge([
        'frequency' => ObligationFrequency::Monthly,
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-12-31',
        'due_rule_type' => ObligationDueRuleType::FixedDay,
        'due_day' => 10,
        'due_offset_months' => 1,
        'due_offset_days' => null,
        'invalid_day_policy' => ObligationInvalidDayPolicy::LastValidDay,
        'generation_horizon_days' => 90,
        'status' => ObligationSeriesStatus::Active,
        'configuration_confirmed_at' => now(),
    ], $seriesOverrides));

    $rule = ObligationSeriesRule::factory()->for($series, 'series')->create(array_merge([
        'version' => 1,
        'effective_from' => $series->starts_on,
        'frequency' => $series->frequency,
        'due_rule_type' => $series->due_rule_type,
        'due_day' => $series->due_day,
        'due_offset_months' => $series->due_offset_months,
        'due_offset_days' => $series->due_offset_days,
        'invalid_day_policy' => $series->invalid_day_policy,
        'calendar_code' => $series->calendar_code,
    ], $ruleOverrides));

    return [$series, $rule];
}

function recurrenceUserWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('creates the recurrence schema with explicit competence uniqueness', function () {
    expect(Schema::hasTable('obligation_series'))->toBeTrue()
        ->and(Schema::hasTable('obligation_series_rules'))->toBeTrue()
        ->and(Schema::hasColumn('obligation_series', 'due_offset_days'))->toBeTrue()
        ->and(Schema::hasColumn('obligation_series_rules', 'due_offset_days'))->toBeTrue()
        ->and(Schema::hasColumns('obligations', [
            'obligation_series_id',
            'obligation_series_rule_id',
            'competence_date',
            'generation_source',
            'generated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('obligation_notifications', 'deduplication_key'))->toBeTrue();
});

it('materializes a monthly 90 day window idempotently', function () {
    [$series] = createTestObligationSeries();
    $generator = app(GenerateObligationOccurrencesAction::class);

    $firstRun = $generator->generateForSeries($series, CarbonImmutable::parse('2026-08-18'));
    $secondRun = $generator->generateForSeries($series, CarbonImmutable::parse('2026-08-18'));

    expect($firstRun['created'])->toBe(3)
        ->and($secondRun['created'])->toBe(0)
        ->and($secondRun['existing'])->toBe(3)
        ->and($series->occurrences()->count())->toBe(3)
        ->and($series->occurrences()->pluck('competence_date')->map->format('Y-m-d')->all())
        ->toBe(['2026-08-01', '2026-09-01', '2026-10-01'])
        ->and($series->occurrences()->pluck('due_date')->map->format('Y-m-d')->all())
        ->toBe(['2026-09-10', '2026-10-10', '2026-11-10'])
        ->and(ObligationHistoryEntry::query()
            ->whereIn('obligation_id', $series->occurrences()->pluck('id'))
            ->where('event_type', ObligationHistoryEntry::EVENT_GENERATED_FROM_SERIES)
            ->count())->toBe(3);
});

it('activates a recurring document suggestion only with a reviewer-confirmed structured rule', function () {
    $emission = Emission::factory()->create(['maturity_date' => '2030-12-15']);
    $suggestion = ExtractedObligation::factory()->for($emission)->create([
        'recurrence' => 'Mensal',
        'due_rule' => 'Até o 10º dia útil do mês subsequente.',
        'due_date' => null,
        'status' => ExtractedObligation::STATUS_SUGGESTED,
    ]);
    $reviewer = recurrenceUserWithPermissions([
        AccessPermission::ObligationsReviewSuggestions->value,
        AccessPermission::ObligationsApproveSuggestion->value,
    ]);

    app(ObligationSuggestionReviewService::class)->approve(
        $suggestion,
        $reviewer,
        'Regra conferida no documento.',
        [
            'frequency' => ObligationFrequency::Monthly->value,
            'starts_on' => '2026-08-01',
            'ends_on' => '2030-12-15',
            'due_rule_type' => ObligationDueRuleType::NthBusinessDay->value,
            'due_day' => 10,
            'due_offset_months' => 1,
            'calendar_code' => 'B3',
            'generation_horizon_days' => 90,
        ],
    );

    $series = $suggestion->fresh()->obligationSeries;

    expect($suggestion->fresh()->status)->toBe(ExtractedObligation::STATUS_APPROVED)
        ->and($series)->not->toBeNull()
        ->and($series->status)->toBe(ObligationSeriesStatus::Active)
        ->and($series->due_rule)->toBe('Até o 10º dia útil do mês subsequente.')
        ->and($series->rules()->count())->toBe(1)
        ->and($series->occurrences()->count())->toBe(3);
});

it('keeps fixed day calculations anchored without end of month drift', function () {
    [$series] = createTestObligationSeries([
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-04-30',
        'due_day' => 31,
        'due_offset_months' => 0,
        'generation_horizon_days' => 180,
    ]);

    app(GenerateObligationOccurrencesAction::class)
        ->generateForSeries($series, CarbonImmutable::parse('2026-01-01'));

    expect($series->occurrences()->pluck('due_date')->map->format('Y-m-d')->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('calculates calendar days after competence end across month year and leap-day boundaries', function (
    string $competence,
    int $days,
    string $expectedDueDate,
) {
    [$series, $rule] = createTestObligationSeries([
        'starts_on' => CarbonImmutable::parse($competence)->startOfMonth(),
        'ends_on' => CarbonImmutable::parse($competence)->endOfMonth(),
        'due_rule_type' => ObligationDueRuleType::CalendarDaysAfterCompetenceEnd,
        'due_day' => null,
        'due_offset_months' => 0,
        'due_offset_days' => $days,
        'invalid_day_policy' => null,
    ]);

    $dueDate = app(ObligationScheduleCalculator::class)
        ->resolveDueDate($rule, CarbonImmutable::parse($competence));

    expect($dueDate?->toDateString())->toBe($expectedDueDate)
        ->and($series->rule_summary)->toContain($days.' dia');
})->with([
    'virada de mês' => ['2026-01-01', 10, '2026-02-10'],
    'fevereiro bissexto' => ['2028-02-01', 1, '2028-03-01'],
    'virada de ano' => ['2026-12-01', 5, '2027-01-05'],
]);

it('validates persists and materializes calendar days after competence end idempotently', function () {
    $emission = Emission::factory()->create();
    $actor = recurrenceUserWithPermissions([AccessPermission::ObligationsCreate->value]);
    $configuration = [
        'title' => 'Relatório mensal da emissora',
        'priority' => 'high',
        'frequency' => ObligationFrequency::Monthly->value,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-10-31',
        'due_rule_type' => ObligationDueRuleType::CalendarDaysAfterCompetenceEnd->value,
        'generation_horizon_days' => 90,
    ];

    try {
        app(ObligationSeriesService::class)->createConfigured($emission, $actor, $configuration);
        $this->fail('A configuração sem a quantidade de dias deveria ser inválida.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('due_offset_days');
    }

    $series = app(ObligationSeriesService::class)->createConfigured($emission, $actor, [
        ...$configuration,
        'due_offset_days' => 10,
    ]);
    $secondRun = app(GenerateObligationOccurrencesAction::class)
        ->generateForSeries($series, CarbonImmutable::parse('2026-08-18'));

    expect($series->due_offset_months)->toBe(0)
        ->and($series->due_offset_days)->toBe(10)
        ->and($series->latestRule->due_offset_days)->toBe(10)
        ->and($series->rule_summary)->toBe('10 dias corridos após o encerramento da competência')
        ->and($series->occurrences()->pluck('competence_date')->map->format('Y-m-d')->all())
        ->toBe(['2026-08-01', '2026-09-01', '2026-10-01'])
        ->and($series->occurrences()->pluck('due_date')->map->format('Y-m-d')->all())
        ->toBe(['2026-09-10', '2026-10-10', '2026-11-10'])
        ->and($secondRun['created'])->toBe(0)
        ->and($secondRun['existing'])->toBe(3);
});

it('handles leap day predictably according to the configured invalid-day policy', function () {
    [$series] = createTestObligationSeries([
        'frequency' => ObligationFrequency::Annual,
        'starts_on' => '2028-02-01',
        'ends_on' => '2029-02-28',
        'due_day' => 29,
        'due_offset_months' => 0,
        'generation_horizon_days' => 730,
    ]);

    app(GenerateObligationOccurrencesAction::class)
        ->generateForSeries($series, CarbonImmutable::parse('2028-01-01'));

    expect($series->occurrences()->pluck('due_date')->map->format('Y-m-d')->all())
        ->toBe(['2028-02-29', '2029-02-28']);
});

it('keeps quarterly semiannual and annual frequencies anchored to the initial competence', function (
    ObligationFrequency $frequency,
    string $endsOn,
    array $expectedCompetences,
) {
    [$series] = createTestObligationSeries([
        'frequency' => $frequency,
        'starts_on' => '2026-01-01',
        'ends_on' => $endsOn,
        'due_day' => 1,
        'due_offset_months' => 0,
        'generation_horizon_days' => 730,
    ]);

    app(GenerateObligationOccurrencesAction::class)
        ->generateForSeries($series, CarbonImmutable::parse('2026-01-01'));

    expect($series->occurrences()->pluck('competence_date')->map->format('Y-m-d')->all())
        ->toBe($expectedCompetences);
})->with([
    'trimestral' => [
        ObligationFrequency::Quarterly,
        '2026-10-31',
        ['2026-01-01', '2026-04-01', '2026-07-01', '2026-10-01'],
    ],
    'semestral' => [
        ObligationFrequency::Semiannual,
        '2027-07-31',
        ['2026-01-01', '2026-07-01', '2027-01-01', '2027-07-01'],
    ],
    'anual' => [
        ObligationFrequency::Annual,
        '2028-01-31',
        ['2026-01-01', '2027-01-01', '2028-01-01'],
    ],
]);

it('never generates a competence after the configured end date', function () {
    [$series] = createTestObligationSeries([
        'starts_on' => '2030-10-01',
        'ends_on' => '2030-12-15',
        'due_day' => 10,
        'due_offset_months' => 0,
        'generation_horizon_days' => 180,
    ]);

    app(GenerateObligationOccurrencesAction::class)
        ->generateForSeries($series, CarbonImmutable::parse('2030-10-01'));

    expect($series->occurrences()->pluck('competence_date')->map->format('Y-m-d')->all())
        ->toBe(['2030-10-01', '2030-11-01', '2030-12-01'])
        ->not->toContain('2031-01-01');
});

it('does not generate while paused and resumes only missing occurrences after reactivation', function () {
    [$series] = createTestObligationSeries([
        'status' => ObligationSeriesStatus::Paused,
    ]);
    $actor = recurrenceUserWithPermissions([AccessPermission::ObligationsUpdate->value]);

    $pausedResult = app(GenerateObligationOccurrencesAction::class)->generateForSeries($series);

    expect($pausedResult['created'])->toBe(0)
        ->and($series->occurrences()->count())->toBe(0);

    app(ObligationSeriesService::class)->reactivate($series, $actor, 'Retomada da exigibilidade contratual.');

    expect($series->fresh()->status)->toBe(ObligationSeriesStatus::Active)
        ->and($series->occurrences()->count())->toBe(3);
});

it('keeps completion evidence and comments isolated by competence', function () {
    [$series] = createTestObligationSeries();
    app(GenerateObligationOccurrencesAction::class)->generateForSeries($series);
    $occurrences = $series->occurrences()->get();
    $actor = recurrenceUserWithPermissions([AccessPermission::ObligationsComplete->value]);

    ObligationEvidence::factory()->approved()->create([
        'obligation_id' => $occurrences[0]->id,
        'emission_id' => $series->emission_id,
    ]);
    ObligationComment::factory()->create([
        'obligation_id' => $occurrences[0]->id,
        'emission_id' => $series->emission_id,
        'user_id' => $actor->id,
        'body' => 'Comentário exclusivo da competência 08/2026.',
    ]);
    app(ObligationWorkflowService::class)->complete(
        $occurrences[0],
        $actor,
        'Relatório da competência entregue.',
        false,
    );

    expect($occurrences[0]->fresh()->status)->toBe('concluida')
        ->and($occurrences[0]->evidences()->count())->toBe(1)
        ->and($occurrences[0]->comments()->count())->toBe(1)
        ->and($occurrences[1]->fresh()->status)->toBe('a_vencer')
        ->and($occurrences[1]->evidences()->count())->toBe(0)
        ->and($occurrences[1]->comments()->count())->toBe(0)
        ->and($occurrences[2]->fresh()->status)->toBe('a_vencer')
        ->and($occurrences[2]->evidences()->count())->toBe(0);
});

it('marks only one competence not applicable without closing its series', function () {
    [$series] = createTestObligationSeries();
    app(GenerateObligationOccurrencesAction::class)->generateForSeries($series);
    $occurrences = $series->occurrences()->get();
    $actor = recurrenceUserWithPermissions([AccessPermission::ObligationsMarkNotApplicable->value]);

    app(ObligationWorkflowService::class)->markNotApplicable(
        $occurrences[0],
        $actor,
        'Exceção aplicável somente à competência.',
    );

    expect($occurrences[0]->fresh()->status)->toBe('nao_aplicavel')
        ->and($occurrences[1]->fresh()->status)->toBe('a_vencer')
        ->and($series->fresh()->status)->toBe(ObligationSeriesStatus::Active);
});

it('requires an explicit series scope to close recurrence from a not-applicable occurrence', function () {
    [$series] = createTestObligationSeries();
    app(GenerateObligationOccurrencesAction::class)->generateForSeries($series);
    $occurrences = $series->occurrences()->get();
    $actor = recurrenceUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsMarkNotApplicable->value,
        AccessPermission::ObligationsUpdate->value,
    ]);
    $this->actingAs($actor);

    Livewire::test(ObligationsRelationManager::class, [
        'ownerRecord' => $series->emission,
        'pageClass' => EditEmission::class,
    ])
        ->callTableAction('mark_not_applicable', $occurrences[0], data: [
            'scope' => 'series',
            'reason' => 'A obrigação recorrente foi revogada pelo aditamento.',
        ])
        ->assertHasNoTableActionErrors();

    expect($occurrences[0]->fresh()->status)->toBe('nao_aplicavel')
        ->and($occurrences[1]->fresh()->status)->toBe('a_vencer')
        ->and($series->fresh()->status)->toBe(ObligationSeriesStatus::Closed)
        ->and($series->fresh()->close_reason)->toBe('A obrigação recorrente foi revogada pelo aditamento.');
});

it('closes a series without deleting or finalizing its existing occurrences', function () {
    [$series] = createTestObligationSeries();
    app(GenerateObligationOccurrencesAction::class)->generateForSeries($series);
    $actor = recurrenceUserWithPermissions([AccessPermission::ObligationsUpdate->value]);

    app(ObligationSeriesService::class)->close($series, $actor, 'A obrigação deixou de existir no instrumento.');
    $generationResult = app(GenerateObligationOccurrencesAction::class)
        ->generateForSeries($series->fresh(), CarbonImmutable::parse('2027-01-01'));

    expect($series->fresh()->status)->toBe(ObligationSeriesStatus::Closed)
        ->and($series->fresh()->closed_by)->toBe($actor->id)
        ->and($series->occurrences()->count())->toBe(3)
        ->and($series->occurrences()->where('status', 'a_vencer')->count())->toBe(3)
        ->and($generationResult['created'])->toBe(0)
        ->and($series->activities()->where('event', 'series_closed')->where('causer_id', $actor->id)->exists())->toBeTrue();
});

it('versions a rule from a competence and preserves touched or historical occurrences', function () {
    [$series] = createTestObligationSeries([
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-12-31',
        'due_offset_months' => 0,
        'generation_horizon_days' => 180,
    ]);
    app(GenerateObligationOccurrencesAction::class)
        ->generateForSeries($series, CarbonImmutable::parse('2026-07-01'));
    $july = $series->occurrences()->whereDate('competence_date', '2026-07-01')->firstOrFail();
    $september = $series->occurrences()->whereDate('competence_date', '2026-09-01')->firstOrFail();
    ObligationEvidence::factory()->create([
        'obligation_id' => $september->id,
        'emission_id' => $series->emission_id,
    ]);
    $actor = recurrenceUserWithPermissions([AccessPermission::ObligationsUpdate->value]);

    app(ObligationSeriesService::class)->reviseRuleFrom($series, $actor, [
        'effective_from' => '2026-09-01',
        'change_reason' => 'Aditamento alterou o vencimento para o dia 15.',
        'frequency' => ObligationFrequency::Monthly->value,
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-12-31',
        'due_rule_type' => ObligationDueRuleType::FixedDay->value,
        'due_day' => 15,
        'due_offset_months' => 0,
        'invalid_day_policy' => ObligationInvalidDayPolicy::LastValidDay->value,
        'generation_horizon_days' => 180,
    ]);

    expect($series->rules()->count())->toBe(2)
        ->and($july->fresh()->due_date->toDateString())->toBe('2026-07-10')
        ->and($september->fresh()->due_date->toDateString())->toBe('2026-09-10')
        ->and($september->fresh()->evidences()->count())->toBe(1)
        ->and($series->occurrences()->whereDate('competence_date', '2026-10-01')->firstOrFail()->due_date->toDateString())->toBe('2026-10-15')
        ->and($series->occurrences()->whereDate('competence_date', '2026-12-01')->firstOrFail()->due_date->toDateString())->toBe('2026-12-15');
});

it('uses the B3 calendar for nth business day across weekends holidays and year boundaries', function () {
    BusinessCalendarDate::factory()->create([
        'calendar_code' => 'B3',
        'calendar_date' => '2027-01-01',
        'is_business_day' => false,
        'description' => 'Confraternização Universal',
    ]);
    app(BusinessCalendarService::class)->flushCache();
    [$series, $rule] = createTestObligationSeries([
        'starts_on' => '2026-12-01',
        'ends_on' => '2026-12-31',
        'due_rule_type' => ObligationDueRuleType::NthBusinessDay,
        'due_day' => 10,
        'due_offset_months' => 1,
        'invalid_day_policy' => null,
        'calendar_code' => 'B3',
    ]);

    $dueDate = app(ObligationScheduleCalculator::class)
        ->resolveDueDate($rule, CarbonImmutable::parse('2026-12-01'));

    expect($dueDate?->toDateString())->toBe('2027-01-15')
        ->and($series->rule_summary)->toContain('10º dia útil');
});

it('never generates on-demand occurrences automatically and enforces one per competence', function () {
    [$series] = createTestObligationSeries([
        'frequency' => ObligationFrequency::OnDemand,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'due_rule_type' => null,
        'due_day' => null,
        'invalid_day_policy' => null,
    ], [
        'frequency' => ObligationFrequency::OnDemand,
        'due_rule_type' => null,
        'due_day' => null,
        'invalid_day_policy' => null,
    ]);
    $actor = recurrenceUserWithPermissions([AccessPermission::ObligationsCreate->value]);

    expect(app(GenerateObligationOccurrencesAction::class)->generateForSeries($series)['created'])->toBe(0);

    app(ObligationSeriesService::class)->createOnDemandOccurrence($series, $actor, [
        'competence_date' => '2026-08-18',
        'due_date' => '2026-08-31',
    ]);

    expect($series->occurrences()->count())->toBe(1)
        ->and(fn () => app(ObligationSeriesService::class)->createOnDemandOccurrence($series, $actor, [
            'competence_date' => '2026-08-01',
            'due_date' => '2026-09-01',
        ]))->toThrow(ValidationException::class);
});

it('backfills legacy recurring records conservatively without inventing competence or dates', function () {
    $emission = Emission::factory()->create();
    $withoutDueDate = Obligation::factory()->for($emission)->create([
        'title' => 'Relatório mensal sem data',
        'recurrence' => 'Mensal',
        'due_date' => null,
        'status' => 'em_dia',
    ]);
    $completed = Obligation::factory()->for($emission)->create([
        'title' => 'Relatório mensal já concluído',
        'recurrence' => 'Mensal',
        'due_date' => '2026-07-10',
        'status' => 'concluida',
        'completed_at' => '2026-07-09 12:00:00',
    ]);
    $evidence = ObligationEvidence::factory()->create([
        'obligation_id' => $completed->id,
        'emission_id' => $emission->id,
    ]);
    $single = Obligation::factory()->for($emission)->create([
        'title' => 'Obrigação única',
        'recurrence' => 'Única',
        'due_date' => '2026-10-10',
    ]);
    $migration = require database_path('migrations/2026_08_18_180612_backfill_existing_obligation_series.php');

    $migration->up();

    expect($withoutDueDate->fresh()->series)->not->toBeNull()
        ->and($withoutDueDate->fresh()->series->status)->toBe(ObligationSeriesStatus::AwaitingConfiguration)
        ->and($withoutDueDate->fresh()->competence_date)->toBeNull()
        ->and($withoutDueDate->fresh()->due_date)->toBeNull()
        ->and($completed->fresh()->status)->toBe('concluida')
        ->and($completed->fresh()->completed_at)->not->toBeNull()
        ->and($completed->fresh()->competence_date)->toBeNull()
        ->and($completed->fresh()->evidences()->whereKey($evidence)->exists())->toBeTrue()
        ->and($single->fresh()->obligation_series_id)->toBeNull()
        ->and(ObligationSeries::query()->where('is_legacy_backfill', true)->count())->toBe(2);
});

it('sends one alert per occurrence and does not duplicate it on reprocessing', function () {
    Mail::fake();
    config()->set('obligations.notifications.due_soon_days', [3]);
    config()->set('obligations.notifications.fallback_email', null);
    [$series, $rule] = createTestObligationSeries();
    $responsible = User::factory()->create(['email' => 'responsavel@example.com']);

    foreach (['2026-07-01', '2026-08-01'] as $competence) {
        Obligation::factory()->for($series->emission)->create([
            'obligation_series_id' => $series->id,
            'obligation_series_rule_id' => $rule->id,
            'competence_date' => $competence,
            'generation_source' => Obligation::GENERATION_SOURCE_AUTOMATIC,
            'responsible_user_id' => $responsible->id,
            'due_date' => '2026-08-21',
            'status' => 'a_vencer',
        ]);
    }

    $firstRun = app(SendObligationDueNotificationsAction::class)->handle(CarbonImmutable::parse('2026-08-18'));
    $secondRun = app(SendObligationDueNotificationsAction::class)->handle(CarbonImmutable::parse('2026-08-18'));

    expect($firstRun['sent'])->toBe(2)
        ->and($secondRun['sent'])->toBe(0)
        ->and(ObligationNotification::query()->count())->toBe(2)
        ->and(ObligationNotification::query()->distinct()->count('obligation_id'))->toBe(2)
        ->and(ObligationHistoryEntry::query()
            ->where('event_type', ObligationHistoryEntry::EVENT_NOTIFICATION_SENT)
            ->count())->toBe(2);
});

it('filters occurrence indicators by series recurrence and competence', function () {
    [$monthlySeries, $monthlyRule] = createTestObligationSeries();
    [$quarterlySeries, $quarterlyRule] = createTestObligationSeries([
        'frequency' => ObligationFrequency::Quarterly,
    ]);
    Obligation::factory()->for($monthlySeries->emission)->create([
        'obligation_series_id' => $monthlySeries->id,
        'obligation_series_rule_id' => $monthlyRule->id,
        'competence_date' => '2026-08-01',
    ]);
    Obligation::factory()->for($quarterlySeries->emission)->create([
        'obligation_series_id' => $quarterlySeries->id,
        'obligation_series_rule_id' => $quarterlyRule->id,
        'competence_date' => '2026-10-01',
    ]);

    $summary = app(ObligationDashboardData::class)->summary([
        'obligation_series_id' => $monthlySeries->id,
        'frequency' => ObligationFrequency::Monthly->value,
        'competence_from' => '2026-08-01',
        'competence_to' => '2026-08-31',
    ]);

    expect($summary['total'])->toBe(1);
});

it('renders the series administration with its operational occurrences', function () {
    $this->actingAs(makeAdminUser());
    [$series] = createTestObligationSeries();
    app(GenerateObligationOccurrencesAction::class)->generateForSeries($series);

    Livewire::test(ObligationSeriesRelationManager::class, [
        'ownerRecord' => $series->emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$series]);
});
