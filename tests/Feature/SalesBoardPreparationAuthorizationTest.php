<?php

use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\AccessPermission;
use App\Enums\BuilderReviewerType;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardRolloutRecipientRole;
use App\Enums\SalesBoardUnitClassification;
use App\Exceptions\SalesBoardRolloutException;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\SalesBoardRolloutRecipient;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use App\Services\SalesBoards\SalesBoardBuilderReviewSubmissionService;
use App\Services\SalesBoards\SalesBoardManagementReviewOpeningService;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use App\Services\SalesBoards\SalesBoardRolloutRecipientDirectory;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ForgedLivewireRequest;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * Preparar uma competência é de quem a opera (`sales-boards.update`), e a regra
 * vale no servidor, não só na tela.
 *
 * Cada ação de preparo é provada em dois níveis, sempre com o par que aceita:
 * `Livewire::test` (ação oculta e mount forjado recusado) e a rota real de
 * update do Livewire, com os middlewares do painel ligados -- o caminho de quem
 * chama uma ação pelo console do navegador. Os serviços recusam do mesmo jeito,
 * porque um comando ou um job chegam a eles sem passar pela tela.
 *
 * Os perfis são montados por permissão avulsa, como o formulário de Usuários
 * permite: "consulta" vê o Quadro e a Emissão; "gestão" vê e aprova; nenhum
 * dos dois opera.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function preparationProfile(string $profile): User
{
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);

    $user->givePermissionTo(match ($profile) {
        'consulta' => [
            AccessPermission::SalesBoardsView->value,
            AccessPermission::EmissionsView->value,
        ],
        'gestao' => [
            AccessPermission::SalesBoardsView->value,
            AccessPermission::EmissionsView->value,
            AccessPermission::SalesBoardsApprove->value,
        ],
        'operador' => [
            AccessPermission::SalesBoardsView->value,
            AccessPermission::SalesBoardsCreate->value,
            AccessPermission::SalesBoardsUpdate->value,
            AccessPermission::EmissionsView->value,
        ],
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * Uma validação em rascunho com uma seção confirmada (para desfazer), uma
 * divergência declarada pelo operador (para remover) e as demais pendentes.
 *
 * @return array{cycle: SalesBoardCycle, review: SalesBoardBuilderReview, confirmed: SalesBoardBuilderReviewSection, divergent: SalesBoardBuilderReviewSection, pending: SalesBoardBuilderReviewSection, divergence: SalesBoardBuilderDivergence}
 */
function preparationReview(): array
{
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    $operator = BuilderReviewFixture::reviewer();

    $confirmed = BuilderReviewFixture::section($review, SectionEnum::PositionStock);
    app(SalesBoardBuilderReviewEditor::class)->confirmSection($confirmed, $operator, 'Estoque confere.');

    $divergence = BuilderReviewFixture::declare($review, SectionEnum::PositionFinanced, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::StockMismatch,
        reason: 'A unidade foi distratada em junho.',
        lineId: BuilderReviewFixture::lineFor($review, $scenario['units']['financed'])->id,
        declaredClassification: SalesBoardUnitClassification::Stock,
    ), $operator);

    return [
        'cycle' => $scenario['cycle'],
        'review' => $review->fresh(),
        'confirmed' => $confirmed->fresh(),
        'divergent' => BuilderReviewFixture::section($review, SectionEnum::PositionFinanced),
        'pending' => BuilderReviewFixture::section($review, SectionEnum::PositionSettled),
        'divergence' => $divergence,
    ];
}

/**
 * Tudo o que a construtora declarou na rodada, para provar que uma recusa não
 * mexeu em nada -- e que o par aceito mexeu.
 *
 * @return array<string, mixed>
 */
function preparationReviewState(SalesBoardBuilderReview $review): array
{
    $review = $review->fresh();

    return [
        'status' => $review->status->value,
        'overall_comment' => $review->overall_comment,
        'sections' => $review->sections()->orderBy('id')->get()
            ->map(fn (SalesBoardBuilderReviewSection $section): array => [$section->section->value, $section->status->value, $section->comment])
            ->all(),
        'divergences' => $review->divergences()->orderBy('id')->pluck('reason', 'id')->all(),
    ];
}

/**
 * Uma operação do editor da validação, pela identidade recebida.
 *
 * @param  array{cycle: SalesBoardCycle, review: SalesBoardBuilderReview, confirmed: SalesBoardBuilderReviewSection, divergent: SalesBoardBuilderReviewSection, pending: SalesBoardBuilderReviewSection, divergence: SalesBoardBuilderDivergence}  $scenario
 */
function preparationEditorOperation(string $operation, array $scenario, BuilderReviewerIdentity $reviewer): void
{
    $editor = app(SalesBoardBuilderReviewEditor::class);

    match ($operation) {
        'confirm' => $editor->confirmSection($scenario['pending']->fresh(), $reviewer, 'Quitados conferem.'),
        'reopen' => $editor->reopenSection($scenario['confirmed']->fresh(), $reviewer),
        'declare' => $editor->addDivergence($scenario['pending']->fresh(), $reviewer, new SalesBoardBuilderDivergenceInput(
            type: SalesBoardBuilderDivergenceType::Other,
            reason: 'A construtora registrou a quitação em outra data.',
        )),
        'update' => $editor->updateDivergence($scenario['divergence']->fresh(), $reviewer, new SalesBoardBuilderDivergenceInput(
            type: SalesBoardBuilderDivergenceType::StockMismatch,
            reason: 'A unidade foi distratada em maio, não em junho.',
            lineId: $scenario['divergence']->sales_board_cycle_line_id,
            declaredClassification: SalesBoardUnitClassification::Stock,
        )),
        'remove' => $editor->removeDivergence($scenario['divergence']->fresh(), $reviewer),
        'comment' => $editor->updateOverallComment($scenario['review']->fresh(), $reviewer, 'Comentário geral da construtora.'),
    };
}

/**
 * Uma Emissão em homologação, aberta pelo operador, com uma diferença a
 * analisar e um responsável já definido.
 *
 * @return array{emission: Emission, homologation: SalesBoardRolloutHomologation, row: SalesBoardRolloutHomologationConstruction, recipient: SalesBoardRolloutRecipient}
 */
function preparationRollout(): array
{
    $scenario = RolloutFixture::emission(2);
    RolloutFixture::legacyBoard($scenario['constructions'][0], stockUnits: 9);
    RolloutFixture::legacyBoard($scenario['constructions'][1]);

    $homologation = RolloutFixture::open($scenario['emission']);
    $recipient = app(SalesBoardRolloutRecipientDirectory::class)->add(
        $scenario['emission'],
        SalesBoardRolloutRecipientRole::Operational,
        RolloutFixture::operationalUser(),
        GovernanceFixture::operator(),
    );

    return [
        'emission' => $scenario['emission'],
        'homologation' => $homologation->fresh(),
        'row' => $homologation->constructions()->get()
            ->first(fn (SalesBoardRolloutHomologationConstruction $row): bool => $row->requiresAcknowledgement()),
        'recipient' => $recipient,
    ];
}

/**
 * Uma operação de preparo do rollout e como saber se ela gravou.
 *
 * @return array{run: Closure(?User): mixed, changed: Closure(): bool}
 */
function preparationRolloutCase(string $operation): array
{
    $service = app(SalesBoardRolloutHomologationService::class);
    $directory = app(SalesBoardRolloutRecipientDirectory::class);

    if ($operation === 'open') {
        $scenario = RolloutFixture::emission(1);
        RolloutFixture::legacyBoard($scenario['constructions'][0]);

        return [
            'run' => fn (?User $actor): mixed => $service->open(
                $scenario['emission']->fresh(),
                CarbonImmutable::parse(RolloutFixture::START_MONTH),
                $actor,
            ),
            'changed' => fn (): bool => SalesBoardRolloutHomologation::query()->exists(),
        ];
    }

    $scenario = preparationRollout();
    $newcomer = RolloutFixture::operationalUser();

    // Para a reavaliação ter o que reescrever: a fonte muda depois da abertura.
    $hashBefore = (string) $scenario['homologation']->assessment_hash;

    if ($operation === 'reassess') {
        ConstructionUnit::factory()->create([
            'construction_id' => $scenario['homologation']->constructions()->value('construction_id'),
            'block' => '01',
            'unit' => '888',
            'base_value' => '400000.00',
            'base_value_reference_date' => '2026-01-01',
        ]);
    }

    return match ($operation) {
        'accept' => [
            'run' => fn (?User $actor): mixed => $service->acceptDifference(
                $scenario['row']->fresh(),
                'O quadro legado contava um bloco que foi desmembrado.',
                $actor,
            ),
            'changed' => fn (): bool => (bool) $scenario['row']->fresh()->accepted_difference,
        ],
        'reassess' => [
            'run' => fn (?User $actor): mixed => $service->reassess($scenario['homologation']->fresh(), $actor),
            'changed' => fn (): bool => (string) $scenario['homologation']->fresh()->assessment_hash !== $hashBefore,
        ],
        'reject' => [
            'run' => fn (?User $actor): mixed => $service->reject(
                $scenario['homologation']->fresh(),
                $actor,
                'A competência de comparação precisa ser revista.',
            ),
            'changed' => fn (): bool => $scenario['homologation']->fresh()->status === SalesBoardRolloutHomologationStatus::Rejected,
        ],
        'add-recipient' => [
            'run' => fn (?User $actor): mixed => $directory->add(
                $scenario['emission'],
                SalesBoardRolloutRecipientRole::Management,
                $newcomer,
                $actor,
            ),
            'changed' => fn (): bool => SalesBoardRolloutRecipient::query()->where('user_id', $newcomer->id)->exists(),
        ],
        'remove-recipient' => [
            'run' => fn (?User $actor): mixed => $directory->remove($scenario['recipient'], $actor),
            'changed' => fn (): bool => SalesBoardRolloutRecipient::query()->whereKey($scenario['recipient']->id)->doesntExist(),
        ],
    };
}

// ── Validação da construtora: tela ────────────────────────────────────────────

it('hides every builder validation action from a profile without sales-boards.update and ignores the forged mounts', function (string $profile) {
    $scenario = preparationReview();
    $before = preparationReviewState($scenario['review']);

    $this->actingAs(preparationProfile($profile));

    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertOk()
        ->assertActionHidden('confirmSection', ['section' => $scenario['pending']->id])
        ->assertActionHidden('reopenSection', ['section' => $scenario['confirmed']->id])
        ->assertActionHidden('declareDivergence', ['section' => $scenario['pending']->id])
        ->assertActionHidden('removeDivergence', ['divergence' => $scenario['divergence']->id])
        ->assertActionHidden('submitReview');

    expect($page->instance()->canEdit())->toBeFalse();

    // "Desfazer confirmação" não tem modal: sem a guarda, executaria já no mount.
    $page->call('mountAction', 'confirmSection', ['section' => $scenario['pending']->id])
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'reopenSection', ['section' => $scenario['confirmed']->id])
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'declareDivergence', ['section' => $scenario['pending']->id])
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'removeDivergence', ['divergence' => $scenario['divergence']->id])
        ->assertSet('mountedActions', [])
        ->call('mountAction', 'submitReview')
        ->assertSet('mountedActions', []);

    expect(preparationReviewState($scenario['review']))->toBe($before)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
})->with(['consulta', 'gestao']);

