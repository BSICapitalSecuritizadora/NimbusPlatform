<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\SalesBoardRollouts\Pages\PreviewSalesBoardReadiness;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardReadinessPreviewService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * "Prévia de prontidão do Quadro": o diagnóstico que só existia na linha de
 * comando, como tela somente leitura para quem enxerga o rollout.
 *
 * Deriva cada empreendimento da Emissão na competência escolhida, mostra o que
 * bloqueia e o que só avisa, o ciclo que já existe e a integridade dos quadros
 * registrados -- e não grava nada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function readinessPreviewOf(Emission $emission, string $referenceMonth = '2026-07-01'): array
{
    return app(SalesBoardReadinessPreviewService::class)
        ->preview($emission->fresh(), CarbonImmutable::parse($referenceMonth))
        ->toArray();
}

/**
 * Quem só enxerga: o Quadro e a Emissão, sem nenhuma escrita.
 */
function readinessViewer(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo([AccessPermission::SalesBoardsView->value, AccessPermission::EmissionsView->value]);

    return $user;
}

function readinessBlockConstruction(Construction $construction): void
{
    ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'block' => '01', 'unit' => '950',
        'base_value' => null, 'base_value_reference_date' => null,
    ]);
}

function readinessRawBoard(int $emissionId, int $constructionId, string $referenceMonth): void
{
    DB::table('sales_boards')->insert([
        'emission_id' => $emissionId,
        'construction_id' => $constructionId,
        'reference_month' => $referenceMonth,
        'stock_units' => 1, 'financed_units' => 0, 'paid_units' => 0, 'exchanged_units' => 0, 'total_units' => 1,
        'stock_value' => '500000.00', 'financed_value' => '0.00', 'paid_value' => '0.00', 'exchanged_value' => '0.00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('previews the readiness of every construction of an emission without writing anything', function () {
    $scenario = RolloutFixture::emission(2);
    [$ready, $blocked] = $scenario['constructions'];
    readinessBlockConstruction($blocked);

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $preview = readinessPreviewOf($scenario['emission']);

    $rows = collect($preview['constructions'])->keyBy('id');
    $blocker = $rows[$blocked->id]['blockers'][0];

    expect($writes)->toBe([])
        ->and($preview['reference_month'])->toBe('07/2026')
        ->and($preview['ready_count'])->toBe(1)
        ->and($rows[$ready->id]['ready'])->toBeTrue()
        ->and($rows[$ready->id]['blockers'])->toBe([])
        ->and($rows[$ready->id]['buckets'])->toBe(['stock' => 2, 'financed' => 0, 'settled' => 0, 'exchanged' => 0, 'undetermined' => 0, 'total' => 2])
        ->and($rows[$blocked->id]['ready'])->toBeFalse()
        ->and($blocker['code'])->toBe('UNIT_VALUE_MISSING')
        ->and($blocker['count'])->toBe(1)
        ->and($blocker['label'])->not->toBe('UNIT_VALUE_MISSING')
        ->and($blocker['hint'])->toContain('Atualizar Valores');
});

it('shows the cycle that already exists for the competence and whether the automation covers it', function () {
    [$construction] = CycleFixture::readyConstruction(2);
    CycleFixture::generate($construction, '2026-07-01');

    $cycle = SalesBoardCycle::query()->sole();
    $preview = readinessPreviewOf($construction->emission);

    expect($preview['automation_covers'])->toBeTrue()
        ->and($preview['competence_closed'])->toBeTrue()
        ->and($preview['due_date'])->toBe('13/08/2026')
        ->and($preview['emission_liquidated'])->toBeFalse()
        ->and($preview['constructions'][0]['cycle'])->toBe(['id' => $cycle->id, 'status' => 'Gerado']);

    $legacy = RolloutFixture::emission(1);

    expect(readinessPreviewOf($legacy['emission'])['automation_covers'])->toBeFalse()
        ->and(readinessPreviewOf($legacy['emission'])['constructions'][0]['cycle'])->toBeNull();
});

it('lists boards recorded outside the construction emission and competences with several boards', function () {
    $scenario = RolloutFixture::emission(1);
    $construction = $scenario['constructions'][0];
    $elsewhere = Emission::factory()->create(['status' => 'active']);

    readinessRawBoard($scenario['emission']->id, $construction->id, '2026-06-01');
    readinessRawBoard($elsewhere->id, $construction->id, '2026-06-01');

    $preview = readinessPreviewOf($scenario['emission']);

    expect($preview['misplaced_boards'])->toHaveCount(1)
        ->and($preview['misplaced_boards'][0]['board_emission_id'])->toBe($elsewhere->id)
        ->and($preview['misplaced_boards'][0]['construction_emission_id'])->toBe($scenario['emission']->id)
        ->and($preview['misplaced_boards'][0]['reference_month'])->toBe('06/2026')
        ->and($preview['duplicated_competences'])->toHaveCount(1)
        ->and($preview['duplicated_competences'][0]['reference_month'])->toBe('06/2026');

    $clean = RolloutFixture::emission(1, 'C');

    expect(readinessPreviewOf($clean['emission'])['misplaced_boards'])->toBe([])
        ->and(readinessPreviewOf($clean['emission'])['duplicated_competences'])->toBe([]);
});

it('denies the preview to a user without sales-boards.view and serves a view-only user', function () {
    $scenario = RolloutFixture::emission(1);

    $this->actingAs(User::factory()->withTwoFactor()->create());

    Livewire::test(PreviewSalesBoardReadiness::class, ['record' => $scenario['emission']->getKey()])
        ->assertForbidden();

    $this->actingAs(readinessViewer());

    Livewire::test(PreviewSalesBoardReadiness::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertSee('Escolha a competência e calcule a prévia. Nada é gravado.')
        ->callAction('calculatePreview', data: ['reference_month' => '2026-07-01 00:00:00'])
        ->assertHasNoActionErrors()
        ->assertSee('Competência 07/2026')
        ->assertSee('Pronta')
        ->assertSee('Nenhum quadro fora da Emissão do empreendimento e nenhuma competência com mais de um quadro.')
        ->assertSet('preview.reference_month', '07/2026');
});

it('refuses a forged calculation from a user who lost the permission', function () {
    $scenario = RolloutFixture::emission(1);
    $viewer = readinessViewer();

    $this->actingAs($viewer);

    $page = Livewire::test(PreviewSalesBoardReadiness::class, ['record' => $scenario['emission']->getKey()])
        ->mountAction('calculatePreview')
        ->setActionData(['reference_month' => '2026-07-01 00:00:00']);

    $viewer->revokePermissionTo(AccessPermission::SalesBoardsView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer->fresh());

    // A ação reconfere a permissão no servidor: o modal aberto antes não é
    // licença para calcular.
    $page->callMountedAction()
        ->assertForbidden();
});

it('rate limits repeated previews for the same user', function () {
    $scenario = RolloutFixture::emission(1);
    $viewer = readinessViewer();

    $this->actingAs($viewer);

    foreach (range(1, PreviewSalesBoardReadiness::PREVIEWS_PER_MINUTE) as $attempt) {
        RateLimiter::hit('sales-board-readiness-preview:'.$viewer->id, 60);
    }

    Livewire::test(PreviewSalesBoardReadiness::class, ['record' => $scenario['emission']->getKey()])
        ->callAction('calculatePreview', data: ['reference_month' => '2026-07-01 00:00:00'])
        ->assertNotified('Muitas prévias seguidas. Aguarde um minuto.')
        ->assertSet('preview', null);

    RateLimiter::clear('sales-board-readiness-preview:'.$viewer->id);

    Livewire::test(PreviewSalesBoardReadiness::class, ['record' => $scenario['emission']->getKey()])
        ->callAction('calculatePreview', data: ['reference_month' => '2026-07-01 00:00:00'])
        ->assertSet('preview.reference_month', '07/2026');
});
