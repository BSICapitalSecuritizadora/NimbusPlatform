<?php

use App\Enums\MeasurementResponsibility;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarYear;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementPendingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PerformanceProbe;

uses(RefreshDatabase::class);

/**
 * Benchmarks da P2.4. Ficam fora da suíte padrão porque montam centenas de
 * fixtures; rodam com NIMBUS_BENCH=1.
 */
beforeEach(function () {
    if (env('NIMBUS_BENCH') !== '1') {
        test()->markTestSkipped('Benchmark da P2.4: defina NIMBUS_BENCH=1 para executar.');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function benchUser(array $permissions): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo($permissions);

    return $user;
}

function benchOperationalPermissions(): array
{
    return [
        'measurements.view', 'operations.view',
        'measurements.review', 'measurements.pay',
        'measurements.receipts', 'measurements.finalize',
    ];
}

/**
 * Cenário determinístico de My Pendings.
 *
 * `$mode` decide de onde vem a autoridade do usuário sobre cada medição, o que
 * é exatamente a variável que muda o caminho de resolução medido.
 *
 * @return array{user: User, measurements: int}
 */
/**
 * O calendário decide qual caminho do SLA a medição percorre, e cada caminho tem
 * um custo diferente -- por isso ele é uma dimensão do benchmark, não um detalhe
 * de fixture.
 */
function benchCalendar(string $calendar): void
{
    if ($calendar === 'none') {
        config(['measurements.sla.calendar_code' => null]);

        return;
    }

    $code = 'BENCH_CALENDAR';
    config(['measurements.sla.calendar_code' => $code]);
    BusinessCalendar::factory()->create(['code' => $code]);

    if ($calendar === 'unmaterialized') {
        return;
    }

    foreach ([now()->year - 1, now()->year, now()->year + 1] as $year) {
        $calendarYear = BusinessCalendarYear::factory()->create(['calendar_code' => $code, 'year' => $year]);

        // O calendário recusa inferir dia útil de data sem linha, então o ano
        // precisa estar materializado de verdade para o caminho saudável valer.
        $cursor = CarbonImmutable::create($year, 1, 1);
        $dates = [];

        while ($cursor->year === $year) {
            $dates[] = [
                'calendar_code' => $code,
                'business_calendar_year_id' => $calendarYear->getKey(),
                'calendar_date' => $cursor->toDateString(),
                'is_business_day' => ! $cursor->isWeekend(),
                'data_origin' => 'inferred',
                'source' => 'calendar_inference',
                'source_is_official' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $cursor = $cursor->addDay();
        }

        foreach (array_chunk($dates, 200) as $chunk) {
            DB::table('business_calendar_dates')->insert($chunk);
        }
    }
}

function benchPendingScenario(int $volume, string $mode, int $operations = 1, string $calendar = 'none'): array
{
    benchCalendar($calendar);
    SlaConfiguration::query()->delete();

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, days: 5)->create();
    }

    $user = benchUser(benchOperationalPermissions());
    $principal = benchUser(benchOperationalPermissions());
    $columns = array_map(
        fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
        MeasurementResponsibility::cases(),
    );

    $operationIds = [];

    for ($index = 0; $index < max(1, $operations); $index++) {
        $holder = match ($mode) {
            'direct' => $user,
            'delegated', 'ineffective' => $principal,
            'mixed' => $index % 2 === 0 ? $user : $principal,
            default => $user,
        };

        $operation = Operation::factory()->create(array_fill_keys($columns, $holder->getKey()));
        $operationIds[] = $operation->getKey();

        if (in_array($mode, ['delegated', 'ineffective', 'mixed'], true) && $holder->is($principal)) {
            ResponsibilityDelegation::factory()->active()->forOperation($operation)->create([
                'delegator_user_id' => $principal->getKey(),
                'delegate_user_id' => $user->getKey(),
            ]);
        }
    }

    if ($mode === 'ineffective') {
        // O delegante perde o assignment direto: a delegação existe, mas não é efetiva.
        Operation::query()->whereKey($operationIds)->update(array_fill_keys($columns, null));
    }

    $statuses = match ($mode) {
        'no_action' => ['finalized'],
        'variety' => ['in_review', 'awaiting_payment', 'awaiting_receipt', 'approved', 'paused'],
        default => ['in_review'],
    };

    $rows = [];
    $now = now();

    for ($index = 0; $index < $volume; $index++) {
        $status = $statuses[$index % count($statuses)];
        $rows[] = [
            'operation_id' => $operationIds[$index % count($operationIds)],
            'reference_month' => $now->copy()->subMonths($index % 12)->format('Y-m-01'),
            'filename' => "bench-{$index}.pdf",
            'storage_path' => "measurements/bench-{$index}.pdf",
            'status' => $status,
            'current_stage' => match ($status) {
                'awaiting_payment' => 4,
                'awaiting_receipt', 'approved' => 5,
                default => 1,
            },
            'workflow_revision' => 0,
            'uploaded_at' => $now,
            'created_at' => $now->copy()->subDays(10),
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('measurements')->insert($chunk);
    }

    $measurements = Measurement::query()->orderBy('id')->get(['id', 'status', 'current_stage']);
    $reviews = [];
    $pauses = [];

    foreach ($measurements as $measurement) {
        $reviews[] = [
            'measurement_id' => $measurement->getKey(),
            'stage' => (int) $measurement->current_stage,
            'status' => 'pending',
            'paused_at' => $measurement->status === 'paused' ? $now->copy()->subDay() : null,
            'created_at' => $now->copy()->subDays(10),
            'updated_at' => $now,
        ];

        if ($measurement->status === 'paused') {
            $pauses[] = [
                'measurement_id' => $measurement->getKey(),
                'stage' => (int) $measurement->current_stage,
                'paused_by' => $principal->getKey(),
                'pause_reason' => 'benchmark',
                'paused_operation_status' => 'in_review',
                'paused_at' => $now->copy()->subDay(),
                'resumed_at' => null,
                'created_at' => $now->copy()->subDay(),
                'updated_at' => $now,
            ];
        }
    }

    foreach (array_chunk($reviews, 200) as $chunk) {
        DB::table('measurement_reviews')->insert($chunk);
    }

    foreach (array_chunk($pauses, 200) as $chunk) {
        DB::table('measurement_pauses')->insert($chunk);
    }

    return ['user' => $user->fresh(), 'measurements' => $volume];
}

it('mede a curva de My Pendings por volume', function (int $volume) {
    $scenario = benchPendingScenario($volume, 'direct', calendar: 'none');
    $user = $scenario['user'];

    $probe = PerformanceProbe::measure(function () use ($user): array {
        app()->forgetInstance(MeasurementPendingService::class);

        return app(MeasurementPendingService::class)->summaryFor($user);
    }, runs: 5);

    fwrite(STDERR, PHP_EOL.$probe->summary("pendings/direct/{$volume}").PHP_EOL);
    fwrite(STDERR, sprintf(
        '   count=%d delegated=%d overdue=%d | queries por medição=%.2f',
        $probe->result['count'],
        $probe->result['delegated_count'],
        $probe->result['overdue_count'],
        $probe->queryCount / $volume,
    ).PHP_EOL);

    foreach ($probe->queryShapes(4) as $shape) {
        fwrite(STDERR, sprintf('   %4dx %s', $shape['times'], substr($shape['query'], 0, 120)).PHP_EOL);
    }

    expect($probe->result['count'])->toBe($volume);
})->with([10, 100, 500]);

it('mede My Pendings por origem de autoridade', function (string $mode, int $expectedCount) {
    $volume = 100;
    $scenario = benchPendingScenario($volume, $mode, operations: $mode === 'mixed' ? 4 : 1, calendar: 'none');
    $user = $scenario['user'];

    $probe = PerformanceProbe::measure(function () use ($user): array {
        app()->forgetInstance(MeasurementPendingService::class);

        return app(MeasurementPendingService::class)->summaryFor($user);
    }, runs: 3);

    fwrite(STDERR, PHP_EOL.$probe->summary("pendings/{$mode}/{$volume}").PHP_EOL);
    fwrite(STDERR, sprintf(
        '   count=%d delegated=%d | dup=%d',
        $probe->result['count'],
        $probe->result['delegated_count'],
        $probe->duplicateQueryCount,
    ).PHP_EOL);

    foreach ($probe->queryShapes(8) as $shape) {
        fwrite(STDERR, sprintf('   %4dx %s', $shape['times'], substr($shape['query'], 0, 150)).PHP_EOL);
    }

    expect($probe->result['count'])->toBe($expectedCount);
})->with([
    ['direct', 100],
    ['delegated', 100],
    ['ineffective', 0],
    ['mixed', 100],
    ['variety', 100],
    ['no_action', 0],
]);

it('mede a curva de recipients de SLA por volume', function (int $volume) {
    benchPendingScenario($volume, 'delegated', calendar: 'none');

    $probe = PerformanceProbe::measure(
        fn (): int => (int) app(Kernel::class)
            ->call('measurements:evaluate-sla', ['--dry-run' => true]),
        runs: 3,
    );

    fwrite(STDERR, PHP_EOL.$probe->summary("sla-recipients/{$volume}").PHP_EOL);
    fwrite(STDERR, sprintf('   queries por medição=%.2f dup=%d', $probe->queryCount / $volume, $probe->duplicateQueryCount).PHP_EOL);

    foreach ($probe->queryShapes(4) as $shape) {
        fwrite(STDERR, sprintf('   %4dx %s', $shape['times'], substr($shape['query'], 0, 120)).PHP_EOL);
    }

    expect($probe->queryCount)->toBeGreaterThan(0);
})->with([10, 100, 500]);

it('mede o efeito do calendário no caminho do SLA dentro de My Pendings', function (string $calendar) {
    $volume = 100;
    $scenario = benchPendingScenario($volume, 'direct', calendar: $calendar);
    $user = $scenario['user'];

    $probe = PerformanceProbe::measure(function () use ($user): array {
        app()->forgetInstance(MeasurementPendingService::class);

        return app(MeasurementPendingService::class)->summaryFor($user);
    }, runs: 3);

    fwrite(STDERR, PHP_EOL.$probe->summary("pendings/calendar-{$calendar}/{$volume}").PHP_EOL);
    fwrite(STDERR, sprintf(
        '   count=%d dup=%d calendar_years_queries=%d',
        $probe->result['count'],
        $probe->duplicateQueryCount,
        $probe->countMatching('business_calendar_years'),
    ).PHP_EOL);

    expect($probe->queryCount)->toBeGreaterThan(0);
})->with(['none', 'materialized', 'unmaterialized']);