it('lets the operator confirm, reopen, declare, remove and submit from the workspace', function () {
    Storage::fake('local');

    $scenario = preparationReview();
    $operator = preparationProfile('operador');
    $this->actingAs($operator);

    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionVisible('confirmSection', ['section' => $scenario['pending']->id])
        ->callAction('reopenSection', arguments: ['section' => $scenario['confirmed']->id])
        ->assertHasNoActionErrors()
        ->callAction('removeDivergence', arguments: ['divergence' => $scenario['divergence']->id])
        ->assertHasNoActionErrors()
        ->callAction('declareDivergence', [
            'type' => SalesBoardBuilderDivergenceType::Other->value,
            'reason' => 'A construtora registrou a quitação em outra data.',
        ], ['section' => $scenario['pending']->id])
        ->assertHasNoActionErrors();

    expect($scenario['confirmed']->fresh()->status)->toBe(SalesBoardBuilderReviewSectionStatus::Pending)
        ->and($scenario['divergence']->fresh())->toBeNull()
        ->and($scenario['pending']->fresh()->status)->toBe(SalesBoardBuilderReviewSectionStatus::Divergent);

    foreach ($scenario['review']->fresh()->sections as $section) {
        if (! $section->isDivergent()) {
            $page->callAction('confirmSection', ['comment' => 'Confere.'], ['section' => $section->id])
                ->assertHasNoActionErrors();
        }
    }

    $page->callAction('submitReview', [...BuilderReviewFixture::evidenceFormData($scenario['review']), 'declaration' => true])
        ->assertHasNoActionErrors();

    expect($scenario['review']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($scenario['review']->fresh()->submitted_by_user_id)->toBe($operator->id)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('refuses forged builder validation calls sent to the real Livewire endpoint', function () {
    $scenario = preparationReview();
    $before = preparationReviewState($scenario['review']);
    $viewer = preparationProfile('consulta');
    $this->actingAs($viewer);

    $html = $this->get(BuilderReviewWorkspace::getUrl(['record' => $scenario['cycle']]))
        ->assertOk()
        ->assertDontSee('Enviar validação')
        ->getContent();

    $snapshot = ForgedLivewireRequest::snapshotOf($html, BuilderReviewWorkspace::class);

    $removal = ForgedLivewireRequest::post($this, $snapshot, [
        ForgedLivewireRequest::call('mountAction', ['removeDivergence', ['divergence' => $scenario['divergence']->id], []]),
    ])->assertOk();

    $submission = ForgedLivewireRequest::post($this, $snapshot, [
        ForgedLivewireRequest::call('mountAction', ['submitReview', [], []]),
    ])->assertOk();

    expect(ForgedLivewireRequest::mountedActionNames($removal))->toBe([])
        ->and(ForgedLivewireRequest::mountedActionNames($submission))->toBe([])
        ->and(preparationReviewState($scenario['review']))->toBe($before)
        ->and(Activity::query()->where('causer_type', $viewer->getMorphClass())->where('causer_id', $viewer->id)->exists())->toBeFalse();
});

it('lets the operator reach the same calls through the real Livewire endpoint', function () {
    $scenario = preparationReview();
    $operator = preparationProfile('operador');
    $this->actingAs($operator);

    $html = $this->get(BuilderReviewWorkspace::getUrl(['record' => $scenario['cycle']]))->assertOk()->getContent();

    $mounted = ForgedLivewireRequest::post($this, ForgedLivewireRequest::snapshotOf($html, BuilderReviewWorkspace::class), [
        ForgedLivewireRequest::call('mountAction', ['removeDivergence', ['divergence' => $scenario['divergence']->id], []]),
    ])->assertOk();

    expect(ForgedLivewireRequest::mountedActionNames($mounted))->toBe(['removeDivergence']);

    ForgedLivewireRequest::post($this, ForgedLivewireRequest::nextSnapshot($mounted), [
        ForgedLivewireRequest::call('callMountedAction'),
    ])->assertOk();

    expect($scenario['divergence']->fresh())->toBeNull()
        ->and(Activity::query()
            ->where('subject_type', SalesBoardBuilderDivergence::class)
            ->where('subject_id', $scenario['divergence']->id)
            ->where('event', 'deleted')
            ->value('causer_id'))->toBe($operator->id);
});

it('tells whoever cannot operate that the validation belongs to the operator', function (string $profile) {
    $scenario = preparationReview();
    $this->actingAs(preparationProfile($profile));

    $notice = 'Confirmar seções, apontar divergências e enviar a validação são de quem opera a competência';
    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()]);

    match ($profile) {
        'consulta', 'gestao' => $page->assertSee($notice)->assertSee('peça a quem tem a permissão de edição do Quadro de Vendas'),
        'operador' => $page->assertDontSee($notice),
    };
})->with(['consulta', 'gestao', 'operador']);

// ── Validação da construtora: serviços ────────────────────────────────────────

it('refuses every builder review editor operation to an internal reviewer without sales-boards.update', function (string $operation) {
    $scenario = preparationReview();
    $before = preparationReviewState($scenario['review']);

    foreach (['consulta', 'gestao'] as $profile) {
        $refused = BuilderReviewFixture::reviewer(preparationProfile($profile));

        expect(fn () => preparationEditorOperation($operation, $scenario, $refused))
            ->toThrow(AuthorizationException::class, 'Quadro de vendas: editar');

        expect(preparationReviewState($scenario['review']))->toBe($before);
    }

    // O par: a mesma operação, por quem opera, grava.
    preparationEditorOperation($operation, $scenario, BuilderReviewFixture::reviewer());

    expect(preparationReviewState($scenario['review']))->not->toBe($before);
})->with(['confirm', 'reopen', 'declare', 'update', 'remove', 'comment']);

it('refuses the submission of a reviewer without sales-boards.update before checking the source', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    $checkedAt = CycleFixture::currentBaseline($scenario['cycle'])->last_checked_at;
    $this->travel(10)->minutes();

    expect(fn () => BuilderReviewFixture::submit($review, preparationProfile('consulta')))
        ->toThrow(AuthorizationException::class, 'Quadro de vendas: editar');

    // Nenhuma conferência contra a fonte rodou a pedido de quem não pode enviar.
    expect(CycleFixture::currentBaseline($scenario['cycle'])->last_checked_at?->toIso8601String())->toBe($checkedAt?->toIso8601String())
        ->and($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);

    $operator = GovernanceFixture::operator();
    $submitted = BuilderReviewFixture::submit($review, $operator);

    expect($submitted->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($submitted->submitted_by_user_id)->toBe($operator->id)
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->last_checked_at?->toIso8601String())->not->toBe($checkedAt?->toIso8601String());
});

