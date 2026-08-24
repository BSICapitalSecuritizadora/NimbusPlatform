<?php

use App\Enums\ContractStatus;
use App\Support\Contracts\ContractOccupancyPeriod;
use App\Support\Contracts\ContractOccupancyTimeline;

/**
 * The interval rule on its own, with no database in sight.
 *
 * Deliberately outside the `parity` group: nothing here touches a column, an
 * index or a collation. It is date arithmetic, and it means the same thing on
 * every engine.
 */
function period(
    string $code,
    string $startsOn,
    ?string $endsOn = null,
    bool $isSubject = true,
    ?int $line = null,
): ContractOccupancyPeriod {
    return new ContractOccupancyPeriod(
        code: $code,
        clientName: null,
        startsOn: $startsOn,
        endsOn: $endsOn,
        isSubject: $isSubject,
        line: $line,
    );
}

it('accepts a sale opened on the day the previous period closed', function () {
    // [venda, distrato) is half open, so the two meet without ever sharing a day.
    $overlap = ContractOccupancyTimeline::of([
        period('A', '2024-03-10', '2026-08-20', isSubject: false),
        period('B', '2026-08-20'),
    ])->firstOverlap();

    expect($overlap)->toBeNull();
});

it('reports a sale opened before the previous period closed', function () {
    $overlap = ContractOccupancyTimeline::of([
        period('A', '2024-03-10', '2026-08-20', isSubject: false),
        period('B', '2026-08-10'),
    ])->firstOverlap();

    expect($overlap)->not->toBeNull()
        ->and($overlap->earlier->code)->toBe('A')
        ->and($overlap->later->code)->toBe('B')
        ->and($overlap->days)->toBe(10);
});

it('reads the same verdict whatever order the periods arrive in', function () {
    $a = period('A', '2024-03-10', '2026-08-20', isSubject: false);
    $b = period('B', '2026-08-10');

    $forwards = ContractOccupancyTimeline::of([$a, $b])->firstOverlap();
    $backwards = ContractOccupancyTimeline::of([$b, $a])->firstOverlap();

    expect($backwards->earlier->code)->toBe($forwards->earlier->code)
        ->and($backwards->later->code)->toBe($forwards->later->code)
        ->and($backwards->days)->toBe($forwards->days);
});

it('walks a chain of transitions on one unit without complaining', function () {
    // A -> B -> C, each opening exactly where the one before it closed.
    $overlap = ContractOccupancyTimeline::of([
        period('C', '2026-08-25'),
        period('A', '2023-01-01', '2026-08-10'),
        period('B', '2026-08-15', '2026-08-20'),
    ])->firstOverlap();

    expect($overlap)->toBeNull();
});

it('finds the break in a chain of transitions wherever it is', function () {
    $overlap = ContractOccupancyTimeline::of([
        period('C', '2026-08-25'),
        period('A', '2023-01-01', '2026-08-18'),
        period('B', '2026-08-15', '2026-08-20'),
    ])->firstOverlap();

    expect($overlap->earlier->code)->toBe('A')
        ->and($overlap->later->code)->toBe('B')
        ->and($overlap->days)->toBe(3);
});

it('leaves two periods nobody is touching alone', function () {
    // Already inconsistent on record. Whoever is saving something else today did
    // not cause it and cannot fix it from here.
    $overlap = ContractOccupancyTimeline::of([
        period('A', '2024-01-01', '2025-06-01', isSubject: false),
        period('B', '2024-06-01', '2025-01-01', isSubject: false),
    ])->firstOverlap();

    expect($overlap)->toBeNull();
});

it('holds an untouched period against one that is being written', function () {
    $overlap = ContractOccupancyTimeline::of([
        period('A', '2024-01-01', '2025-06-01', isSubject: false),
        period('B', '2024-06-01', '2025-01-01'),
    ])->firstOverlap();

    expect($overlap)->not->toBeNull()
        ->and($overlap->lines())->toBe([]);
});

it('reports only the lines that came from a spreadsheet', function () {
    $overlap = ContractOccupancyTimeline::of([
        period('A', '2024-03-10', '2026-08-20', isSubject: false),
        period('B', '2026-08-10', line: 7),
    ])->firstOverlap();

    expect($overlap->lines())->toBe([7]);
});

it('treats a period still holding the unit as running forever', function () {
    $overlap = ContractOccupancyTimeline::of([
        period('A', '2024-03-10', null, isSubject: false),
        period('B', '2026-08-10'),
    ])->firstOverlap();

    expect($overlap)->not->toBeNull()
        ->and($overlap->earlier->code)->toBe('A');
});

it('refuses to place a closed period that carries no closing date', function () {
    // Only ever happens on a row the analysis already refused, and a refused row
    // moves nothing -- so there is nothing to put on the line.
    $period = ContractOccupancyPeriod::fromValues(
        code: 'A',
        clientName: null,
        saleDate: '2026-01-01',
        cancellationDate: null,
        status: ContractStatus::Cancelled,
    );

    expect($period)->toBeNull()
        ->and(ContractOccupancyTimeline::of([$period, period('B', '2026-01-01')])->firstOverlap())->toBeNull();
});

it('describes the overlap naming both contracts, both dates and the days', function () {
    $overlap = ContractOccupancyTimeline::of([
        new ContractOccupancyPeriod('A305-01', 'João da Silva', '2024-03-10', '2026-08-20', false),
        new ContractOccupancyPeriod('A305-02', 'Maria Oliveira', '2026-08-10', null, true),
    ])->firstOverlap();

    expect($overlap->describe('Bloco A - 305'))->toBe(
        'Sobreposição de 10 dia(s) na unidade Bloco A - 305: A305-01 (João da Silva) só foi distratado em 20/08/2026, '
        .'mas A305-02 (Maria Oliveira) já foi vendido em 10/08/2026. Revise as datas antes de continuar.'
    );
});
