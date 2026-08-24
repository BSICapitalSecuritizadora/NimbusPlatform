<?php

use App\Actions\Emissions\GenerateObligationOccurrencesAction;
use App\Domain\PuCalculator\Services\BusinessCalendarRevisionService;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Enums\AccessPermission;
use App\Enums\ObligationDueDateCalculationStatus;
use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInitialDateInclusion;
use App\Enums\ObligationOffsetDirection;
use App\Enums\ObligationSeriesStatus;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarSelectionEvidence;
use App\Models\BusinessCalendarYear;
use App\Models\Emission;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use App\Models\User;
use App\Services\Obligations\ObligationCalendarReproducibilityService;
use App\Services\Obligations\ObligationScheduleCalculator;
use App\Services\Obligations\ObligationSeriesService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    CarbonImmutable::setTestNow('2026-08-24 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>  $holidays
 */
function createConfirmedObligationCalendar(
    string $code,
    int $fromYear = 2025,
    int $toYear = 2027,
    array $holidays = [],
): BusinessCalendar {
    $calendar = BusinessCalendar::factory()->create([
        'code' => $code,
        'name' => 'Calendário contratual '.$code,
        'purpose' => 'Calendário confirmado exclusivamente para o contrato em teste.',
        'calendar_type' => 'contractual',
        'source' => 'Instrumento contratual em teste',
        'status' => 'active',
        'financial_use_allowed' => true,
        'is_legacy' => false,
        'is_homologation' => false,
        'available_for_new_configurations' => true,
    ]);

    for ($year = $fromYear; $year <= $toYear; $year++) {
        $calendarYear = BusinessCalendarYear::factory()->create([
            'calendar_code' => $code,
            'year' => $year,
            'status' => BusinessCalendarYear::STATUS_CONFIRMED,
            'source_is_official' => true,
            'source_document' => 'Contrato confirmado.pdf',
            'confirmed_at' => now(),
        ]);
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end = $start->endOfYear();
        $rows = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $isHoliday = in_array($date->toDateString(), $holidays, true);
            $rows[] = [
                'calendar_code' => $code,
                'business_calendar_year_id' => $calendarYear->id,
                'calendar_date' => $date->toDateString(),
                'is_business_day' => ! $date->isWeekend() && ! $isHoliday,
                'description' => match (true) {
                    $isHoliday => 'Feriado contratual de teste',
                    $date->isWeekend() => 'Final de semana',
                    default => 'Dia útil confirmado',
                },
                'data_origin' => 'contractual_test',
                'source' => 'contractual_test',
                'source_is_official' => true,
                'source_document' => 'Contrato confirmado.pdf',
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            BusinessCalendarDate::query()->insert($chunk);
        }
    }

    app(BusinessCalendarService::class)->flushCache();

    return $calendar;
}

function obligationDeadlineActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        AccessPermission::ObligationsCreate->value,
        AccessPermission::ObligationsUpdate->value,
    ]);

    return $actor;
}

/** @return array<string, mixed> */
function relativeDeadlineConfiguration(string $calendarCode, array $overrides = []): array
{
    return array_merge([
        'title' => 'Entregar documento após solicitação',
        'priority' => 'high',
        'frequency' => ObligationFrequency::OnDemand->value,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'due_rule_type' => ObligationDueRuleType::BusinessDaysRelativeToEvent->value,
        'relative_offset_quantity' => 5,
        'relative_offset_unit' => 'business_days',
        'relative_offset_direction' => ObligationOffsetDirection::After->value,
        'anchor_description' => 'recebimento da solicitação',
        'initial_date_inclusion' => ObligationInitialDateInclusion::Excluded->value,
        'calendar_code' => $calendarCode,
        'calendar_evidence_source_document' => 'Termo de Securitização.pdf',
        'calendar_evidence_clause_reference' => '7.1.1 (i) (c)',
        'calendar_evidence_page_reference' => '42',
        'calendar_evidence_excerpt' => 'em até 5 Dias Úteis contados do recebimento da solicitação',
        'calendar_evidence_notes' => 'Definição de Dia Útil confirmada pelo jurídico.',
        'generation_horizon_days' => 90,
    ], $overrides);
}

