<?php

use App\Domain\PuCalculator\Enums\PuCalendarHomologationDecision;
use App\Domain\PuCalculator\Enums\PuCalendarHomologationStatus;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Exceptions\PuCalendarHomologationException;
use App\Domain\PuCalculator\Services\BusinessCalendarService;
use App\Domain\PuCalculator\Services\PuCalendarHomologationComparisonService;
use App\Domain\PuCalculator\Services\PuCalendarHomologationService;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuCalendarHomologationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\PuCalendarHomologation;
use App\Models\PuHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

dataset('cdi_lookup_modes_for_calendar_homologation', [
    'PreviousAvailableBusinessDay' => [PuIndexRateLookupMode::PreviousAvailableBusinessDay, 1],
    'PreviousCalendarDayExact' => [PuIndexRateLookupMode::PreviousCalendarDayExact, 1],
    'BusinessDayLagExact' => [PuIndexRateLookupMode::BusinessDayLagExact, -1],
]);

it('compares legacy and candidate without changing any operational financial record', function () {
    $analyst = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationExecute);
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: true);
    seedHomologationRates('2026-01-01', '2026-01-15');
    $eventCount = $emission->puEvents()->count();
    $activeParameterBefore = $emission->puParameter()->sole()->getAttributes();
    $officialCounts = [
        'curves' => EmissionPuDailyCurve::query()->count(),
        'histories' => PuHistory::query()->count(),
        'payments' => Payment::query()->count(),
    ];
    $homologation = createExecutableHomologation($emission, $analyst);

    $executed = app(PuCalendarHomologationService::class)->execute($homologation, $analyst);

    expect($executed->comparison_checksum)->toHaveLength(64)
        ->and($executed->result_summary['side_effect_free'])->toBeTrue()
        ->and($emission->puParameter()->sole()->getAttributes())->toBe($activeParameterBefore)
        ->and($emission->puEvents()->count())->toBe($eventCount)
        ->and(EmissionPuDailyCurve::query()->count())->toBe($officialCounts['curves'])
        ->and(PuHistory::query()->count())->toBe($officialCounts['histories'])
        ->and(Payment::query()->count())->toBe($officialCounts['payments']);
});

it('reports identical scenarios without treating numerical equality as approval', function () {
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: true);
    seedHomologationRates('2026-01-01', '2026-01-15');

    $comparison = app(PuCalendarHomologationComparisonService::class)->compare(
        $emission,
        'BR_BANKING_ANBIMA',
        CarbonImmutable::parse('2026-01-05'),
        CarbonImmutable::parse('2026-01-12'),
    );

    expect($comparison->summary['identical'])->toBeTrue()
        ->and($comparison->summary['divergent_rows'])->toBe(0)
        ->and($comparison->firstDivergence)->toBeNull()
        ->and(collect($comparison->dailyDiff)->every(fn (array $row): bool => $row['diverges'] === false))->toBeTrue();
});

it('finds the first calendar divergence and propagates it through DUP factors PU and accumulated impact', function () {
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: true, holiday: '2026-01-07');
    seedHomologationRates('2026-01-01', '2026-01-15');

    $comparison = app(PuCalendarHomologationComparisonService::class)->compare(
        $emission,
        'BR_BANKING_ANBIMA',
        CarbonImmutable::parse('2026-01-05'),
        CarbonImmutable::parse('2026-01-12'),
    );
    $first = $comparison->firstDivergence;
    $januarySeventh = collect($comparison->dailyDiff)->firstWhere('date', '2026-01-07');

    expect($comparison->summary['identical'])->toBeFalse()
        ->and($comparison->summary['first_divergence_date'])->toBe('2026-01-07')
        ->and($comparison->summary['critical_dates'])->toContain('2026-01-07')
        ->and($first['legacy']['is_business_day'])->toBeTrue()
        ->and($first['candidate']['is_business_day'])->toBeFalse()
        ->and($januarySeventh['changed_fields'])->toContain('dup', 'factor_di', 'factor_spread', 'factor_total', 'pu')
        ->and($januarySeventh['difference']['pu'])->not->toBe('0.0000000000000000')
        ->and($comparison->summary['accumulated_impacts']['end_of_curve']['pu_difference'])->not->toBeNull()
        ->and($first['causal_chain'])->not->toBeEmpty();
});

