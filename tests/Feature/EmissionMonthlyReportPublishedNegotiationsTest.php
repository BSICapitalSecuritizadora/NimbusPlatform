<?php

use App\Enums\ContractStatus;
use App\Enums\SalesBoardSource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Services\Reports\EmissionMonthlyReportService;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;

/**
 * As negociações do relatório mensal numa competência publicada vêm dos
 * movimentos congelados da publicação vigente: o PDF dela não muda a cada
 * download, os fatos de competências anteriores saem rotulados na competência em
 * que foram publicados, e o contrato de permuta nunca é venda nem distrato.
 */
uses(RefreshDatabase::class);

/**
 * Um empreendimento com uma venda em julho e julho publicado, na emissão que
 * lê as negociações dos contratos.
 *
 * @return array{emission: Emission, construction: Construction, units: list<ConstructionUnit>, july: SalesBoardCycle}
 */
function publishedNegotiationsScenario(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(4);
    $construction->emission->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS])->save();

    ExtemporaneousFixture::sale($units[0], '2026-07-08');

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    ExtemporaneousFixture::publish($july);

    return ['emission' => $construction->emission->fresh(), 'construction' => $construction, 'units' => $units, 'july' => $july->fresh()];
}

/**
 * @return array<string, mixed>
 */
function negotiationsReport(Emission $emission, string $month): array
{
    return app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse($month));
}

/**
 * @param  array<string, mixed>  $negotiations
 */
function negotiationsRowValue(array $negotiations, string $label): ?string
{
    return collect($negotiations['rows'])->firstWhere('label', $label)['value'] ?? null;
}

it('regenerates the published July the same after a late fact, and lists the late sale in August', function () {
    $scenario = publishedNegotiationsScenario();
    $before = negotiationsReport($scenario['emission'], '2026-07-01');

    // A venda de julho lançada depois da publicação.
    $late = ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18');

    $after = negotiationsReport($scenario['emission'], '2026-07-01');

    expect($after['negotiations'])->toEqual($before['negotiations'])
        ->and($after['negotiations']['sales_count'])->toBe(1)
        ->and($after['negotiations']['vendas'][0]['situation'])->toBe('Publicada');

    ExtemporaneousFixture::publish(ExtemporaneousFixture::generateAugust($scenario['construction']));

    $august = negotiationsReport($scenario['emission'], '2026-08-01');
    $lateRow = collect($august['negotiations']['vendas'])->sole();
    $html = view('pdf.emission-monthly-report', $august)->render();

    expect(negotiationsRowValue($august['negotiations'], 'Vendas (mês)'))->toBe('0')
        ->and(negotiationsRowValue($august['negotiations'], 'Vendas de competências anteriores'))->toBe('1')
        ->and(negotiationsRowValue($august['negotiations'], 'Saldo líquido de unidades'))->toBe('1')
        ->and($lateRow['code'])->toBe((string) $late->code)
        ->and($lateRow['date_formatted'])->toBe('18/07/2026')
        ->and($lateRow['competence'])->toBe('07/2026')
        ->and($lateRow['situation'])->toBe('Extemporânea (07/2026)')
        ->and($html)->toContain('Competência do fato')
        ->and($html)->toContain('Extemporânea (07/2026)');
});

it('lists the settlements of a published competence', function () {
    $scenario = publishedNegotiationsScenario();
    $cash = ExtemporaneousFixture::cashSale($scenario['units'][2], '2026-08-10');

    ExtemporaneousFixture::publish(ExtemporaneousFixture::generateAugust($scenario['construction']));

    $august = negotiationsReport($scenario['emission'], '2026-08-01');

    expect(negotiationsRowValue($august['negotiations'], 'Quitações (mês)'))->toBe('1')
        ->and(collect($august['negotiations']['quitacoes'])->sole()['code'])->toBe((string) $cash->code)
        ->and(view('pdf.emission-monthly-report', $august)->render())->toContain('Quitações no período');
});

