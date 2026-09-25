<?php

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\ContractInstallment;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;
use App\Services\SalesBoards\SalesBoardBuilderReviewApplicability;
use App\Services\SalesBoards\SalesBoardBuilderReviewSupersedingService;
use App\Services\SalesBoards\SalesBoardManagementReviewApplicability;
use App\Services\SalesBoards\SalesBoardManagementReviewSupersedingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * Quando um ouvinte da troca de versão falha, a competência não fica presa.
 *
 * Os ouvintes rodam depois do commit do recálculo, síncronos e cada um na sua
 * transação. Uma falha ali -- um lock que não chega a tempo -- não pode virar
 * erro para um recálculo gravado, nem deixar a competência sem saída: as
 * aberturas, o recálculo sem alteração e o que só troca a origem material
 * concluem a substituição pendente.
 */

/**
 * Faz a próxima chamada de um serviço de substituição falhar, como um lock wait
 * timeout falharia.
 *
 * @param  class-string<SalesBoardBuilderReviewSupersedingService|SalesBoardManagementReviewSupersedingService>  $service
 */
function failNextSupersession(string $service): stdClass
{
    $state = new stdClass;
    $state->remaining = 1;

    app()->bind($service, fn ($app) => match ($service) {
        SalesBoardBuilderReviewSupersedingService::class => new class($app->make(SalesBoardBuilderReviewApplicability::class), $state) extends SalesBoardBuilderReviewSupersedingService
        {
            public function __construct(SalesBoardBuilderReviewApplicability $applicability, private readonly stdClass $state)
            {
                parent::__construct($applicability);
            }

            public function supersedeOutdated(SalesBoardCycle $cycle, SalesBoardCycleBaseline $newBaseline): array
            {
                if ($this->state->remaining > 0) {
                    $this->state->remaining--;

                    throw new RuntimeException('Lock wait timeout simulado no ouvinte da construtora.');
                }

                return parent::supersedeOutdated($cycle, $newBaseline);
            }
        },
        SalesBoardManagementReviewSupersedingService::class => new class($app->make(SalesBoardManagementReviewApplicability::class), $state) extends SalesBoardManagementReviewSupersedingService
        {
            public function __construct(SalesBoardManagementReviewApplicability $applicability, private readonly stdClass $state)
            {
                parent::__construct($applicability);
            }

            public function supersedeOutdated(SalesBoardCycle $cycle, SalesBoardCycleBaseline $newBaseline): array
            {
                if ($this->state->remaining > 0) {
                    $this->state->remaining--;

                    throw new RuntimeException('Lock wait timeout simulado no ouvinte da Gestão.');
                }

                return parent::supersedeOutdated($cycle, $newBaseline);
            }
        },
    });

    return $state;
}

/**
 * Uma competência em análise da Gestão cuja fonte acabou de mudar
 * materialmente.
 *
 * @return array{cycle: SalesBoardCycle, builderReview: SalesBoardBuilderReview, managementReview: SalesBoardManagementReview, cancelledContractId: int}
 */
function managementReviewWithMaterialChange(): array
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $managementReview = ManagementReviewFixture::open($scenario['cycle']);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    return [
        'cycle' => $scenario['cycle']->fresh(),
        'builderReview' => $scenario['builderReview']->fresh(),
        'managementReview' => $managementReview->fresh(),
        'cancelledContractId' => $scenario['contracts']['cancelled']->id,
    ];
}

