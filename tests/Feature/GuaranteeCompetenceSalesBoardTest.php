<?php

use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeType;
use App\Enums\GuaranteeValueStatus;
use App\Enums\SalesBoardPositionStatus;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\GuaranteesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeSnapshot;
use App\Models\IntegralizationHistory;
use App\Models\PuHistory;
use App\Models\SalesBoard;
use App\Models\User;
use App\Services\Guarantees\EmissionGuaranteeCoverageEngine;
use App\Services\Guarantees\GuaranteeAlertBuilder;
use App\Services\Guarantees\GuaranteeSnapshotWriter;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Emissão com garantia de estoque sobre um empreendimento: exigência de 120% de
 * um saldo devedor de R$ 8 mi (PU 8.000 × 1.000 cotas) e quadro de julho com
 * R$ 10 mi em estoque (cobertura de 125%).
 *
 * @return array{0: Emission, 1: Construction, 2: SalesBoard}
 */
function stockGuaranteeEmission(): array
{
    $emission = Emission::factory()->create(['issued_quantity' => 1000000]);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Alfa',
    ]);

    IntegralizationHistory::query()->create([
        'emission_id' => $emission->id,
        'date' => '2026-06-01',
        'quantity' => 1000,
        'unit_value' => 1,
        'financial_value' => 1000,
        'investor_fund' => 'Fundo A',
    ]);

    foreach (['2026-07-31', '2026-08-31', '2026-09-14'] as $date) {
        PuHistory::query()->create(['emission_id' => $emission->id, 'date' => $date, 'unit_value' => 8000]);
    }

    $julyBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 20,
        'stock_value' => 10_000_000,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->requiringPercentage(1.2)
        ->create([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    return [$emission, $construction, $julyBoard];
}

function registerAugustBoard(Emission $emission, Construction $construction, float $stockValue = 6_000_000): SalesBoard
{
    return SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-08-01',
        'stock_units' => 12,
        'stock_value' => $stockValue,
    ]);
}

function competenceGuaranteesTab(Emission $emission)
{
    return Livewire::test(GuaranteesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ]);
}

function guaranteeEditorUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

function augustSnapshot(Emission $emission): ?GuaranteeSnapshot
{
    return GuaranteeSnapshot::query()
        ->where('emission_id', $emission->id)
        ->whereDate('reference_month', '2026-08-01')
        ->first();
}

it('offers the previous business month as the competence to update and close', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = stockGuaranteeEmission();
    $this->actingAs(makeAdminUser());

    competenceGuaranteesTab($emission)
        ->mountAction(TestAction::make('update_competence')->table())
        ->assertSchemaStateSet(['reference_month' => '08/2026'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Posição da competência 08/2026 atualizada.');

    expect(augustSnapshot($emission))->not->toBeNull()
        ->and(GuaranteeSnapshot::query()->whereDate('reference_month', '2026-09-01')->exists())->toBeFalse();

    competenceGuaranteesTab($emission->fresh())
        ->mountAction(TestAction::make('close_competence')->table())
        ->assertSchemaStateSet(['reference_month' => '08/2026']);
});

it('updates any past competence the user picks, not only the current month', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = stockGuaranteeEmission();
    $this->actingAs(makeAdminUser());

    competenceGuaranteesTab($emission)
        ->callTableAction('update_competence', data: ['reference_month' => '07/2026'])
        ->assertHasNoTableActionErrors();

    $snapshot = GuaranteeSnapshot::query()->where('emission_id', $emission->id)->sole();

    expect($snapshot->reference_month->toDateString())->toBe('2026-07-01')
        ->and((float) $snapshot->total_eligible_value)->toBe(10_000_000.0);
});