it('refuses an external reviewer identity until the external channel exists', function () {
    $scenario = preparationReview();
    $before = preparationReviewState($scenario['review']);

    $external = new BuilderReviewerIdentity(
        type: BuilderReviewerType::External,
        stableKey: 'construtora:portal:1',
        displayName: 'Construtora Exemplo',
        email: 'contato@construtora.example',
    );

    expect(fn () => preparationEditorOperation('confirm', $scenario, $external))
        ->toThrow(AuthorizationException::class, 'o canal externo da construtora ainda não existe');

    expect(fn () => app(SalesBoardBuilderReviewSubmissionService::class)->submit($scenario['review']->fresh(), $external))
        ->toThrow(AuthorizationException::class, 'o canal externo da construtora ainda não existe');

    expect(preparationReviewState($scenario['review']))->toBe($before);
});

it('refuses to open a builder review for a named actor without sales-boards.update and still lets the automation open it', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $checkedAt = CycleFixture::currentBaseline($scenario['cycle'])->last_checked_at;
    $this->travel(10)->minutes();

    expect(fn () => BuilderReviewFixture::open($scenario['cycle'], preparationProfile('gestao')))
        ->toThrow(AuthorizationException::class, 'Quadro de vendas: editar');

    expect(SalesBoardBuilderReview::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->last_checked_at?->toIso8601String())->toBe($checkedAt?->toIso8601String());

    // Sem ator é a abertura automática, depois da apuração: continua aberta, e
    // sem ator humano inventado.
    $review = BuilderReviewFixture::open($scenario['cycle']);

    expect($review->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($review->opened_by_user_id)->toBeNull()
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

/**
 * Recalcular e congelar também conferem o ator no serviço: a tela esconde o
 * botão, mas um comando, um job ou uma ação nova que chame o serviço com uma
 * conta de consulta não pode gravar versão em nome dela. Sem ator é a rotina de
 * sistema (automação ou comando), que segue funcionando.
 */
it('refuses to recalculate for a named actor who does not operate the competence, and still recalculates for the operator and the system', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $scenario['contracts']['financed']->forceFill(['sale_value' => '950000.00'])->save();

    expect(fn () => CycleFixture::recalculate($scenario['cycle'], 'Valor da venda corrigido.', preparationProfile('consulta')))
        ->toThrow(AuthorizationException::class, 'Quadro de vendas: editar');

    expect(CycleFixture::currentBaseline($scenario['cycle'])->version)->toBe(1);

    expect(CycleFixture::recalculate($scenario['cycle'], 'Valor da venda corrigido.', preparationProfile('operador'))->baseline?->version)->toBe(2);

    $scenario['contracts']['financed']->forceFill(['sale_value' => '960000.00'])->save();

    expect(CycleFixture::recalculate($scenario['cycle'], 'Valor da venda corrigido de novo.')->baseline?->version)->toBe(3);
});

it('refuses to freeze a competence for a named actor without sales-boards.create, and still freezes for the operator and the system', function () {
    [$construction] = CycleFixture::readyConstruction(2);

    expect(fn () => CycleFixture::generate($construction, '2026-07-01', preparationProfile('gestao')))
        ->toThrow(AuthorizationException::class, 'Quadro de vendas: criar');

    expect(SalesBoardCycle::query()->count())->toBe(0)
        ->and(CycleFixture::generate($construction, '2026-07-01', preparationProfile('operador'))->wasGenerated())->toBeTrue()
        ->and(CycleFixture::generate($construction, '2026-08-01')->wasGenerated())->toBeTrue();
});

// ── Rollout ───────────────────────────────────────────────────────────────────

it('hides Analisar diferença from a profile without sales-boards.update and ignores the forged mount', function () {
    $scenario = preparationRollout();
    $viewer = preparationProfile('consulta');
    $this->actingAs($viewer);

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $scenario['emission']->getKey()])
        ->assertOk()
        ->assertActionHidden('acceptDifference', ['row' => $scenario['row']->id])
        ->call('mountAction', 'acceptDifference', ['row' => $scenario['row']->id])
        ->assertSet('mountedActions', []);

    $html = $this->get(ManageSalesBoardRollout::getUrl(['record' => $scenario['emission']]))->assertOk()->getContent();

    $forged = ForgedLivewireRequest::post($this, ForgedLivewireRequest::snapshotOf($html, ManageSalesBoardRollout::class), [
        ForgedLivewireRequest::call('mountAction', ['acceptDifference', ['row' => $scenario['row']->id], []]),
    ])->assertOk();

    expect(ForgedLivewireRequest::mountedActionNames($forged))->toBe([])
        ->and($scenario['row']->fresh()->accepted_difference)->toBeFalse()
        ->and($scenario['row']->fresh()->accepted_by_user_id)->toBeNull();

    // O par: quem opera registra a análise pelo mesmo caminho.
    $operator = preparationProfile('operador');
    $this->actingAs($operator);

    $html = $this->get(ManageSalesBoardRollout::getUrl(['record' => $scenario['emission']]))->assertOk()->getContent();

    $mounted = ForgedLivewireRequest::post($this, ForgedLivewireRequest::snapshotOf($html, ManageSalesBoardRollout::class), [
        ForgedLivewireRequest::call('mountAction', ['acceptDifference', ['row' => $scenario['row']->id], []]),
    ])->assertOk();

    expect(ForgedLivewireRequest::mountedActionNames($mounted))->toBe(['acceptDifference']);

    ForgedLivewireRequest::post($this, ForgedLivewireRequest::nextSnapshot($mounted), [
        ForgedLivewireRequest::call('callMountedAction'),
    ], ['mountedActions.0.data.reason' => 'O quadro legado contava um bloco que foi desmembrado.'])->assertOk();

    expect($scenario['row']->fresh()->accepted_difference)->toBeTrue()
        ->and($scenario['row']->fresh()->accepted_by_user_id)->toBe($operator->id);
});

