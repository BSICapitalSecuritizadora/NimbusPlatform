<?php

use App\Enums\SalesBoardRolloutComparisonStatus;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * As linhas por empreendimento são o retrato que a Gestão revisou. A
 * homologação pai já era imutável depois de encerrada; as linhas não tinham
 * guard nenhum, e a reavaliação conferia a editabilidade na instância da tela,
 * sem lock nem transação.
 */

/**
 * Uma homologação aprovada, e a instância de rascunho que a tela ainda segura
 * de antes da aprovação.
 *
 * @return array{scenario: array{emission: Emission, constructions: list<Construction>}, stale: SalesBoardRolloutHomologation}
 */
function homologationApprovedBehindAStaleDraft(): array
{
    $scenario = RolloutFixture::emission();

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $stale = RolloutFixture::open($scenario['emission'], GovernanceFixture::operator());

    RolloutFixture::recipients($scenario['emission']);

    // Atestar e aprovar são da Gestão, e não de quem abriu a homologação.
    $approver = GovernanceFixture::approver();
    RolloutFixture::reviewImpacts($stale, $approver);
    RolloutFixture::approve($stale, $approver);

    return ['scenario' => $scenario, 'stale' => $stale];
}

it('refuses to change, add or delete a row of an approved homologation', function () {
    ['scenario' => $scenario, 'stale' => $stale] = homologationApprovedBehindAStaleDraft();

    $approved = $stale->fresh();
    $row = $approved->constructions()->firstOrFail();
    $rowsBefore = $approved->constructions()->count();

    expect($approved->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and(fn () => $row->forceFill(['difference_reason' => 'Reescrita depois da aprovação.'])->save())
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $row->delete())
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => SalesBoardRolloutHomologationConstruction::query()->create([
            'sales_board_rollout_homologation_id' => $approved->getKey(),
            'construction_id' => Construction::factory()->create(['emission_id' => $scenario['emission']->getKey()])->getKey(),
            'comparison_status' => SalesBoardRolloutComparisonStatus::NoLegacyPosition,
        ]))
        ->toThrow(LogicException::class, 'immutable');

    expect($row->fresh()->difference_reason)->toBeNull()
        ->and($approved->constructions()->count())->toBe($rowsBefore);
});

it('refuses to change a row of a rejected homologation', function () {
    $scenario = RolloutFixture::emission();
    $homologation = RolloutFixture::open($scenario['emission']);

    app(SalesBoardRolloutHomologationService::class)
        ->reject($homologation, GovernanceFixture::operator(), 'Cadastro de unidades ainda incompleto.');

    $row = $homologation->constructions()->firstOrFail();

    expect(fn () => $row->forceFill(['is_ready' => ! $row->is_ready])->save())
        ->toThrow(LogicException::class, 'immutable');
});

it('keeps rows of a draft homologation writable', function () {
    $scenario = RolloutFixture::emission();
    $homologation = RolloutFixture::open($scenario['emission']);

    $row = $homologation->constructions()->firstOrFail();
    $row->forceFill(['difference_reason' => 'Anotação do rascunho.'])->save();

    expect($row->fresh()->difference_reason)->toBe('Anotação do rascunho.');
});

it('refuses to reassess a draft the screen still holds after the homologation was approved', function () {
    ['scenario' => $scenario, 'stale' => $stale] = homologationApprovedBehindAStaleDraft();

    $approved = $stale->fresh();
    $rowsBefore = $approved->constructions()->get()
        ->mapWithKeys(fn (SalesBoardRolloutHomologationConstruction $row): array => [
            $row->getKey() => [$row->snapshot_fingerprint, $row->derived_position, $row->updated_at?->toIso8601String()],
        ])
        ->all();

    // A fonte muda depois da aprovação: reavaliar agora reescreveria o retrato.
    DerivationFixture::unit($scenario['constructions'][0], 'A0999', '400000.00');

    expect($stale->isEditable())->toBeTrue()
        ->and(fn () => app(SalesBoardRolloutHomologationService::class)->reassess($stale, GovernanceFixture::operator()))
        ->toThrow(SalesBoardRolloutException::class, 'já foi encerrada');

    $afterwards = $approved->fresh();

    expect($afterwards->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        ->and($afterwards->assessment_hash)->toBe($approved->assessment_hash)
        ->and($afterwards->assessed_at?->toIso8601String())->toBe($approved->assessed_at?->toIso8601String())
        ->and($afterwards->constructions()->get()
            ->mapWithKeys(fn (SalesBoardRolloutHomologationConstruction $row): array => [
                $row->getKey() => [$row->snapshot_fingerprint, $row->derived_position, $row->updated_at?->toIso8601String()],
            ])
            ->all())->toBe($rowsBefore);
});

it('refuses to accept a difference on a row the screen loaded before the approval', function () {
    ['stale' => $stale] = homologationApprovedBehindAStaleDraft();

    $row = $stale->constructions()->firstOrFail();

    expect(fn () => app(SalesBoardRolloutHomologationService::class)
        ->acceptDifference($row, 'Diferença entendida depois da aprovação.', GovernanceFixture::operator()))
        ->toThrow(SalesBoardRolloutException::class, 'já foi encerrada');

    expect($row->fresh()->difference_reason)->toBeNull();
});
