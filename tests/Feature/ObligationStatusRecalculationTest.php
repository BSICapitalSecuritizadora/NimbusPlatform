<?php

use App\Actions\Emissions\RecalculateObligationStatusesAction;
use App\Models\Obligation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks an obligation with a future due date as a vencer', function () {
    $obligation = Obligation::factory()->create([
        'status' => 'em_dia',
        'due_date' => now()->addDays(5),
    ]);

    app(RecalculateObligationStatusesAction::class)->handle();

    expect($obligation->refresh()->status)->toBe('a_vencer');
});

it('keeps an obligation due today as a vencer', function () {
    $obligation = Obligation::factory()->create([
        'status' => 'em_dia',
        'due_date' => now(),
    ]);

    app(RecalculateObligationStatusesAction::class)->handle();

    expect($obligation->refresh()->status)->toBe('a_vencer');
});

it('marks an obligation with a past due date as vencida', function () {
    $obligation = Obligation::factory()->create([
        'status' => 'a_vencer',
        'due_date' => now()->subDay(),
    ]);

    app(RecalculateObligationStatusesAction::class)->handle();

    expect($obligation->refresh()->status)->toBe('vencida');
});

it('does not alter obligations in manually decided statuses', function () {
    $protectedStatuses = ['concluida', 'em_analise', 'nao_aplicavel'];

    $obligations = collect($protectedStatuses)->map(fn (string $status): Obligation => Obligation::factory()->create([
        'status' => $status,
        'due_date' => now()->subDays(10),
    ]));

    app(RecalculateObligationStatusesAction::class)->handle();

    $obligations->each(fn (Obligation $obligation, int $index) => expect($obligation->refresh()->status)->toBe($protectedStatuses[$index]));
});

it('does not alter obligations without a due date', function () {
    $obligation = Obligation::factory()->create([
        'status' => 'em_dia',
        'due_date' => null,
    ]);

    app(RecalculateObligationStatusesAction::class)->handle();

    expect($obligation->refresh()->status)->toBe('em_dia');
});

it('reports accurate counts and leaves already-correct obligations unchanged', function () {
    Obligation::factory()->create(['status' => 'em_dia', 'due_date' => now()->addDays(3)]);
    Obligation::factory()->create(['status' => 'em_dia', 'due_date' => now()->subDays(3)]);
    Obligation::factory()->create(['status' => 'vencida', 'due_date' => now()->subDays(8)]);
    Obligation::factory()->create(['status' => 'concluida', 'due_date' => now()->subDays(8)]);
    Obligation::factory()->create(['status' => 'em_dia', 'due_date' => null]);

    $result = app(RecalculateObligationStatusesAction::class)->handle();

    expect($result)->toMatchArray([
        'analyzed' => 3,
        'marked_a_vencer' => 1,
        'marked_vencida' => 1,
        'unchanged' => 1,
    ]);
});

it('runs the artisan command successfully with a clear output', function () {
    Obligation::factory()->create(['status' => 'em_dia', 'due_date' => now()->addDays(2)]);
    Obligation::factory()->create(['status' => 'em_dia', 'due_date' => now()->subDays(2)]);

    $this->artisan('obligations:recalculate-statuses')
        ->expectsOutputToContain('Obrigações analisadas: 2')
        ->expectsOutputToContain("Atualizadas para 'A vencer': 1")
        ->expectsOutputToContain("Atualizadas para 'Vencida': 1")
        ->assertSuccessful();
});
