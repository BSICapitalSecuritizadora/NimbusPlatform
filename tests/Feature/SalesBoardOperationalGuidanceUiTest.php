<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardSource;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardRolloutException;
use App\Filament\Resources\SalesBoardAutomationTargets\Pages\ListSalesBoardAutomationTargets;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Filament\Resources\SalesBoardRollouts\Pages\ListSalesBoardRollouts;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\ContractInstallment;
use App\Models\SalesBoard;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardRolloutHomologation;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * A rodada de clareza operacional antes do primeiro uso manual.
 *
 * Nada aqui prova regra de negócio -- cada guard tem teste próprio. O que se
 * prova é que a tela diz onde a competência está, o que falta, por que uma ação
 * está indisponível e em que modo a Emissão opera, sem depender de cor nem de
 * conhecer o enum.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
    AutomationFixture::disable();
});

/**
 * As notificações enviadas, com título e corpo como texto.
 *
 * Lidas sem consumir a sessão; `assertNotified()` as consome, e por isso é
 * chamado depois.
 *
 * @return list<array{title: string, body: string}>
 */
function guidanceNotifications(): array
{
    return collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->map(fn (array $notification): array => [
            'title' => (string) ($notification['title'] ?? ''),
            'body' => (string) ($notification['body'] ?? ''),
        ])
        ->values()
        ->all();
}

/**
 * O corpo da notificação com o título dado.
 */
function guidanceNotificationBody(string $title): ?string
{
    return collect(guidanceNotifications())->firstWhere('title', $title)['body'] ?? null;
}

/**
 * Uma competência publicada pela governança, pelo caminho normal.
 *
 * @return array{cycle: SalesBoardCycle, salesBoard: SalesBoard}
 */
function guidancePublishedCycle(): array
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $result = ManagementReviewFixture::approve($review);

    return ['cycle' => $scenario['cycle']->fresh(), 'salesBoard' => $result->salesBoard];
}

describe('modo do Quadro de Vendas no rollout', function () {
    it('says in words that a legacy emission is legacy, and whether global automation is on', function (bool $globalEnabled, string $globalLabel) {
        config()->set('sales_board.automation.enabled', $globalEnabled);
        $scenario = RolloutFixture::emission(1);

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertOk()
            ->assertSeeInOrder(['Modo do Quadro de Vendas', 'Legado'])
            ->assertSeeInOrder(['Automação global', $globalLabel])
            ->assertSeeInOrder(['Competência inicial da automação', '—'])
            ->assertSeeInOrder(['Homologação', 'Nenhuma homologação ativa.']);
    })->with([
        'global desligada' => [false, 'Desligada'],
        'global ligada' => [true, 'Ligada'],
    ]);

    it('shows mode, start month, global state and active homologation of an automated emission', function () {
        RolloutFixture::enableGlobalAutomation();
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);
        RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertOk()
            ->assertSeeInOrder(['Modo do Quadro de Vendas', 'Automatizado'])
            ->assertSeeInOrder(['Automação global', 'Ligada'])
            ->assertSeeInOrder(['Competência inicial da automação', '08/2026'])
            ->assertSee('Tentativa 1 · Homologação aprovada')
            ->assertSeeInOrder(['Escopo', 'Íntegro'])
            ->assertSee('Automação ativa.');

        expect(SalesBoardSource::Legacy->label())->toBe('Legado')
            ->and(SalesBoardSource::Legacy->value)->toBe('legacy')
            ->and(SalesBoardSource::Automated->label())->toBe('Automatizado');
    });

    it('does not pretend an automated emission is running while the global switch is off', function () {
        config()->set('sales_board.automation.enabled', false);
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);
        RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertOk()
            ->assertSeeInOrder(['Modo do Quadro de Vendas', 'Automatizado'])
            ->assertSeeInOrder(['Automação global', 'Desligada'])
            ->assertSee('A Emissão está configurada para automação, mas o interruptor global está desligado.')
            ->assertDontSee('Automação ativa.');

        Livewire::test(ListSalesBoardRollouts::class)
            ->assertOk()
            ->assertSee('Automação global desligada: nada é processado.');
    });
});

