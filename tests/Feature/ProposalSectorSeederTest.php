<?php

use App\Models\ProposalSector;
use Database\Seeders\ProposalSectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
