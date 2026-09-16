<?php

use App\Domain\PuCalculator\Services\BusinessCalendarOverrideService;
use App\Domain\PuCalculator\Services\BusinessCalendarRevisionService;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\PuReferenceWorkbookScenarioService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Filament\Resources\BusinessHolidays\Pages\CompareBusinessCalendars;
use App\Filament\Resources\BusinessHolidays\Pages\ListBusinessHolidays;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarOverride;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use App\Models\Emission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('catalogues the governed calendars while preserving B3 as a non redirected legacy alias', function () {
    $definitions = BusinessCalendarRegistry::definitions();

    expect(array_keys($definitions))->toBe([
        BusinessCalendarRegistry::LEGACY_B3,
        BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        BusinessCalendarRegistry::B3_LISTED_TRADING,
        BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
        BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
    ])->and($definitions[BusinessCalendarRegistry::LEGACY_B3]['legacy'])->toBeTrue()
        ->and($definitions[BusinessCalendarRegistry::LEGACY_B3]['legacy_alias_of'])->toBe(BusinessCalendarRegistry::BR_BANKING_ANBIMA)
        ->and($definitions[BusinessCalendarRegistry::BR_BANKING_ANBIMA]['label'])->toBe('ANBIMA — calendário bancário')
        ->and($definitions[BusinessCalendarRegistry::B3_LISTED_TRADING]['label'])->toBe('B3 — sessões de negociação')
        ->and($definitions[BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS]['label'])->toBe('Feriados Nacionais — Brasil')
        ->and(BusinessCalendarRegistry::acceptsAnbima(BusinessCalendarRegistry::BR_BANKING_ANBIMA))->toBeTrue()
        ->and(BusinessCalendarRegistry::acceptsAnbima(BusinessCalendarRegistry::B3_LISTED_TRADING))->toBeFalse();
});

it('governs the consolidated financial market calendar as a reconciled rule, never as a source alias', function () {
    $definitions = BusinessCalendarRegistry::definitions();
    $consolidated = $definitions[BusinessCalendarRegistry::BR_FINANCIAL_MARKET];

    expect($consolidated['label'])->toBe('Mercado financeiro brasileiro — calendário consolidado')
        ->and($consolidated['legacy'])->toBeFalse()
        // O alias é o que separa este calendário de um apelido de BR_BANKING_ANBIMA: manter
        // legacy_alias_of nulo é o que obriga a decisão a vir da reconciliação, e não de uma fonte.
        ->and($consolidated['legacy_alias_of'])->toBeNull()
        // Recusar importação ANBIMA direta é a outra metade da mesma garantia.
        ->and($consolidated['accepts_anbima'])->toBeFalse()
        ->and(BusinessCalendarRegistry::acceptsAnbima(BusinessCalendarRegistry::BR_FINANCIAL_MARKET))->toBeFalse()
        ->and($consolidated['meaning'])->toContain('reconciliação auditável')
        ->and($consolidated['meaning'])->toContain('Não inclui automaticamente feriados estaduais ou municipais')
        ->and(BusinessCalendarRegistry::ensureKnown('br_financial_market'))
        ->toBe(BusinessCalendarRegistry::BR_FINANCIAL_MARKET);
});

it('distinguishes missing partial provisional confirmed and stale annual coverage', function () {
    $yearService = app(BusinessCalendarYearService::class);
    $calendarCode = BusinessCalendarRegistry::BR_BANKING_ANBIMA;

    expect($yearService->coverage($calendarCode, 2026)['state'])->toBe('missing');

    BusinessCalendarDate::query()->create([
        'calendar_code' => $calendarCode,
        'calendar_date' => '2026-01-01',
        'is_business_day' => false,
        'data_origin' => 'imported',
        'source' => 'anbima',
    ]);

    expect($yearService->coverage($calendarCode, 2026)['state'])->toBe('partial');

    $calendarYear = BusinessCalendarYear::query()->create([
        'calendar_code' => $calendarCode,
        'year' => 2026,
        'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
        'source' => 'anbima',
        'source_is_official' => true,
        'source_document' => 'https://www.anbima.com.br/feriados/fer_nacionais/2026.asp',
        'source_revision' => '2026-v1',
        'revision' => 1,
        'checksum' => str_repeat('a', 64),
    ]);
    BusinessCalendarImportRun::factory()->create([
        'business_calendar_year_id' => $calendarYear->id,
        'calendar_code' => $calendarCode,
        'year' => 2026,
        'source_is_official' => true,
        'checksum' => str_repeat('a', 64),
        'result' => BusinessCalendarImportRun::RESULT_SUCCEEDED,
        'dry_run' => false,
        'removals_detected' => 0,
        'conflicts_detected' => 0,
        'errors' => null,
    ]);

    expect($yearService->coverage($calendarCode, 2026)['state'])->toBe('provisional');

    $user = User::factory()->create();
    $confirmed = $yearService->confirm(
        $calendarCode,
        2026,
        'anbima',
        'https://www.anbima.com.br/feriados/fer_nacionais/2026.asp',
        '2026-v1',
        str_repeat('a', 64),
        $user->id,
    );

    expect($confirmed->status)->toBe(BusinessCalendarYear::STATUS_CONFIRMED)
        ->and($yearService->coverage($calendarCode, 2026)['state'])->toBe('confirmed');

    app(BusinessCalendarOverrideService::class)->apply(
        $calendarCode,
        CarbonImmutable::parse('2026-01-02'),
        false,
        'Exceção operacional documentada para teste.',
        $user->id,
    );

    expect($yearService->coverage($calendarCode, 2026)['state'])->toBe('stale');
});