it('preserves each homologated CDI lookup mode inside the parallel scenarios', function (
    PuIndexRateLookupMode $mode,
    int $lag,
) {
    $emission = makeCalendarHomologationEmission($mode, $lag);
    seedCandidateCalendarYear(2026, confirmed: true, holiday: '2026-01-07');
    seedHomologationRates('2025-12-20', '2026-01-15', includeWeekends: true);

    $comparison = app(PuCalendarHomologationComparisonService::class)->compare(
        $emission,
        'BR_BANKING_ANBIMA',
        CarbonImmutable::parse('2026-01-05'),
        CarbonImmutable::parse('2026-01-12'),
    );

    expect($comparison->summary['index_rate_lookup_mode'])->toBe($mode->value)
        ->and($comparison->summary['index_rate_lag_business_days'])->toBe($lag)
        ->and($comparison->dailyDiff)->not->toBeEmpty()
        ->and(collect($comparison->dailyDiff)->every(
            fn (array $row): bool => ($row['legacy']['present'] ?? false) && ($row['candidate']['present'] ?? false),
        ))->toBeTrue();
})->with('cdi_lookup_modes_for_calendar_homologation');

it('requires coverage for every year crossed by an exact business day lag', function () {
    $emission = makeCalendarHomologationEmission(PuIndexRateLookupMode::BusinessDayLagExact, -1);
    $emission->puParameter()->update([
        'curve_start_date' => '2026-01-01',
        'curve_end_date' => '2026-01-05',
    ]);
    seedCandidateCalendarYear(2026, confirmed: true);
    seedHomologationRates('2025-12-20', '2026-01-10', includeWeekends: true);

    expect(fn () => app(PuCalendarHomologationComparisonService::class)->compare(
        $emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']),
        'BR_BANKING_ANBIMA',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-05'),
    ))->toThrow(PuCalendarHomologationException::class, '2025');

    seedCandidateCalendarYear(2025, confirmed: true);

    $comparison = app(PuCalendarHomologationComparisonService::class)->compare(
        $emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']),
        'BR_BANKING_ANBIMA',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-05'),
    );

    expect($comparison->calendarGovernance)->toHaveKeys([2025, 2026]);
});

it('blocks execution without the complete contractual evidence matrix and primary excerpt', function () {
    $analyst = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationExecute);
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: true);
    seedHomologationRates('2026-01-01', '2026-01-15');
    $homologation = app(PuCalendarHomologationService::class)->createDraft($emission, [
        'candidate_calendar_code' => 'BR_BANKING_ANBIMA',
        'period_start' => '2026-01-05',
        'period_end' => '2026-01-12',
    ], $analyst);

    expect(fn () => app(PuCalendarHomologationService::class)->execute($homologation, $analyst))
        ->toThrow(PuCalendarHomologationException::class, 'matriz de evidências');
});

it('allows a provisional covered candidate to be compared but not submitted for approval', function () {
    $analyst = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationExecute);
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: false);
    seedHomologationRates('2026-01-01', '2026-01-15');
    $homologation = createExecutableHomologation($emission, $analyst);

    $executed = app(PuCalendarHomologationService::class)->execute($homologation, $analyst);

    expect($executed->calendar_governance_snapshot[2026]['coverage_status'])->toBe('complete')
        ->and($executed->calendar_governance_snapshot[2026]['state'])->toBe('provisional')
        ->and($executed->result_summary['governance_warnings'])->toHaveCount(1)
        ->and(fn () => app(PuCalendarHomologationService::class)->submitForReview($executed, $analyst))
        ->toThrow(PuCalendarHomologationException::class, 'não está confirmado');
});