describe('próxima ação', function () {
    it('tells what comes next for each stage of the cycle', function (Closure $arrange, string $expected) {
        $cycle = $arrange();

        Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
            ->assertOk()
            ->assertSee('Próxima ação')
            ->assertSee($expected);

        Livewire::test(ListSalesBoardCycles::class)
            ->assertOk()
            ->assertSee($expected);
    })->with([
        'gerado' => [
            fn () => BuilderReviewFixture::generatedCycle()['cycle'],
            'Envie a posição para a validação da construtora.',
        ],
        'em validação da construtora' => [
            function () {
                $cycle = BuilderReviewFixture::generatedCycle()['cycle'];
                BuilderReviewFixture::open($cycle);

                return $cycle;
            },
            'Aguardando a validação da construtora.',
        ],
        'em análise da Gestão' => [
            fn () => ManagementReviewFixture::submittedCycleWithNonConformSale()['cycle'],
            'Aguardando análise da Gestão.',
        ],
        'aprovado' => [
            fn () => guidancePublishedCycle()['cycle'],
            'Posição aprovada e publicada no Quadro de Vendas.',
        ],
        'posição materialmente alterada' => [
            function () {
                $scenario = BuilderReviewFixture::generatedCycle();
                $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
                CycleFixture::check($scenario['cycle']);

                return $scenario['cycle'];
            },
            'Recalcule a posição antes de continuar.',
        ],
    ]);

    it('keeps a source-only change apart from a material one', function () {
        $scenario = ManagementReviewFixture::submittedCycle();
        ManagementReviewFixture::open($scenario['cycle']);

        ContractInstallment::query()
            ->where('contract_id', $scenario['contracts']['cancelled']->id)
            ->sole()
            ->update(['expected_value' => '469000.00']);

        expect(CycleFixture::check($scenario['cycle'])->impact)->toBe(SalesBoardStaleImpact::SourceOnly);

        Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
            ->assertOk()
            ->assertSee('Aguardando análise da Gestão.')
            ->assertSee('A fonte mudou, mas a posição material permanece igual')
            ->assertDontSee('Recalcule a posição antes de continuar.');

        Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
            ->assertOk()
            ->assertSee('Aprove e publique, ou devolva à construtora.')
            ->assertSee('A fonte mudou, mas a posição material permanece igual')
            ->assertDontSee('Recalcule a posição antes de continuar.');
    });

    it('tells the builder which sections are still missing', function () {
        $scenario = BuilderReviewFixture::generatedCycle();
        BuilderReviewFixture::open($scenario['cycle']);

        Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
            ->assertOk()
            ->assertSee('Conclua a revisão da construtora.')
            ->assertSee('Faltam 7 de 7 seções')
            ->assertSeeInOrder(['Divergências declaradas', '0']);
    });

    it('tells management what still blocks publication, in the gate own words', function () {
        $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
        ManagementReviewFixture::open($scenario['cycle']);

        Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
            ->assertOk()
            ->assertSee('Resolva as não conformidades e conclua a análise.')
            ->assertSee('Não conformidades decididas — 1 pendente(s) de decisão.')
            ->assertSee('Aprovar e publicar indisponível:')
            ->assertActionHidden('approve');
    });

    it('guides the rollout from legacy to activation', function (Closure $arrange, string $expected) {
        $emission = $arrange();

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $emission->getKey()])
            ->assertOk()
            ->assertSee('Próxima ação')
            ->assertSee($expected);
    })->with([
        'legado sem homologação' => [
            fn () => RolloutFixture::emission(1)['emission'],
            'Abra uma homologação para preparar a automação.',
        ],
        'homologação em rascunho' => [
            function () {
                $scenario = RolloutFixture::emission(1);
                RolloutFixture::open($scenario['emission']);

                return $scenario['emission'];
            },
            'Conclua os itens pendentes da homologação.',
        ],
        'homologação aprovada' => [
            function () {
                $scenario = RolloutFixture::emission(1);
                RolloutFixture::legacyBoard($scenario['constructions'][0]);
                RolloutFixture::approvedHomologation($scenario['emission']);

                return $scenario['emission'];
            },
            'A homologação está aprovada. Ative a automação quando for o momento.',
        ],
        'escopo alterado' => [
            function () {
                RolloutFixture::enableGlobalAutomation();
                $scenario = RolloutFixture::emission(1);
                RolloutFixture::legacyBoard($scenario['constructions'][0]);
                RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));
                RolloutFixture::construction($scenario['emission'], 'Z');

                return $scenario['emission'];
            },
            'O escopo da Emissão mudou. É necessária nova homologação.',
        ],
    ]);

    it('explains why suspended automation needs a new homologation', function () {
        RolloutFixture::enableGlobalAutomation();
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);
        RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));
        RolloutFixture::construction($scenario['emission'], 'Z');

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertOk()
            ->assertSeeInOrder(['Escopo', 'Alterado desde a homologação · automação suspensa'])
            ->assertSee('retorne ao modo legado e abra uma nova homologação que cubra os empreendimentos atuais');
    });
});