/** @return array<string, mixed> */
function nthBusinessDayConfiguration(string $calendarCode, array $overrides = []): array
{
    return array_merge([
        'title' => 'Entrega no quinto dia útil',
        'priority' => 'high',
        'frequency' => ObligationFrequency::Monthly->value,
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-09-30',
        'due_rule_type' => ObligationDueRuleType::NthBusinessDay->value,
        'due_day' => 5,
        'due_offset_months' => 0,
        'calendar_code' => $calendarCode,
        'calendar_evidence_source_document' => 'Termo de Securitização.pdf',
        'calendar_evidence_clause_reference' => '8.2',
        'calendar_evidence_excerpt' => 'até o quinto Dia Útil de cada mês',
        'generation_horizon_days' => 90,
    ], $overrides);
}

/**
 * @return array{0: ObligationSeries, 1: ObligationSeriesRule}
 */
function relativeDeadlineRule(
    string $calendarCode,
    ObligationOffsetDirection $direction = ObligationOffsetDirection::After,
    int $quantity = 5,
    ObligationInitialDateInclusion $inclusion = ObligationInitialDateInclusion::Excluded,
): array {
    $series = ObligationSeries::factory()->create([
        'frequency' => ObligationFrequency::OnDemand,
        'starts_on' => '2025-01-01',
        'ends_on' => '2027-12-31',
        'due_rule_type' => ObligationDueRuleType::BusinessDaysRelativeToEvent,
        'relative_offset_quantity' => $quantity,
        'relative_offset_unit' => 'business_days',
        'relative_offset_direction' => $direction,
        'anchor_description' => 'evento contratual',
        'initial_date_inclusion' => $inclusion,
        'calendar_code' => $calendarCode,
        'status' => ObligationSeriesStatus::Active,
    ]);
    $rule = ObligationSeriesRule::factory()->for($series, 'series')->create([
        'effective_from' => '2025-01-01',
        'frequency' => ObligationFrequency::OnDemand,
        'due_rule_type' => ObligationDueRuleType::BusinessDaysRelativeToEvent,
        'due_day' => null,
        'due_offset_months' => 0,
        'relative_offset_quantity' => $quantity,
        'relative_offset_unit' => 'business_days',
        'relative_offset_direction' => $direction,
        'anchor_description' => 'evento contratual',
        'initial_date_inclusion' => $inclusion,
        'invalid_day_policy' => null,
        'calendar_code' => $calendarCode,
    ]);

    return [$series, $rule];
}

function confirmRuleCalendarEvidence(ObligationSeriesRule $rule): void
{
    BusinessCalendarSelectionEvidence::query()->create([
        'subject_type' => $rule->getMorphClass(),
        'subject_id' => $rule->id,
        'context' => ObligationSeriesRule::CALENDAR_EVIDENCE_CONTEXT,
        'calendar_code' => $rule->calendar_code,
        'source_document' => 'Contrato confirmado.pdf',
        'excerpt' => 'Definição contratual de Dia Útil.',
        'confirmed_at' => now(),
    ]);
    $rule->load('calendarSelectionEvidence');
}