it('allows an indeterminate interpretation to be measured but blocks review', function () {
    $analyst = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationExecute);
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: true);
    seedHomologationRates('2026-01-01', '2026-01-15');
    $homologation = createExecutableHomologation($emission, $analyst);
    $matrix = $homologation->evidence_matrix;
    $matrix[3]['confidence'] = 'Indeterminada';
    $homologation->update(['evidence_matrix' => $matrix]);
    $executed = app(PuCalendarHomologationService::class)->execute($homologation, $analyst);

    expect($executed->comparison_checksum)->not->toBeNull()
        ->and(fn () => app(PuCalendarHomologationService::class)->submitForReview($executed, $analyst))
        ->toThrow(PuCalendarHomologationException::class, 'confiança Indeterminada');
});

it('uses maker checker and records only a recommendation instead of applying the candidate', function () {
    $analyst = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationExecute);
    $reviewer = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationReview);
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: true);
    seedHomologationRates('2026-01-01', '2026-01-15');
    $activeCalendar = $emission->puParameter()->value('calendar_code');
    $homologation = createExecutableHomologation($emission, $analyst);
    $executed = app(PuCalendarHomologationService::class)->execute($homologation, $analyst);
    $ready = app(PuCalendarHomologationService::class)->submitForReview($executed, $analyst);
    Permission::findOrCreate(AccessPermission::PuCalendarHomologationReview->value);
    $analyst->givePermissionTo(AccessPermission::PuCalendarHomologationReview->value);

    expect(fn () => app(PuCalendarHomologationService::class)->approve($ready, $analyst, 'Aprovação documental e numérica.'))
        ->toThrow(PuCalendarHomologationException::class, 'maker/checker');

    $approved = app(PuCalendarHomologationService::class)->approve(
        $ready,
        $reviewer,
        'Evidência contratual e resultado numérico revisados de forma independente.',
    );

    expect($approved->status)->toBe(PuCalendarHomologationStatus::Approved)
        ->and($approved->decision)->toBe(PuCalendarHomologationDecision::RecommendMigration)
        ->and($emission->puParameter()->value('calendar_code'))->toBe($activeCalendar)
        ->and($activeCalendar)->toBe('B3');
});

it('supports rejected and inconclusive conclusions without changing financial data', function (PuCalendarHomologationDecision $decision) {
    $analyst = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationExecute);
    $reviewer = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationReview);
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: true);
    $homologation = createExecutableHomologation($emission, $analyst);

    $closed = app(PuCalendarHomologationService::class)->closeWithoutRecommendation(
        $homologation,
        $reviewer,
        $decision,
        'A evidência disponível não sustenta uma recomendação de migração.',
    );

    expect($closed->status)->toBe(PuCalendarHomologationStatus::Rejected)
        ->and($closed->decision)->toBe($decision)
        ->and($emission->puParameter()->value('calendar_code'))->toBe('B3');
})->with([
    'rejected' => PuCalendarHomologationDecision::RejectCandidate,
    'inconclusive' => PuCalendarHomologationDecision::Inconclusive,
]);

it('rejects legacy HML and B3 Listed calendars as financial candidates', function (string $calendarCode) {
    $analyst = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationExecute);
    $emission = makeCalendarHomologationEmission();

    if ($calendarCode === 'HML_PU_TEST') {
        BusinessCalendar::query()->create([
            'code' => $calendarCode,
            'name' => 'Gabarito HML de teste',
            'purpose' => 'Evidência comparativa de gabarito',
            'calendar_type' => 'homologation',
            'source' => 'testing',
            'status' => 'active',
            'import_mode' => 'manual_approval',
            'is_official' => false,
            'financial_use_allowed' => true,
            'is_legacy' => false,
            'is_homologation' => true,
            'accepts_anbima' => false,
            'available_for_new_configurations' => false,
        ]);
    }

    expect(fn () => app(PuCalendarHomologationService::class)->createDraft($emission, [
        'candidate_calendar_code' => $calendarCode,
        'period_start' => '2026-01-05',
        'period_end' => '2026-01-12',
    ], $analyst))->toThrow(PuCalendarHomologationException::class, 'não é permitido');
})->with(['B3', 'B3_LISTED_TRADING', 'HML_PU_TEST']);