it('counts the history by the competence of publication, with the footnote', function () {
    $scenario = publishedNegotiationsScenario();
    ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18');

    ExtemporaneousFixture::publish(ExtemporaneousFixture::generateAugust($scenario['construction']));

    $august = negotiationsReport($scenario['emission'], '2026-08-01');
    $history = collect($august['negotiations_history']['rows'])->keyBy('competencia');

    expect($august['negotiations_history']['has_data'])->toBeTrue()
        ->and($august['negotiations_history']['includes_late'])->toBeTrue()
        // A venda de 18/07 conta em agosto, a competência que a publicou.
        ->and($history['07/2026']['sales'])->toBe('1')
        ->and($history['08/2026']['sales'])->toBe('1')
        ->and(view('pdf.emission-monthly-report', $august)->render())
        ->toContain('Inclui fatos de competências anteriores publicados no mês');
});

it('never counts an exchange contract as a sale or a distrato, frozen or live', function () {
    // Caminho ao vivo: sem ciclo publicado.
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $construction->emission->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS])->save();

    ExtemporaneousFixture::sale($units[0], '2026-07-10');
    $exchangeSale = DerivationFixture::contract($units[1], '2026-07-05', '300000.00', status: ContractStatus::Exchanged);
    ConstructionUnitExchange::factory()->forUnit($units[1])->forContract($exchangeSale)->effectiveFrom('2026-07-05')->worth('300000.00')->create();
    $endedExchange = DerivationFixture::contract($units[2], '2026-02-05', '300000.00', cancellationDate: '2026-07-20', status: ContractStatus::Cancelled);
    ConstructionUnitExchange::factory()->forUnit($units[2])->forContract($endedExchange)->effectiveFrom('2026-02-05')->endedOn('2026-07-20')->worth('300000.00')->create();

    $live = negotiationsReport($construction->emission, '2026-07-01')['negotiations'];

    expect($live['sales_count'])->toBe(1)
        ->and($live['cancellations_count'])->toBe(0)
        ->and(collect($live['vendas'])->pluck('code')->all())->not->toContain((string) $exchangeSale->code);

    // Caminho congelado: julho publicado com o contrato de permuta na unidade.
    [$published, $publishedUnits] = CycleFixture::readyConstruction(2);
    $published->emission->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS])->save();

    ExtemporaneousFixture::sale($publishedUnits[0], '2026-07-10');
    $frozenExchange = DerivationFixture::contract($publishedUnits[1], '2026-07-05', '300000.00', status: ContractStatus::Exchanged);
    DerivationFixture::installment($frozenExchange, '001', '2026-07-05', '300000.00', '2026-07-05', '300000.00');
    ConstructionUnitExchange::factory()->forUnit($publishedUnits[1])->forContract($frozenExchange)->effectiveFrom('2026-07-05')->worth('300000.00')->create();

    ExtemporaneousFixture::publish(CycleFixture::generate($published, '2026-07-01')->cycle);

    $frozen = negotiationsReport($published->emission, '2026-07-01')['negotiations'];

    expect($frozen['sources'])->toBe([$published->development_name => 'publicada'])
        ->and($frozen['sales_count'])->toBe(1)
        ->and($frozen['cancellations_count'])->toBe(0)
        ->and(collect($frozen['vendas'])->pluck('code')->all())->not->toContain((string) $frozenExchange->code);
});

it('keeps reading the contracts as a preview where nothing was published, and leaves a legacy emission alone', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);
    $construction->emission->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS])->save();
    ExtemporaneousFixture::sale($units[0], '2026-07-10');

    $preview = negotiationsReport($construction->emission, '2026-07-01')['negotiations'];

    expect($preview['sales_count'])->toBe(1)
        ->and($preview['vendas'][0]['situation'])->toBe('Prévia')
        ->and($preview['note'])->toBe('Fonte: contratos da emissão — Data da Venda e Data do Distrato dentro da competência. Prévia sujeita a alteração: a competência ainda não tem Quadro de Vendas publicado.')
        ->and(negotiationsRowValue($preview, 'Vendas de competências anteriores'))->toBeNull();

    $legacy = publishedNegotiationsScenario();
    $legacy['emission']->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_LEGACY])->save();

    $manual = negotiationsReport($legacy['emission'], '2026-07-01')['negotiations'];

    expect($manual['source'])->toBe('legacy')
        ->and($manual)->not->toHaveKey('sources')
        ->and($manual['has_data'])->toBeFalse();
});

