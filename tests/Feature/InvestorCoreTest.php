<?php

use App\Models\Investor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores the finalized investor defaults', function () {
    $investor = Investor::query()->create([
        'name' => 'Investidor Teste',
        'email' => 'investidor@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $investor->refresh();

    expect($investor->is_active)->toBeTrue()
        ->and($investor->last_login_at)->toBeNull()
        ->and($investor->last_portal_seen_at)->toBeNull()
        ->and($investor->notes)->toBeNull();
});

it('enforces unique investor emails', function () {
    Investor::factory()->create(['email' => 'duplicado@example.com']);

    expect(fn () => Investor::factory()->create(['email' => 'duplicado@example.com']))
        ->toThrow(QueryException::class);
});

it('supports the finalized investor fields and casts', function () {
    $investor = Investor::query()->create([
        'name' => 'Maria Investidora',
        'email' => 'maria@example.com',
        'password' => bcrypt('secret123'),
        'phone' => '(11) 3333-4444',
        'mobile' => '(11) 98888-7777',
        'cpf' => '123.456.789-00',
        'rg' => '12.345.678-9',
        'is_active' => false,
        'last_login_at' => '2026-03-16 08:00:00',
        'last_portal_seen_at' => '2026-03-16 09:15:00',
        'notes' => 'Conta validada para o core v1.0.',
    ]);

    $investor->refresh();

    expect($investor->last_login_at?->format('Y-m-d H:i:s'))->toBe('2026-03-16 08:00:00')
        ->and($investor->last_portal_seen_at?->format('Y-m-d H:i:s'))->toBe('2026-03-16 09:15:00')
        ->and($investor->is_active)->toBeFalse()
        ->and($investor->only([
            'name',
            'email',
            'phone',
            'mobile',
            'cpf',
            'rg',
            'notes',
        ]))->toMatchArray([
            'name' => 'Maria Investidora',
            'email' => 'maria@example.com',
            'phone' => '(11) 3333-4444',
            'mobile' => '(11) 98888-7777',
            'cpf' => '123.456.789-00',
            'rg' => '12.345.678-9',
            'notes' => 'Conta validada para o core v1.0.',
        ]);
});

it('generates factory data in the expected investor formats', function () {
    $investor = Investor::factory()->make();

    expect($investor->phone)->toMatch('/^\(\d{2}\)\s\d{4}-\d{4}$/')
        ->and($investor->mobile)->toMatch('/^\(\d{2}\)\s\d{5}-\d{4}$/')
        ->and($investor->cpf)->toMatch('/^\d{3}\.\d{3}\.\d{3}-\d{2}$/')
        ->and($investor->rg)->toMatch('/^\d{2}\.\d{3}\.\d{3}-\d$/');
});
