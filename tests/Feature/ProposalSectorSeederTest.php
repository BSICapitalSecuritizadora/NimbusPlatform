<?php

use App\Models\ProposalSector;
use Database\Seeders\ProposalSectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provisions the official proposal sectors through the migrations alone', function () {
    expect(ProposalSector::query()->active()->orderBy('name')->pluck('name')->all())
        ->toBe(['Agronegócio', 'Imobiliário', 'Outros']);
});

it('seeds the proposal sectors offered on the public form', function () {
    $this->seed(ProposalSectorSeeder::class);

    expect(ProposalSector::orderBy('name')->pluck('name')->all())
        ->toBe(['Agronegócio', 'Imobiliário', 'Outros']);
});

it('does not duplicate sectors when seeded more than once', function () {
    $this->seed(ProposalSectorSeeder::class);
    $this->seed(ProposalSectorSeeder::class);

    expect(ProposalSector::count())->toBe(3);
});

it('keeps a manually deactivated sector untouched when provisioned again', function () {
    ProposalSector::query()->where('name', 'Outros')->update(['is_active' => false]);

    $this->seed(ProposalSectorSeeder::class);

    expect(ProposalSector::query()->where('name', 'Outros')->firstOrFail()->is_active)->toBeFalse()
        ->and(ProposalSector::query()->count())->toBe(3);
});
