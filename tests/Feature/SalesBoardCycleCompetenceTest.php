<?php

use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardGenerationOutcome;
use App\Enums\SalesBoardSource;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Support\SalesBoards\CompetenceCalendar;
use App\Support\SalesBoards\ReferenceMonthInput;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * Instantes no relógio da aplicação (UTC) e a competência encerrada mais recente
 * no calendário de Brasília.
 *
 * Os dias 31 são os meses em que `now()->subMonth()` transbordava e devolvia o
 * próprio mês. O dia 1º à 01h UTC ainda é o último dia do mês anterior, às 22h,
 * em Brasília. O fuso fica escrito por extenso de propósito: derivá-lo da
 * configuração faria o teste e o código deslizarem juntos.
 */
dataset('instantes de virada', [
    '31/10 ao meio-dia' => ['2026-10-31 12:00:00', '2026-09'],
    '31/12 ao meio-dia' => ['2026-12-31 12:00:00', '2026-11'],
    '31/03 ao meio-dia' => ['2027-03-31 12:00:00', '2027-02'],
    '01/10 à 01h UTC (30/09 às 22h em Brasília)' => ['2026-10-01 01:00:00', '2026-08'],
    '01/10 às 03h UTC (meia-noite em Brasília)' => ['2026-10-01 03:00:00', '2026-09'],
]);

function travelToUtc(string $instant): void
{
    test()->travelTo(CarbonImmutable::parse($instant, 'UTC'));
}

/**
 * Um empreendimento pronto, de uma Emissão que ainda registra o Quadro à mão.
 */
function legacyReadyConstruction(): Construction
{
    $construction = Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);

    SalesDiscountPolicy::factory()->forConstruction($construction)
        ->effectiveFrom('2020-01-01')->allowing('10.00')->create();

    DerivationFixture::unit($construction, '101');

    return $construction;
}

function actingAsCycleAdmin(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->seed(RolesAndPermissionsSeeder::class);
    test()->actingAs(makeAdminUser());
}

