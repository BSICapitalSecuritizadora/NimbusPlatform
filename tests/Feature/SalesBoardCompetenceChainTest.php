<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardGenerationOutcome;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardStaleImpact;
use App\Enums\SalesPriceConformityStatus;
use App\Exceptions\SalesBoardCycleReopeningException;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardPublication;
use App\Services\SalesBoards\ConstructionUnitRetirementService;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardCycleReopeningService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * O encadeamento das competências quando há competência cancelada, reaberta,
 * ausente ou gerada fora de ordem.
 *
 * O invariante: cada venda entra como movimento em exatamente uma competência
 * publicada -- nunca em duas, nunca em nenhuma --, e a posição publicada só muda
 * pela retificação. Cada cenário percorre o fluxo real (geração, cancelamento,
 * reabertura, validação, análise, aprovação) e termina contando em quais
 * publicações vigentes a venda aparece.
 */

/**
 * As publicações vigentes em que a venda do contrato é movimento, como
 * `aaaa-mm:timing` (`mes` para o movimento do mês).
 *
 * @return list<string>
 */
function chainPublishedSaleOwners(Contract $contract): array
{
    return SalesBoardPublication::query()
        ->whereDoesntHave('supersededBy')
        ->with('cycle')
        ->get()
        ->flatMap(fn (SalesBoardPublication $publication): array => SalesBoardCycleMovement::query()
            ->where('sales_board_cycle_baseline_id', $publication->sales_board_cycle_baseline_id)
            ->where('contract_id', $contract->id)
            ->where('movement_type', SalesBoardMovementType::Sale->value)
            ->get()
            ->map(fn (SalesBoardCycleMovement $movement): string => $publication->cycle->reference_month->format('Y-m').':'.($movement->timing?->value ?? 'mes'))
            ->all())
        ->sort()
        ->values()
        ->all();
}

function chainCancel(SalesBoardCycle $cycle): void
{
    app(SalesBoardCycleCancellationService::class)->cancel($cycle->fresh(), GovernanceFixture::approver(), 'Competência encerrada sem posição no piloto.');
}

function chainReopen(SalesBoardCycle $cycle): SalesBoardCycle
{
    return app(SalesBoardCycleReopeningService::class)->reopen($cycle->fresh(), GovernanceFixture::approver(), 'O cancelamento foi um engano: a fonte já estava correta.');
}

/**
 * O item "Competência anterior encerrada" do portão da análise.
 *
 * @return array{label: string, passed: bool, detail: string|null}
 */
function chainOrderCheck(SalesBoardCycle $cycle): array
{
    $review = ExtemporaneousFixture::analysis($cycle);

    return collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'Competência anterior encerrada');
}

/**
 * Os códigos dos avisos que a versão congelou para a unidade.
 *
 * @return list<string>
 */
function chainFrozenWarningCodes(SalesBoardCycleBaseline $baseline, ConstructionUnit $unit): array
{
    return collect($baseline->frozenWarnings() ?? [])
        ->where('construction_unit_id', $unit->id)
        ->pluck('code')
        ->values()
        ->all();
}

/**
 * Leva de novo à publicação a competência que ficou desatualizada: confere,
 * recalcula e passa pelo fluxo inteiro.
 */
function chainRepublishAfterRecalculation(SalesBoardCycle $cycle): void
{
    expect(CycleFixture::check($cycle)->impact)->toBe(SalesBoardStaleImpact::Material);

    CycleFixture::recalculate($cycle, 'A competência de partida mudou.');

    ExtemporaneousFixture::publish($cycle->fresh());
}

