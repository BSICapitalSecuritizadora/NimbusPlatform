<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the configurable viability simulator on the cri page', function () {
    $this->get(route('site.imobiliario.cri'))
        ->assertSuccessful()
        ->assertSee('Prazo (meses)')
        ->assertSee('Indexador')
        ->assertSee('Percentual (%)');
});

it('updates the displayed term and remuneration when the simulator fields change', function () {
    Livewire::test('imobiliario.viability-simulator')
        ->set('term', '120')
        ->set('indexer', 'IPCA')
        ->set('rate', '100')
        ->assertSet('term', '120')
        ->assertSet('indexer', 'IPCA')
        ->assertSet('rate', '100')
        ->assertSee('120 meses')
        ->assertSee('IPCA + 100,00%');
});

it('recalculates the capture potential when term indexer and rate change', function () {
    Livewire::test('imobiliario.viability-simulator')
        ->set('vgv', '25.000.000')
        ->assertSet('potential', 16250000.0)
        ->assertSee('R$ 16.250.000,00')
        ->set('term', '120')
        ->assertSet('potential', 17250000.0)
        ->assertSee('R$ 17.250.000,00')
        ->set('indexer', 'IPCA')
        ->assertSet('potential', 17000000.0)
        ->assertSee('R$ 17.000.000,00')
        ->set('rate', '7.5')
        ->assertSet('potential', 16700000.0)
        ->assertSee('R$ 16.700.000,00');
});

it('enforces the configured limits for term and remuneration rate', function () {
    Livewire::test('imobiliario.viability-simulator')
        ->set('term', '999')
        ->assertSet('term', '120')
        ->set('term', '0')
        ->assertSet('term', '1')
        ->set('rate', '999')
        ->assertSet('rate', '100')
        ->set('rate', '-5')
        ->assertSet('rate', '0');
});
