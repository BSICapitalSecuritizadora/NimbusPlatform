<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports the competences whose panel number changes under the consolidated reading', function () {
    $emission = Emission::factory()->create();
    $updated = Construction::factory()->create(['emission_id' => $emission->id]);
    $stale = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $stale)->create([
        'reference_month' => '2026-05-01',
        'stock_units' => 100,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $updated)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 20,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);

    $this->artisan('sales-boards:position-drift')
        ->expectsOutputToContain('Competências com número diferente: 1')
        ->expectsOutputToContain('Diferença total de unidades: +100')
        ->assertSuccessful();
});

it('does not create or change any sales board while diagnosing', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    $salesBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-05-01',
    ]);

    $before = SalesBoard::count();
    $updatedAt = $salesBoard->updated_at;

    $this->artisan('sales-boards:position-drift', ['--all' => true])->assertSuccessful();

    expect(SalesBoard::count())->toBe($before)
        ->and($salesBoard->fresh()->updated_at->toDateTimeString())->toBe($updatedAt->toDateTimeString());
});

it('reports no divergence for a single-construction emission', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-05-01',
    ]);

    $this->artisan('sales-boards:position-drift')
        ->expectsOutputToContain('Competências com número diferente: 0')
        ->assertSuccessful();
});

/**
 * O cenário do empreendimento trocado de Emissão: C2 tinha quadro de 07/2026 na
 * Emissão A, passou para a B e ganhou outro quadro de 07/2026 lá. A unique
 * aceita os dois, porque inclui a Emissão; o leitor por empreendimento enxerga
 * duas posições no mesmo mês, e a Emissão A continua contando C2.
 *
 * A troca é feita direto na tabela, como numa carga feita por fora -- o
 * diagnóstico existe justamente para achar o que passou ao largo das telas.
 *
 * @return array{a: Emission, b: Emission, moved: Construction, orphan: SalesBoard, duplicate: SalesBoard}
 */
function driftConstructionMovedBetweenEmissions(): array
{
    $emissionA = Emission::factory()->create(['status' => 'active']);
    $emissionB = Emission::factory()->create(['status' => 'active']);

    $staying = Construction::factory()->create(['emission_id' => $emissionA->id, 'development_name' => 'Residencial Fica']);
    $moved = Construction::factory()->create(['emission_id' => $emissionA->id, 'development_name' => 'Residencial Muda']);
    $other = Construction::factory()->create(['emission_id' => $emissionB->id, 'development_name' => 'Residencial Outro']);

    $board = fn (Emission $emission, Construction $construction, int $stock): SalesBoard => SalesBoard::factory()
        ->forEmissionAndConstruction($emission, $construction)
        ->create([
            'reference_month' => '2026-07-01',
            'stock_units' => $stock,
            'financed_units' => 0,
            'paid_units' => 0,
            'exchanged_units' => 0,
        ]);

    $board($emissionA, $staying, 10);
    $orphan = $board($emissionA, $moved, 20);
    $board($emissionB, $other, 30);

    Construction::query()->whereKey($moved->id)->update(['emission_id' => $emissionB->id]);

    $duplicate = $board($emissionB, $moved->fresh(), 5);

    return ['a' => $emissionA, 'b' => $emissionB, 'moved' => $moved, 'orphan' => $orphan, 'duplicate' => $duplicate];
}

it('reports boards recorded under an emission other than the construction current one', function () {
    $scenario = driftConstructionMovedBetweenEmissions();

    $this->artisan('sales-boards:position-drift')
        ->expectsOutputToContain('Quadros gravados sob uma Emissão diferente da atual do empreendimento')
        ->expectsOutputToContain('Residencial Muda (#'.$scenario['moved']->id.')')
        ->expectsOutputToContain('Quadros fora da Emissão do empreendimento: 1')
        ->assertSuccessful();
});

it('reports the same construction and competence recorded in more than one emission', function () {
    $scenario = driftConstructionMovedBetweenEmissions();

    $this->artisan('sales-boards:position-drift')
        ->expectsOutputToContain('Empreendimentos com a mesma competência registrada em mais de uma Emissão')
        ->expectsOutputToContain($scenario['orphan']->id.', '.$scenario['duplicate']->id)
        ->expectsOutputToContain('Empreendimentos com a mesma competência em mais de uma Emissão: 1')
        ->assertSuccessful();
});

it('does not blame first() alone for a divergence caused by a misplaced board', function () {
    $scenario = driftConstructionMovedBetweenEmissions();

    $this->artisan('sales-boards:position-drift', ['--emission' => [$scenario['a']->id]])
        ->expectsOutputToContain('Quadro fora da Emissão do empreendimento + Empreendimentos omitidos pelo first()')
        ->assertSuccessful();

    $this->artisan('sales-boards:position-drift', ['--emission' => [$scenario['b']->id]])
        ->expectsOutputToContain('Quadros fora da Emissão do empreendimento: 1')
        ->expectsOutputToContain('Empreendimentos com a mesma competência em mais de uma Emissão: 1')
        ->assertSuccessful();
});

it('keeps an emission unrelated to the misplaced board out of the integrity sections', function () {
    driftConstructionMovedBetweenEmissions();

    $unrelated = Emission::factory()->create(['status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $unrelated->id]);
    SalesBoard::factory()->forEmissionAndConstruction($unrelated, $construction)->create(['reference_month' => '2026-07-01']);

    $this->artisan('sales-boards:position-drift', ['--emission' => [$unrelated->id]])
        ->expectsOutputToContain('Quadros fora da Emissão do empreendimento: 0')
        ->expectsOutputToContain('Empreendimentos com a mesma competência em mais de uma Emissão: 0')
        ->doesntExpectOutputToContain('Quadros gravados sob uma Emissão diferente da atual do empreendimento')
        ->assertSuccessful();
});

it('confirms a base without misplaced or duplicated boards', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-05-01',
    ]);

    $this->artisan('sales-boards:position-drift')
        ->expectsOutputToContain('Quadros fora da Emissão do empreendimento: 0')
        ->expectsOutputToContain('Empreendimentos com a mesma competência em mais de uma Emissão: 0')
        ->expectsOutputToContain('Nenhum quadro fora da Emissão do empreendimento e nenhuma competência registrada em duas Emissões.')
        ->assertSuccessful();
});