describe('ações bloqueadas', function () {
    it('shows activation as unavailable while the homologation is still a draft, saying why', function () {
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::open($scenario['emission']);

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertActionVisible('activate')
            ->assertActionDisabled('activate')
            ->assertActionExists('activate', fn (Action $action): bool => $action->getTooltip() === 'A homologação precisa estar aprovada. A validade dela é reavaliada no momento da ativação.')
            ->assertSee('Aprovar homologação indisponível:');
    });

    it('keeps activation available once the homologation is approved, warning that data will be revalidated', function () {
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);
        RolloutFixture::approvedHomologation($scenario['emission']);

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertActionEnabled('activate')
            ->assertSee('Tentativa 1 · Homologação aprovada')
            ->assertSee('A aprovação representa o estado revisado no momento da homologação.')
            ->assertSee('Os dados serão revalidados no momento da ativação.');
    });

    it('disables recalculation of an approved cycle and links to what it published', function () {
        $published = guidancePublishedCycle();

        expect($published['cycle']->status)->toBe(SalesBoardCycleStatus::Approved);

        Livewire::test(ViewSalesBoardCycle::class, ['record' => $published['cycle']->getKey()])
            ->assertActionDisabled('recalculate')
            ->assertActionExists('recalculate', fn (Action $action): bool => $action->getTooltip() === 'Competência aprovada e publicada: a posição publicada é imutável e não é recalculada.')
            ->assertActionVisible('viewPublishedBoard')
            ->assertActionVisible('viewManagementReview');
    });

    it('turns a manual write on an automated competence into a notification instead of an error', function () {
        $scenario = RolloutFixture::emission(1);
        $construction = $scenario['constructions'][0];
        RolloutFixture::legacyBoard($construction);
        RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));

        $before = SalesBoard::query()->count();

        $page = Livewire::test(CreateSalesBoard::class)
            ->fillForm([
                'emission_id' => $scenario['emission']->id,
                'construction_id' => $construction->id,
                'reference_month' => '08/2026',
                'stock_units' => 2,
                'financed_units' => 0,
                'paid_units' => 0,
                'exchanged_units' => 0,
                'stock_value' => '1.000.000,00',
                'financed_value' => '0,00',
                'paid_value' => '0,00',
                'exchanged_value' => '0,00',
            ])
            ->call('create');

        expect(SalesBoard::query()->count())->toBe($before)
            ->and(guidanceNotificationBody('Registro manual recusado'))->toBe(
                'A Emissão está no modo automatizado a partir desta competência. '
                    .'O Quadro de Vendas de '.$construction->development_name.' em 08/2026 é produzido pelo ciclo mensal e publicado pela Gestão, '
                    .'e registrá-lo à mão criaria uma segunda posição para o mesmo mês.',
            );

        $page->assertNotified('Registro manual recusado');
    });
});

/**
 * `assertActionHidden()` confere a lógica da ação, não o HTML: uma ação escrita
 * direto no Blade é impressa mesmo oculta, como botão inerte. Estes testes olham
 * o que o operador vê.
 */
describe('ações ocultas não aparecem na tela', function () {
    it('shows only the homologation entry point on a legacy emission without homologation', function () {
        $scenario = RolloutFixture::emission(1);

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertActionHidden('activate')
            ->assertActionHidden('returnToLegacy')
            ->assertSee('Abrir homologação')
            ->assertDontSee('Ativar automação')
            ->assertDontSee('Retornar ao modo legado');
    });

    it('shows only the return to legacy on an automated emission', function () {
        RolloutFixture::enableGlobalAutomation();
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);
        RolloutFixture::activate($scenario['emission'], RolloutFixture::approvedHomologation($scenario['emission']));

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertSee('Retornar ao modo legado')
            ->assertDontSee('Abrir homologação')
            ->assertDontSee('Abrir nova homologação')
            ->assertDontSee('Ativar automação');
    });

    it('drops the draft-only actions once the homologation is approved', function () {
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);
        RolloutFixture::approvedHomologation($scenario['emission']);

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertSee('Ativar automação')
            ->assertDontSee('Reavaliar')
            ->assertDontSee('Marcar impacto sobre Garantias como revisado')
            ->assertDontSee('Aprovar homologação')
            ->assertDontSee('Rejeitar homologação');
    });

    it('shows no approve or return button on a closed management round', function () {
        $published = guidancePublishedCycle();

        Livewire::test(ManagementReviewWorkspace::class, ['record' => $published['cycle']->getKey()])
            ->assertOk()
            ->assertSee('Encerramento desta rodada')
            ->assertDontSee('Aprovar e publicar')
            ->assertDontSee('Devolver para a construtora');
    });
});