it('holds August while its anchor across a cancelled July is open, and publishes the late June sale only once', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $june = CycleFixture::generate($construction, '2026-06-01')->cycle;
    chainCancel(CycleFixture::generate($construction, '2026-07-01')->cycle);

    // Lançada depois da versão congelada de junho: em agosto, extemporânea contra junho V1.
    $sale = ExtemporaneousFixture::sale($units[0], '2026-06-20');

    $august = ExtemporaneousFixture::generateAugust($construction);

    expect(ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::Extemporaneous))->toHaveCount(1);

    $review = ExtemporaneousFixture::analysis($august);
    ManagementReviewFixture::decideAll($review);
    $check = collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'Competência anterior encerrada');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain('A competência 06/2026 ainda não foi aprovada nem cancelada (situação: Gerado)')
        ->and($check['detail'])->toContain('Como 07/2026 foi cancelada, os movimentos de 08/2026 partem da posição de 06/2026')
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'aprove ou cancele 06/2026 antes de aprovar 08/2026');

    CycleFixture::recalculate($june, 'Venda de junho lançada depois da apuração.');
    ExtemporaneousFixture::publish($june->fresh());

    chainRepublishAfterRecalculation($august);

    expect($august->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and(chainPublishedSaleOwners($sale))->toBe(['2026-06:mes']);
});

it('never leaves a sale of the open anchor without publication when the anchor is cancelled later', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    // Abaixo do piso de 450.000: só passa pela Gestão se virar movimento publicado.
    $sale = ExtemporaneousFixture::sale($units[0], '2026-06-15', '400000.00');

    $june = CycleFixture::generate($construction, '2026-06-01')->cycle;
    chainCancel(CycleFixture::generate($construction, '2026-07-01')->cycle);
    $august = ExtemporaneousFixture::generateAugust($construction);

    $review = ExtemporaneousFixture::analysis($august);
    ManagementReviewFixture::decideAll($review);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'A competência 06/2026 ainda não foi aprovada nem cancelada');

    // A Gestão cancela junho: agosto passa a absorver junho e julho, sem âncora,
    // e o ouvinte do cancelamento já o marca como desatualizado.
    chainCancel($june);

    expect(CycleFixture::currentBaseline($august)->stale_impact)->toBe(SalesBoardStaleImpact::Material);

    CycleFixture::recalculate($august, 'Junho cancelado: agosto absorve os fatos dele.');

    $absorbed = ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::WithoutPosition)->sole();
    $analysis = ExtemporaneousFixture::analysis($august->fresh());

    expect($absorbed->contract_id)->toBe($sale->id)
        ->and($absorbed->conformity_status)->toBe(SalesPriceConformityStatus::NonConform)
        ->and(ManagementReviewFixture::nonconformityOf($analysis, SalesBoardNonconformityOrigin::SystemSaleNonConform)->movement->contract_id)->toBe($sale->id);

    ManagementReviewFixture::decideAll($analysis);
    ManagementReviewFixture::approve($analysis);

    expect(chainPublishedSaleOwners($sale))->toBe(['2026-08:competencia_sem_posicao']);
});

it('refuses to freeze and to publish a competence after a later one of the construction was published', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    // Agosto publicado sem ciclo em julho (a prontidão de julho estava bloqueada).
    ExtemporaneousFixture::publish(ExtemporaneousFixture::generateAugust($construction));

    $late = ExtemporaneousFixture::sale($units[0], '2026-07-15');

    $july = CycleFixture::generate($construction, '2026-07-01');

    expect($july->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
        ->and($july->isBehindLaterPublication())->toBeTrue()
        ->and($july->laterPublishedMonth?->format('m/Y'))->toBe('08/2026')
        ->and($july->blockedReason)->toStartWith('A competência 07/2026 não pode ser congelada: 08/2026 já foi publicada')
        ->and(SalesBoardCycle::query()->whereDate('reference_month', '2026-07-01')->exists())->toBeFalse();

    // A venda de julho lançada depois de agosto entra uma vez, extemporânea em setembro.
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-09-01')->cycle);

    expect(chainPublishedSaleOwners($late))->toBe(['2026-09:extemporaneo']);
});