it('refuses a competence that has not started yet', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = stockGuaranteeEmission();
    $admin = makeAdminUser();
    $this->actingAs($admin);

    competenceGuaranteesTab($emission)
        ->callTableAction('close_competence', data: ['reference_month' => '10/2026'])
        ->assertHasTableActionErrors(['reference_month']);

    expect(GuaranteeSnapshot::query()->count())->toBe(0)
        ->and(fn () => app(GuaranteeSnapshotWriter::class)->close($emission, '2026-10-01', $admin))
        ->toThrow(ValidationException::class, 'A competência 10/2026 ainda não começou.');
});

it('requires an explicit confirmation to close with a carried-forward stock position', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission, $construction, $julyBoard] = stockGuaranteeEmission();
    $admin = makeAdminUser();
    $this->actingAs($admin);

    competenceGuaranteesTab($emission)
        ->mountAction(TestAction::make('close_competence')->table())
        ->assertMountedActionModalSee('Posição do Quadro de Vendas incompleta')
        ->assertMountedActionModalSee('Residencial Alfa: última posição conhecida (07/2026)')
        ->callMountedAction()
        ->assertHasActionErrors(['confirm_partial_coverage' => 'accepted']);

    expect(augustSnapshot($emission))->toBeNull()
        ->and(fn () => app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin))
        ->toThrow(ValidationException::class, 'Confirme o fechamento com a posição parcial.');

    competenceGuaranteesTab($emission->fresh())
        ->mountAction(TestAction::make('close_competence')->table())
        ->fillForm(['confirm_partial_coverage' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Competência 08/2026 fechada.');

    $snapshot = augustSnapshot($emission);

    expect($snapshot->isClosed())->toBeTrue()
        ->and((float) $snapshot->total_eligible_value)->toBe(10_000_000.0)
        ->and($snapshot->hasPartialCoverageConfirmation())->toBeTrue()
        ->and($snapshot->partial_coverage_confirmed_by)->toBe($admin->id)
        // `toEqual`: o JSON do MySQL reordena as chaves do objeto gravado.
        ->and($snapshot->partial_coverage_confirmation['gaps'])->toEqual([[
            'construction_id' => $construction->id,
            'construction_name' => 'Residencial Alfa',
            'status' => SalesBoardPositionStatus::CarriedForward->value,
            'reference_month_used' => '2026-07-01',
        ]])
        ->and($snapshot->salesBoardCoverage()->constructions)->toBe([[
            'construction_id' => $construction->id,
            'construction_name' => 'Residencial Alfa',
            'status' => SalesBoardPositionStatus::CarriedForward->value,
            'reference_month_used' => '2026-07-01',
        ]]);

    $closing = Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_CLOSED)->sole();

    expect($closing->properties['partial_coverage_confirmation']['gaps'][0]['reference_month_used'])->toBe('2026-07-01');
});

it('closes without confirmation once the board of the competence itself is published', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission, $construction] = stockGuaranteeEmission();
    registerAugustBoard($emission, $construction);
    $this->actingAs(makeAdminUser());

    competenceGuaranteesTab($emission)
        ->mountAction(TestAction::make('close_competence')->table())
        ->assertMountedActionModalDontSee('Posição do Quadro de Vendas incompleta')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $snapshot = augustSnapshot($emission);

    expect($snapshot->isClosed())->toBeTrue()
        ->and($snapshot->hasPartialCoverageConfirmation())->toBeFalse()
        ->and((float) $snapshot->total_eligible_value)->toBe(6_000_000.0)
        ->and($snapshot->coverage_status)->toBe(GuaranteeCoverageStatus::NonCompliant)
        ->and($snapshot->salesBoardCoverage()->hasGaps())->toBeFalse();
});

it('refuses a confirmation given for gaps that changed before the closing', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission, $construction] = stockGuaranteeEmission();
    $admin = makeAdminUser();

    $seenGaps = app(EmissionGuaranteeCoverageEngine::class)
        ->buildPosition($emission, '2026-08-01')
        ->salesBoardGapsFingerprint();

    // Outro empreendimento entra na emissão, sem quadro, depois da confirmação.
    $other = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Residencial Beta']);
    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->create([
            'emission_id' => $emission->id,
            'construction_id' => $other->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    expect(fn () => app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin, $seenGaps))
        ->toThrow(ValidationException::class, 'mudou desde a confirmação');

    expect(augustSnapshot($emission))->toBeNull();
});