it('refuses difference analysis, opening, reassessment, rejection and recipients to an actor without sales-boards.update', function (string $operation) {
    $case = preparationRolloutCase($operation);

    foreach (['consulta', 'gestao'] as $profile) {
        expect(fn () => ($case['run'])(preparationProfile($profile)))
            ->toThrow(AuthorizationException::class, 'Quadro de vendas: editar');

        expect(($case['changed'])())->toBeFalse();
    }

    expect(fn () => ($case['run'])(null))
        ->toThrow(SalesBoardRolloutException::class, 'Não foi possível identificar quem está conduzindo esta ação.');

    expect(($case['changed'])())->toBeFalse();

    ($case['run'])(GovernanceFixture::operator());

    expect(($case['changed'])())->toBeTrue();
})->with(['accept', 'open', 'reassess', 'reject', 'add-recipient', 'remove-recipient']);

// ── Análise da Gestão e cancelamento ──────────────────────────────────────────

it('lets the Gestão open the management review without sales-boards.update', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $manager = preparationProfile('gestao');
    $this->actingAs($manager);

    $page = Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionVisible('openManagementReview')
        ->callAction('openManagementReview')
        ->assertHasNoActionErrors();

    $review = SalesBoardManagementReview::query()->sole();

    $page->assertRedirect(ManagementReviewWorkspace::getUrl(['record' => $scenario['cycle'], 'review' => $review->getKey()]));

    expect($review->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($review->opened_by_user_id)->toBe($manager->id)
        ->and($review->nonconformities)->toHaveCount(1);

    // O serviço aceita a Gestão sem a permissão de operar.
    $other = ManagementReviewFixture::submittedCycle();
    $approver = GovernanceFixture::approver();

    expect(app(SalesBoardManagementReviewOpeningService::class)->open($other['cycle']->fresh(), $approver)->opened_by_user_id)
        ->toBe($approver->id);
});

