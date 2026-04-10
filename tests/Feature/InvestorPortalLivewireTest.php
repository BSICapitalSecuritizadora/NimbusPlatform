<?php

use App\Livewire\Investor\DocumentList;
use App\Livewire\Investor\InvestorDashboard;
use App\Livewire\Investor\InvestorEmissions;
use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the investor auth middleware on protected portal routes', function (string $routeName) {
    $this->get(route($routeName))
        ->assertRedirect();
})->with([
    'dashboard' => 'investor.dashboard',
    'emissions' => 'investor.emissions',
    'documents' => 'investor.documents',
]);

it('renders the branded investor login experience', function () {
    $this->get(route('investor.login'))
        ->assertOk()
        ->assertSee('Portal do Investidor')
        ->assertSee('Entrar no portal')
        ->assertSee('Acompanhe emissões, documentos e eventos do seu investimento');
});

it('renders the investor dashboard through a full-page livewire component', function () {
    $investor = Investor::factory()->create([
        'name' => 'Investidor Portal',
        'last_portal_seen_at' => now()->subDay(),
    ]);

    $document = Document::factory()->published()->create([
        'title' => 'Informe Mensal',
    ]);
    $document->investors()->attach($investor->id);

    $this->actingAs($investor, 'investor')
        ->get(route('investor.dashboard'))
        ->assertOk()
        ->assertSeeLivewire(InvestorDashboard::class)
        ->assertSee('Bem-vindo, Investidor Portal')
        ->assertSee('Novos documentos disponíveis')
        ->assertSee('Portal do investidor');

    expect($investor->fresh()->last_portal_seen_at)->not->toBeNull();
});

it('renders the investor emissions page through a full-page livewire component', function () {
    $investor = Investor::factory()->create();
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Atlantico',
        'type' => 'CRI',
    ]);

    $investor->emissions()->attach($emission->id);

    $this->actingAs($investor, 'investor')
        ->get(route('investor.emissions'))
        ->assertOk()
        ->assertSeeLivewire(InvestorEmissions::class)
        ->assertSee('Minhas emissões')
        ->assertSee('CRI Atlantico')
        ->assertSee('Ativa');
});

it('renders the investor documents page through a full-page livewire component', function () {
    $investor = Investor::factory()->create([
        'last_portal_seen_at' => now()->subDays(2),
    ]);

    $emission = Emission::factory()->active()->create([
        'name' => 'Debenture Verde',
    ]);

    $investor->emissions()->attach($emission->id);

    $document = Document::factory()->published()->create([
        'title' => 'Relatorio Gerencial',
    ]);

    $document->emissions()->attach($emission->id);

    $this->actingAs($investor, 'investor')
        ->get(route('investor.documents'))
        ->assertOk()
        ->assertSeeLivewire(DocumentList::class)
        ->assertSee('Meus documentos')
        ->assertSee('Relatorio Gerencial')
        ->assertSee('Novo');

    expect($investor->fresh()->last_portal_seen_at)->not->toBeNull();
});
