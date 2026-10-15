<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementWorkflow;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Medição original (R0) de 05/2026 aprovada pela Engenharia em 10% de um Fundo
 * de Obra de R$ 1.000.000,00, e pela Gestão e pela Compliance: parada na etapa
 * Pagamento com R$ 100.000,00 a pagar. Montada no relógio já viajado -- a
 * competência é anterior a todos os instantes destes testes.
 *
 * @return array{0: array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}, 1: Measurement}
 */
function p4aPayDateAwaitingPayment(): array
{
    $scenario = Scenario::plan();

    return [$scenario, Scenario::awaitingPayment($scenario, '2026-05', 10)];
}

/**
 * Registra pelo fluxo um pagamento com a data informada, no valor aprovado
 * inteiro: sem divergência, nada além da data decide a recusa.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function p4aPayDateRegister(array $scenario, Measurement $measurement, mixed $payDate): MeasurementPayment
{
    return app(MeasurementWorkflow::class)->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSet']->id,
        'pay_date' => $payDate,
        'amount' => Scenario::expected(10),
        'method' => 'TED',
    ]);
}

/**
 * As mensagens da recusa de validação, por campo -- lidas da exceção, não por
 * `toThrow()`: a recusa da data futura tem dois-pontos.
 *
 * @return array<string, list<string>>|null
 */
function p4aPayDateValidationErrors(callable $action): ?array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return null;
}

/**
 * A recusa da data posterior à de hoje em Brasília, com as duas datas em
 * dd/mm/aaaa.
 */
function p4aPayDateFutureRefusal(string $payDate, string $businessToday): string
{
    return "A data do pagamento ({$payDate}) é posterior à data de hoje em Brasília ({$businessToday}): um pagamento só é registrado depois de acontecer.";
}

/**
 * A coluna como o banco a guarda, sem o cast do model: `Y-m-d` na coluna DATE
 * do MySQL; no SQLite, o texto que o cast `date` grava, à meia-noite.
 */
function p4aPayDateColumn(MeasurementPayment $payment): ?string
{
    $value = DB::table('measurement_payments')->where('id', $payment->getKey())->value('pay_date');

    return $value === null ? null : (string) $value;
}

/**
 * A mesma data civil no model, na coluna e no enquadramento financeiro
 * congelado no registro -- o que a Finalização confere contra o pagamento.
 */
function p4aPayDateExpectStoredAs(MeasurementPayment $payment, string $date): void
{
    $stored = $payment->fresh();

    expect($stored->pay_date->toDateString())->toBe($date)
        ->and(p4aPayDateColumn($stored))->toBeIn([$date, "{$date} 00:00:00"])
        ->and($stored->financial_assessment['pay_date'])->toBe($date)
        ->and(app(MeasurementPaymentFinancialService::class)->assessmentMatchesSnapshot($stored->measurement, $stored))->toBeTrue();
}

// ── O dia de hoje é o civil de Brasília ──────────────────────────────────────

it('keeps 14/10/2026 as today in Brasília at every clock time of the day and refuses 15/10/2026', function (string $clock, string $utcDate) {
    $this->travelTo(CarbonImmutable::parse("2026-10-14 {$clock}", 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    // Das 21h em diante o dia UTC já é o seguinte; o de Brasília, não.
    expect(BusinessTime::dateString())->toBe('2026-10-14')
        ->and(now()->toDateString())->toBe($utcDate)
        ->and(p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, '2026-10-15')))
        ->toBe(['payments.0.pay_date' => ['A data do pagamento (15/10/2026) é posterior à data de hoje em Brasília (14/10/2026): um pagamento só é registrado depois de acontecer.']])
        ->and(MeasurementPayment::query()->count())->toBe(0);

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, '2026-10-14'), '2026-10-14');
})->with([
    'midnight (03:00 UTC)' => ['00:00:00', '2026-10-14'],
    'noon' => ['12:00:00', '2026-10-14'],
    'last second of the UTC day (20:59:59)' => ['20:59:59', '2026-10-14'],
    'UTC already on the next day (21:00:00)' => ['21:00:00', '2026-10-15'],
    'late evening (22:30:00)' => ['22:30:00', '2026-10-15'],
    'last second of the Brasília day (23:59:59)' => ['23:59:59', '2026-10-15'],
]);

it('accepts 15/10/2026 as soon as the day starts in Brasília, three hours after the UTC day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:00:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    expect(BusinessTime::dateString())->toBe('2026-10-15')
        ->and(now()->format('Y-m-d H:i:s'))->toBe('2026-10-15 03:00:00');

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, '2026-10-15'), '2026-10-15');
});

// ── Viradas de mês e de ano: o UTC já está no dia seguinte ───────────────────