it('refuses to open the management review to a view-only profile and to a missing actor', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $viewer = preparationProfile('consulta');
    $this->actingAs($viewer);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionHidden('openManagementReview')
        ->call('mountAction', 'openManagementReview')
        ->assertSet('mountedActions', []);

    $service = app(SalesBoardManagementReviewOpeningService::class);

    expect(fn () => $service->open($scenario['cycle']->fresh(), $viewer))
        ->toThrow(AuthorizationException::class, '"Quadro de vendas: editar" ou "Quadro de vendas: decidir, aprovar e ativar (Gestão)"');

    expect(fn () => $service->open($scenario['cycle']->fresh(), null))
        ->toThrow(AuthorizationException::class);

    expect(SalesBoardManagementReview::query()->count())->toBe(0);

    // O par: quem opera abre.
    expect($service->open($scenario['cycle']->fresh(), GovernanceFixture::operator())->status)
        ->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('shows Cancelar competência enabled to the Gestão without sales-boards.update and hides it from a view-only profile', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $reason = 'A competência não será publicada: a obra voltou ao registro manual.';

    $this->actingAs(preparationProfile('consulta'));

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionHidden('cancelCompetence')
        ->call('mountAction', 'cancelCompetence')
        ->assertSet('mountedActions', []);

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);

    $this->actingAs(preparationProfile('gestao'));

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionVisible('cancelCompetence')
        ->assertActionEnabled('cancelCompetence')
        ->callAction('cancelCompetence', data: ['reason' => $reason])
        ->assertHasNoActionErrors();

    expect($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
});

