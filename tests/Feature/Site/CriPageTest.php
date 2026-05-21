<?php

use App\Models\Emission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the CRI page successfully', function () {
    $this->get(route('site.imobiliario.cri'))
        ->assertSuccessful()
        ->assertViewIs('site.imobiliario.cri');
});

it('displays public CRI emissions on the CRI page', function () {
    Emission::factory()->active()->create([
        'type' => 'CRI',
        'is_public' => true,
        'if_code' => 'IF-CRI-001',
        'name' => 'CRI Horizonte Imobiliário',
    ]);

    Emission::factory()->active()->create([
        'type' => 'CRA',
        'is_public' => true,
        'if_code' => 'IF-CRA-001',
        'name' => 'CRA Agro Fundos',
    ]);

    $response = $this->get(route('site.imobiliario.cri'));

    $response->assertSuccessful()
        ->assertViewHas('featuredEmissions', fn ($emissions) => $emissions->contains('if_code', 'IF-CRI-001'))
        ->assertViewHas('featuredEmissions', fn ($emissions) => ! $emissions->contains('if_code', 'IF-CRA-001'));
});

it('does not expose private CRI emissions on the CRI page', function () {
    Emission::factory()->active()->create([
        'type' => 'CRI',
        'is_public' => false,
        'if_code' => 'IF-CRI-PRIVATE',
        'name' => 'CRI Privado',
    ]);

    $response = $this->get(route('site.imobiliario.cri'));

    $response->assertSuccessful()
        ->assertViewHas('featuredEmissions', fn ($emissions) => ! $emissions->contains('if_code', 'IF-CRI-PRIVATE'));
});

it('limits featured emissions to three on the CRI page', function () {
    Emission::factory()->active()->count(5)->create([
        'type' => 'CRI',
        'is_public' => true,
    ]);

    $response = $this->get(route('site.imobiliario.cri'));

    $response->assertSuccessful()
        ->assertViewHas('featuredEmissions', fn ($emissions) => $emissions->count() <= 3);
});