it('takes 31/10/2026 as today at 22:30 in Brasília although UTC is already on 01/11/2026', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();
    $refusal = ['payments.0.pay_date' => [p4aPayDateFutureRefusal('01/11/2026', '31/10/2026')]];

    expect(config('app.timezone'))->toBe('UTC')
        ->and(BusinessTime::timezone())->toBe('America/Sao_Paulo')
        ->and(BusinessTime::dateString())->toBe('2026-10-31')
        ->and(now()->toDateString())->toBe('2026-11-01')
        ->and(now()->getTimezone()->getName())->toBe('UTC')
        // A data UTC, como texto ou como o instante de agora em UTC: a data do
        // próprio valor é 01/11, e a de hoje em Brasília, 31/10.
        ->and(p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, '2026-11-01')))->toBe($refusal)
        ->and(p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, now())))->toBe($refusal)
        ->and(MeasurementPayment::query()->count())->toBe(0);

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, '2026-10-31'), '2026-10-31');
});

it('refuses at 22:30 in Brasília on 31/10/2026 a value whose own calendar date is the UTC 01/11/2026', function (Closure $payDate) {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    // O instante é agora, mas a data é a escrita no valor, sem trocar de fuso:
    // quem envia o instante em UTC depois das 21h de Brasília envia 01/11.
    expect(p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, $payDate())))
        ->toBe(['payments.0.pay_date' => [p4aPayDateFutureRefusal('01/11/2026', '31/10/2026')]])
        ->and(MeasurementPayment::query()->count())->toBe(0);
})->with([
    'UTC instant as text (Z)' => [fn () => '2026-11-01T01:30:00Z'],
    'UTC instant as text (+00:00)' => [fn () => '2026-11-01T01:30:00+00:00'],
    'UTC date and time without offset' => [fn () => '2026-11-01 01:30:00'],
    'UTC instant as an immutable Carbon' => [fn () => CarbonImmutable::now('UTC')],
    'same instant in a timezone east of UTC (Asia/Tokyo)' => [fn () => CarbonImmutable::now('Asia/Tokyo')],
]);

it('accepts at 22:30 in Brasília on 31/10/2026 a value whose own calendar date is 31/10/2026', function (Closure $payDate) {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, $payDate()), '2026-10-31');
})->with([
    'Brasília instant as text with offset' => [fn () => '2026-10-31T22:30:00-03:00'],
    'now re-read in Brasília' => [fn () => BusinessTime::at(now())],
    'midnight of 31/10 in Brasília' => [fn () => CarbonImmutable::parse('2026-10-31', 'America/Sao_Paulo')],
    'midnight of 31/10 in UTC' => [fn () => CarbonImmutable::parse('2026-10-31', 'UTC')],
]);

it('refuses 01/01/2027 while it is still 31/12/2026 in Brasília', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-31 22:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    expect(BusinessTime::dateString())->toBe('2026-12-31')
        ->and(now()->toDateString())->toBe('2027-01-01')
        ->and(BusinessTime::at(now())->year)->toBe(2026)
        ->and(p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, '2027-01-01')))
        ->toBe(['payments.0.pay_date' => [p4aPayDateFutureRefusal('01/01/2027', '31/12/2026')]])
        ->and(MeasurementPayment::query()->count())->toBe(0);

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, '2026-12-31'), '2026-12-31');
});

it('accepts 31/10/2026 right after midnight of 01/11/2026 in Brasília and stores it unshifted', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    expect(BusinessTime::dateString())->toBe('2026-11-01')
        ->and(now()->format('Y-m-d H:i:s'))->toBe('2026-11-01 03:30:00')
        ->and(p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, '2026-11-02')))
        ->toBe(['payments.0.pay_date' => [p4aPayDateFutureRefusal('02/11/2026', '01/11/2026')]]);

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, '2026-10-31'), '2026-10-31');
});

// ── Datas passadas: gravadas como informadas ─────────────────────────────────

it('stores a payment date months in the past exactly as informed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, '2026-06-10'), '2026-06-10');
});

it('stores a Carbon instance at UTC midnight on its own calendar date, never on the previous day of Brasília', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();
    $payDate = CarbonImmutable::parse('2026-06-10');

    // O mesmo instante, relido em Brasília, cai em 09/06: converter o fuso
    // deslocaria o dia do pagamento.
    expect($payDate->format('Y-m-d H:i:s e'))->toBe('2026-06-10 00:00:00 UTC')
        ->and(BusinessTime::dateString($payDate))->toBe('2026-06-09');

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, $payDate), '2026-06-10');
});

