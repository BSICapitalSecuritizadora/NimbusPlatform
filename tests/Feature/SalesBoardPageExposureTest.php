<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\SalesBoardAutomationTargets\SalesBoardAutomationTargetResource;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Filament\Resources\SalesBoardRollouts\Pages\PreviewSalesBoardReadiness;
use App\Filament\Resources\SalesBoardRollouts\SalesBoardRolloutResource;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\SalesBoard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * A superfície pública das telas do Quadro.
 *
 * No Livewire, todo método público da página pode ser chamado pelo navegador, e
 * o retorno volta serializado. Os auxiliares que o Blade usa devolviam a
 * Emissão inteira, a homologação, a ficha dos responsáveis e a revisão com a
 * posição; agora são protegidos e continuam funcionando no Blade. O registro
 * que o Filament entrega por `getRecord()` -- com a Emissão e a obra
 * carregadas -- é fechado pela regra de leitura: ver o Quadro exige também
 * `emissions.view`.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A tela de um caso, aberta por quem pode tudo, e o escalar público que ela
 * continua respondendo -- o par que prova que a chamada em si funciona.
 *
 * @return array{page: Testable, scalar: string}
 */
function exposurePage(string $screen): array
{
    test()->actingAs(makeAdminUser());

    return match ($screen) {
        'rollout' => (function (): array {
            $scenario = RolloutFixture::emission(1);
            RolloutFixture::legacyBoard($scenario['constructions'][0]);
            RolloutFixture::open($scenario['emission']);
            RolloutFixture::recipients($scenario['emission']);

            return [
                'page' => Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()]),
                'scalar' => 'canManage',
            ];
        })(),
        'builder' => (function (): array {
            $scenario = BuilderReviewFixture::generatedCycle();
            BuilderReviewFixture::open($scenario['cycle']);

            return [
                'page' => Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()]),
                'scalar' => 'canEdit',
            ];
        })(),
        'management' => (function (): array {
            $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
            ManagementReviewFixture::open($scenario['cycle']);

            return [
                'page' => Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()]),
                'scalar' => 'canDecide',
            ];
        })(),
    };
}

it('does not let the browser call the page helpers that return models', function (string $helper) {
    [$screen, $method] = explode('.', $helper);
    $context = exposurePage($screen);

    // O par: o escalar público da mesma tela continua respondendo ao navegador.
    $context['page']->call($context['scalar']);

    expect($context['page']->effects['returns'] ?? null)->toBe([true]);

    expect(fn () => $context['page']->call($method))->toThrow(MethodNotFoundException::class);
})->with([
    'rollout.emission',
    'rollout.currentHomologation',
    'rollout.recipients',
    'rollout.gate',
    'rollout.nextAction',
    'rollout.failedGateChecks',
    'rollout.supersessionNotice',
    'rollout.unusedApprovedAttemptsNotice',
    'builder.currentReview',
    'builder.workspace',
    'builder.attempts',
    'builder.returnReason',
    'builder.nextAction',
    'builder.builderResponse',
    'builder.openSectionFor',
    'builder.visibleRowsOf',
    'builder.submissionForm',
    'management.currentReview',
    'management.workspace',
    'management.attempts',
    'management.nextAction',
    'management.failedGateChecks',
]);

it('declares only scalar, action or view results on the public surface of the custom pages', function () {
    $allowed = ['void', 'bool', 'int', 'string', '?string', Action::class, '?'.View::class];
    $offending = [];

    foreach ([ManageSalesBoardRollout::class, BuilderReviewWorkspace::class, ManagementReviewWorkspace::class, PreviewSalesBoardReadiness::class] as $page) {
        $reflection = new ReflectionClass($page);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Só o que a própria página escreve: o resto é do Filament e do
            // Livewire, e é contra eles que a regra de leitura protege.
            if ($method->getFileName() !== $reflection->getFileName()) {
                continue;
            }

            $type = (string) $method->getReturnType();

            if (! in_array($type, $allowed, true)) {
                $offending[] = class_basename($page).'::'.$method->getName().'(): '.$type;
            }
        }
    }

    expect($offending)->toBe([]);
});

it('still renders the custom pages with the helpers protected', function () {
    $this->actingAs(makeAdminUser());

    $builder = BuilderReviewFixture::generatedCycle();
    BuilderReviewFixture::open($builder['cycle']);

    $this->get(BuilderReviewWorkspace::getUrl(['record' => $builder['cycle']]))
        ->assertOk()
        ->assertSee('Posição no fechamento')
        ->assertSee('Movimentações do mês');

    $management = ManagementReviewFixture::submittedCycleWithNonConformSale();
    ManagementReviewFixture::open($management['cycle']);

    $this->get(ManagementReviewWorkspace::getUrl(['record' => $management['cycle']]))
        ->assertOk()
        ->assertSee('Versão / rodadas')
        ->assertSee((string) $management['construction']->development_name);

    $rollout = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($rollout['constructions'][0]);
    RolloutFixture::open($rollout['emission']);
    $people = RolloutFixture::recipients($rollout['emission']);

    $this->get(ManageSalesBoardRollout::getUrl(['record' => $rollout['emission']]))
        ->assertOk()
        ->assertSee($people['operational']->name)
        ->assertSee($people['management']->name);
});

it('keeps the Sales Board screens behind emissions.view as well', function () {
    $builder = BuilderReviewFixture::generatedCycle();
    BuilderReviewFixture::open($builder['cycle']);
    $rollout = RolloutFixture::emission(1);

    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->givePermissionTo(AccessPermission::SalesBoardsView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user->fresh());

    expect(SalesBoardCycleResource::canViewAny())->toBeFalse()
        ->and(SalesBoardRolloutResource::canViewAny())->toBeFalse()
        ->and(SalesBoardAutomationTargetResource::canViewAny())->toBeFalse()
        ->and(SalesBoardResource::canViewAny())->toBeFalse()
        ->and($user->fresh()->can('viewAny', SalesBoard::class))->toBeFalse();

    $this->get(ManageSalesBoardRollout::getUrl(['record' => $rollout['emission']]))->assertForbidden();
    $this->get(PreviewSalesBoardReadiness::getUrl(['record' => $rollout['emission']]))->assertForbidden();
    $this->get(ViewSalesBoardCycle::getUrl(['record' => $builder['cycle']]))->assertForbidden();
    $this->get(BuilderReviewWorkspace::getUrl(['record' => $builder['cycle']]))->assertForbidden();

    $user->givePermissionTo(AccessPermission::EmissionsView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user->fresh());

    expect(SalesBoardCycleResource::canViewAny())->toBeTrue()
        ->and(SalesBoardRolloutResource::canViewAny())->toBeTrue()
        ->and(SalesBoardAutomationTargetResource::canViewAny())->toBeTrue()
        ->and(SalesBoardResource::canViewAny())->toBeTrue()
        ->and($user->fresh()->can('viewAny', SalesBoard::class))->toBeTrue();

    $this->get(ManageSalesBoardRollout::getUrl(['record' => $rollout['emission']]))->assertOk();
    $this->get(PreviewSalesBoardReadiness::getUrl(['record' => $rollout['emission']]))->assertOk();
    $this->get(ViewSalesBoardCycle::getUrl(['record' => $builder['cycle']]))->assertOk();
    $this->get(BuilderReviewWorkspace::getUrl(['record' => $builder['cycle']]))->assertOk();
});