// ── Verificar alterações ──────────────────────────────────────────────────────

it('keeps Verificar alterações away from a view-only profile, on the cycle and on the list', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $cycle = $scenario['cycle'];
    $checkedAt = CycleFixture::currentBaseline($cycle)->last_checked_at;
    $this->travel(10)->minutes();

    $this->actingAs(preparationProfile('consulta'));

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertActionHidden('checkStale')
        ->call('mountAction', 'checkStale')
        ->assertSet('mountedActions', []);

    Livewire::test(ListSalesBoardCycles::class)
        ->assertCanSeeTableRecords([$cycle])
        ->assertActionHidden(TestAction::make('checkStale')->table($cycle))
        ->call('mountAction', 'checkStale', [], ['table' => true, 'recordKey' => (string) $cycle->getKey()])
        ->assertSet('mountedActions', []);

    expect(CycleFixture::currentBaseline($cycle)->last_checked_at?->toIso8601String())->toBe($checkedAt?->toIso8601String());
});

it('offers Verificar alterações to whoever operates or approves the competence', function (string $profile) {
    $scenario = BuilderReviewFixture::generatedCycle();
    $cycle = $scenario['cycle'];
    $checkedAt = CycleFixture::currentBaseline($cycle)->last_checked_at;
    $this->travel(10)->minutes();

    $this->actingAs(preparationProfile($profile));

    $tooltip = 'Compara a versão congelada com a fonte atual e registra o resultado na versão vigente. Não recalcula nem altera a posição.';

    Livewire::test(ListSalesBoardCycles::class)
        ->assertActionVisible(TestAction::make('checkStale')->table($cycle));

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertActionVisible('checkStale')
        ->assertActionExists('checkStale', fn (Action $action): bool => $action->getTooltip() === $tooltip)
        ->callAction('checkStale')
        ->assertNotified();

    expect(CycleFixture::currentBaseline($cycle)->last_checked_at?->toIso8601String())->not->toBe($checkedAt?->toIso8601String());
})->with(['operador', 'gestao']);
