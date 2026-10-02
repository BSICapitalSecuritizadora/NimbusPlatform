<?php

use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\ConstructionUnitExchangeException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardPublication;
use App\Services\SalesBoards\ConstructionUnitExchangeService;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;

/**
 * Competência publicada é a que tem publicação -- nunca o status Aprovado. A
 * regra é uma só para permuta, baixa de unidade e quem mais precisar saber até
 * onde vai a posição publicada; com a retificação o ciclo publicado volta a
 * Gerado, e a regra por status abriria a posição publicada.
 */
uses(RefreshDatabase::class);

pest()->group('parity');

function boundaryConstruction(): Construction
{
    return Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);
}

function boundaryCycle(Construction $construction, string $month, SalesBoardCycleStatus $status, bool $published): SalesBoardCycle
{
    $cycle = SalesBoardCycle::factory()->forConstruction($construction)->referenceMonth($month)->create(['status' => $status]);

    if ($published) {
        SalesBoardPublication::factory()->create(['sales_board_cycle_id' => $cycle->id]);
    }

    return $cycle;
}

it('reads the published competences from the publications, not from the cycle status', function () {
    $construction = boundaryConstruction();
    $other = boundaryConstruction();

    boundaryCycle($construction, '2026-05-01', SalesBoardCycleStatus::Approved, published: true);
    // Em retificação: publicada, de volta a Gerado.
    boundaryCycle($construction, '2026-06-01', SalesBoardCycleStatus::Generated, published: true);
    // Aprovado sem publicação não é posição publicada.
    boundaryCycle($construction, '2026-07-01', SalesBoardCycleStatus::Approved, published: false);
    boundaryCycle($other, '2026-09-01', SalesBoardCycleStatus::Approved, published: true);

    $months = fn (array $list): array => array_map(fn (CarbonImmutable $month): string => $month->toDateString(), $list);

    expect(PublishedCompetenceBoundary::lastPublishedMonth($construction->id)?->toDateString())->toBe('2026-06-01')
        ->and(PublishedCompetenceBoundary::lastPublishedMonth($construction->id, lockingRead: true)?->toDateString())->toBe('2026-06-01')
        ->and(PublishedCompetenceBoundary::firstOpenDay($construction->id)?->toDateString())->toBe('2026-07-01')
        ->and($months(PublishedCompetenceBoundary::publishedMonthsBetween($construction->id, CarbonImmutable::parse('2026-01-15'), CarbonImmutable::parse('2026-12-31'))))
        ->toBe(['2026-05-01', '2026-06-01'])
        ->and($months(PublishedCompetenceBoundary::publishedMonthsBetween($construction->id, CarbonImmutable::parse('2026-06-20'), CarbonImmutable::parse('2026-06-20'))))
        ->toBe(['2026-06-01']);
});

it('answers nothing for a construction without publication', function () {
    $construction = boundaryConstruction();
    boundaryCycle($construction, '2026-07-01', SalesBoardCycleStatus::Approved, published: false);

    expect(PublishedCompetenceBoundary::lastPublishedMonth($construction->id))->toBeNull()
        ->and(PublishedCompetenceBoundary::firstOpenDay($construction->id))->toBeNull()
        ->and(PublishedCompetenceBoundary::publishedMonthsBetween($construction->id, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))->toBe([]);
});

it('keeps the exchange of a competence under rectification protected', function () {
    $unit = DerivationFixture::unit(boundaryConstruction(), '101');
    boundaryCycle($unit->construction, '2026-07-01', SalesBoardCycleStatus::Generated, published: true);

    $service = app(ConstructionUnitExchangeService::class);

    expect($service->lastPublishedCompetence($unit)?->toDateString())->toBe('2026-07-01')
        ->and(fn () => $service->registerExtraordinary(
            $unit,
            GovernanceFixture::approver(),
            '450000.00',
            CarbonImmutable::parse('2026-07-20'),
            null,
            'Permuta acertada com a construtora em aditivo ao contrato de obra.',
        ))->toThrow(ConstructionUnitExchangeException::class, 'posterior a 31/07/2026');
});
