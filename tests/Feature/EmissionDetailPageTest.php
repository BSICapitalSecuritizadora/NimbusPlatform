<?php

use App\Models\Document;
use App\Models\Emission;
use App\Models\IntegralizationHistory;
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
        ->assertSee('Documentos da operação')
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
        ->assertSee('Exibindo os 5 documentos mais recentes por padrão');
});
