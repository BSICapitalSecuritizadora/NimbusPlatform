<?php

use App\Models\Document;
use App\Models\Emission;
use App\Models\IntegralizationHistory;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the branded public emission detail experience', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Horizonte',
        'type' => 'CRI',
        'if_code' => 'IF-HORIZONTE-01',
        'issuer' => 'BSI Capital',
        'is_public' => true,
    ]);

    $document = Document::factory()->public()->create([
        'title' => 'Termo de Securitizacao',
        'category' => 'documentos_operacao',
    ]);

    $emission->documents()->attach($document->id);

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertSee('Detalhe da emissão')
        ->assertSee('Resumo operacional')
        ->assertSee('Fluxo de pagamentos e acompanhamento')
        ->assertSee('Repositório de Documentos e Atos da Operação')
        ->assertSee('.emission-detail-tabs.nav-pills .nav-link.active', false)
        ->assertSee('border-color: color-mix(in srgb, var(--brand) 12%, var(--border));', false)
        ->assertSee('Termo de Securitizacao');
});

it('sums all integralization history entries while showing only the latest five records', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Integralizacao',
        'type' => 'CRI',
        'if_code' => 'IF-INTEGRAL-01',
        'is_public' => true,
    ]);

    collect([
        ['date' => '2024-12-12', 'quantity' => 235],
        ['date' => '2024-11-29', 'quantity' => 1364],
        ['date' => '2024-11-12', 'quantity' => 2],
        ['date' => '2024-10-28', 'quantity' => 1705],
        ['date' => '2024-10-11', 'quantity' => 700],
        ['date' => '2024-09-27', 'quantity' => 9988],
    ])->each(function (array $history) use ($emission): void {
        IntegralizationHistory::query()->create([
            'emission_id' => $emission->id,
            'date' => $history['date'],
            'quantity' => $history['quantity'],
        ]);
    });

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertSee('13.994')
        ->assertSee('12/12/2024')
        ->assertSee('29/11/2024')
        ->assertSee('11/10/2024')
        ->assertDontSee('27/09/2024');
});

it('explains that only the latest five documents are highlighted by default', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Documentos',
        'type' => 'CRI',
        'if_code' => 'IF-DOCS-01',
        'is_public' => true,
    ]);

    Document::factory()
        ->count(6)
        ->public()
        ->create()
        ->each(function (Document $document) use ($emission): void {
            $emission->documents()->attach($document->id);
        });

    $this->get(route('site.emissions.show', $emission->if_code))
        ->assertOk()
        ->assertSee('Exibindo os documentos mais recentes por padrão');
});

it('renders the payment flow with the legacy chart model', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Fluxo',
        'type' => 'CRI',
        'if_code' => 'IF-FLUXO-01',
        'is_public' => true,
    ]);

    Payment::query()->create([
        'emission_id' => $emission->id,
        'payment_date' => '2025-01-15',
        'premium_value' => 1000,
        'interest_value' => 250,
        'amortization_value' => 3000,
        'extra_amortization_value' => 500,
    ]);

    $response = $this->get(route('site.emissions.show', $emission->if_code));

    $response
        ->assertOk()
        ->assertSee('paymentsChart')
        ->assertSee('cdn.jsdelivr.net/npm/chart.js')
        ->assertSee('ticks: { display: false }', false);

    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);

    $nonce = $matches[1] ?? null;

    expect($nonce)->not->toBeNull();
    expect(substr_count($response->getContent(), 'nonce="'.$nonce.'"'))->toBeGreaterThanOrEqual(2);
});
