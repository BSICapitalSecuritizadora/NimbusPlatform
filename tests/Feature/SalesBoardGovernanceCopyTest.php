<?php

use App\Enums\SalesBoardRolloutRecipientRole;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Support\SalesBoards\GateChecklistSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * O texto das telas de governança.
 *
 * Uma publicação irreversível não termina num "Enviar" genérico, a linha que
 * explica um botão indisponível não sai com pontuação dupla, e a auto-aprovação
 * do super-admin -- isenta da segregação por decisão do dono -- aparece na
 * tela em vez de ficar só inferível comparando colunas.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * O texto da linha "… indisponível:" da tela, sem as tags.
 */
function unavailableGateLine(string $html, string $label): ?string
{
    if (preg_match('/'.preg_quote($label, '/').'<\/span>(.*?)<\/p>/s', $html, $matches) !== 1) {
        return null;
    }

    return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($matches[1]))));
}

it('labels the final confirmation of the publication Aprovar e publicar', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionVisible('approve')
        ->assertActionExists('approve', fn (Action $action): bool => $action->getModalSubmitAction()?->getLabel() === 'Aprovar e publicar');
});

it('labels the recipient confirmation Adicionar responsável', function () {
    $scenario = RolloutFixture::emission(1);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertActionExists(
            'addRecipient',
            fn (Action $action): bool => $action->getModalSubmitAction()?->getLabel() === 'Adicionar responsável',
            arguments: ['role' => SalesBoardRolloutRecipientRole::Operational->value],
        );
});

it('joins failed gate checks in one sentence without double punctuation', function () {
    expect(GateChecklistSummary::sentence([
        'Não conformidades decididas — 1 pendente(s) de decisão.',
        'Nenhuma correção de fonte pendente;',
        'Fonte sem alteração material — Alteração material:',
        'Análise em andamento',
    ]))->toBe('Não conformidades decididas — 1 pendente(s) de decisão; Nenhuma correção de fonte pendente; Fonte sem alteração material — Alteração material; Análise em andamento.')
        ->and(GateChecklistSummary::sentence(['Responsável operacional definido — 0 ativo(s). ']))->toBe('Responsável operacional definido — 0 ativo(s).')
        ->and(GateChecklistSummary::sentence([]))->toBe('');
});

it('never prints double punctuation on either gate line', function () {
    $management = ManagementReviewFixture::submittedCycleWithNonConformSale();
    ManagementReviewFixture::open($management['cycle']);

    $analysis = unavailableGateLine(
        Livewire::test(ManagementReviewWorkspace::class, ['record' => $management['cycle']->getKey()])->html(),
        'Aprovar e publicar indisponível:',
    );

    $rollout = RolloutFixture::emission(1);
    RolloutFixture::open($rollout['emission']);

    $homologation = unavailableGateLine(
        Livewire::test(ManageSalesBoardRollout::class, ['record' => $rollout['emission']->getKey()])->html(),
        'Aprovar homologação indisponível:',
    );

    foreach ([$analysis, $homologation] as $line) {
        expect($line)->not->toBeNull()
            ->and($line)->toEndWith('.')
            ->and($line)->not->toContain('..')
            ->and($line)->not->toContain('.;')
            ->and($line)->not->toContain(';.');
    }

    // Os detalhes dos serviços terminam em ponto: antes viravam "decisão.." e
    // "ativo(s).;" na tela.
    expect($analysis)->toBe('Não conformidades decididas — 1 pendente(s) de decisão.')
        ->and($homologation)->toContain('0 ativo(s);');
});

it('shows when a super admin approved a round they registered', function () {
    $superAdmin = GovernanceFixture::superAdmin();
    $scenario = BuilderReviewFixture::generatedCycle();

    $builderReview = BuilderReviewFixture::open($scenario['cycle'], $superAdmin);
    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview, $superAdmin);

    $review = ManagementReviewFixture::open($scenario['cycle'], $superAdmin);
    ManagementReviewFixture::approve($review, $superAdmin);

    $notice = 'Aprovada pelo mesmo usuário que registrou a validação — isenção de segregação do super-admin.';

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Encerramento desta rodada')
        ->assertSee($notice);

    // O par: aprovada por outra pessoa da Gestão, nada a destacar.
    $other = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::approve(ManagementReviewFixture::open($other['cycle']));

    Livewire::test(ManagementReviewWorkspace::class, ['record' => $other['cycle']->getKey()])
        ->assertSee('Encerramento desta rodada')
        ->assertDontSee($notice);

    // A homologação aprovada por quem a abriu mostra o aviso equivalente.
    $rollout = RolloutFixture::emission(1);
    RolloutFixture::legacyBoard($rollout['constructions'][0]);
    $homologation = RolloutFixture::open($rollout['emission'], $superAdmin);
    RolloutFixture::recipients($rollout['emission'], $superAdmin);
    RolloutFixture::reviewImpacts($homologation->fresh(), $superAdmin);
    RolloutFixture::approve($homologation, $superAdmin);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $rollout['emission']->getKey()])
        ->assertSee('Aprovada pelo mesmo usuário que abriu a homologação — isenção de segregação do super-admin.');
});