it('marks the competence as outdated when the board of that month is registered later', function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');
    [$emission, $construction] = stockGuaranteeEmission();
    $admin = makeAdminUser();

    app(GuaranteeSnapshotWriter::class)->close(
        $emission,
        '2026-08-01',
        $admin,
        app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission, '2026-08-01')->salesBoardGapsFingerprint(),
    );

    expect(augustSnapshot($emission)->isSalesBoardOutdated())->toBeFalse();

    Carbon::setTestNow('2026-09-15 10:00:00');
    registerAugustBoard($emission, $construction);

    $snapshot = augustSnapshot($emission);

    expect($snapshot->isSalesBoardOutdated())->toBeTrue()
        ->and($snapshot->sales_board_outdated_at->toDateTimeString())->toBe('2026-09-15 10:00:00')
        // O número fechado não muda sozinho: continua o histórico até a reabertura.
        ->and((float) $snapshot->total_eligible_value)->toBe(10_000_000.0)
        ->and($emission->fresh()->requiresMonthlyGuaranteeSnapshotUpdate())->toBeTrue()
        ->and($emission->fresh()->pendingGuaranteeSnapshotReason())->toContain('08/2026');

    $this->actingAs($admin);

    competenceGuaranteesTab($emission->fresh())
        ->assertSee('Desatualizada')
        ->assertSee('Competência desatualizada pelo Quadro de Vendas');

    expect(GuaranteesRelationManager::getBadge($emission->fresh(), EditEmission::class))->toBe('Pendente');
});

it('marks only the competences the changed board would alter', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission, $construction] = stockGuaranteeEmission();
    $admin = makeAdminUser();
    $writer = app(GuaranteeSnapshotWriter::class);

    $writer->persist($emission, '2026-07-01', $admin);
    $writer->persist($emission, '2026-08-01', $admin);

    // Quadro de outro empreendimento: a garantia recai só sobre o Alfa.
    $other = Construction::factory()->create(['emission_id' => $emission->id]);
    SalesBoard::factory()->forEmissionAndConstruction($emission, $other)->create(['reference_month' => '2026-07-01']);

    // Quadro de setembro: nenhuma competência apurada o usaria.
    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create(['reference_month' => '2026-09-01']);

    expect(GuaranteeSnapshot::query()->whereNotNull('sales_board_outdated_at')->count())->toBe(0);

    // Quadro de agosto: muda agosto, não julho.
    registerAugustBoard($emission, $construction);

    expect(augustSnapshot($emission)->isSalesBoardOutdated())->toBeTrue()
        ->and(GuaranteeSnapshot::query()->whereDate('reference_month', '2026-07-01')->sole()->isSalesBoardOutdated())->toBeFalse();
});

it('marks the competence when the board it used is corrected or removed', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission, $construction] = stockGuaranteeEmission();
    $admin = makeAdminUser();
    $augustBoard = registerAugustBoard($emission, $construction);
    $writer = app(GuaranteeSnapshotWriter::class);

    $writer->persist($emission, '2026-08-01', $admin);

    $augustBoard->changeReason = 'Correção do estoque';
    $augustBoard->update(['stock_value' => 7_000_000]);

    expect(augustSnapshot($emission)->isSalesBoardOutdated())->toBeTrue();

    // Apurar de novo tira a marca e usa o quadro corrigido.
    $writer->persist($emission, '2026-08-01', $admin);

    expect(augustSnapshot($emission)->isSalesBoardOutdated())->toBeFalse()
        ->and((float) augustSnapshot($emission)->total_eligible_value)->toBe(7_000_000.0);

    $augustBoard->delete();

    expect(augustSnapshot($emission)->isSalesBoardOutdated())->toBeTrue();
});

