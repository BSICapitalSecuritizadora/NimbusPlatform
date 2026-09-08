<?php

use App\Services\SalesBoards\SalesBoardAutomationDueDateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * A regra do dia 13, isolada de banco e de configuração de alvos.
 *
 * Nenhum destes testes toca no banco: a decisão de competência é aritmética de
 * calendário no fuso de negócio, e amarrá-la a fixtures esconderia a única coisa
 * que ela precisa provar.
 */
function dueService(): SalesBoardAutomationDueDateService
{
    return app(SalesBoardAutomationDueDateService::class);
}

function businessDate(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date)->startOfDay();
}

it('does not owe the previous competence before the thirteenth', function () {
    expect(dueService()->latestDueReferenceMonth(businessDate('2026-09-12'))->format('Y-m'))
        ->toBe('2026-07')
        ->and(dueService()->isDue(businessDate('2026-08-01'), businessDate('2026-09-12')))
        ->toBeFalse();
});

it('owes the previous competence from the thirteenth on', function (string $date) {
    expect(dueService()->latestDueReferenceMonth(businessDate($date))->format('Y-m'))
        ->toBe('2026-08')
        ->and(dueService()->isDue(businessDate('2026-08-01'), businessDate($date)))
        ->toBeTrue();
})->with(['2026-09-13', '2026-09-14', '2026-09-30']);

it('crosses the year boundary without special casing', function () {
    expect(dueService()->latestDueReferenceMonth(businessDate('2027-01-12'))->format('Y-m'))
        ->toBe('2026-11')
        ->and(dueService()->latestDueReferenceMonth(businessDate('2027-01-13'))->format('Y-m'))
        ->toBe('2026-12')
        ->and(dueService()->isDue(businessDate('2026-12-01'), businessDate('2027-01-12')))
        ->toBeFalse()
        ->and(dueService()->isDue(businessDate('2026-12-01'), businessDate('2027-01-13')))
        ->toBeTrue();
});

it('places the due date on the thirteenth of the following month', function () {
    expect(dueService()->dueDateFor(businessDate('2026-08-01'))->toDateString())->toBe('2026-09-13')
        ->and(dueService()->dueDateFor(businessDate('2026-12-01'))->toDateString())->toBe('2027-01-13');
});

/**
 * O dia 13 é civil: não anda para a segunda-feira, não anda por feriado.
 * 13/09/2026 é um domingo, e 13/12/2026 também.
 */
it('never shifts the thirteenth off a weekend', function () {
    expect(businessDate('2026-09-13')->isSunday())->toBeTrue()
        ->and(dueService()->isDue(businessDate('2026-08-01'), businessDate('2026-09-13')))->toBeTrue();
});

it('reads the civil day in the business timezone, not in UTC', function () {
    // 13/09/2026 00:30 UTC ainda é 12/09 21:30 em São Paulo: agosto não venceu.
    $stillTwelfth = dueService()->businessDate(CarbonImmutable::parse('2026-09-13 00:30:00', 'UTC'));

    expect($stillTwelfth->toDateString())->toBe('2026-09-12')
        ->and(dueService()->isDue(businessDate('2026-08-01'), $stillTwelfth))->toBeFalse();

    // 13/09/2026 03:30 UTC já é 13/09 00:30 em São Paulo: agosto venceu.
    $alreadyThirteenth = dueService()->businessDate(CarbonImmutable::parse('2026-09-13 03:30:00', 'UTC'));

    expect($alreadyThirteenth->toDateString())->toBe('2026-09-13')
        ->and(dueService()->isDue(businessDate('2026-08-01'), $alreadyThirteenth))->toBeTrue();
});

it('lists every competence due since activation, oldest first', function () {
    $months = dueService()->dueReferenceMonths(businessDate('2026-08-01'), businessDate('2026-11-15'));

    expect(collect($months)->map(fn (CarbonImmutable $m): string => $m->format('Y-m'))->all())
        ->toBe(['2026-08', '2026-09', '2026-10']);
});

it('never lists a competence older than the activation month', function () {
    $months = dueService()->dueReferenceMonths(businessDate('2026-08-01'), businessDate('2026-12-20'));

    expect(collect($months)->map(fn (CarbonImmutable $m): string => $m->format('Y-m'))->all())
        ->toBe(['2026-08', '2026-09', '2026-10', '2026-11'])
        ->and($months)->not->toContain(businessDate('2026-07-01'));
});

it('lists nothing when the activation month is not due yet', function () {
    expect(dueService()->dueReferenceMonths(businessDate('2026-08-01'), businessDate('2026-09-12')))->toBe([])
        ->and(dueService()->dueReferenceMonths(businessDate('2027-01-01'), businessDate('2026-12-20')))->toBe([]);
});

it('honours a business timezone change through BusinessTime', function () {
    Config::set('measurements.business_timezone', 'UTC');

    // Sob UTC o mesmo instante já é dia 13.
    expect(dueService()->businessDate(CarbonImmutable::parse('2026-09-13 00:30:00', 'UTC'))->toDateString())
        ->toBe('2026-09-13');
});
