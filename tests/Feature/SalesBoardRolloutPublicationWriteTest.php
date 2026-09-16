<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Construction;
use App\Models\ConstructionUnitExchange;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardHistory;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardAutomationService;
use App\Services\SalesBoards\SalesBoardPositionReader;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use App\Support\SalesBoards\SalesBoardWriteContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * A publicação continua sendo a porta de escrita depois de a Emissão virar
 * automatizada -- e só ela.
 *
 * Nada aqui monta o modo automatizado por `forceFill`: a Emissão passa pelo
 * rollout inteiro (homologação, aprovação, ativação), a competência nasce da
 * execução da Fase F, e o quadro sai da validação da construtora e da aprovação
 * da Gestão. É o caminho que a produção vai percorrer, e é nele que o guard de
 * escrita precisa deixar passar exatamente uma coisa.
 */
pest()->group('parity');

/**
 * Uma Emissão automatizada de verdade, com a competência de agosto já validada
 * pela construtora e com a análise da Gestão aberta e decidida.
 *
 * O empreendimento tem um balde de cada -- estoque, financiado, quitado e
 * permutado -- para que o `total_units` publicado seja uma soma, e não um
 * número que coincide por acaso.
 *
 * @return array{emission: Emission, construction: Construction, homologation: SalesBoardRolloutHomologation, cycle: SalesBoardCycle, review: SalesBoardManagementReview}
 */
function automatedCycleInManagement(): array
{
    $scenario = RolloutFixture::emission(1);
    $construction = $scenario['constructions'][0];

    RolloutFixture::legacyBoard($construction);

    $financed = DerivationFixture::contract(DerivationFixture::unit($construction, 'A11'), '2026-03-10', '900000.00');
    DerivationFixture::installment($financed, '001', '2026-04-10', '450000.00', '2026-04-09', '450000.00');
    DerivationFixture::installment($financed, '002', '2026-10-10', '450000.00');

    $settled = DerivationFixture::contract(DerivationFixture::unit($construction, 'A12'), '2026-02-10', '850000.00');
    DerivationFixture::installment($settled, '001', '2026-03-10', '425000.00', '2026-03-09', '425000.00');
    DerivationFixture::installment($settled, '002', '2026-06-10', '425000.00', '2026-07-10', '425000.00');

    ConstructionUnitExchange::factory()->create([
        'construction_unit_id' => DerivationFixture::unit($construction, 'A13')->id,
        'exchange_value' => '700000.00',
        'effective_from' => '2026-01-01',
    ]);

    // O legado registrou só o estoque; a diferença é analisada, como no rollout real.
    $actor = User::factory()->create();
    $homologation = RolloutFixture::open($scenario['emission'], $actor);

    foreach ($homologation->constructions as $row) {
        if ($row->requiresAcknowledgement()) {
            app(SalesBoardRolloutHomologationService::class)
                ->acceptDifference($row, 'O quadro manual não registrava financiados, quitados e permutas.', $actor);
        }
    }

    RolloutFixture::recipients($scenario['emission'], $actor);
    RolloutFixture::reviewImpacts($homologation, $actor);
    $homologation = RolloutFixture::approve($homologation, $actor);
    RolloutFixture::activate($scenario['emission'], $homologation, $actor);

    // Fase F: a competência de agosto vence em 13/09.
    RolloutFixture::enableGlobalAutomation();
    app(SalesBoardAutomationService::class)->run(asOf: CarbonImmutable::parse('2026-09-13'));

    $cycle = SalesBoardCycle::query()->where('construction_id', $construction->id)->sole();

    $builderReview = BuilderReviewFixture::open($cycle);
    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);

    $review = ManagementReviewFixture::open($cycle);
    ManagementReviewFixture::decideAll($review);

    return [
        'emission' => $scenario['emission']->fresh(),
        'construction' => $construction,
        'homologation' => $homologation->fresh(),
        'cycle' => $cycle->fresh(),
        'review' => $review->fresh(),
    ];
}

/**
 * @return array{sales_boards: int, sales_board_publications: int, sales_board_histories: int}
 */
function publicationCounters(): array
{
    return [
        'sales_boards' => DB::table('sales_boards')->count(),
        'sales_board_publications' => DB::table('sales_board_publications')->count(),
        'sales_board_histories' => DB::table('sales_board_histories')->count(),
    ];
}

function writeContext(): SalesBoardWriteContext
{
    return app(SalesBoardWriteContext::class);
}