it('refuses to freeze a competence behind a later publication on the screen and on the command too', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    [$construction] = CycleFixture::readyConstruction(2);
    ExtemporaneousFixture::publish(ExtemporaneousFixture::generateAugust($construction));

    $page = Livewire::test(ListSalesBoardCycles::class)
        ->callAction(TestAction::make('generateCycle'), data: [
            'construction_id' => $construction->id,
            'reference_month' => '2026-07-01 00:00:00',
        ]);

    $notification = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications');
    $body = (string) (collect($notification)->last()['body'] ?? '');

    expect($body)->toStartWith('A competência 07/2026 não pode ser congelada: 08/2026 já foi publicada');

    $page->assertNotified('Geração bloqueada');

    Artisan::call('sales-boards:generate-cycle', [
        '--construction' => $construction->id,
        '--reference-month' => '07/2026',
    ]);

    expect(Artisan::output())->toContain('BLOQUEADO A competência 07/2026 não pode ser congelada: 08/2026 já foi publicada')
        ->and(SalesBoardCycle::query()->whereDate('reference_month', '2026-07-01')->exists())->toBeFalse();
});

it('refuses to publish a cycle left behind a later publication, whatever made it exist', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    $sale = ExtemporaneousFixture::sale($units[0], '2026-07-10');

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    chainCancel($july);
    ExtemporaneousFixture::publish(ExtemporaneousFixture::generateAugust($construction));

    // Um ciclo de julho de volta a "Gerado" por fora da reabertura -- o estado
    // que uma corrida antiga ou uma carga deixariam.
    DB::table('sales_board_cycles')->where('id', $july->id)->update(['status' => SalesBoardCycleStatus::Generated->value]);

    $review = ExtemporaneousFixture::analysis($july->fresh());
    ManagementReviewFixture::decideAll($review);
    $check = collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'Nenhuma competência posterior publicada');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain('08/2026 já foi publicada')
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'A competência 07/2026 não pode ser publicada: 08/2026 já foi publicada')
        ->and(chainPublishedSaleOwners($sale))->toBe(['2026-08:competencia_sem_posicao']);
});

it('absorbs the first automated competence cancelled by the Gestão into the next one', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    // Julho é a primeira competência da obra: não há ciclo em junho.
    $sale = ExtemporaneousFixture::sale($units[0], '2026-07-12', '400000.00');

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    chainCancel($july);

    $august = ExtemporaneousFixture::generateAugust($construction);
    $absorbed = ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::WithoutPosition)->sole();

    expect($absorbed->contract_id)->toBe($sale->id)
        ->and($absorbed->conformity_status)->toBe(SalesPriceConformityStatus::NonConform)
        ->and(CycleFixture::currentBaseline($august)->previous_competence_baseline_id)->toBeNull();

    $review = ExtemporaneousFixture::analysis($august);

    expect(ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform)->movement->contract_id)->toBe($sale->id);

    ManagementReviewFixture::decideAll($review);
    ManagementReviewFixture::approve($review);

    expect(chainPublishedSaleOwners($sale))->toBe(['2026-08:competencia_sem_posicao'])
        ->and(fn () => chainReopen($july))
        ->toThrow(SalesBoardCycleReopeningException::class, 'A competência 07/2026 não pode ser reaberta: 08/2026 já foi publicada e absorveu os fatos dela.');

    // O que chega depois da publicação de agosto, datado no mês cancelado, entra
    // uma vez: extemporâneo em setembro.
    $late = ExtemporaneousFixture::sale($units[1], '2026-07-25');
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-09-01')->cycle);

    expect(chainPublishedSaleOwners($late))->toBe(['2026-09:extemporaneo']);
});

it('holds the competence after a reopened one across a cancelled month, and publishes the late sale only once', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-05-01')->cycle);

    $june = CycleFixture::generate($construction, '2026-06-01')->cycle;
    chainCancel($june);
    chainCancel(CycleFixture::generate($construction, '2026-07-01')->cycle);

    chainReopen($june);

    // Lançada depois da versão de junho: agosto a apura contra junho, aberto.
    $sale = ExtemporaneousFixture::sale($units[0], '2026-06-20');
    $august = ExtemporaneousFixture::generateAugust($construction);

    $check = chainOrderCheck($august);

    expect(ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::Extemporaneous))->toHaveCount(1)
        ->and($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain('A competência 06/2026 ainda não foi aprovada nem cancelada');

    CycleFixture::recalculate($june, 'Venda de junho lançada depois da apuração.');
    ExtemporaneousFixture::publish($june->fresh());

    chainRepublishAfterRecalculation($august);

    expect(chainPublishedSaleOwners($sale))->toBe(['2026-06:mes']);
});