/**
 * Achados do primeiro teste manual: a resposta da própria ação precisa mostrar o
 * estado novo, sem depender de recarregar a página.
 */
describe('tela atualizada logo depois da ação', function () {
    it('shows the automated mode in the same response that activates, and legacy in the one that returns', function () {
        config()->set('sales_board.automation.enabled', false);
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);
        RolloutFixture::approvedHomologation($scenario['emission']);

        // Depois de uma ação, `assertSeeInOrder()` lê o JSON da resposta, onde acentos
        // chegam escapados; a ordem é conferida no HTML renderizado.
        $page = Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->callAction('activate', data: ['reason' => 'Ativação acordada com a operação.'])
            ->assertHasNoActionErrors()
            ->assertSee('A Emissão está configurada para automação, mas o interruptor global está desligado.')
            ->assertDontSee('Abrir nova homologação');

        expect($page->html())
            ->toMatch('/Modo do Quadro de Vendas\s*<\/dt>\s*<dd[^>]*>\s*Automatizado/u')
            ->toMatch('/Competência inicial da automação\s*<\/dt>\s*<dd[^>]*>\s*08\/2026/u');

        $page->callAction('returnToLegacy', data: ['reason' => 'Retorno para revisar o cadastro da operação.'])
            ->assertHasNoActionErrors()
            ->assertSee('A última homologação já foi usada numa ativação. Reativar exige uma nova homologação.');

        expect($page->html())->toMatch('/Modo do Quadro de Vendas\s*<\/dt>\s*<dd[^>]*>\s*Legado/u');
    });

    it('says the competence went to management in the same response that submits the validation', function () {
        $scenario = BuilderReviewFixture::generatedCycle();
        $review = BuilderReviewFixture::open($scenario['cycle']);
        BuilderReviewFixture::confirmAll($review);

        Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
            ->callAction('submitReview', data: ['declaration' => true])
            ->assertHasNoActionErrors()
            ->assertSee('Aguardando análise da Gestão.')
            ->assertDontSee('A competência voltou à construtora');

        expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
    });

    it('shows the material change in the same response that checks the source', function () {
        $scenario = BuilderReviewFixture::generatedCycle();
        $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

        Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
            ->assertDontSee('Recalcule a posição antes de continuar.')
            ->callAction('checkStale')
            ->assertSee('Recalcule a posição antes de continuar.');
    });

    it('shows the new version in the same response that recalculates', function () {
        $scenario = BuilderReviewFixture::generatedCycle();
        $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

        Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
            ->assertSee('congelada na versão V1')
            ->callAction('recalculate', data: ['reason' => 'Valor de venda corrigido pela construtora.'])
            ->assertHasNoActionErrors()
            ->assertSee('congelada na versão V2')
            ->assertDontSee('congelada na versão V1');
    });
});

