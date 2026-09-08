<?php

use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\DTOs\SalesBoards\SalesBoardBuilderReviewSubmissionSummary;
use App\Enums\BuilderReviewerType;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use App\Services\SalesBoards\SalesBoardBuilderReviewSubmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;

uses(RefreshDatabase::class);

it('accepts a review that confirms the whole position', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $actor = User::factory()->create();

    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review, $actor, 'Posição conferida integralmente.');

    expect($submitted->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull()
        ->and($submitted->submitted_by_user_id)->toBe($actor->id)
        ->and($submitted->reviewer_type)->toBe(BuilderReviewerType::InternalPreview->value)
        ->and($submitted->reviewer_key)->toBe('user:'.$actor->id)
        ->and($submitted->reviewer_name)->toBe($actor->name)
        ->and($submitted->reviewer_email)->toBe($actor->email)
        ->and($submitted->declaration_version)->toBe(SalesBoardBuilderReviewSubmissionService::DECLARATION_VERSION)
        ->and($submitted->overall_comment)->toBe('Posição conferida integralmente.')
        ->and($submitted->divergences)->toHaveCount(0)
        ->and($submitted->isFullyConfirmed())->toBeTrue()
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('accepts a review that mixes confirmations and divergences', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);
    BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Unidade distratada em junho.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    BuilderReviewFixture::declare($review, SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::SaleMissing,
        reason: 'Venda de 22/07 ainda não repassada.',
        declaredBlock: 'B',
        declaredUnit: '404',
        declaredValueCents: 88_000_000,
        declaredDate: CarbonImmutable::parse('2026-07-22'),
    ));

    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review);

    $statuses = $submitted->sections->keyBy(fn ($s): string => $s->section->value);

    expect($submitted->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($statuses[SectionEnum::PositionFinanced->value]->status)->toBe(SalesBoardBuilderReviewSectionStatus::Divergent)
        ->and($statuses[SectionEnum::MovementSales->value]->status)->toBe(SalesBoardBuilderReviewSectionStatus::Divergent)
        ->and($statuses[SectionEnum::PositionStock->value]->status)->toBe(SalesBoardBuilderReviewSectionStatus::Confirmed)
        ->and($submitted->divergences)->toHaveCount(2)
        ->and($submitted->isFullyConfirmed())->toBeFalse()
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('blocks a submission while any section is still pending, naming them', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $editor = app(SalesBoardBuilderReviewEditor::class);
    foreach ([SectionEnum::PositionStock, SectionEnum::PositionFinanced] as $section) {
        $editor->confirmSection(BuilderReviewFixture::section($review, $section));
    }

    expect(fn () => BuilderReviewFixture::submit($review))
        ->toThrow(SalesBoardBuilderReviewException::class, 'Ainda falta validar: Quitado');

    expect($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('blocks a submission when a section says divergent but has no divergence', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    BuilderReviewFixture::confirmAll($review);

    // Estado incoerente forçado por fora do serviço, como uma remoção concorrente
    // deixaria: a seção diz que há problema e não há nenhum registrado.
    BuilderReviewFixture::section($review, SectionEnum::PositionStock)
        ->forceFill(['status' => SalesBoardBuilderReviewSectionStatus::Divergent])
        ->save();

    expect(fn () => BuilderReviewFixture::submit($review))
        ->toThrow(SalesBoardBuilderReviewException::class, 'não tem nenhuma divergência registrada');
});

it('refuses a second submission of the same review', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    expect(fn () => BuilderReviewFixture::submit($review))
        ->toThrow(SalesBoardBuilderReviewException::class, 'já foi enviada');
});

it('freezes the review, its sections and its divergences after submission', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);
    $divergence = BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Unidade distratada.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));

    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review);

    $section = BuilderReviewFixture::section($submitted, SectionEnum::PositionStock);
    $editor = app(SalesBoardBuilderReviewEditor::class);

    expect(fn () => $submitted->update(['overall_comment' => 'outro comentário']))
        ->toThrow(LogicException::class, 'A submitted builder review is immutable.')
        ->and(fn () => $section->update(['comment' => 'outro']))
        ->toThrow(LogicException::class, 'A submitted builder review section is immutable.')
        ->and(fn () => $divergence->fresh()->update(['reason' => 'outro motivo']))
        ->toThrow(LogicException::class, 'A submitted builder divergence is immutable.')
        ->and(fn () => $divergence->fresh()->delete())
        ->toThrow(LogicException::class, 'A submitted builder divergence is immutable.')
        ->and(fn () => $submitted->delete())
        ->toThrow(LogicException::class, 'Builder reviews cannot be deleted.')
        ->and(fn () => $editor->confirmSection($section))
        ->toThrow(SalesBoardBuilderReviewException::class, 'já foi enviada');
});

