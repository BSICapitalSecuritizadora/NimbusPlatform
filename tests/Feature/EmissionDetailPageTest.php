<?php

use App\Models\Document;
use App\Models\Emission;
use App\Models\IntegralizationHistory;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the branded public emission detail experience', function () {
    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Horizonte',
        'type' => 'CRI',
        'if_code' => 'IF-HORIZONTE-01',
        'issuer' => 'BSI Capital',
        'remuneration_indexer' => 'IPCA',
        'remuneration_rate' => 7.50,
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
        ->assertSee('IPCA + 7,50% a.a.')
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
        'issued_quantity' => 20000,
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

it('shows the emission progress timeline with issue date, maturity date and status', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-06'));

    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Linha do Tempo',
        'type' => 'CRI',
        'if_code' => 'IF-TIMELINE-01',
        'is_public' => true,
        'issue_date' => '2026-01-01',
        'maturity_date' => '2026-01-11',
        'integralization_status' => 'Aguardando Integração',
    ]);

    $response = $this->get(route('site.emissions.show', $emission->if_code));

    $response
        ->assertOk()
        ->assertSee('Data de Emissão')
        ->assertSee('01/01/2026')
        ->assertSee('Data de Vencimento')
        ->assertSee('11/01/2026')
        ->assertSee('Aguardando Integração')
        ->assertSee('50% do prazo decorrido')
        ->assertSee('5 dias desde a emissão')
        ->assertSee('5 dias até o vencimento')
        ->assertSee('aria-valuenow="50"', false);

    $content = mb_strtolower($response->getContent());

    expect(substr_count($content, mb_strtolower('Data de Emissão')))->toBe(1)
        ->and(substr_count($content, mb_strtolower('Data de Vencimento')))->toBe(1);

    $this->travelBack();
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