describe('ajustes do primeiro teste manual', function () {
    it('titles cycles and boards by competence and construction, never by the raw date', function () {
        $cycle = BuilderReviewFixture::generatedCycle()['cycle']->fresh(['construction']);
        $board = RolloutFixture::legacyBoard(RolloutFixture::emission(1)['constructions'][0])->fresh(['construction']);

        expect(SalesBoardCycleResource::getRecordTitle($cycle))->toBe('07/2026 · '.$cycle->construction->development_name)
            ->and(SalesBoardResource::getRecordTitle($board))->toBe('07/2026 · '.$board->construction->development_name);

        Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
            ->assertOk()
            ->assertDontSee('2026-07-01 00:00:00');
    });

    it('credits the automation for a cycle it generated, and nobody for one without user', function () {
        $construction = AutomationFixture::readyConstruction();
        AutomationFixture::enable([$construction]);
        AutomationFixture::run();

        $automated = SalesBoardCycle::query()->where('construction_id', $construction->id)->sole();

        Livewire::test(ViewSalesBoardCycle::class, ['record' => $automated->getKey()])
            ->assertSeeInOrder(['Congelado por', 'Automação do Quadro'])
            ->assertSeeInOrder(['Apurada por', 'Automação do Quadro']);

        $withoutUser = BuilderReviewFixture::generatedCycle()['cycle'];

        Livewire::test(ViewSalesBoardCycle::class, ['record' => $withoutUser->getKey()])
            ->assertSeeInOrder(['Congelado por', 'Sem usuário registrado'])
            ->assertDontSee('Automação do Quadro');
    });

    it('does not send the operator to recalculate a published board', function () {
        $message = SalesBoardRolloutException::publishedBoardIsImmutable()->getMessage();

        expect($message)->toContain('não pode ser alterado nem removido')
            ->toContain('correções na fonte passam a valer a partir das próximas competências')
            ->not->toContain('recalcular');
    });

    it('shows "stopped since" only for what is actually stopped', function () {
        $ready = AutomationFixture::readyConstruction('1');
        $blocked = AutomationFixture::blockedConstruction('2');
        AutomationFixture::enable([$ready, $blocked]);
        AutomationFixture::run();

        $satisfied = SalesBoardAutomationTarget::query()->where('construction_id', $ready->id)->sole();
        $stopped = SalesBoardAutomationTarget::query()->where('construction_id', $blocked->id)->sole();

        expect($satisfied->first_attempt_at)->not->toBeNull();

        Livewire::test(ListSalesBoardAutomationTargets::class)
            ->set('activeTab', 'todos')
            ->assertTableColumnStateSet('first_attempt_at', null, $satisfied)
            ->assertTableColumnStateNotSet('first_attempt_at', null, $stopped);
    });

    it('does not point to a screen that cannot register the exchange', function () {
        $hint = SalesBoardIssuePresenter::describe(['EXCHANGE_SOURCE_MISSING'])[0]['hint'];

        expect($hint)->toContain('enquanto a Emissão está em elaboração')
            ->toContain('leve o caso à Gestão')
            ->and(SalesBoardIssuePresenter::describe(['SETTLEMENT_UNDETERMINED'])[0]['hint'])->toContain('permuta registrada');
    });
});

describe('quadro publicado', function () {
    it('says a published board is immutable before anyone tries to change it', function () {
        $published = guidancePublishedCycle();

        Livewire::test(ViewSalesBoard::class, ['record' => $published['salesBoard']->getKey()])
            ->assertOk()
            ->assertSee('Publicado pelo fluxo de governança')
            ->assertSee('Este quadro foi publicado pelo fluxo de governança e não pode mais ser alterado ou excluído manualmente.')
            ->assertSeeInOrder(['Modo do Quadro de Vendas', 'Legado']);
    });

    it('does not show the immutability notice on a manually recorded board', function () {
        $salesBoard = RolloutFixture::legacyBoard(RolloutFixture::emission(1)['constructions'][0]);

        Livewire::test(ViewSalesBoard::class, ['record' => $salesBoard->getKey()])
            ->assertOk()
            ->assertDontSee('Publicado pelo fluxo de governança');
    });
});