it('stores the date part of a historical payment date as written, whatever its time or offset', function (Closure $payDate) {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    p4aPayDateExpectStoredAs(p4aPayDateRegister($scenario, $measurement, $payDate()), '2026-06-10');
})->with([
    'late evening in Brasília, already 11/06 in UTC (text)' => [fn () => '2026-06-10T23:30:00-03:00'],
    'early morning in UTC, still 09/06 in Brasília (text)' => [fn () => '2026-06-10T00:30:00Z'],
    'fractional seconds and +00:00 offset' => [fn () => '2026-06-10T02:00:00.123456+00:00'],
    'date and time without offset' => [fn () => '2026-06-10 23:59:59'],
    'date and time without seconds' => [fn () => '2026-06-10 21:30'],
    'late evening in Brasília, already 11/06 in UTC (Carbon)' => [fn () => CarbonImmutable::parse('2026-06-10 23:30:00', 'America/Sao_Paulo')],
    'early morning in UTC, still 09/06 in Brasília (Carbon)' => [fn () => CarbonImmutable::parse('2026-06-10 00:30:00', 'UTC')],
    'native DateTime in Brasília' => [fn () => new DateTime('2026-06-10 23:30:00', new DateTimeZone('America/Sao_Paulo'))],
]);

// ── O que não é data do calendário ───────────────────────────────────────────

it('refuses, without creating any payment, a value the date rule already rejects', function (string $payDate) {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    // A regra `date` do Laravel recusa antes da conferência da data civil: a
    // recusa é dela, no mesmo campo, com o nome do campo e não a chave técnica.
    expect(Validator::make(['pay_date' => $payDate], ['pay_date' => 'date'])->fails())->toBeTrue();

    $errors = p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, $payDate));

    expect(array_keys($errors ?? []))->toBe(['payments.0.pay_date'])
        ->and($errors['payments.0.pay_date'][0])->not->toContain('payments.0.pay_date')
        ->and(MeasurementPayment::query()->count())->toBe(0);
})->with([
    'tomorrow' => ['tomorrow'],
    '+1 day' => ['+1 day'],
    'today' => ['today'],
    'day first with slashes (14/10/2026)' => ['14/10/2026'],
    'nonexistent day (2026-02-30)' => ['2026-02-30'],
    'nonexistent month (2026-13-01)' => ['2026-13-01'],
    'not a date (abc)' => ['abc'],
]);

it('refuses with the calendar-date message a value that passes the date rule but is not written as YYYY-MM-DD', function (string $payDate) {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();

    // A regra `date` aceita: quem recusa é a conferência da data civil -- o
    // texto relativo deslocaria o dia escrito, e a ordem do dia e do mês fica
    // por conta do PHP (10/12/2026 é lido 12/10).
    expect(Validator::make(['pay_date' => $payDate], ['pay_date' => 'date'])->passes())->toBeTrue()
        ->and(p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, $payDate)))
        ->toBe(['payments.0.pay_date' => [MeasurementWorkflow::INVALID_PAY_DATE_REFUSAL]])
        ->and(MeasurementPayment::query()->count())->toBe(0);
})->with([
    'relative modifier after a date (+1 day)' => ['2026-10-14 +1 day'],
    'relative modifier after a date (tomorrow)' => ['2026-10-14 tomorrow'],
    'relative modifier before a date (tomorrow)' => ['tomorrow 2026-10-14'],
    'relative month after a date' => ['2026-10-14 first day of next month'],
    'ambiguous day and month (10/12/2026)' => ['10/12/2026'],
    'day first with dashes (14-10-2026)' => ['14-10-2026'],
    'slashes (2026/10/14)' => ['2026/10/14'],
    'dots (14.10.2026)' => ['14.10.2026'],
    'month name (14 October 2026)' => ['14 October 2026'],
    'no separators (20261014)' => ['20261014'],
    'ISO week date (2026-W42-3)' => ['2026-W42-3'],
]);

// ── Várias linhas: todas conferidas antes de gravar ──────────────────────────