/**
 * Maio publicado; junho cancelado pela Gestão, com uma venda e um distrato;
 * julho publicado, absorvendo os fatos de junho. Na emissão que lê as
 * negociações dos contratos.
 *
 * @return array{emission: Emission, construction: Construction, units: list<ConstructionUnit>, mayCode: string, juneSale: Contract, juneCancellation: Contract, julySale: Contract}
 */
function absorbedJuneScenario(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(5);
    $construction->emission->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS])->save();

    $may = ExtemporaneousFixture::sale($units[0], '2026-05-10');
    $cancelledInJune = ExtemporaneousFixture::sale($units[1], '2026-03-05');
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-05-01')->cycle);

    $juneSale = ExtemporaneousFixture::sale($units[2], '2026-06-20');
    $cancelledInJune->forceFill(['cancellation_date' => '2026-06-18', 'status' => ContractStatus::Cancelled])->save();

    $june = CycleFixture::generate($construction, '2026-06-01')->cycle;
    app(SalesBoardCycleCancellationService::class)->cancel($june, GovernanceFixture::approver(), 'Competência cancelada no piloto.');

    $julySale = ExtemporaneousFixture::sale($units[3], '2026-07-08');
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-07-01')->cycle);

    return [
        'emission' => $construction->emission->fresh(),
        'construction' => $construction,
        'units' => $units,
        'mayCode' => (string) $may->code,
        'juneSale' => $juneSale,
        'juneCancellation' => $cancelledInJune,
        'julySale' => $julySale,
    ];
}

it('counts the facts of a cancelled competence once, in the competence that published them', function () {
    $scenario = absorbedJuneScenario();

    $july = negotiationsReport($scenario['emission'], '2026-07-01');
    $history = collect($july['negotiations_history']['rows'])->keyBy('competencia');

    expect($history['05/2026']['sales'])->toBe('1')
        ->and($history['06/2026']['sales'])->toBe('0')
        ->and($history['06/2026']['cancellations'])->toBe('0')
        ->and($history['07/2026']['sales'])->toBe('2')
        ->and($history['07/2026']['cancellations'])->toBe('1')
        ->and(collect($july['negotiations']['vendas'])->firstWhere('code', (string) $scenario['juneSale']->code)['situation'])
        ->toBe('De competência sem posição (06/2026)');

    $june = negotiationsReport($scenario['emission'], '2026-06-01');
    $html = view('pdf.emission-monthly-report', $june)->render();

    expect($june['negotiations']['has_data'])->toBeFalse()
        ->and($june['negotiations']['sources'])->toBe([$scenario['construction']->development_name => 'absorvida'])
        ->and($june['negotiations']['absorbed'])->toBe([$scenario['construction']->development_name => '07/2026'])
        ->and($june['negotiations']['note'])->toStartWith('Competência cancelada pela Gestão: os fatos deste mês foram publicados em 07/2026')
        ->and($html)->toContain('os fatos deste mês foram publicados em 07/2026')
        ->and($html)->not->toContain('Prévia sujeita a alteração: a competência ainda não tem Quadro de Vendas publicado');

    $consolidated = app(EmissionMonthlyReportService::class)->buildConsolidated(
        $scenario['emission'],
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-07-01'),
    );

    $listed = collect($consolidated['months'] ?? [])
        ->flatMap(fn (array $month): array => collect($month['data']['negotiations']['vendas'] ?? [])->pluck('code')->all())
        ->filter(fn (string $code): bool => $code === (string) $scenario['juneSale']->code);

    expect($listed)->toHaveCount(1);
});

