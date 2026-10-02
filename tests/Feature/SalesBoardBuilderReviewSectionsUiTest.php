<?php

use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;

/**
 * A Validação com uma seção aberta por vez.
 *
 * Com 800 unidades a página inteira passava de 800 KB por clique, as ações
 * ficavam no fim de centenas de linhas e a revisão era relida a cada pergunta
 * da página. Agora só a seção aberta renderiza linhas, 100 por página, com as
 * ações no topo e a busca; confirmar continua valendo para a seção inteira.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * O HTML das seções, da primeira em diante. Os pontos para conferir que a
 * apuração congelou ficam acima delas e podem citar uma unidade de seção
 * fechada -- o que se prova aqui é que a seção fechada não renderiza linhas.
 */
function builderSectionsHtml(Testable $page): string
{
    $html = $page->html();

    return substr($html, (int) strpos($html, 'id="secao-'));
}

it('opens the first pending section and renders only its rows', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    BuilderReviewFixture::open($scenario['cycle']);

    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertSee('01 / 101')
        ->assertSee('Abrir seção');

    foreach (SectionEnum::ordered() as $section) {
        $page->assertSee($section->label());
    }

    expect(substr_count($page->html(), 'id="secao-'))->toBe(7)
        // A unidade quitada só aparece em seções fechadas.
        ->and(builderSectionsHtml($page))->toContain('01 / 101')
        ->and(builderSectionsHtml($page))->not->toContain('01 / 103');
});

it('puts the section actions above the rows', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    BuilderReviewFixture::open($scenario['cycle']);

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSeeInOrder(['Estoque', 'Confirmar seção', 'Apontar divergência', '01 / 101']);
});

it('advances to the next pending section after confirming one', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $stock = BuilderReviewFixture::section($review, SectionEnum::PositionStock);

    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSet('openSection', null)
        ->callAction('confirmSection', ['comment' => 'Estoque confere.'], ['section' => $stock->id])
        ->assertHasNoActionErrors()
        ->assertSet('openSection', SectionEnum::PositionFinanced->value)
        ->assertSee('01 / 102')
        ->assertDontSee('01 / 104');

    // Abrir outra seção pela tela leva às linhas dela, e a busca recomeça.
    $page->call('showSection', SectionEnum::PositionExchanged->value)
        ->assertSet('openSection', SectionEnum::PositionExchanged->value)
        ->assertSet('sectionPage', 1)
        ->assertSee('01 / 104')
        ->assertDontSee('01 / 102');
});

it('paginates a long section and filters it by unit or contract', function () {
    [$construction] = CycleFixture::readyConstruction(120);
    $cycle = CycleFixture::generate($construction)->cycle;
    BuilderReviewFixture::open($cycle);

    // A ordem é a natural: 101 a 220, cem na primeira página.
    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $cycle->getKey()])
        ->assertSee('Mostrando 1–100 de 120')
        ->assertSee('01 / 200')
        ->assertDontSee('01 / 201')
        ->assertSee('Buscar unidade ou contrato nesta seção');

    $page->call('nextSectionPage')
        ->assertSee('Mostrando 101–120 de 120')
        ->assertSee('01 / 220')
        ->assertDontSee('01 / 150')
        // A última página é a última: avançar de novo não passa dela.
        ->call('nextSectionPage')
        ->assertSet('sectionPage', 2)
        ->call('previousSectionPage')
        ->assertSee('Mostrando 1–100 de 120');

    $page->call('nextSectionPage');

    $page->set('sectionSearch', '219')
        ->assertSet('sectionPage', 1)
        ->assertSee('Mostrando 1–1 de 1')
        ->assertSee('01 / 219')
        ->assertDontSee('01 / 218');

    $page->set('sectionSearch', 'não existe')
        ->assertSee('Nenhuma unidade ou contrato encontrado para a busca.');
});

it('falls back to the first pending section on an unknown section in the URL', function (string $requested) {
    $scenario = BuilderReviewFixture::generatedCycle();
    BuilderReviewFixture::open($scenario['cycle']);

    $page = Livewire::withQueryParams(['secao' => $requested])
        ->test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk();

    match ($requested) {
        'seção-que-não-existe' => expect(builderSectionsHtml($page))->toContain('01 / 101')->not->toContain('01 / 103'),
        SectionEnum::PositionSettled->value => expect(builderSectionsHtml($page))->toContain('01 / 103')->not->toContain('01 / 102'),
    };
})->with([
    'seção-que-não-existe',
    SectionEnum::PositionSettled->value,
]);

it('loads the review once per request', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $stock = BuilderReviewFixture::section($review, SectionEnum::PositionStock);

    $reads = ['review' => 0, 'lines' => 0];

    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'from "sales_board_builder_reviews"') && str_contains($sql, "case when status = 'em_andamento'")) {
            $reads['review']++;
        }

        if (str_contains($sql, 'from "sales_board_cycle_lines"')) {
            $reads['lines']++;
        }
    });

    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()]);

    expect($reads)->toBe(['review' => 1, 'lines' => 1]);

    $reads = ['review' => 0, 'lines' => 0];
    $page->call('showSection', SectionEnum::MovementSales->value);

    expect($reads)->toBe(['review' => 1, 'lines' => 1]);

    $page->mountAction('confirmSection', ['section' => $stock->id]);
    $reads = ['review' => 0, 'lines' => 0];
    $page->callMountedAction();

    // Uma leitura para a ação e uma para a resposta, que precisa mostrar o
    // que a ação gravou.
    expect($reads)->toBe(['review' => 2, 'lines' => 2])
        ->and($stock->fresh()->status->isResolved())->toBeTrue();
});