it('refuses to infer missing B3 listed sessions from weekdays', function () {
    $calendar = app(BusinessDayCalendarService::class);
    $calendarCode = BusinessCalendarRegistry::B3_LISTED_TRADING;

    expect(fn () => $calendar->explain(CarbonImmutable::parse('2026-01-03'), $calendarCode))
        ->toThrow(RuntimeException::class, 'exige decisão explícita')
        ->and(fn () => $calendar->isBusinessDay(CarbonImmutable::parse('2026-01-05'), $calendarCode))
        ->toThrow(RuntimeException::class, 'exige decisão explícita');
});

it('reloads a cached year when its shared revision changes', function () {
    $calendarCode = BusinessCalendarRegistry::LEGACY_B3;
    $calendarYear = BusinessCalendarYear::query()->create([
        'calendar_code' => $calendarCode,
        'year' => 2026,
        'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
        'revision' => 1,
    ]);
    BusinessCalendarDate::query()->create([
        'business_calendar_year_id' => $calendarYear->id,
        'calendar_code' => $calendarCode,
        'calendar_date' => '2026-06-15',
        'is_business_day' => true,
        'revision' => 1,
    ]);
    $calendar = app(BusinessDayCalendarService::class);

    expect($calendar->isBusinessDay(CarbonImmutable::parse('2026-06-15'), $calendarCode))->toBeTrue();

    BusinessCalendarDate::query()->where('calendar_code', $calendarCode)->whereDate('calendar_date', '2026-06-15')->update([
        'is_business_day' => false,
        'revision' => 2,
    ]);
    $calendarYear->update(['revision' => 2]);
    app(BusinessCalendarRevisionService::class)->publish($calendarYear->fresh());

    expect($calendar->isBusinessDay(CarbonImmutable::parse('2026-06-15'), $calendarCode))->toBeFalse();
});

it('keeps the default B3 lookup isolated from the new banking calendar', function () {
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
        'calendar_date' => '2026-07-09',
        'is_business_day' => false,
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        'calendar_date' => '2026-07-09',
        'is_business_day' => true,
    ]);

    $calendar = app(BusinessDayCalendarService::class);

    expect($calendar->isBusinessDay(CarbonImmutable::parse('2026-07-09')))->toBeFalse()
        ->and($calendar->isBusinessDay(CarbonImmutable::parse('2026-07-09'), BusinessCalendarRegistry::BR_BANKING_ANBIMA))->toBeTrue();
});

it('summarizes calendar years with SQL compatible date aggregation', function () {
    BusinessCalendarDate::factory()->createMany([
        [
            'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
            'calendar_date' => '2026-01-01',
            'is_business_day' => false,
        ],
        [
            'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
            'calendar_date' => '2026-01-02',
            'is_business_day' => true,
        ],
    ]);
    BusinessHoliday::factory()->create([
        'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
        'holiday_date' => '2027-01-01',
    ]);
    BusinessCalendarOverride::factory()->create([
        'calendar_code' => BusinessCalendarRegistry::LEGACY_B3,
        'calendar_date' => '2026-01-02',
    ]);

    $summaries = collect(app(BusinessCalendarYearService::class)->administrativeSummary())
        ->keyBy(fn (array $summary): string => $summary['calendar_code'].'|'.$summary['year']);

    expect($summaries->get('B3|2026'))->toMatchArray([
        'covered_days' => 2,
        'non_business_days' => 1,
        'overrides' => 1,
    ])->and($summaries->get('B3|2027'))->toMatchArray([
        'covered_days' => 0,
        'state' => 'missing',
    ]);
});

it('shows calendar governance actions only to authorized users', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListBusinessHolidays::class)
        ->assertOk()
        ->assertSee('BR_BANKING_ANBIMA — Calendário bancário ANBIMA')
        ->assertSee('B3_LISTED_TRADING — Sessões de negociação B3')
        ->assertSee('BR_NATIONAL_HOLIDAYS — Feriados Nacionais — Brasil')
        ->assertActionVisible('compareCalendars')
        ->assertActionVisible('importAnbimaUrl')
        ->assertActionVisible('manualCalendarOverride')
        ->assertActionVisible('confirmCalendarYear');

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('pu.dashboard.view');
    $this->actingAs($viewer);

    Livewire::test(ListBusinessHolidays::class)
        ->assertOk()
        ->assertActionHidden('importAnbimaUrl')
        ->assertActionHidden('manualCalendarOverride')
        ->assertActionHidden('confirmCalendarYear');
});

it('runs the read only calendar diff from the administrative page', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(CompareBusinessCalendars::class)
        ->callAction('compare', [
            'calendar_a' => BusinessCalendarRegistry::LEGACY_B3,
            'calendar_b' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'from' => '2026-01-01',
            'to' => '2026-01-10',
        ])
        ->assertHasNoActionErrors()
        ->assertSet('comparison.calendar_a', BusinessCalendarRegistry::LEGACY_B3)
        ->assertSet('comparison.total_dates', 10);
});

it('blocks reference workbook scenario synchronization in production', function () {
    $emission = Emission::factory()->create();
    $originalEnvironment = app()->environment();
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        expect(fn () => app(PuReferenceWorkbookScenarioService::class)->sync($emission, '/tmp/missing.xlsx'))
            ->toThrow(LogicException::class, 'proibida em produção');
    } finally {
        app()->detectEnvironment(static fn (): string => $originalEnvironment);
    }
});