it('refuses new divergences once the review is submitted', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    expect(fn () => BuilderReviewFixture::declare($review->fresh(), SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::Other,
        reason: 'Tentativa tardia.',
    )))->toThrow(SalesBoardBuilderReviewException::class, 'já foi enviada');
});

it('leaves the snapshot byte for byte identical across the whole review', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $baseline = CycleFixture::currentBaseline($scenario['cycle'])->load(['lines', 'movements']);

    $snapshot = fn ($b): array => [
        'baseline' => $b->snapshot_fingerprint,
        'source' => $b->source_fingerprint,
        'lines' => $b->lines->map(fn ($l): string => $l->snapshot_fingerprint)->sort()->values()->all(),
        'movements' => $b->movements->map(fn ($m): string => $m->snapshot_fingerprint)->sort()->values()->all(),
        'financed_value' => $b->financed_value,
    ];

    $before = $snapshot($baseline);

    $review = BuilderReviewFixture::open($scenario['cycle']);
    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);
    BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));
    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    expect($snapshot($baseline->fresh()->load(['lines', 'movements'])))->toBe($before);
});

it('publishes nothing and notifies nobody', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    Notification::fake();

    BuilderReviewFixture::confirmAll($review);
    BuilderReviewFixture::submit($review);

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardHistory::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('refuses a submission without a resolved reviewer identity', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    $anonymous = new BuilderReviewerIdentity(
        type: BuilderReviewerType::InternalPreview,
        stableKey: '   ',
        displayName: '   ',
    );

    expect(fn () => app(SalesBoardBuilderReviewSubmissionService::class)->submit($review->fresh(), $anonymous))
        ->toThrow(SalesBoardBuilderReviewException::class, 'identificar quem está enviando');
});

it('hands management a summary it does not have to rebuild', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);

    $line = BuilderReviewFixture::lineFor($review, $scenario['units']['financed']);
    BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'Deveria estar em estoque.',
        lineId: $line->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ));
    BuilderReviewFixture::confirmAll($review);
    $submitted = BuilderReviewFixture::submit($review);

    $summary = SalesBoardBuilderReviewSubmissionSummary::for($submitted);

    expect($summary->isFullyConfirmed)->toBeFalse()
        ->and($summary->divergenceCount)->toBe(1)
        ->and($summary->divergentSections())->toBe([SectionEnum::PositionFinanced])
        ->and($summary->divergenceCountsByType)->toBe([SalesBoardBuilderDivergenceType::StockMismatch->value => 1])
        ->and($summary->reviewerType)->toBe(BuilderReviewerType::InternalPreview)
        ->and($summary->baseline->id)->toBe($submitted->sales_board_cycle_baseline_id)
        ->and($summary->sectionStatuses)->toHaveCount(7)
        ->and($summary->headline())->toContain('1 divergência(s) em 1 seção(ões)')
        // A conformidade que o sistema apurou continua disponível pelo snapshot.
        ->and($summary->baseline->movements->firstWhere('movement_type', SalesBoardMovementType::Sale)->conformity_status)
        ->not->toBeNull();
});