it('marks an emission-wide stock competence when a construction registers its first board', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    $emission = Emission::factory()->create();
    $admin = makeAdminUser();

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-08-01', $admin);

    $newcomer = Construction::factory()->create(['emission_id' => $emission->id]);
    SalesBoard::factory()->forEmissionAndConstruction($emission, $newcomer)->create(['reference_month' => '2026-08-01']);

    expect(augustSnapshot($emission)->isSalesBoardOutdated())->toBeTrue();
});

it('reports partial stock coverage instead of a complete automatic value', function (): void {
    Carbon::setTestNow('2026-08-20 12:00:00');

    $emission = Emission::factory()->create(['issued_quantity' => 1000000]);
    $current = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Alfa Atual']);
    $stale = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Beta Transportado']);
    $never = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Gama Sem Quadro']);

    SalesBoard::factory()->forEmissionAndConstruction($emission, $current)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 9,
        'stock_value' => 1_000_000,
    ]);
    SalesBoard::factory()->forEmissionAndConstruction($emission, $stale)->create([
        'reference_month' => '2025-12-01',
        'stock_units' => 50,
        'stock_value' => 5_000_000,
    ]);

    Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    $linked = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::Inventory)
        ->create([
            'emission_id' => $emission->id,
            'construction_id' => $current->id,
            'legal_status' => GuaranteeLegalStatus::Active,
        ]);

    $position = app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission->fresh(), '2026-07-01');
    $emissionWide = $position->positions->first(fn ($item) => $item->guarantee->construction_id === null);
    $linkedPosition = $position->positions->first(fn ($item) => $item->guarantee->is($linked));

    expect($emissionWide->value->status)->toBe(GuaranteeValueStatus::Partial)
        ->and($emissionWide->value->amount)->toBe(6_000_000.0)
        ->and($emissionWide->value->metadata['reason'])->toContain('Beta Transportado: última posição conhecida (12/2025)')
        ->and($emissionWide->value->metadata['reason'])->toContain('Gama Sem Quadro: sem quadro de vendas')
        // A garantia do Alfa tem o quadro do próprio mês: nada a sinalizar.
        ->and($linkedPosition->value->status)->toBe(GuaranteeValueStatus::Automatic)
        ->and($position->hasSalesBoardGaps())->toBeTrue()
        ->and($position->salesBoardGapDescriptions())->toBe([
            'Beta Transportado: última posição conhecida (12/2025)',
            'Gama Sem Quadro: sem quadro de vendas',
        ]);

    $alerts = app(GuaranteeAlertBuilder::class)->build($emission, $position);

    expect($alerts->pluck('title'))->toContain('Posição parcial do Quadro de Vendas');

    $report = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-07-01'));

    expect($report['guarantees']['partial_sales_board_position'])->toBeTrue()
        ->and($report['guarantees']['sales_board_gaps'])->toContain('Gama Sem Quadro: sem quadro de vendas');

    expect(fn () => app(GuaranteeSnapshotWriter::class)->close($emission->fresh(), '2026-07-01', makeAdminUser()))
        ->toThrow(ValidationException::class, 'está incompleta');
});

