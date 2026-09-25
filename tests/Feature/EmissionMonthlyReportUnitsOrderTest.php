<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A tabela "Posição por empreendimento" e as listas de transportados e
 * faltantes do painel de unidades seguem a ordem da Evolução da Obra: as duas
 * vêm do banco por `development_name`. Com acento e caixa a ordem depende da
 * collation (no MySQL "Ágata" vem antes de "Residencial"; byte a byte, depois),
 * por isso o arquivo roda também no MySQL.
 */
pest()->group('parity');

it('lists the units coverage of the PDF in the order of the construction progress section', function () {
    $emission = Emission::factory()->create();

    $current = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Beta']);
    $lowercase = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'residencial alfa']);
    $accented = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Ágata Parque']);
    $secondStage = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => '2ª Etapa']);
    $firstStage = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => '1ª Etapa']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Zeta Torre']);
    Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Ébano Torre']);

    $boards = [
        [$current, '2026-07-01'],
        [$secondStage, '2026-07-01'],
        [$firstStage, '2026-07-01'],
        [$lowercase, '2026-06-01'],
        [$accented, '2026-06-01'],
    ];

    foreach ($boards as [$construction, $month]) {
        SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => $month]);
    }

    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-07-01'));

    $progressOrder = array_column($data['construction']['constructions'], 'name');
    $coverage = $data['units']['coverage_summary'];
    $inProgressOrder = fn (array $names): array => array_values(array_intersect($progressOrder, $names));

    expect($progressOrder)->toHaveCount(7)
        ->and(array_column($coverage['constructions'], 'name'))->toBe($progressOrder)
        ->and(array_column($coverage['carried_forward'], 'name'))
        ->toBe($inProgressOrder(['residencial alfa', 'Ágata Parque']))
        ->and($coverage['missing'])->toBe($inProgressOrder(['Zeta Torre', 'Ébano Torre']));

    $html = view('pdf.emission-monthly-report', $data)->render();
    $unitsTable = str($html)->after('Posição por empreendimento')->before('Histórico de Unidades')->toString();

    foreach ($progressOrder as $name) {
        expect($unitsTable)->toContain(e($name));
    }

    $renderedOrder = collect($progressOrder)
        ->sortBy(fn (string $name): int => (int) strpos($unitsTable, e($name)))
        ->values()
        ->all();

    expect($renderedOrder)->toBe($progressOrder);
});
