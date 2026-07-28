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

it('loads flux assets for the viability simulator on the CRI page', function () {
    $this->get(route('site.imobiliario.cri'))
        ->assertSuccessful()
        ->assertSee('/flux/flux', false)
        ->assertSee('/livewire-', false);
});

it('renders the securitization flow with four cards and no trailing arrow', function () {
    $response = $this->get(route('site.imobiliario.cri'));

    $response->assertSuccessful()
        ->assertSee('Como funciona o fluxo de Securitização')
        ->assertSeeInOrder(['Originação', 'Estruturação', 'Distribuição', 'Monitoramento']);

    /**
     * A seta era gerada por .flow-container .flow-item:not(:last-child)::after.
     * Como .flow-item não tem contexto de posicionamento, os três pseudoelementos
     * ancoravam em .flow-container e empilhavam à direita do último card.
     */
    $response->assertDontSee('.flow-container .flow-item', false)
        ->assertDontSee('right: -10px;', false);

    expect(substr_count($response->getContent(), 'class="col-md-3 flow-item"'))->toBe(4);
});

it('keeps the call-to-action and mega menu arrows untouched on the CRI page', function () {
    $response = $this->get(route('site.imobiliario.cri'));

    $response->assertSuccessful()
        ->assertSee('Submeter projeto para avaliação →', false)
        ->assertSee('.mega-link::after', false);
});