it('resolves the current competence in the business calendar, not in UTC', function (): void {
    // O fuso técnico é UTC em produção e no CI; um `.env` local com outro fuso
    // esconderia o deslocamento que este teste existe para pegar.
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    // 01:00 UTC de setembro = 22:00 em São Paulo no último dia de agosto.
    Carbon::setTestNow(Carbon::parse('2026-09-01 01:00:00', 'UTC'));

    expect(now()->toDateString())->toBe('2026-09-01');

    [$emission] = stockGuaranteeEmission();
    $admin = makeAdminUser();
    $this->actingAs($admin);

    expect(app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission)->referenceMonth)->toBe('2026-08-01')
        ->and(GuaranteeSnapshot::currentBusinessMonth())->toBe('2026-08-01')
        ->and(GuaranteeSnapshot::previousBusinessMonth())->toBe('2026-07-01');

    $guarantee = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    competenceGuaranteesTab($emission->fresh())
        ->assertSee('Competência 08/2026')
        ->mountTableAction('inform_value', $guarantee)
        ->assertTableActionDataSet(['reference_month' => '08/2026']);

    competenceGuaranteesTab($emission->fresh())
        ->mountAction(TestAction::make('close_competence')->table())
        ->assertSchemaStateSet(['reference_month' => '07/2026']);

    // Setembro ainda não começou no calendário de negócio.
    expect(fn () => app(GuaranteeSnapshotWriter::class)->close($emission, '2026-09-01', $admin))
        ->toThrow(ValidationException::class, 'A competência 09/2026 ainda não começou.');

    // O selo cobra julho — o mês de negócio anterior —, não agosto nem setembro.
    expect($emission->fresh()->pendingGuaranteeSnapshotReason())->toBe('A competência 07/2026 ainda não foi consolidada.');

    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-07-01', $admin);

    expect($emission->fresh()->requiresMonthlyGuaranteeSnapshotUpdate())->toBeFalse();
});

it('reopens a closed competence from the tab with a mandatory reason', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission, $construction] = stockGuaranteeEmission();
    registerAugustBoard($emission, $construction);
    $admin = makeAdminUser();
    $this->actingAs($admin);

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);

    // Fechada, a competência recusa atualização e digitação — com o motivo à vista.
    $quotas = Guarantee::factory()
        ->effectiveBetween()
        ->ofType(GuaranteeType::QuotaFiduciaryAlienation)
        ->create(['emission_id' => $emission->id, 'legal_status' => GuaranteeLegalStatus::Active]);

    competenceGuaranteesTab($emission->fresh())
        ->callTableAction('update_competence', data: ['reference_month' => '08/2026'])
        ->assertHasTableActionErrors(['reference_month']);

    competenceGuaranteesTab($emission->fresh())
        ->callTableAction('inform_value', $quotas, data: ['reference_month' => '08/2026', 'current_value' => '1.000,00'])
        ->assertNotified('Não foi possível informar o valor.');

    expect($quotas->monthlyPositions()->count())->toBe(0);

    competenceGuaranteesTab($emission->fresh())
        ->assertActionVisible(TestAction::make('reopen_competence')->table())
        ->mountAction(TestAction::make('reopen_competence')->table())
        ->assertSchemaStateSet(['reference_month' => '2026-08-01'])
        ->fillForm(['reason' => ''])
        ->callMountedAction()
        ->assertHasActionErrors(['reason' => 'required']);

    expect(augustSnapshot($emission)->isClosed())->toBeTrue();

    competenceGuaranteesTab($emission->fresh())
        ->callAction(TestAction::make('reopen_competence')->table(), [
            'reference_month' => '2026-08-01',
            'reason' => 'Correção do estoque do quadro de agosto.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Competência 08/2026 reaberta.');

    expect(augustSnapshot($emission)->isClosed())->toBeFalse();

    $reopening = Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_REOPENED)->sole();

    expect($reopening->properties['reason'])->toBe('Correção do estoque do quadro de agosto.')
        ->and($reopening->causer_id)->toBe($admin->id);

    // Reaberta, a competência volta a aceitar atualização pela aba.
    competenceGuaranteesTab($emission->fresh())
        ->callTableAction('update_competence', data: ['reference_month' => '08/2026'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Posição da competência 08/2026 atualizada.');
});