it('does not surface a listener failure as an error of a committed recalculation', function () {
    Exceptions::fake();

    $context = managementReviewWithMaterialChange();
    failNextSupersession(SalesBoardBuilderReviewSupersedingService::class);

    $result = CycleFixture::recalculate($context['cycle'], 'Correção do valor de venda do contrato.');

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($context['cycle']->fresh()->current_baseline_id)->toBe($result->baseline->id)
        // O ouvinte da Gestão rodou; o da construtora falhou e ficou registrado.
        ->and($context['managementReview']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded)
        ->and($context['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'ouvinte da construtora'));
});

it('lets a new recalculation finish the supersession a failed listener left behind', function () {
    Exceptions::fake();

    $context = managementReviewWithMaterialChange();
    failNextSupersession(SalesBoardBuilderReviewSupersedingService::class);

    CycleFixture::recalculate($context['cycle'], 'Correção do valor de venda do contrato.');

    expect($context['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);

    // O gesto natural de quem encontra a competência travada.
    $again = CycleFixture::recalculate($context['cycle'], 'Tentando destravar a competência.');

    expect($again->outcome)->toBe(SalesBoardRecalculationOutcome::Unchanged)
        ->and($context['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($context['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);

    $next = BuilderReviewFixture::open($context['cycle']);

    expect($next->attempt)->toBe(2)
        ->and($next->snapshot_fingerprint)->toBe(CycleFixture::currentBaseline($context['cycle'])->snapshot_fingerprint);
});

it('lets a source-only recalculation finish the supersession a failed listener left behind', function () {
    Exceptions::fake();

    $context = managementReviewWithMaterialChange();
    failNextSupersession(SalesBoardBuilderReviewSupersedingService::class);

    CycleFixture::recalculate($context['cycle'], 'Correção do valor de venda do contrato.');
    $v2 = CycleFixture::currentBaseline($context['cycle']);

    expect($context['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and($context['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted);

    // Só a fonte muda: a parcela do contrato distratado não entra na posição.
    ContractInstallment::query()
        ->where('contract_id', $context['cancelledContractId'])
        ->sole()
        ->update(['expected_value' => '469000.00']);

    $sourceOnly = CycleFixture::recalculate($context['cycle'], 'Correção da parcela do contrato distratado.');

    /**
     * Os ouvintes ignoram a V3, que apresenta o mesmo quadro da V2. É o
     * recálculo que conclui a substituição que o aviso da V2 deixou pendente.
     */
    expect($sourceOnly->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($sourceOnly->baseline->snapshot_fingerprint)->toBe($v2->snapshot_fingerprint)
        ->and($context['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($context['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);

    $next = BuilderReviewFixture::open($context['cycle']);

    expect($next->attempt)->toBe(2)
        ->and($next->sales_board_cycle_baseline_id)->toBe($sourceOnly->baseline->id);
});

it('leaves the reviews alone when a source-only recalculation finds nothing outdated', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $managementReview = ManagementReviewFixture::open($scenario['cycle']);

    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['cancelled']->id)
        ->sole()
        ->update(['expected_value' => '469000.00']);

    $sourceOnly = CycleFixture::recalculate($scenario['cycle'], 'Correção da parcela do contrato distratado.');

    expect($sourceOnly->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($managementReview->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($scenario['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('returns the competence to the builder when the management opening finds it stuck', function () {
    Exceptions::fake();

    $context = managementReviewWithMaterialChange();
    failNextSupersession(SalesBoardBuilderReviewSupersedingService::class);

    CycleFixture::recalculate($context['cycle'], 'Correção do valor de venda do contrato.');

    expect(fn () => ManagementReviewFixture::open($context['cycle']))
        ->toThrow(SalesBoardManagementReviewException::class, 'voltou para a construtora');

    expect($context['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($context['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);

    // E a construtora consegue abrir a rodada seguinte.
    expect(BuilderReviewFixture::open($context['cycle'])->attempt)->toBe(2);
});

it('lets the builder opening finish the supersession a failed listener left behind', function () {
    Exceptions::fake();

    $context = managementReviewWithMaterialChange();
    failNextSupersession(SalesBoardBuilderReviewSupersedingService::class);

    CycleFixture::recalculate($context['cycle'], 'Correção do valor de venda do contrato.');

    $next = BuilderReviewFixture::open($context['cycle']);

    expect($next->attempt)->toBe(2)
        ->and($context['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($context['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('never hands the manager an outdated analysis as the one in progress', function () {
    Exceptions::fake();

    $context = managementReviewWithMaterialChange();
    failNextSupersession(SalesBoardManagementReviewSupersedingService::class);

    CycleFixture::recalculate($context['cycle'], 'Correção do valor de venda do contrato.');

    // O ouvinte da Gestão falhou; o da construtora rodou mesmo assim.
    expect($context['managementReview']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($context['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($context['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);

    $builderReview = BuilderReviewFixture::open($context['cycle']);
    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview);

    $review = ManagementReviewFixture::open($context['cycle']);

    expect($review->id)->not->toBe($context['managementReview']->id)
        ->and($review->attempt)->toBe(2)
        ->and($review->snapshot_fingerprint)->toBe(CycleFixture::currentBaseline($context['cycle'])->snapshot_fingerprint)
        ->and($context['managementReview']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded);

    // E a análise nova chega ao fim.
    ManagementReviewFixture::decideAll($review);

    expect(ManagementReviewFixture::approve($review)->review->status)->toBe(SalesBoardManagementReviewStatus::Approved);
});