/**
 * Um quadro manual qualquer do empreendimento.
 */
function manualBoard(Construction $construction, string $referenceMonth): SalesBoard
{
    return SalesBoard::factory()->create([
        'emission_id' => $construction->emission_id,
        'construction_id' => $construction->id,
        'reference_month' => $referenceMonth,
        'stock_units' => 2, 'financed_units' => 1, 'paid_units' => 1, 'exchanged_units' => 1,
        'stock_value' => '1000000.00', 'financed_value' => '900000.00',
        'paid_value' => '850000.00', 'exchanged_value' => '700000.00',
    ]);
}

it('reaches the management gate through the real rollout and automation path', function () {
    $scenario = automatedCycleInManagement();

    expect($scenario['emission']->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and($scenario['emission']->sales_board_active_homologation_id)->toBe($scenario['homologation']->id)
        ->and($scenario['emission']->automationStartsAt()->format('Y-m'))->toBe('2026-08')
        ->and($scenario['cycle']->reference_month->format('Y-m'))->toBe('2026-08')
        ->and($scenario['cycle']->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and($scenario['review']->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('blocks a manual board on an automated competence before and after publication', function () {
    $scenario = automatedCycleInManagement();

    expect(fn () => manualBoard($scenario['construction'], '2026-08-01'))
        ->toThrow(SalesBoardRolloutException::class, 'produzido pelo ciclo mensal');

    ManagementReviewFixture::approve($scenario['review']);

    expect(fn () => manualBoard($scenario['construction'], '2026-09-01'))
        ->toThrow(SalesBoardRolloutException::class, 'produzido pelo ciclo mensal')
        ->and(fn () => manualBoard($scenario['construction'], '2026-12-01'))
        ->toThrow(SalesBoardRolloutException::class, 'produzido pelo ciclo mensal');

    expect(SalesBoard::query()->where('construction_id', $scenario['construction']->id)
        ->where('reference_month', '>=', '2026-09-01')->count())->toBe(0);
});

it('blocks a manual board filed under another emission for a construction that is automated', function () {
    $scenario = RolloutFixture::emission(1);
    $construction = $scenario['constructions'][0];

    RolloutFixture::legacyBoard($construction);
    RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));

    $legacyEmission = Emission::factory()->create(['status' => 'active']);

    /**
     * O Reader lê a posição por empreendimento, sem olhar a Emissão. Um quadro
     * gravado por fora da tela -- tinker, carga pelo model -- com a Emissão
     * errada seria lido como a posição de setembro deste empreendimento
     * automatizado; checar só a Emissão do quadro deixaria a escrita passar.
     */
    expect(fn () => SalesBoard::factory()->create([
        'emission_id' => $legacyEmission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-09-01',
    ]))->toThrow(SalesBoardRolloutException::class, 'produzido pelo ciclo mensal');

    expect(SalesBoard::query()->where('construction_id', $construction->id)
        ->whereDate('reference_month', '2026-09-01')->exists())->toBeFalse();

    // Antes do início, a mesma escrita continua sendo manutenção do passado.
    $past = SalesBoard::factory()->create([
        'emission_id' => $legacyEmission->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-05-01',
    ]);

    expect($past->exists)->toBeTrue();
});

it('publishes exactly one board, one publication and one history version through the normal event path', function () {
    $scenario = automatedCycleInManagement();
    $before = publicationCounters();

    // Instancia o model antes de escutar: os listeners do teste entram depois
    // dos do próprio SalesBoard, na ordem em que o dispatcher os chama.
    new SalesBoard;

    $events = [];

    foreach (['saving', 'creating', 'created'] as $event) {
        Event::listen("eloquent.{$event}: ".SalesBoard::class, function (SalesBoard $board) use (&$events, $event): void {
            $events[] = [
                'event' => 'board.'.$event,
                'publishing' => writeContext()->isPublishing(),
                'total_units' => $board->total_units,
            ];
        });
    }

    Event::listen('eloquent.created: '.SalesBoardHistory::class, function (SalesBoardHistory $history) use (&$events): void {
        $events[] = ['event' => 'history.created', 'publishing' => writeContext()->isPublishing(), 'total_units' => $history->total_units];
    });

    expect(writeContext()->isPublishing())->toBeFalse();

    $result = ManagementReviewFixture::approve($scenario['review']);
    $board = $result->salesBoard;

    expect(writeContext()->isPublishing())->toBeFalse()
        ->and(publicationCounters())->toBe([
            'sales_boards' => $before['sales_boards'] + 1,
            'sales_board_publications' => $before['sales_board_publications'] + 1,
            'sales_board_histories' => $before['sales_board_histories'] + 1,
        ]);

    /**
     * A criação passou pelo caminho normal: `saving` (onde o model normaliza a
     * competência e soma o total), `creating` (onde o observer aplica o guard,
     * já com o contexto de publicação aberto) e `created` -- e a versão do
     * histórico nasce **dentro** do `created`, antes do listener do teste, ou
     * seja, pelo observer, e não por uma escrita da publicação.
     */
    expect(collect($events)->pluck('event')->all())
        ->toBe(['board.saving', 'board.creating', 'history.created', 'board.created'])
        ->and(collect($events)->pluck('publishing')->unique()->all())->toBe([true])
        ->and(collect($events)->pluck('total_units')->unique()->all())->toBe([5]);

    $history = SalesBoardHistory::query()->where('sales_board_id', $board->id)->sole();

    expect($board->reference_month->toDateString())->toBe('2026-08-01')
        ->and([$board->stock_units, $board->financed_units, $board->paid_units, $board->exchanged_units])->toBe([2, 1, 1, 1])
        ->and($board->total_units)->toBe(5)
        ->and($board->total_units)->toBe($board->calculateTotalUnits())
        ->and($board->total_units)->toBe($result->payload->totalUnits)
        ->and($history->total_units)->toBe(5)
        ->and($history->is_initial)->toBeFalse()
        ->and(SalesBoardPublication::query()->sole()->sales_board_id)->toBe($board->id)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);

    // O leitor que Garantias e Relatório usam enxerga o quadro publicado.
    $position = app(SalesBoardPositionReader::class)
        ->forConstruction($scenario['construction'], CarbonImmutable::parse('2026-08-01'));

    expect($position->salesBoard?->id)->toBe($board->id)
        ->and($position->totalUnits)->toBe(5);

    // Idempotência da Fase E: aprovar de novo devolve a mesma publicação.
    $again = ManagementReviewFixture::approve($scenario['review']);

    expect($again->salesBoard->id)->toBe($board->id)
        ->and(publicationCounters())->toBe([
            'sales_boards' => $before['sales_boards'] + 1,
            'sales_board_publications' => $before['sales_board_publications'] + 1,
            'sales_board_histories' => $before['sales_board_histories'] + 1,
        ]);
});

it('rolls the whole publication back and closes the context when the observer fails', function () {
    $scenario = automatedCycleInManagement();
    $before = publicationCounters();

    // A falha acontece dentro do observer: a versão do histórico não consegue
    // nascer depois de o quadro já ter sido inserido.
    $failHistory = true;

    Event::listen('eloquent.creating: '.SalesBoardHistory::class, function () use (&$failHistory): void {
        if ($failHistory) {
            throw new RuntimeException('Falha simulada ao gravar o histórico do quadro.');
        }
    });

    expect(fn () => ManagementReviewFixture::approve($scenario['review']))
        ->toThrow(RuntimeException::class, 'Falha simulada');

    expect(writeContext()->isPublishing())->toBeFalse()
        ->and(publicationCounters())->toBe($before)
        ->and($scenario['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);

    // O contexto fechado vale para a escrita manual seguinte da mesma requisição.
    expect(fn () => manualBoard($scenario['construction'], '2026-08-01'))
        ->toThrow(SalesBoardRolloutException::class, 'produzido pelo ciclo mensal');

    // E nada ficou pela metade: a mesma aprovação, sem a falha, publica normalmente.
    $failHistory = false;

    ManagementReviewFixture::approve($scenario['review']);

    expect(publicationCounters())->toBe([
        'sales_boards' => $before['sales_boards'] + 1,
        'sales_board_publications' => $before['sales_board_publications'] + 1,
        'sales_board_histories' => $before['sales_board_histories'] + 1,
    ]);
});

it('keeps the published board immutable, also after returning to legacy', function () {
    $scenario = automatedCycleInManagement();
    $board = ManagementReviewFixture::approve($scenario['review'])->salesBoard;
    $histories = SalesBoardHistory::query()->where('sales_board_id', $board->id)->count();

    $edit = function () use ($board): void {
        $fresh = $board->fresh();
        $fresh->changeReason = 'Tentativa de correção manual do quadro publicado.';
        $fresh->update(['stock_units' => 99]);
    };

    expect($edit)->toThrow(SalesBoardRolloutException::class, 'não pode ser alterado nem removido')
        ->and(fn () => $board->fresh()->delete())->toThrow(SalesBoardRolloutException::class, 'não pode ser alterado nem removido');

    RolloutFixture::returnToLegacy($scenario['emission']);

    expect($scenario['emission']->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);

    // A imutabilidade vem da publicação, e não do modo atual da Emissão.
    expect($edit)->toThrow(SalesBoardRolloutException::class, 'não pode ser alterado nem removido')
        ->and(fn () => $board->fresh()->delete())->toThrow(SalesBoardRolloutException::class, 'não pode ser alterado nem removido');

    expect($board->fresh()->stock_units)->toBe(2)
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe($histories);
});

it('keeps legacy rules for manual boards before the start and after returning to legacy', function () {
    $scenario = automatedCycleInManagement();
    ManagementReviewFixture::approve($scenario['review']);

    // Antes da competência inicial, o guard do rollout não se aplica.
    $past = manualBoard($scenario['construction'], '2026-06-01');

    expect($past->exists)->toBeTrue()
        ->and(SalesBoardHistory::query()->where('sales_board_id', $past->id)->count())->toBe(1);

    RolloutFixture::returnToLegacy($scenario['emission']);

    // De volta ao legado, uma competência futura sem quadro volta a aceitar registro manual.
    $future = manualBoard($scenario['construction'], '2026-09-01');

    expect($future->exists)->toBeTrue()
        ->and($future->total_units)->toBe(5)
        ->and(SalesBoardHistory::query()->where('sales_board_id', $future->id)->count())->toBe(1);
});

it('never lets the publication context override an existing position', function () {
    $scenario = automatedCycleInManagement();

    /**
     * Uma linha que chegou por fora do model -- uma carga direta no banco -- é o
     * pior caso: nem o guard a viu. A Fase E precisa recusar mesmo assim, e o
     * contexto de publicação, ainda que alguém o abrisse em volta da aprovação,
     * não é licença para passar por cima de uma posição registrada.
     */
    DB::table('sales_boards')->insert([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => $scenario['construction']->id,
        'reference_month' => '2026-08-01',
        'stock_units' => 2, 'financed_units' => 0, 'paid_units' => 0, 'exchanged_units' => 0, 'total_units' => 2,
        'stock_value' => '1000000.00', 'financed_value' => '0.00', 'paid_value' => '0.00', 'exchanged_value' => '0.00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $before = publicationCounters();

    expect(fn () => writeContext()->asPublication(fn () => ManagementReviewFixture::approve($scenario['review'])))
        ->toThrow(SalesBoardManagementReviewException::class, SalesBoardManagementReviewException::LEGACY_POSITION_EXISTS);

    expect(writeContext()->isPublishing())->toBeFalse()
        ->and(publicationCounters())->toBe($before)
        ->and($scenario['review']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('opens the publication context only around the callback, nested and exception-safe', function () {
    $context = writeContext();

    expect($context->isPublishing())->toBeFalse();

    $inside = $context->asPublication(fn (): array => [
        $context->isPublishing(),
        $context->asPublication(fn (): bool => $context->isPublishing()),
        $context->isPublishing(),
    ]);

    expect($inside)->toBe([true, true, true])
        ->and($context->isPublishing())->toBeFalse();

    expect(fn () => $context->asPublication(function (): never {
        throw new RuntimeException('Falha no meio da publicação.');
    }))->toThrow(RuntimeException::class);

    expect($context->isPublishing())->toBeFalse();

    expect(fn () => $context->asPublication(fn () => $context->asPublication(function (): never {
        throw new RuntimeException('Falha aninhada.');
    })))->toThrow(RuntimeException::class);

    expect($context->isPublishing())->toBeFalse();
});

it('keeps the publication context per scope and never in static state', function () {
    $first = writeContext();

    expect(writeContext())->toBe($first);

    // Uma nova requisição/job no Octane ou no worker começa com um contexto novo.
    app()->forgetScopedInstances();

    expect(writeContext())->not->toBe($first)
        ->and(writeContext()->isPublishing())->toBeFalse()
        ->and((new ReflectionClass(SalesBoardWriteContext::class))->getProperties(ReflectionProperty::IS_STATIC))->toBe([]);
});