describe('homologação desatualizada', function () {
    it('tells the operator to open a new homologation when activation finds the approval stale', function () {
        $scenario = RolloutFixture::emission(1);
        $board = RolloutFixture::legacyBoard($scenario['constructions'][0]);
        $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

        // 07/2026 é a competência de comparação: mudar o quadro legado dela muda a fonte homologada.
        $board->changeReason = 'Correção do valor de estoque informada pela construtora.';
        $board->update(['stock_value' => '1100000.00']);

        $page = Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->callAction('activate', data: ['reason' => 'Ativação acordada com a operação.'])
            ->assertSee('Esta homologação não representa mais o estado atual das fontes.')
            ->assertActionDisabled('activate')
            ->assertActionVisible('openHomologation');

        expect((string) guidanceNotificationBody('Homologação desatualizada'))
            ->toStartWith('Esta homologação não representa mais o estado atual das fontes. Abra uma nova homologação antes de ativar.')
            // A aprovação não é reescrita: o aviso é da tela, não do banco.
            ->and(SalesBoardRolloutHomologation::query()->sole()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
            ->and($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse()
            ->and($homologation->fresh()->activated_at)->toBeNull();

        $page->assertNotified('Homologação desatualizada');
    });

    it('says a new homologation is required when the scope changed after approval', function () {
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);
        RolloutFixture::approvedHomologation($scenario['emission']);
        RolloutFixture::construction($scenario['emission'], 'Z');

        $page = Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->callAction('activate', data: ['reason' => 'Ativação acordada com a operação.'])
            ->assertActionDisabled('activate');

        expect((string) guidanceNotificationBody('Escopo da Emissão alterado'))
            ->toStartWith('O escopo da Emissão mudou desde a homologação aprovada. É necessária nova homologação.')
            ->and($scenario['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();

        $page->assertNotified('Escopo da Emissão alterado');
    });
});

describe('bloqueios de prontidão', function () {
    it('describes a known blocker in plain language without hiding its code', function () {
        $described = SalesBoardIssuePresenter::describe(['UNIT_VALUE_MISSING' => 3, 'CODIGO_DESCONHECIDO' => 1]);

        expect($described[0])->toMatchArray([
            'code' => 'UNIT_VALUE_MISSING',
            'label' => 'Unidade em estoque sem valor vigente na data',
            'count' => 3,
        ])
            ->and($described[0]['hint'])->toContain('Histórico de Valores')
            // Código que o enum não conhece aparece como veio, sem tradução inventada.
            ->and($described[1])->toMatchArray(['code' => 'CODIGO_DESCONHECIDO', 'label' => 'CODIGO_DESCONHECIDO', 'hint' => null])
            ->and(SalesBoardIssuePresenter::describe(['SALE_DISCOUNT_POLICY_MISSING'])[0]['count'])->toBeNull()
            ->and(SalesBoardIssuePresenter::toHtml(['<b>' => 1])->toHtml())->toContain('&lt;b&gt;');
    });

    it('lists the blocked target with the human reason and the code', function () {
        $blocked = AutomationFixture::blockedConstruction();
        AutomationFixture::enable([$blocked]);
        AutomationFixture::run();

        Livewire::test(ListSalesBoardAutomationTargets::class)
            ->assertOk()
            ->assertSee('Motivo da parada')
            ->assertSee('Unidade em estoque sem valor vigente na data')
            ->assertSee('UNIT_VALUE_MISSING');
    });

    it('explains a blocked freeze with the human reason, the code and where to fix it', function () {
        $blocked = AutomationFixture::blockedConstruction();

        $page = Livewire::test(ListSalesBoardCycles::class)
            ->callAction(TestAction::make('generateCycle'), [
                'construction_id' => $blocked->id,
                'reference_month' => '2026-08-01',
            ]);

        expect((string) guidanceNotificationBody('Geração bloqueada'))
            ->toContain('Unidade em estoque sem valor vigente na data')
            ->toContain('UNIT_VALUE_MISSING')
            ->toContain('Histórico de Valores');

        $page->assertNotified('Geração bloqueada');
    });
});

describe('listas vazias', function () {
    it('explains an empty cycle list', function () {
        Livewire::test(ListSalesBoardCycles::class)
            ->assertOk()
            ->assertSee('Nenhum ciclo gerado')
            ->assertSee('Use “Congelar competência”', escape: false);
    });

    it('explains a rollout without homologations', function () {
        $scenario = RolloutFixture::emission(1);

        Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
            ->assertOk()
            ->assertSee('Nenhuma homologação foi aberta para esta Emissão.');
    });

    it('says there are no blocked targets instead of looking broken', function () {
        $ready = AutomationFixture::readyConstruction();
        AutomationFixture::enable([$ready]);
        AutomationFixture::run();

        Livewire::test(ListSalesBoardAutomationTargets::class)
            ->set('activeTab', 'bloqueados')
            ->assertCountTableRecords(0)
            ->assertSee('Não há alvos bloqueados')
            ->assertDontSee('Nenhuma competência sob automação');
    });

    it('tells an enabled automation without runs apart from a disabled one', function () {
        $construction = AutomationFixture::readyConstruction();
        AutomationFixture::enable([$construction]);

        Livewire::test(ListSalesBoardAutomationTargets::class)
            ->assertOk()
            ->assertSee('Automação global ligada, mas ainda sem execução')
            ->assertDontSee('Automação desligada');

        AutomationFixture::disable();

        Livewire::test(ListSalesBoardAutomationTargets::class)
            ->assertOk()
            ->assertSee('Automação desligada no interruptor global')
            ->assertSee('nenhum processamento mensal é executado');
    });
});