it('blocks a stale draft when the active PU configuration changed after its snapshot', function () {
    $analyst = makeCalendarHomologationUser(AccessPermission::PuCalendarHomologationExecute);
    $emission = makeCalendarHomologationEmission();
    seedCandidateCalendarYear(2026, confirmed: true);
    seedHomologationRates('2026-01-01', '2026-01-15');
    $homologation = createExecutableHomologation($emission, $analyst);
    $emission->puParameter()->update(['spread_rate' => '7.00000000']);

    expect(fn () => app(PuCalendarHomologationService::class)->execute($homologation, $analyst))
        ->toThrow(PuCalendarHomologationException::class, 'configuração ativa mudou');
});

it('exposes execution and review as separate permissions and has no migration action', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $emission = makeCalendarHomologationEmission();
    $editor = User::factory()->withTwoFactor()->create();
    $editor->assignRole('editor');
    $this->actingAs($editor);

    Livewire::test(PuCalendarHomologationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertTableActionVisible('create_candidate')
        ->assertTableActionDoesNotExist('migrate_calendar');

    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(PuCalendarHomologationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->assertSuccessful();
});

it('enforces homologation permissions in the domain service', function () {
    $userWithoutPermission = User::factory()->create();
    $emission = makeCalendarHomologationEmission();

    expect(fn () => app(PuCalendarHomologationService::class)->createDraft($emission, [
        'candidate_calendar_code' => 'BR_BANKING_ANBIMA',
        'period_start' => '2026-01-05',
        'period_end' => '2026-01-12',
    ], $userWithoutPermission))->toThrow(
        AuthorizationException::class,
        'permissão',
    );
});

function makeCalendarHomologationUser(AccessPermission $permission): User
{
    Permission::findOrCreate($permission->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = User::factory()->create();
    $user->givePermissionTo($permission->value);

    return $user;
}

function makeCalendarHomologationEmission(
    PuIndexRateLookupMode $mode = PuIndexRateLookupMode::PreviousAvailableBusinessDay,
    int $lag = 1,
): Emission {
    $emission = Emission::factory()->create([
        'name' => 'Alto Bellevue — Piloto CDI',
        'type' => 'CRI',
        'status' => 'active',
        'maturity_date' => '2026-01-12',
        'issued_quantity' => 1000,
        'remuneration_indexer' => 'CDI',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-01-05',
        'curve_end_date' => '2026-01-12',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '7.50000000',
        'indexer' => 'CDI',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => $mode->value,
        'index_rate_lag_business_days' => $lag,
        'legacy_projection_enabled' => true,
    ]);
    $emission->integralizationHistories()->create([
        'date' => '2026-01-05',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Piloto',
    ]);
    $emission->puEvents()->create([
        'event_type' => 'interest_payment',
        'original_date' => '2026-01-12',
        'effective_date' => '2026-01-12',
        'amortization_type' => 'none',
        'amortization_value' => null,
        'sequence' => 1,
        'description' => 'Evento do cenário de homologação',
    ]);
    seedLegacyCalendarForHomologation('2025-12-20', '2026-01-15');

    return $emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']);
}

function seedLegacyCalendarForHomologation(string $start, string $end): void
{
    for ($date = CarbonImmutable::parse($start); $date->lte(CarbonImmutable::parse($end)); $date = $date->addDay()) {
        BusinessCalendarDate::query()->updateOrCreate([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
        ], [
            'is_business_day' => ! $date->isWeekend(),
            'description' => $date->isWeekend() ? 'Final de semana legado' : null,
            'data_origin' => 'legacy',
            'revision' => 1,
        ]);
    }

    app(BusinessCalendarService::class)->flushCache();
}

function seedCandidateCalendarYear(int $year, bool $confirmed, ?string $holiday = null): BusinessCalendarYear
{
    $checksum = hash('sha256', 'ANBIMA-'.$year);
    $calendarYear = BusinessCalendarYear::factory()->create([
        'calendar_code' => 'BR_BANKING_ANBIMA',
        'year' => $year,
        'status' => $confirmed ? BusinessCalendarYear::STATUS_CONFIRMED : BusinessCalendarYear::STATUS_PROVISIONAL,
        'source_is_official' => true,
        'source_document' => 'https://www.anbima.com.br/feriados/',
        'revision' => $confirmed ? 4 : 3,
        'checksum' => $checksum,
        'confirmed_at' => $confirmed ? now() : null,
    ]);
    $run = BusinessCalendarImportRun::factory()->create([
        'business_calendar_year_id' => $calendarYear->id,
        'calendar_code' => 'BR_BANKING_ANBIMA',
        'year' => $year,
        'checksum' => $checksum,
        'records_found' => $holiday === null ? 0 : 1,
        'records_inserted' => $holiday === null ? 0 : 1,
        'removals_detected' => 0,
        'conflicts_detected' => 0,
        'errors' => [],
        'result' => BusinessCalendarImportRun::RESULT_SUCCEEDED,
        'dry_run' => false,
    ]);

    if ($holiday !== null) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'BR_BANKING_ANBIMA',
            'business_calendar_year_id' => $calendarYear->id,
            'calendar_date' => $holiday,
            'is_business_day' => false,
            'description' => 'Exceção oficial candidata',
            'data_origin' => 'official_import',
            'source' => 'ANBIMA',
            'source_is_official' => true,
            'source_document' => 'https://www.anbima.com.br/feriados/',
            'revision' => $calendarYear->revision,
            'import_run_id' => $run->id,
        ]);
    }

    app(BusinessCalendarService::class)->flushCache();

    return $calendarYear;
}