it('does not let a user without the reopen permission reopen a competence', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission, $construction] = stockGuaranteeEmission();
    registerAugustBoard($emission, $construction);
    $editor = guaranteeEditorUser();

    expect($editor->can('guarantees.close_competence'))->toBeTrue()
        ->and($editor->can('guarantees.reopen_competence'))->toBeFalse();

    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', makeAdminUser());

    $this->actingAs($editor);

    competenceGuaranteesTab($emission->fresh())
        ->assertActionHidden(TestAction::make('reopen_competence')->table())
        ->call('mountAction', 'reopen_competence', [], ['table' => true])
        ->assertSet('mountedActions', [])
        // Mesmo com o modal forjado no estado do componente, o servidor recusa.
        ->set('mountedActions', [[
            'name' => 'reopen_competence',
            'arguments' => [],
            'context' => ['table' => true],
            'data' => ['reference_month' => '2026-08-01', 'reason' => 'Tentativa sem permissão.'],
        ]])
        ->call('callMountedAction');

    expect(augustSnapshot($emission)->isClosed())->toBeTrue()
        ->and(Activity::query()->where('event', GuaranteeSnapshotWriter::EVENT_COMPETENCE_REOPENED)->exists())->toBeFalse()
        ->and(fn () => app(GuaranteeSnapshotWriter::class)->reopen($emission, '2026-08-01', $editor, 'Sem permissão.'))
        ->toThrow(AuthorizationException::class);
});

it('drops the partial-coverage confirmation of the undone closing and refuses a blank reason', function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
    [$emission] = stockGuaranteeEmission();
    $admin = makeAdminUser();
    $writer = app(GuaranteeSnapshotWriter::class);

    $writer->close(
        $emission,
        '2026-08-01',
        $admin,
        app(EmissionGuaranteeCoverageEngine::class)->buildPosition($emission, '2026-08-01')->salesBoardGapsFingerprint(),
    );

    expect(fn () => $writer->reopen($emission, '2026-08-01', $admin, '   '))
        ->toThrow(ValidationException::class, 'Informe o motivo da reabertura.');

    $writer->reopen($emission, '2026-08-01', $admin, 'Quadro de agosto publicado.');

    $snapshot = augustSnapshot($emission);

    expect($snapshot->isClosed())->toBeFalse()
        ->and($snapshot->hasPartialCoverageConfirmation())->toBeFalse()
        ->and($snapshot->partial_coverage_confirmation)->toBeNull();
});

it('uses only a closed competence as the consolidated guarantees of the monthly report', function (): void {
    Carbon::setTestNow('2026-08-28 10:00:00');
    [$emission, $construction] = stockGuaranteeEmission();
    $admin = makeAdminUser();

    // Apuração intermediária de agosto, com o quadro de julho.
    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-08-01', $admin);

    Carbon::setTestNow('2026-09-15 10:00:00');
    registerAugustBoard($emission, $construction);

    $report = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-08-01'));

    expect($report['guarantees']['consolidated'])->toBeFalse()
        ->and($report['guarantees']['closed_at'])->toBeNull()
        ->and($report['guarantees']['eligible_value'])->toBe('R$ 6.000.000,00')
        ->and($report['guarantees']['status'])->toBe(GuaranteeCoverageStatus::NonCompliant->label());

    app(GuaranteeSnapshotWriter::class)->persist($emission, '2026-08-01', $admin);
    app(GuaranteeSnapshotWriter::class)->close($emission, '2026-08-01', $admin);

    $report = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-08-01'));

    expect($report['guarantees']['consolidated'])->toBeTrue()
        ->and($report['guarantees']['closed_at'])->toBe('15/09/2026 10:00')
        ->and($report['guarantees']['sales_board_outdated'])->toBeFalse()
        ->and($report['guarantees']['eligible_value'])->toBe('R$ 6.000.000,00');
});

it('keeps a single valuation rule for guarantees, without the legacy coverage calculator', function (): void {
    expect(class_exists('App\\Services\\GuaranteeCoverageCalculator'))->toBeFalse();
});