describe('competência padrão', function () {
    it('takes the last month closed in the business calendar, never the running one', function (string $instant, string $expected) {
        travelToUtc($instant);

        expect(ReferenceMonthInput::parseOrPreviousMonth(null)->format('Y-m'))->toBe($expected)
            ->and(ReferenceMonthInput::parseOrPreviousMonth(null)->toDateString())->toBe($expected.'-01')
            ->and(CompetenceCalendar::isClosed(CarbonImmutable::parse($expected.'-01')))->toBeTrue()
            ->and(CompetenceCalendar::isClosed(CarbonImmutable::parse($expected.'-01')->addMonth()))->toBeFalse();
    })->with('instantes de virada');

    it('freezes the last closed month when the command gets no competence', function (string $instant, string $expected) {
        [$construction] = CycleFixture::readyConstruction(2);

        travelToUtc($instant);

        $this->artisan('sales-boards:generate-cycle', ['--construction' => $construction->id])
            ->expectsOutputToContain('Competência: '.CarbonImmutable::parse($expected.'-01')->format('m/Y'))
            ->expectsOutputToContain('GERADO')
            ->assertExitCode(0);

        $cycle = SalesBoardCycle::query()->sole();

        expect($cycle->reference_month->format('Y-m'))->toBe($expected)
            ->and($cycle->position_date->toDateString())->toBe(CarbonImmutable::parse($expected.'-01')->endOfMonth()->toDateString());
    })->with('instantes de virada');

    it('proposes and freezes the last closed month in the freeze action', function (string $instant, string $expected) {
        actingAsCycleAdmin();
        [$construction] = CycleFixture::readyConstruction(2);

        travelToUtc($instant);

        $page = Livewire::test(ListSalesBoardCycles::class)
            ->mountAction(TestAction::make('generateCycle'));

        expect(CarbonImmutable::parse($page->get('mountedActions.0.data.reference_month'))->format('Y-m'))->toBe($expected);

        $page->setActionData(['construction_id' => $construction->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect(SalesBoardCycle::query()->sole()->reference_month->format('Y-m'))->toBe($expected);
    })->with('instantes de virada');
});

describe('competência ainda aberta', function () {
    it('refuses to freeze a competence whose last day has not passed in Brasília', function (string $instant, string $month) {
        [$construction] = CycleFixture::readyConstruction(2);

        travelToUtc($instant);

        $result = CycleFixture::generate($construction, $month);

        expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
            ->and($result->blockedReason)->toContain('ainda não terminou no calendário de negócio')
            ->and(SalesBoardCycle::query()->count())->toBe(0)
            ->and(SalesBoardCycleBaseline::query()->count())->toBe(0);
    })->with([
        'o mês corrente' => ['2026-09-25 15:00:00', '2026-09-01'],
        'um mês futuro' => ['2026-09-25 15:00:00', '2027-01-01'],
        'o último minuto do mês em Brasília' => ['2026-10-01 02:59:59', '2026-09-01'],
        'o dia 31 em que o padrão antigo transbordava' => ['2026-10-31 12:00:00', '2026-10-01'],
    ]);

    it('freezes the month as soon as its last day has passed in Brasília', function () {
        [$construction] = CycleFixture::readyConstruction(2);

        travelToUtc('2026-10-01 03:00:00');

        expect(CycleFixture::generate($construction, '2026-09-01')->outcome)->toBe(SalesBoardGenerationOutcome::Generated);
    });

    it('refuses an open competence through the command, even on a dry run', function () {
        [$construction] = CycleFixture::readyConstruction(2);

        travelToUtc('2026-09-25 15:00:00');

        $this->artisan('sales-boards:generate-cycle', [
            '--construction' => $construction->id,
            '--reference-month' => '09/2026',
        ])
            ->expectsOutputToContain('BLOQUEADO A competência 09/2026 ainda não terminou')
            ->assertExitCode(0);

        $this->artisan('sales-boards:generate-cycle', [
            '--construction' => $construction->id,
            '--reference-month' => '09/2026',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('BLOQUEADO')
            ->doesntExpectOutputToContain('SERIA GERADO')
            ->assertExitCode(0);

        expect(SalesBoardCycle::query()->count())->toBe(0);
    });

    it('does not let the freeze action pick a competence that has not ended', function (string $month) {
        actingAsCycleAdmin();
        [$construction] = CycleFixture::readyConstruction(2);

        travelToUtc('2026-09-25 15:00:00');

        Livewire::test(ListSalesBoardCycles::class)
            ->callAction(TestAction::make('generateCycle'), [
                'construction_id' => $construction->id,
                'reference_month' => $month,
            ])
            ->assertHasActionErrors(['reference_month' => 'before_or_equal']);

        expect(SalesBoardCycle::query()->count())->toBe(0);
    })->with(['o mês corrente' => '2026-09-01', 'um mês futuro' => '2027-01-01']);
});

describe('cobertura da automação', function () {
    it('refuses to freeze a competence of an emission still in legacy mode', function () {
        $construction = legacyReadyConstruction();

        $result = CycleFixture::generate($construction);

        expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
            ->and($result->blockedReason)->toContain('modo legado')
            ->and(SalesBoardCycle::query()->count())->toBe(0)
            ->and(SalesBoard::query()->count())->toBe(0)
            ->and($construction->emission->fresh()->sales_board_source)->toBe(SalesBoardSource::Legacy);
    });

    it('refuses a competence before the activation of the automation', function () {
        [$construction] = CycleFixture::readyConstruction(2);
        CycleFixture::automate($construction->emission, '2026-08-01');

        $before = CycleFixture::generate($construction, '2026-07-01');

        expect($before->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
            ->and($before->blockedReason)->toContain('anterior à ativação da automação')
            ->and($before->blockedReason)->toContain('08/2026')
            ->and(SalesBoardCycle::query()->count())->toBe(0)
            ->and(CycleFixture::generate($construction, '2026-08-01')->outcome)->toBe(SalesBoardGenerationOutcome::Generated);
    });

    it('rereads the mode of the emission instead of trusting a relation loaded before the return to legacy', function () {
        [$construction] = CycleFixture::readyConstruction(2);
        $construction->load('emission');

        Emission::query()->whereKey($construction->emission_id)->update([
            'sales_board_source' => SalesBoardSource::Legacy->value,
            'sales_board_automation_start_reference_month' => null,
        ]);

        $result = app(SalesBoardGenerationService::class)
            ->generateForConstruction($construction, CarbonImmutable::parse('2026-07-01'));

        expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
            ->and(SalesBoardCycle::query()->count())->toBe(0);
    });

    it('refuses a legacy emission through the command', function () {
        $construction = legacyReadyConstruction();

        $this->artisan('sales-boards:generate-cycle', [
            '--construction' => $construction->id,
            '--reference-month' => '07/2026',
        ])
            ->expectsOutputToContain('(modo legado)')
            ->doesntExpectOutputToContain('GERADO')
            ->assertExitCode(0);

        expect(SalesBoardCycle::query()->count())->toBe(0);
    });

    it('refuses a target whose emission went back to legacy through the automation', function () {
        $construction = AutomationFixture::readyConstruction();
        AutomationFixture::enable([$construction]);

        Emission::query()->whereKey($construction->emission_id)->update([
            'sales_board_source' => SalesBoardSource::Legacy->value,
            'sales_board_automation_start_reference_month' => null,
        ]);

        AutomationFixture::run();

        $target = SalesBoardAutomationTarget::query()->sole();

        expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Blocked)
            ->and($target->last_blocker_message)->toContain('modo legado')
            ->and(SalesBoardCycle::query()->count())->toBe(0);
    });

    it('offers in the freeze action only the constructions of an automated emission', function () {
        actingAsCycleAdmin();
        [$covered] = CycleFixture::readyConstruction(2);
        $legacy = legacyReadyConstruction();

        Livewire::test(ListSalesBoardCycles::class)
            ->callAction(TestAction::make('generateCycle'), [
                'construction_id' => $legacy->id,
                'reference_month' => '2026-07-01',
            ])
            ->assertHasActionErrors(['construction_id']);

        expect(SalesBoardCycle::query()->count())->toBe(0);

        Livewire::test(ListSalesBoardCycles::class)
            ->callAction(TestAction::make('generateCycle'), [
                'construction_id' => $covered->id,
                'reference_month' => '2026-07-01',
            ])
            ->assertHasNoActionErrors();

        expect(SalesBoardCycle::query()->sole()->construction_id)->toBe($covered->id);
    });

    it('disables the freeze action while no emission has the automation on', function () {
        actingAsCycleAdmin();
        legacyReadyConstruction();

        Livewire::test(ListSalesBoardCycles::class)
            ->assertActionVisible(TestAction::make('generateCycle'))
            ->assertActionDisabled(TestAction::make('generateCycle'));

        expect(SalesBoardCycle::query()->count())->toBe(0);
    });

    it('does not let the freeze action pick a competence before the activation', function () {
        actingAsCycleAdmin();
        [$construction] = CycleFixture::readyConstruction(2);
        CycleFixture::automate($construction->emission, '2026-08-01');

        Livewire::test(ListSalesBoardCycles::class)
            ->callAction(TestAction::make('generateCycle'), [
                'construction_id' => $construction->id,
                'reference_month' => '2026-07-01',
            ])
            ->assertHasActionErrors(['reference_month' => 'after_or_equal']);

        expect(SalesBoardCycle::query()->count())->toBe(0);
    });
});