function seedHomologationRates(
    string $start,
    string $end,
    bool $includeWeekends = false,
): void {
    for ($date = CarbonImmutable::parse($start); $date->lte(CarbonImmutable::parse($end)); $date = $date->addDay()) {
        if (! $includeWeekends && $date->isWeekend()) {
            continue;
        }

        IndexRate::query()->updateOrCreate([
            'indexer' => 'CDI',
            'rate_date' => $date->toDateString(),
        ], [
            'rate_value' => '14.90000000',
            'source' => 'testing',
            'source_reference' => 'homologation-fixed-rate',
        ]);
    }
}

function createExecutableHomologation(
    Emission $emission,
    User $analyst,
    array $overrides = [],
): PuCalendarHomologation {
    $matrix = collect([
        'daily_application',
        'dup',
        'spread',
        'index_lag',
        'index_lookup',
        'business_day_basis',
        'payment_dates',
    ])->map(fn (string $function): array => [
        'function' => $function,
        'rule' => 'Regra contratual documentada para '.$function,
        'document' => 'Termo de Securitização Alto Bellevue',
        'reference' => 'Cláusula 2.3 / página 99',
        'interpretation' => 'Interpretação controlada para o cenário piloto.',
        'confidence' => 'Alta',
    ])->all();

    return app(PuCalendarHomologationService::class)->createDraft($emission, [
        'candidate_calendar_code' => 'BR_BANKING_ANBIMA',
        'period_start' => '2026-01-05',
        'period_end' => '2026-01-12',
        'evidence_matrix' => $matrix,
        'external_reference' => [
            'availability' => 'unavailable',
            'notes' => 'Planilha operacional da emissão piloto não localizada no ambiente.',
        ],
        'primary_evidence' => [
            'source_document' => 'Termo de Securitização Alto Bellevue',
            'clause_reference' => 'Definições — Dia Útil',
            'page_reference' => '13–14',
            'excerpt' => 'Dia que não seja sábado, domingo ou feriado nacional no Brasil.',
            'notes' => 'B3 aparece separadamente como divulgadora da Taxa DI.',
            'confirmed' => true,
        ],
        ...$overrides,
    ], $analyst);
}