it('checks the date of every row before registering any payment', function (string $secondPayDate, string $refusal) {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 22:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();
    $workflow = app(MeasurementWorkflow::class);
    $revision = (int) $measurement->workflow_revision;
    $rows = fn (string $second): array => [
        ['plan_set_id' => $scenario['planSet']->id, 'pay_date' => '2026-10-14', 'amount' => '50000.00', 'method' => 'TED', 'financial_justification' => 'Primeira parcela da competência.'],
        ['plan_set_id' => $scenario['planSet']->id, 'pay_date' => $second, 'amount' => '50000.00', 'method' => 'TED'],
    ];
    // A transação desfaria uma linha gravada antes da recusa; o que prova a
    // conferência prévia é nenhum INSERT ter sido sequer tentado.
    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserts): void {
        if (preg_match('/^\s*insert\s+into\s+[`"]?measurement_payments[`"]?\s/i', $query->sql) === 1) {
            $inserts++;
        }
    });

    expect(p4aPayDateValidationErrors(fn () => $workflow->registerPayments($measurement->fresh(), $scenario['actor'], $rows($secondPayDate))))
        ->toBe(['payments.1.pay_date' => [$refusal]])
        ->and($inserts)->toBe(0)
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and((int) $measurement->fresh()->workflow_revision)->toBe($revision)
        ->and(Activity::query()->where('description', 'measurement_payment_registered')->count())->toBe(0);

    // Com a segunda linha corrigida, as duas entram, cada uma com a própria data.
    $payments = $workflow->registerPayments($measurement->fresh(), $scenario['actor'], $rows('2026-10-13'));

    expect($payments)->toHaveCount(2)
        ->and($inserts)->toBe(2);
    p4aPayDateExpectStoredAs($payments[0], '2026-10-14');
    p4aPayDateExpectStoredAs($payments[1], '2026-10-13');
})->with([
    'second row on the UTC date, after today in Brasília' => ['2026-10-15', 'A data do pagamento (15/10/2026) é posterior à data de hoje em Brasília (14/10/2026): um pagamento só é registrado depois de acontecer.'],
    'second row not a calendar date' => ['2026-10-14 +1 day', MeasurementWorkflow::INVALID_PAY_DATE_REFUSAL],
]);

// ── O enquadramento financeiro congela a data normalizada ────────────────────

it('finalizes a payment registered with a time and an offset, because the frozen assessment holds the same civil date', function (Closure $payDate) {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();
    $payment = p4aPayDateRegister($scenario, $measurement, $payDate());

    p4aPayDateExpectStoredAs($payment, '2026-10-31');

    // A Finalização reconfere o enquadramento contra a data do pagamento: o
    // valor gravado cru no enquadramento a faria recusar o contexto financeiro.
    $measurement = Scenario::closePaymentAndFinalize($scenario, $measurement);

    expect($measurement->status)->toBe('finalized')
        ->and($payment->fresh()->pay_date->toDateString())->toBe('2026-10-31');
})->with([
    'text with time and offset' => [fn () => '2026-10-31T22:30:00-03:00'],
    'now re-read in Brasília' => [fn () => BusinessTime::at(now())],
]);

// ── A data registrada não muda mais ──────────────────────────────────────────

it('never rewrites the date of a registered payment', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();
    $payment = p4aPayDateRegister($scenario, $measurement, '2026-10-31');
    $refusal = 'O valor e a data de um pagamento registrado não podem ser alterados';

    expect(fn () => $payment->fresh()->update(['pay_date' => '2026-10-01']))->toThrow(MeasurementWorkflowException::class, $refusal)
        // Nem para o dia UTC, que o padrão antigo gravaria a esta hora.
        ->and(fn () => $payment->fresh()->update(['pay_date' => '2026-11-01']))->toThrow(MeasurementWorkflowException::class, $refusal)
        ->and(fn () => $payment->fresh()->forceFill(['pay_date' => now()])->save())->toThrow(MeasurementWorkflowException::class, $refusal);

    p4aPayDateExpectStoredAs($payment, '2026-10-31');
});

it('keeps the registered date when the payment is reassessed, even if the data carries another one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 22:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();
    $payment = p4aPayDateRegister($scenario, $measurement, '2026-10-14');

    app(MeasurementWorkflow::class)->reassessPayment($payment->fresh(), $scenario['actor'], [
        'pay_date' => '2026-10-15',
    ], (int) $measurement->fresh()->workflow_revision);

    p4aPayDateExpectStoredAs($payment, '2026-10-14');
    expect(Activity::query()->where('description', 'measurement_payment_reassessed')->sole()->properties['assessment']['pay_date'])
        ->toBe('2026-10-14');
});

it('does not validate the registered date again when the payment is reassessed', function () {
    // Registrado à 00h30 de 01/11 em Brasília com a data de hoje...
    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30:00', 'America/Sao_Paulo'));
    [$scenario, $measurement] = p4aPayDateAwaitingPayment();
    $payment = p4aPayDateRegister($scenario, $measurement, '2026-11-01');

    // ...e reavaliado num relógio em que essa data é a de amanhã em Brasília:
    // a de um pagamento antigo, registrado com o dia UTC depois das 21h.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'America/Sao_Paulo'));

    expect(BusinessTime::dateString())->toBe('2026-10-31')
        ->and(p4aPayDateValidationErrors(fn () => p4aPayDateRegister($scenario, $measurement, '2026-11-01')))
        ->toBe(['payments.0.pay_date' => [p4aPayDateFutureRefusal('01/11/2026', '31/10/2026')]]);

    app(MeasurementWorkflow::class)->reassessPayment($payment->fresh(), $scenario['actor'], [], (int) $measurement->fresh()->workflow_revision);

    p4aPayDateExpectStoredAs($payment, '2026-11-01');
    expect(Activity::query()->where('description', 'measurement_payment_reassessed')->count())->toBe(1);
});
