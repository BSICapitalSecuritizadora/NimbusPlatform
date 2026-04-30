<?php

use App\Livewire\Investor\DocumentList;
use App\Livewire\Investor\InvestorDashboard;
use App\Livewire\Investor\InvestorEmissions;
use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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
        ->assertSee('images/logo-mob.png', false)
        ->assertSee('Voltar ao site')
        ->assertSee('Canal institucional')
        ->assertSee('bsi-investor-credential-field', false)
        ->assertDontSee('bg-zinc-50/70', false)
        ->assertDontSee('Acesso institucional')
        ->assertDontSee('Autenticação')
        ->assertDontSee('Entrar no portal')
        ->assertDontSee('Acesse documentos, emissões e comunicados vinculados ao seu relacionamento com investidores.')
        ->assertDontSee('Consulta desenhada para leitura objetiva');
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
        ->assertSee('Em Operação');
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

it('loads pagination translations in portuguese', function () {
    expect(__('pagination.previous'))->toBe('Anterior');
    expect(__('pagination.next'))->toBe('Próxima');
    expect(__('Showing'))->toBe('Exibindo');
    expect(__('results'))->toBe('resultados');
});

it('can reset investor document filters without leaving the page', function () {
    $investor = Investor::factory()->create();
    $emission = Emission::factory()->active()->create([
        'name' => 'Debênture Azul',
    ]);

    $investor->emissions()->attach($emission->id);

    $this->actingAs($investor, 'investor');

    Livewire::test(DocumentList::class)
        ->set('search', 'Relatório')
        ->set('category', 'fatos_relevantes')
        ->set('emissionId', (string) $emission->id)
        ->set('dateFrom', '2024-01-01')
        ->set('dateTo', '2024-12-31')
        ->set('onlyNew', true)
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('category', '')
        ->assertSet('emissionId', '')
        ->assertSet('dateFrom', '')
        ->assertSet('dateTo', '')
        ->assertSet('onlyNew', false);
});