it('adds contractual offset anchor and explanation structures without changing existing rows', function () {
    expect(Schema::hasColumns('obligation_series', [
        'relative_offset_quantity',
        'relative_offset_unit',
        'relative_offset_direction',
        'anchor_description',
        'initial_date_inclusion',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('obligation_series_rules', [
            'relative_offset_quantity',
            'relative_offset_unit',
            'relative_offset_direction',
            'anchor_description',
            'initial_date_inclusion',
        ]))->toBeTrue()
        ->and(Schema::hasTable('obligation_anchor_events'))->toBeTrue()
        ->and(Schema::hasColumns('obligations', [
            'obligation_anchor_event_id',
            'due_date_resolution',
            'due_date_calculation_status',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('extracted_obligations', 'schedule_suggestion'))->toBeTrue();
});

it('preserves calendar-free rules and requires a calendar only for business-day semantics', function () {
    $actor = obligationDeadlineActor();
    $emission = Emission::factory()->create();

    $fixed = app(ObligationSeriesService::class)->createConfigured($emission, $actor, [
        'title' => 'Entregar relatório no dia 15',
        'priority' => 'medium',
        'frequency' => ObligationFrequency::Monthly->value,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-10-31',
        'due_rule_type' => ObligationDueRuleType::FixedDay->value,
        'due_day' => 15,
        'due_offset_months' => 0,
        'invalid_day_policy' => 'last_valid_day',
        'generation_horizon_days' => 90,
    ]);
    $calendarDays = app(ObligationSeriesService::class)->createConfigured($emission, $actor, [
        'title' => 'Entregar em 30 dias corridos',
        'priority' => 'medium',
        'frequency' => ObligationFrequency::Monthly->value,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-10-31',
        'due_rule_type' => ObligationDueRuleType::CalendarDaysAfterCompetenceEnd->value,
        'due_offset_days' => 30,
        'generation_horizon_days' => 90,
    ]);

    expect($fixed->calendar_code)->toBeNull()
        ->and($fixed->latestRule->calendar_code)->toBeNull()
        ->and($calendarDays->calendar_code)->toBeNull()
        ->and($calendarDays->latestRule->calendar_code)->toBeNull();
});

it('blocks absent and unapproved calendars but does not demand unknown years during activation', function () {
    $actor = obligationDeadlineActor();
    $emission = Emission::factory()->create();
    BusinessCalendar::factory()->create([
        'code' => 'CONTRACT_PENDING',
        'available_for_new_configurations' => true,
    ]);
    createConfirmedObligationCalendar('CONTRACT_PROVISIONAL');
    BusinessCalendarYear::query()
        ->where('calendar_code', 'CONTRACT_PROVISIONAL')
        ->update([
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
            'confirmed_at' => null,
        ]);

    foreach ([
        relativeDeadlineConfiguration('', ['calendar_code' => null]),
        relativeDeadlineConfiguration('B3'),
    ] as $configuration) {
        try {
            app(ObligationSeriesService::class)->createConfigured($emission, $actor, $configuration);
            $this->fail('A regra dependente de calendário não deveria ter sido ativada.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('calendar_code');
        }
    }

    $withoutCoverage = app(ObligationSeriesService::class)->createConfigured(
        $emission,
        $actor,
        relativeDeadlineConfiguration('CONTRACT_PENDING'),
    );
    $provisional = app(ObligationSeriesService::class)->createConfigured(
        $emission,
        $actor,
        relativeDeadlineConfiguration('CONTRACT_PROVISIONAL'),
    );

    expect($emission->obligationSeries()->count())->toBe(2)
        ->and($withoutCoverage->status)->toBe(ObligationSeriesStatus::Active)
        ->and($provisional->status)->toBe(ObligationSeriesStatus::Active)
        ->and($withoutCoverage->occurrences()->count())->toBe(0)
        ->and($provisional->occurrences()->count())->toBe(0);
});

it('calculates first and fifth business day using only the selected confirmed calendar', function () {
    createConfirmedObligationCalendar('CONTRACT_NTH', 2026, 2027, ['2027-01-01', '2026-08-03']);
    $series = ObligationSeries::factory()->create([
        'frequency' => ObligationFrequency::Monthly,
        'starts_on' => '2026-01-01',
        'ends_on' => '2027-12-31',
        'due_rule_type' => ObligationDueRuleType::NthBusinessDay,
        'due_day' => 1,
        'due_offset_months' => 0,
        'calendar_code' => 'CONTRACT_NTH',
        'status' => ObligationSeriesStatus::Active,
    ]);
    $rule = ObligationSeriesRule::factory()->for($series, 'series')->create([
        'effective_from' => '2026-01-01',
        'due_rule_type' => ObligationDueRuleType::NthBusinessDay,
        'due_day' => 1,
        'due_offset_months' => 0,
        'invalid_day_policy' => null,
        'calendar_code' => 'CONTRACT_NTH',
    ]);
    confirmRuleCalendarEvidence($rule);
    $calculator = app(ObligationScheduleCalculator::class);

    $rule->due_day = 1;
    expect($calculator->resolveDueDate($rule, CarbonImmutable::parse('2026-08-01'))?->toDateString())->toBe('2026-08-04');
    $rule->due_day = 5;
    expect($calculator->resolveDueDate($rule, CarbonImmutable::parse('2026-08-01'))?->toDateString())->toBe('2026-08-10');
    $rule->due_offset_months = 1;
    $rule->due_day = 1;
    expect($calculator->resolveDueDate($rule, CarbonImmutable::parse('2026-12-01'))?->toDateString())->toBe('2027-01-04');
    $rule->due_day = 5;
    expect($calculator->resolveDueDate($rule, CarbonImmutable::parse('2026-12-01'))?->toDateString())->toBe('2027-01-08');
});

it('returns an explicit calendar pendency instead of runtime fallback', function () {
    BusinessCalendar::factory()->create(['code' => 'CONTRACT_GAP']);
    $series = ObligationSeries::factory()->create();
    $rule = ObligationSeriesRule::factory()->for($series, 'series')->create([
        'due_rule_type' => ObligationDueRuleType::NthBusinessDay,
        'due_day' => 5,
        'due_offset_months' => 0,
        'calendar_code' => 'CONTRACT_GAP',
    ]);
    confirmRuleCalendarEvidence($rule);

    $resolution = app(ObligationScheduleCalculator::class)
        ->resolveDueDateWithExplanation($rule, CarbonImmutable::parse('2026-08-01'));

    expect($resolution->dueDate)->toBeNull()
        ->and($resolution->calculationStatus)->toBe(ObligationDueDateCalculationStatus::AwaitingCalendar)
        ->and($resolution->blockingReason)->toContain('não pode degradar para segunda a sexta')
        ->and($resolution->requiredCalendarDate)->toBe('2026-08-01');
});

it('calculates business days after events across holidays weekends months and years', function () {
    createConfirmedObligationCalendar('CONTRACT_AFTER', 2025, 2027, ['2026-08-12', '2027-01-01']);
    [, $rule] = relativeDeadlineRule('CONTRACT_AFTER');
    $calculator = app(ObligationScheduleCalculator::class);

    foreach ([
        ['2026-08-10', 5, '2026-08-18'],
        ['2026-08-14', 5, '2026-08-21'],
        ['2026-08-15', 1, '2026-08-17'],
        ['2026-08-28', 3, '2026-09-02'],
        ['2026-12-30', 3, '2027-01-05'],
    ] as [$anchor, $quantity, $expected]) {
        $rule->relative_offset_quantity = $quantity;

        expect($calculator->resolveDueDate($rule, CarbonImmutable::parse($anchor))?->toDateString())
            ->toBe($expected);
    }
});

it('calculates business days before events across holidays weekends months and years', function () {
    createConfirmedObligationCalendar('CONTRACT_BEFORE', 2025, 2027, ['2026-08-12', '2027-01-01']);
    [, $rule] = relativeDeadlineRule('CONTRACT_BEFORE', ObligationOffsetDirection::Before, 3);
    $calculator = app(ObligationScheduleCalculator::class);

    foreach ([
        ['2026-08-17', 3, '2026-08-11'],
        ['2026-08-14', 3, '2026-08-10'],
        ['2026-08-16', 1, '2026-08-14'],
        ['2026-09-02', 3, '2026-08-28'],
        ['2027-01-05', 3, '2026-12-30'],
    ] as [$anchor, $quantity, $expected]) {
        $rule->relative_offset_quantity = $quantity;

        expect($calculator->resolveDueDate($rule, CarbonImmutable::parse($anchor))?->toDateString())
            ->toBe($expected);
    }
});

it('models inclusion of the initial date explicitly and rejects zero', function () {
    createConfirmedObligationCalendar('CONTRACT_INCLUDE');
    [, $rule] = relativeDeadlineRule(
        'CONTRACT_INCLUDE',
        ObligationOffsetDirection::After,
        1,
        ObligationInitialDateInclusion::Included,
    );

    expect(app(ObligationScheduleCalculator::class)
        ->resolveDueDate($rule, CarbonImmutable::parse('2026-08-10'))?->toDateString())
        ->toBe('2026-08-10');

    $actor = obligationDeadlineActor();

    foreach ([
        'relative_offset_quantity' => ['relative_offset_quantity' => 0],
        'relative_offset_direction' => ['relative_offset_direction' => 'sideways'],
        'anchor_description' => ['anchor_description' => null],
        'initial_date_inclusion' => ['initial_date_inclusion' => null],
    ] as $expectedError => $overrides) {
        try {
            app(ObligationSeriesService::class)->createConfigured(
                Emission::factory()->create(),
                $actor,
                relativeDeadlineConfiguration('CONTRACT_INCLUDE', $overrides),
            );
            $this->fail("O campo {$expectedError} deveria ter sido rejeitado.");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($expectedError);
        }
    }
});

it('does not invent a due date before the external anchor is recorded', function () {
    createConfirmedObligationCalendar('CONTRACT_ANCHOR');
    $actor = obligationDeadlineActor();
    $series = app(ObligationSeriesService::class)->createConfigured(
        Emission::factory()->create(),
        $actor,
        relativeDeadlineConfiguration('CONTRACT_ANCHOR'),
    );

    expect($series->occurrences()->count())->toBe(0)
        ->and($series->anchorEvents()->count())->toBe(0);

    try {
        app(ObligationSeriesService::class)->recordAnchorEvent($series, $actor, [
            'event_name' => 'Recebimento da solicitação',
        ]);
        $this->fail('A data da âncora deveria ser obrigatória.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('occurred_on');
    }

    expect($series->occurrences()->count())->toBe(0)
        ->and($series->anchorEvents()->count())->toBe(0);
});

it('records anchor rule calendar evidence and an auditable due-date explanation', function () {
    createConfirmedObligationCalendar('CONTRACT_AUDIT', 2025, 2027, ['2026-08-12']);
    $actor = obligationDeadlineActor();
    $series = app(ObligationSeriesService::class)->createConfigured(
        Emission::factory()->create(),
        $actor,
        relativeDeadlineConfiguration('CONTRACT_AUDIT'),
    );
    $occurrence = app(ObligationSeriesService::class)->recordAnchorEvent($series, $actor, [
        'event_name' => 'Recebimento da solicitação',
        'occurred_on' => '2026-08-10',
        'notes' => 'Solicitação recebida pelo canal contratual.',
    ]);
    $rule = $series->latestRule()->with('calendarSelectionEvidence')->firstOrFail();
    $evidence = $rule->calendarSelectionEvidence->first();

    expect($occurrence->due_date->toDateString())->toBe('2026-08-18')
        ->and($occurrence->seriesRule->is($rule))->toBeTrue()
        ->and($occurrence->anchorEvent->occurred_on->toDateString())->toBe('2026-08-10')
        ->and($occurrence->due_date_resolution)->toMatchArray([
            'anchor_date' => '2026-08-10',
            'calendar_code' => 'CONTRACT_AUDIT',
            'quantity' => 5,
            'direction' => 'after',
            'initial_date_inclusion' => 'excluded',
            'due_date' => '2026-08-18',
        ])
        ->and(collect($occurrence->due_date_resolution['skipped_dates'])->pluck('date')->all())
        ->toBe(['2026-08-12', '2026-08-15', '2026-08-16'])
        ->and($evidence->confirmed_by)->toBe($actor->id)
        ->and($evidence->confirmed_at)->not->toBeNull()
        ->and($evidence->excerpt)->toContain('5 Dias Úteis');
});

it('isolates two contractual calendars inside the same emission', function () {
    createConfirmedObligationCalendar('CONTRACT_X', 2025, 2027, ['2026-08-11']);
    createConfirmedObligationCalendar('CONTRACT_Y');
    $actor = obligationDeadlineActor();
    $emission = Emission::factory()->create();
    $service = app(ObligationSeriesService::class);
    $seriesX = $service->createConfigured($emission, $actor, relativeDeadlineConfiguration('CONTRACT_X', [
        'title' => 'Obrigação pecuniária',
        'relative_offset_quantity' => 1,
    ]));
    $seriesY = $service->createConfigured($emission, $actor, relativeDeadlineConfiguration('CONTRACT_Y', [
        'title' => 'Obrigação não pecuniária',
        'relative_offset_quantity' => 1,
    ]));
    $occurrenceX = $service->recordAnchorEvent($seriesX, $actor, [
        'event_name' => 'Solicitação X',
        'occurred_on' => '2026-08-10',
    ]);
    $occurrenceY = $service->recordAnchorEvent($seriesY, $actor, [
        'event_name' => 'Solicitação Y',
        'occurred_on' => '2026-08-10',
    ]);

    expect($seriesX->emission_id)->toBe($seriesY->emission_id)
        ->and($seriesX->latestRule->calendar_code)->toBe('CONTRACT_X')
        ->and($seriesY->latestRule->calendar_code)->toBe('CONTRACT_Y')
        ->and($occurrenceX->due_date->toDateString())->toBe('2026-08-12')
        ->and($occurrenceY->due_date->toDateString())->toBe('2026-08-11');
});

it('preserves v1 occurrences and applies v2 calendar only to later anchor events', function () {
    createConfirmedObligationCalendar('CONTRACT_V1', 2025, 2027, ['2026-06-11']);
    createConfirmedObligationCalendar('CONTRACT_V2', 2025, 2027, ['2026-07-13']);
    $actor = obligationDeadlineActor();
    $service = app(ObligationSeriesService::class);
    $series = $service->createConfigured(
        Emission::factory()->create(),
        $actor,
        relativeDeadlineConfiguration('CONTRACT_V1', ['relative_offset_quantity' => 1]),
    );
    $v1Occurrence = $service->recordAnchorEvent($series, $actor, [
        'event_name' => 'Solicitação de junho',
        'occurred_on' => '2026-06-10',
    ]);

    $service->reviseRuleFrom($series, $actor, [
        ...relativeDeadlineConfiguration('CONTRACT_V2', ['relative_offset_quantity' => 1]),
        'effective_from' => '2026-07-01',
        'change_reason' => 'Aditamento alterou a definição contratual de Dia Útil.',
    ]);
    $v2Occurrence = $service->recordAnchorEvent($series->refresh(), $actor, [
        'event_name' => 'Solicitação de julho',
        'occurred_on' => '2026-07-10',
    ]);
    $rules = $series->rules()->orderBy('version')->get();

    expect($rules)->toHaveCount(2)
        ->and($v1Occurrence->fresh()->due_date->toDateString())->toBe('2026-06-12')
        ->and($v1Occurrence->fresh()->obligation_series_rule_id)->toBe($rules[0]->id)
        ->and($v2Occurrence->due_date->toDateString())->toBe('2026-07-14')
        ->and($v2Occurrence->obligation_series_rule_id)->toBe($rules[1]->id)
        ->and($v1Occurrence->fresh()->due_date_resolution['calendar_code'])->toBe('CONTRACT_V1')
        ->and($v2Occurrence->due_date_resolution['calendar_code'])->toBe('CONTRACT_V2');
});

it('materializes a concrete monthly competence only when its calendar year is confirmed', function () {
    createConfirmedObligationCalendar('CONTRACT_MONTH_CONFIRMED', 2026, 2026);
    createConfirmedObligationCalendar('CONTRACT_MONTH_PROVISIONAL', 2026, 2026);
    BusinessCalendarYear::query()
        ->where('calendar_code', 'CONTRACT_MONTH_PROVISIONAL')
        ->where('year', 2026)
        ->update([
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
            'confirmed_at' => null,
        ]);
    $actor = obligationDeadlineActor();
    $emission = Emission::factory()->create();
    $service = app(ObligationSeriesService::class);

    $confirmedSeries = $service->createConfigured(
        $emission,
        $actor,
        nthBusinessDayConfiguration('CONTRACT_MONTH_CONFIRMED'),
    );
    $provisionalSeries = $service->createConfigured(
        $emission,
        $actor,
        nthBusinessDayConfiguration('CONTRACT_MONTH_PROVISIONAL', [
            'title' => 'Entrega pendente de calendário',
        ]),
    );
    $confirmedOccurrence = $confirmedSeries->occurrences()->firstOrFail();
    $pendingOccurrence = $provisionalSeries->occurrences()->firstOrFail();

    expect($confirmedOccurrence->due_date?->toDateString())->toBe('2026-09-07')
        ->and($confirmedOccurrence->due_date_calculation_status)->toBe(ObligationDueDateCalculationStatus::Calculated)
        ->and($pendingOccurrence->due_date)->toBeNull()
        ->and($pendingOccurrence->due_date_calculation_status)->toBe(ObligationDueDateCalculationStatus::AwaitingCalendar)
        ->and($pendingOccurrence->due_date_resolution['required_calendar_date'])->toBe('2026-09-01')
        ->and($pendingOccurrence->due_date_resolution['blocking_reason'])->toContain('não pode degradar para segunda a sexta');
});

it('preserves an anchor that crosses into an unconfirmed year and resolves it later without duplication', function () {
    createConfirmedObligationCalendar('CONTRACT_YEAR_CROSSING', 2026, 2027, ['2027-01-01']);
    BusinessCalendarYear::query()
        ->where('calendar_code', 'CONTRACT_YEAR_CROSSING')
        ->where('year', 2027)
        ->update([
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
            'confirmed_at' => null,
        ]);
    $actor = obligationDeadlineActor();
    $service = app(ObligationSeriesService::class);
    $series = $service->createConfigured(
        Emission::factory()->create(),
        $actor,
        relativeDeadlineConfiguration('CONTRACT_YEAR_CROSSING', [
            'ends_on' => '2027-12-31',
        ]),
    );
    $pendingOccurrence = $service->recordAnchorEvent($series, $actor, [
        'event_name' => 'Notificação recebida',
        'occurred_on' => '2026-12-29',
    ]);
    $anchorEventId = $pendingOccurrence->obligation_anchor_event_id;

    expect($pendingOccurrence->due_date)->toBeNull()
        ->and($pendingOccurrence->due_date_calculation_status)->toBe(ObligationDueDateCalculationStatus::AwaitingCalendar)
        ->and($pendingOccurrence->due_date_resolution['required_calendar_date'])->toBe('2027-01-01')
        ->and(collect($pendingOccurrence->due_date_resolution['calendar_years'])->pluck('year')->all())->toBe([2026, 2027])
        ->and($series->anchorEvents()->count())->toBe(1)
        ->and($series->occurrences()->count())->toBe(1);

    BusinessCalendarYear::query()
        ->where('calendar_code', 'CONTRACT_YEAR_CROSSING')
        ->where('year', 2027)
        ->update([
            'status' => BusinessCalendarYear::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

    $result = app(GenerateObligationOccurrencesAction::class)->generateForSeries($series);
    $resolvedOccurrence = $pendingOccurrence->fresh();

    expect($result['resolved'])->toBe(1)
        ->and($result['pending'])->toBe(0)
        ->and($resolvedOccurrence->due_date?->toDateString())->toBe('2027-01-06')
        ->and($resolvedOccurrence->due_date_calculation_status)->toBe(ObligationDueDateCalculationStatus::Calculated)
        ->and($resolvedOccurrence->obligation_anchor_event_id)->toBe($anchorEventId)
        ->and($series->anchorEvents()->count())->toBe(1)
        ->and($series->occurrences()->count())->toBe(1)
        ->and(collect($resolvedOccurrence->due_date_resolution['calendar_years'])->pluck('governance_status')->all())
        ->toBe([BusinessCalendarYear::STATUS_CONFIRMED, BusinessCalendarYear::STATUS_CONFIRMED]);
});

it('keeps a calculated due date bound to its original calendar revision when the calendar becomes stale', function () {
    createConfirmedObligationCalendar('CONTRACT_REPRODUCIBLE', 2026, 2026);
    $actor = obligationDeadlineActor();
    $service = app(ObligationSeriesService::class);
    $series = $service->createConfigured(
        Emission::factory()->create(),
        $actor,
        relativeDeadlineConfiguration('CONTRACT_REPRODUCIBLE', [
            'relative_offset_quantity' => 1,
        ]),
    );
    $occurrence = $service->recordAnchorEvent($series, $actor, [
        'event_name' => 'Solicitação recebida',
        'occurred_on' => '2026-08-10',
    ]);
    $original = $occurrence->only([
        'due_date',
        'status',
        'obligation_series_rule_id',
        'obligation_anchor_event_id',
        'due_date_resolution',
    ]);
    $calendarYear = BusinessCalendarYear::query()
        ->where('calendar_code', 'CONTRACT_REPRODUCIBLE')
        ->where('year', 2026)
        ->firstOrFail();
    $calendarYear->update([
        'revision' => 2,
        'checksum' => str_repeat('b', 64),
        'status' => BusinessCalendarYear::STATUS_STALE,
    ]);
    app(BusinessCalendarRevisionService::class)->publish($calendarYear->fresh());

    app(GenerateObligationOccurrencesAction::class)->generateForSeries($series);
    $assessment = app(ObligationCalendarReproducibilityService::class)->assess($occurrence->fresh());

    expect($occurrence->fresh()->only(array_keys($original)))->toEqual($original)
        ->and($occurrence->fresh()->due_date_resolution['calendar_years'][0]['revision'])->toBe(1)
        ->and($assessment['status'])->toBe('review_required')
        ->and($assessment['message'])->toContain('revisão 1')
        ->and($assessment['message'])->toContain('revisão atual é 2');
});

it('separates ANBIMA exception-list coverage from annual governance confirmation', function () {
    $calendarCode = BusinessCalendarRegistry::BR_BANKING_ANBIMA;
    $checksum = str_repeat('a', 64);
    $calendarYear = BusinessCalendarYear::factory()->create([
        'calendar_code' => $calendarCode,
        'year' => 2026,
        'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
        'source' => 'anbima',
        'source_is_official' => true,
        'source_document' => 'feriados_nacionais.xls',
        'source_revision' => '2026-v1',
        'checksum' => $checksum,
    ]);
    BusinessCalendarImportRun::factory()->create([
        'business_calendar_year_id' => $calendarYear->id,
        'calendar_code' => $calendarCode,
        'year' => 2026,
        'checksum' => $checksum,
        'records_found' => 2,
        'records_inserted' => 2,
    ]);
    BusinessCalendarDate::factory()->createMany([
        [
            'business_calendar_year_id' => $calendarYear->id,
            'calendar_code' => $calendarCode,
            'calendar_date' => '2026-01-01',
            'is_business_day' => false,
            'data_origin' => 'imported',
            'source' => 'anbima',
            'source_is_official' => true,
        ],
        [
            'business_calendar_year_id' => $calendarYear->id,
            'calendar_code' => $calendarCode,
            'calendar_date' => '2026-12-25',
            'is_business_day' => false,
            'data_origin' => 'imported',
            'source' => 'anbima',
            'source_is_official' => true,
        ],
    ]);

    $coverage = app(BusinessCalendarYearService::class)->coverage($calendarCode, 2026);
    $ordinaryWeekday = app(BusinessCalendarService::class)->explain(
        CarbonImmutable::parse('2026-01-05'),
        $calendarCode,
    );

    expect($coverage['coverage_basis'])->toBe(BusinessCalendar::COVERAGE_BASIS_WEEKDAY_WITH_OFFICIAL_EXCEPTIONS)
        ->and($coverage['coverage_status'])->toBe('complete')
        ->and($coverage['governance_status'])->toBe(BusinessCalendarYear::STATUS_PROVISIONAL)
        ->and($coverage['state'])->toBe('provisional')
        ->and($coverage['covered_days'])->toBe(2)
        ->and($coverage['confirmed'])->toBeFalse()
        ->and($ordinaryWeekday->isBusinessDay)->toBeTrue()
        ->and($ordinaryWeekday->source)->toBe('calendar_base_rule')
        ->and($ordinaryWeekday->reason)->toContain('regra-base oficial');

    app(BusinessCalendarYearService::class)->confirm(
        $calendarCode,
        2026,
        'anbima',
        'feriados_nacionais.xls',
        '2026-v1',
        $checksum,
        User::factory()->create()->id,
    );
    $confirmedCoverage = app(BusinessCalendarYearService::class)->coverage($calendarCode, 2026);

    expect($confirmedCoverage['coverage_status'])->toBe('complete')
        ->and($confirmedCoverage['governance_status'])->toBe(BusinessCalendarYear::STATUS_CONFIRMED)
        ->and($confirmedCoverage['state'])->toBe('confirmed')
        ->and($confirmedCoverage['confirmed'])->toBeTrue();
});
