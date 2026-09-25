<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
        ->expectsOutputToContain('Mais de um quadro para o mesmo empreendimento na mesma competência')
        ->expectsOutputToContain($scenario['orphan']->id.', '.$scenario['duplicate']->id)
        ->expectsOutputToContain('Empreendimentos com mais de um quadro na mesma competência: 1')
        ->assertSuccessful();
});

it('does not blame first() alone for a divergence caused by a misplaced board', function () {
    $scenario = driftConstructionMovedBetweenEmissions();

    $this->artisan('sales-boards:position-drift', ['--emission' => [$scenario['a']->id]])
        ->expectsOutputToContain('Quadro fora da Emissão do empreendimento + Empreendimentos omitidos pelo first()')
        ->assertSuccessful();

    $this->artisan('sales-boards:position-drift', ['--emission' => [$scenario['b']->id]])
        ->expectsOutputToContain('Quadros fora da Emissão do empreendimento: 1')
        ->expectsOutputToContain('Empreendimentos com mais de um quadro na mesma competência: 1')
        ->assertSuccessful();
});

it('keeps an emission unrelated to the misplaced board out of the integrity sections', function () {
    driftConstructionMovedBetweenEmissions();

    $unrelated = Emission::factory()->create(['status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $unrelated->id]);
    SalesBoard::factory()->forEmissionAndConstruction($unrelated, $construction)->create(['reference_month' => '2026-07-01']);

    $this->artisan('sales-boards:position-drift', ['--emission' => [$unrelated->id]])
        ->expectsOutputToContain('Quadros fora da Emissão do empreendimento: 0')
        ->expectsOutputToContain('Empreendimentos com mais de um quadro na mesma competência: 0')
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
        ->expectsOutputToContain('Empreendimentos com mais de um quadro na mesma competência: 0')
        ->expectsOutputToContain('Nenhum quadro fora da Emissão do empreendimento e nenhuma competência com mais de um quadro.')
        ->assertSuccessful();
});

it('reports the misplaced board when filtering by the construction current emission without boards of its own', function () {
    $emissionA = Emission::factory()->create(['status' => 'active']);
    $emissionB = Emission::factory()->create(['status' => 'active']);
    $moved = Construction::factory()->create(['emission_id' => $emissionA->id, 'development_name' => 'Residencial Muda']);

    $orphan = SalesBoard::factory()->forEmissionAndConstruction($emissionA, $moved)->create([
        'reference_month' => '2026-07-01',
    ]);

    // O empreendimento vai para a B, que não tem nenhum quadro próprio.
    Construction::query()->whereKey($moved->id)->update(['emission_id' => $emissionB->id]);

    $this->artisan('sales-boards:position-drift', ['--emission' => [$emissionB->id]])
        ->doesntExpectOutputToContain('Nenhuma emissão com quadro de vendas encontrada nesta base.')
        ->expectsOutputToContain('Quadros gravados sob uma Emissão diferente da atual do empreendimento')
        ->expectsOutputToContain('Residencial Muda (#'.$moved->id.')')
        ->expectsOutputToContain('Emissões analisadas: 0')
        ->expectsOutputToContain('Quadros fora da Emissão do empreendimento: 1')
        ->assertSuccessful();

    expect($orphan->fresh()->emission_id)->toBe($emissionA->id);
});

it('still reports an empty base when the filtered emission has no board on either side', function () {
    $emission = Emission::factory()->create(['status' => 'active']);
    Construction::factory()->create(['emission_id' => $emission->id]);

    $this->artisan('sales-boards:position-drift', ['--emission' => [$emission->id]])
        ->expectsOutputToContain('Nenhuma emissão com quadro de vendas encontrada nesta base.')
        ->assertSuccessful();
});

it('groups the duplicated competences by month even when a board was stored with another day', function () {
    $scenario = driftConstructionMovedBetweenEmissions();

    // Carga feita por fora do model: o dia não foi normalizado para 01.
    DB::table('sales_boards')->where('id', $scenario['orphan']->id)->update(['reference_month' => '2026-07-15']);

    $this->artisan('sales-boards:position-drift')
        ->expectsOutputToContain('Mais de um quadro para o mesmo empreendimento na mesma competência')
        ->expectsOutputToContain($scenario['orphan']->id.', '.$scenario['duplicate']->id)
        ->expectsOutputToContain('Empreendimentos com mais de um quadro na mesma competência: 1')
        ->assertSuccessful();
});

it('reports two boards of the same month inside one emission when a day escaped the normalization', function () {
    $emission = Emission::factory()->create(['status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Repetido']);

    $first = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-07-01']);
    $second = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-08-01']);

    DB::table('sales_boards')->where('id', $second->id)->update(['reference_month' => '2026-07-20']);

    $this->artisan('sales-boards:position-drift')
        ->expectsTable(
            ['Empreendimento', 'Competência', 'Emissão do empreendimento', 'Emissões dos quadros', 'Quadros'],
            [['Residencial Repetido (#'.$construction->id.')', '07/2026', $emission->id, (string) $emission->id, $first->id.', '.$second->id]],
        )
        ->expectsOutputToContain('Quadros fora da Emissão do empreendimento: 0')
        ->expectsOutputToContain('Empreendimentos com mais de um quadro na mesma competência: 1')
        ->assertSuccessful();
});