/**
 * Julho cancelado só com a baixa de uma unidade, em 10/07, e nenhuma venda:
 * agosto absorveu julho e avisou a baixa. Reaberto julho, nenhum número de
 * agosto muda -- o fingerprint não enxerga nada --, mas a janela, o aviso e a
 * ponte dele partiam de junho. O ouvinte da reabertura marca agosto pela cadeia,
 * e o recálculo cria a versão que parte de julho, sem o aviso, que passa a ser
 * de julho. A versão nova de julho não marca agosto de novo: a âncora é a
 * mesma, só a versão dela mudou.
 */
it('marks the next competence when a cancelled month without facts is reopened, and recalculates it into the reopened chain', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    chainCancel($july);
    app(ConstructionUnitRetirementService::class)->retire($units[2], GovernanceFixture::approver(), CarbonImmutable::parse('2026-07-10'), 'Unidade cadastrada em duplicidade na carga inicial.');

    $august = ExtemporaneousFixture::generateAugust($construction);
    $absorbing = CycleFixture::currentBaseline($august);

    expect($absorbing->absorbedCancelledMonths())->toBe(['2026-07'])
        ->and(chainFrozenWarningCodes($absorbing, $units[2]))->toBe([SalesBoardIssueCode::UnitRetired->value]);

    chainReopen($july);

    // A marca vem do ouvinte da reabertura, antes de qualquer conferência.
    expect($absorbing->fresh()->is_stale)->toBeTrue()
        ->and($absorbing->fresh()->stale_impact)->toBe(SalesBoardStaleImpact::Material);

    $check = CycleFixture::check($august);

    expect($check->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and($check->chainChanged())->toBeTrue()
        ->and($check->diff->isEmpty())->toBeTrue()
        ->and($check->message())->toContain('A cadeia de competências mudou depois desta versão: ela foi apurada a partir de 06/2026, absorvendo a competência cancelada 07/2026, e hoje a competência parte de 07/2026.');

    expect(CycleFixture::recalculate($august, 'Julho reaberto: agosto parte dele.')->createdNewVersion())->toBeTrue();

    $reanchored = CycleFixture::currentBaseline($august);

    expect($reanchored->snapshot_fingerprint)->toBe($absorbing->snapshot_fingerprint)
        ->and($reanchored->absorbedCancelledMonths())->toBe([])
        ->and($reanchored->previous_competence_baseline_id)->toBe(CycleFixture::currentBaseline($july)->id)
        ->and(chainFrozenWarningCodes($reanchored, $units[2]))->toBe([])
        ->and(CycleFixture::check($august)->impact)->toBe(SalesBoardStaleImpact::None);

    CycleFixture::recalculate($july, 'Julho reaberto com a baixa de 10/07.');

    expect(chainFrozenWarningCodes(CycleFixture::currentBaseline($july), $units[2]))->toBe([SalesBoardIssueCode::UnitRetired->value])
        ->and(CycleFixture::check($august)->impact)->toBe(SalesBoardStaleImpact::None);
});

/**
 * O caminho inverso: agosto partiu de julho aberto e já está em análise quando
 * julho é cancelado, sem fato nenhum. Agosto passa a partir de junho,
 * absorvendo julho, e fica marcado pela cadeia mesmo sem número diferente: o
 * portão diz por quê e a aprovação recusa. O recálculo registra a cadeia nova
 * com a mesma posição, e a análise continua valendo para ela.
 */