it('reads a fact dated in an absorbed competence once, as a preview until it is published', function () {
    $scenario = absorbedJuneScenario();

    // Lançada depois da publicação de julho: ainda não foi congelada em lugar nenhum.
    $late = ExtemporaneousFixture::sale($scenario['units'][4], '2026-06-25');

    $june = negotiationsReport($scenario['emission'], '2026-06-01')['negotiations'];
    $julyHistory = collect(negotiationsReport($scenario['emission'], '2026-07-01')['negotiations_history']['rows'])->keyBy('competencia');

    expect(collect($june['vendas'])->pluck('code')->all())->toBe([(string) $late->code])
        ->and($june['vendas'][0]['situation'])->toBe('Prévia')
        ->and($julyHistory['06/2026']['sales'])->toBe('1')
        ->and($julyHistory['07/2026']['sales'])->toBe('2');

    ExtemporaneousFixture::publish(ExtemporaneousFixture::generateAugust($scenario['construction']));

    $augustHistory = collect(negotiationsReport($scenario['emission'], '2026-08-01')['negotiations_history']['rows'])->keyBy('competencia');

    expect($augustHistory['06/2026']['sales'])->toBe('0')
        ->and($augustHistory['08/2026']['sales'])->toBe('1')
        ->and(negotiationsReport($scenario['emission'], '2026-06-01')['negotiations']['has_data'])->toBeFalse();
});

it('counts a late sale dated in a month without cycle only in the competence that published it', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    CycleFixture::automate($construction->emission, '2026-05-01');
    $construction->emission->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS])->save();

    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-05-01')->cycle);

    // Abril é anterior à automação e não tem ciclo; a venda chega depois de maio publicado.
    ExtemporaneousFixture::sale($units[0], '2026-04-15');
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    $history = collect(negotiationsReport($construction->emission->fresh(), '2026-06-01')['negotiations_history']['rows'])->keyBy('competencia');

    expect($history['04/2026']['sales'])->toBe('0')
        ->and($history['06/2026']['sales'])->toBe('1');
});

it('does not label as a preview the contracts of a competence the automation does not cover', function (string $case) {
    [$construction, $units] = CycleFixture::readyConstruction(2);
    $emission = $construction->emission;

    match ($case) {
        'Quadro legado' => $emission->forceFill(['sales_board_source' => SalesBoardSource::Legacy, 'sales_board_automation_start_reference_month' => null])->save(),
        'antes do início da automação' => CycleFixture::automate($emission, '2026-07-01'),
    };

    $emission->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS])->save();
    ExtemporaneousFixture::sale($units[0], '2026-03-10');

    $report = negotiationsReport($emission, '2026-03-01');
    $html = view('pdf.emission-monthly-report', $report)->render();

    expect($report['negotiations']['sales_count'])->toBe(1)
        ->and($report['negotiations']['sources'])->toBe([$construction->development_name => 'contratos'])
        ->and($report['negotiations']['vendas'][0]['situation'])->toBe('Lida dos contratos')
        ->and($report['negotiations']['note'])->toBe('Fonte: contratos da emissão — Data da Venda e Data do Distrato dentro da competência.')
        ->and($html)->not->toContain('Prévia')
        ->and($html)->not->toContain('Competência do fato');
})->with(['Quadro legado', 'antes do início da automação']);

it('keeps a cancelled competence as a preview, counted once, until the next one is published', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $construction->emission->forceFill(['negotiations_source' => Emission::NEGOTIATIONS_SOURCE_CONTRACTS])->save();
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-05-01')->cycle);

    $juneSale = ExtemporaneousFixture::sale($units[0], '2026-06-20');
    app(SalesBoardCycleCancellationService::class)->cancel(
        CycleFixture::generate($construction, '2026-06-01')->cycle,
        GovernanceFixture::approver(),
        'Competência cancelada no piloto.',
    );

    // Julho absorve junho, mas ainda não foi publicado: a venda de junho só
    // existe na leitura dos contratos, como prévia de junho.
    CycleFixture::generate($construction, '2026-07-01');

    $june = negotiationsReport($construction->emission->fresh(), '2026-06-01')['negotiations'];
    $history = collect(negotiationsReport($construction->emission->fresh(), '2026-07-01')['negotiations_history']['rows'])->keyBy('competencia');

    expect($june['sources'])->toBe([$construction->development_name => 'previa'])
        ->and($june['absorbed'])->toBe([])
        ->and(collect($june['vendas'])->pluck('code')->all())->toBe([(string) $juneSale->code])
        ->and($history['06/2026']['sales'])->toBe('1')
        ->and($history['07/2026']['sales'])->toBe('0');
});