it('marks the next competence when the month it started from is cancelled without facts, and holds its approval until the recalculation', function () {
    [$construction] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    $august = ExtemporaneousFixture::generateAugust($construction);
    $anchored = CycleFixture::currentBaseline($august);
    $review = ExtemporaneousFixture::analysis($august);
    ManagementReviewFixture::decideAll($review);

    chainCancel($july);

    $staleCheck = collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'Fonte sem alteração material');

    expect($anchored->fresh()->stale_impact)->toBe(SalesBoardStaleImpact::Material)
        ->and($staleCheck['passed'])->toBeFalse()
        ->and($staleCheck['detail'])->toBe('Alterações materiais. A cadeia de competências mudou depois desta versão: ela foi apurada a partir de 07/2026, e hoje a competência parte de 06/2026 e absorve a competência cancelada 07/2026.')
        ->and(fn () => ManagementReviewFixture::approve($review->fresh()))
        ->toThrow(SalesBoardManagementReviewException::class, 'Recalcule antes da aprovação');

    CycleFixture::recalculate($august, 'Julho cancelado: agosto parte de junho.');

    expect(CycleFixture::currentBaseline($august)->absorbedCancelledMonths())->toBe(['2026-07'])
        ->and(ManagementReviewFixture::approve($review->fresh())->outcome->value)->toBe('aprovado');
});

/**
 * Junho é a âncora de agosto (julho cancelado). A permuta da unidade 103
 * valia em junho e terminou em 20/07; corrigido o valor dela, só a posição de
 * junho muda, e junho é retificada e publicada de novo. A cadeia de agosto é
 * a mesma -- a âncora continua junho, só a versão mudou -- e o conteúdo
 * também: o ouvinte da retificação confere agosto e não o marca.
 */
it('does not mark the next competence when its anchor publishes a rectification that changes nothing it inherited', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $exchange = ConstructionUnitExchange::factory()->forUnit($units[2])->effectiveFrom('2026-05-01')->endedOn('2026-07-20')->worth('300000.00')->create();

    $june = CycleFixture::generate($construction, '2026-06-01')->cycle;
    ExtemporaneousFixture::publish($june);
    chainCancel(CycleFixture::generate($construction, '2026-07-01')->cycle);

    $august = ExtemporaneousFixture::generateAugust($construction);
    $baseline = CycleFixture::currentBaseline($august);

    $this->travel(1)->hours();
    $exchange->forceFill(['exchange_value' => '320000.00'])->save();

    ExtemporaneousFixture::rectify($june);
    ExtemporaneousFixture::publish($june->fresh());

    $checked = $baseline->fresh();

    expect(SalesBoardPublication::query()->where('sales_board_cycle_id', $june->id)->count())->toBe(2)
        ->and($checked->previous_competence_baseline_id)->not->toBe(CycleFixture::currentBaseline($june)->id)
        // Conferida pelo ouvinte depois da retificação, e sem marca.
        ->and($checked->last_checked_at->greaterThan($baseline->last_checked_at))->toBeTrue()
        ->and($checked->is_stale)->toBeFalse()
        ->and($checked->stale_impact)->toBe(SalesBoardStaleImpact::None)
        ->and(CycleFixture::check($august)->chainChanged())->toBeFalse();
});

/**
 * A versão congelada antes do registro da cadeia não tem com o que comparar:
 * ela continua julgada só pelo conteúdo, e o deploy não marca de uma vez toda
 * competência em andamento.
 */
it('leaves the chain of a version frozen before the chain was recorded out of the comparison', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    chainCancel($july);
    app(ConstructionUnitRetirementService::class)->retire($units[2], GovernanceFixture::approver(), CarbonImmutable::parse('2026-07-10'), 'Unidade cadastrada em duplicidade na carga inicial.');

    $august = ExtemporaneousFixture::generateAugust($construction);
    $baseline = CycleFixture::currentBaseline($august);

    // Como uma versão gravada antes das duas colunas.
    DB::table('sales_board_cycle_baselines')->where('id', $baseline->id)->update([
        'previous_competence_baseline_id' => null,
        'absorbed_cancelled_months' => null,
    ]);

    chainReopen($july);

    expect($baseline->fresh()->stale_impact)->toBe(SalesBoardStaleImpact::None)
        ->and(CycleFixture::check($august)->chainChanged())->toBeFalse();
});
