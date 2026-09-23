<?php

use App\Enums\AccessPermission;
use App\Enums\MalwareScanStatus;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationSeriesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationSuggestionsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\Obligation;
use App\Models\ObligationSeries;
use App\Models\User;
use App\Services\Obligations\ObligationSuggestionReviewService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function createdObligationModalViewer(array $permissions = [
    AccessPermission::EmissionsView->value,
    AccessPermission::ObligationsView->value,
    AccessPermission::DocumentsView->value,
]): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * Aprova pela mesma service da tela, para a obrigação nascer como em produção.
 *
 * @param  array<string, mixed>  $attributes
 */
function createdObligationModalApprovedSuggestion(Emission $emission, array $attributes = []): ExtractedObligation
{
    $suggestion = ExtractedObligation::factory()->for($emission)->create([
        'status' => ExtractedObligation::STATUS_SUGGESTED,
        'recurrence' => 'Única',
        ...$attributes,
    ]);

    app(ObligationSuggestionReviewService::class)->approve($suggestion, makeAdminUser(), 'Compatível com o Termo.');

    return $suggestion->refresh();
}

function createdObligationModalDocument(Emission $emission): Document
{
    Storage::fake(Document::defaultStorageDisk());
    Storage::disk(Document::defaultStorageDisk())->put('documents/term.pdf', '%PDF-1.4 fake term');

    $document = Document::factory()->create([
        'title' => 'Termo de Securitização',
        'category' => 'documentos_operacao',
        'file_path' => 'documents/term.pdf',
        'storage_disk' => Document::defaultStorageDisk(),
    ]);
    $document->forceFill(['scan_status' => MalwareScanStatus::Clean])->saveQuietly();

    $emission->documents()->attach($document);

    return $document;
}

function createdObligationModalTable(Emission $emission): Testable
{
    return Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ]);
}

function createdObligationModalAction(ExtractedObligation $suggestion): TestAction
{
    return TestAction::make('view_obligation')->table($suggestion);
}

it('opens the created obligation in a read-only modal instead of navigating away', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Alto da Serra']);
    $suggestion = createdObligationModalApprovedSuggestion($emission, [
        'title' => 'Informar ocorrência de Vencimento Antecipado',
        'obligation_category' => 'Vencimento Antecipado',
    ]);
    $this->actingAs(createdObligationModalViewer());

    $component = createdObligationModalTable($emission)
        ->assertActionVisible(createdObligationModalAction($suggestion))
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertActionMounted(createdObligationModalAction($suggestion))
        ->assertNoRedirect()
        ->assertMountedActionModalSee([
            'Obrigação criada',
            'Consulte as informações da obrigação gerada a partir da sugestão aprovada.',
            'Informações gerais',
            'Informar ocorrência de Vencimento Antecipado',
            'CRI Alto da Serra',
            'Vencimento Antecipado',
            'Em dia',
            'Criada a partir de sugestão da IA',
            'Aprovada',
            'Fechar',
            'Abrir página completa',
        ]);

    $action = $component->instance()->getMountedAction();
    $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
    $schemaComponents = collect($schema->getFlatComponents(withHidden: true));

    expect($action->getUrl())->toBeNull()
        ->and($action->getModalSubmitAction())->toBeNull()
        ->and($action->getExtraModalWindowAttributes())->toMatchArray(['autofocus' => true, 'tabindex' => '-1'])
        ->and($action->isModalHeaderSticky())->toBeTrue()
        ->and($action->isModalFooterSticky())->toBeTrue()
        ->and($schemaComponents->filter(fn (mixed $schemaComponent): bool => $schemaComponent instanceof TextEntry))->not->toBeEmpty()
        ->and($schemaComponents->filter(fn (mixed $schemaComponent): bool => $schemaComponent instanceof Field))->toBeEmpty();
});

it('shows the current state of the obligation instead of the suggestion snapshot', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission, [
        'title' => 'Título sugerido pela IA',
        'description' => 'Descrição sugerida pela IA',
        'due_rule' => 'Prazo sugerido pela IA',
    ]);
    $responsible = User::factory()->create(['name' => 'Marina Duarte']);

    $this->travel(5)->minutes();

    $suggestion->obligation->update([
        'title' => 'Título ajustado após a aprovação',
        'description' => null,
        'status' => 'vencida',
        'responsible_user_id' => $responsible->id,
        'due_rule' => 'No prazo de até 1 (um) Dia Útil',
        'source_clause' => '7.1.1, (xix)',
        'source_page' => 39,
    ]);

    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee([
            'Título ajustado após a aprovação',
            'Vencida',
            'Marina Duarte',
            'No prazo de até 1 (um) Dia Útil',
            '7.1.1, (xix)',
            '39',
            'Última atualização',
        ])
        ->assertMountedActionModalDontSee([
            'Título sugerido pela IA',
            'Descrição sugerida pela IA',
            'Prazo sugerido pela IA',
        ]);
});

it('resolves the obligation through its foreign key and emission, never by title', function () {
    $emission = Emission::factory()->create();
    $otherEmission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->approved()->create([
        'title' => 'Enviar relatório anual',
        'recurrence' => 'Única',
    ]);

    // Criadas antes, com id menor: sem o filtro por chave e emissão, venceriam.
    Obligation::factory()->for($otherEmission)->create([
        'title' => 'Enviar relatório anual',
        'extracted_obligation_id' => $suggestion->id,
        'responsible_area' => 'Área da outra emissão',
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Enviar relatório anual',
        'extracted_obligation_id' => null,
        'responsible_area' => 'Área da obrigação manual',
    ]);
    Obligation::factory()->for($emission)->create([
        'title' => 'Enviar relatório anual',
        'extracted_obligation_id' => $suggestion->id,
        'responsible_area' => 'Área da obrigação vinculada',
    ]);

    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Área da obrigação vinculada')
        ->assertMountedActionModalDontSee(['Área da outra emissão', 'Área da obrigação manual']);
});

it('explains that the obligation is gone when the approved suggestion lost its link', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission, ['title' => 'Obrigação removida depois']);
    $suggestion->obligation->delete();

    Log::spy();
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->assertActionVisible(createdObligationModalAction($suggestion))
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertActionMounted(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee([
            'Obrigação não encontrada',
            'A obrigação vinculada a esta sugestão não está mais disponível.',
        ])
        ->assertMountedActionModalDontSee(['Obrigação removida depois', 'Abrir página completa']);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['event'] === 'obligation_suggestion_created_target_missing'
            && $context['suggestion_id'] === $suggestion->id
            && $context['emission_id'] === $emission->id);
});

it('keeps the modal usable when the obligation is deleted while it is open', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission, ['title' => 'Obrigação apagada com o modal aberto']);
    $this->actingAs(createdObligationModalViewer());

    $component = createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Obrigação apagada com o modal aberto');

    $suggestion->obligation->delete();

    $component->call('$refresh')
        ->assertOk()
        ->assertMountedActionModalSee('Obrigação não encontrada')
        ->assertMountedActionModalDontSee('Obrigação apagada com o modal aberto');
});

it('refuses the modal to users who cannot view obligations', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission, ['title' => 'Obrigação restrita']);
    $this->actingAs(createdObligationModalViewer([AccessPermission::EmissionsView->value]));

    expect(ObligationSuggestionsRelationManager::canViewForRecord($emission, EditEmission::class))->toBeFalse();

    createdObligationModalTable($emission)
        ->call('mountAction', 'view_obligation', [], ['table' => true, 'recordKey' => (string) $suggestion->getKey()])
        ->assertForbidden();
});

it('cannot be pointed at a suggestion from another emission through the Livewire payload', function () {
    $emission = Emission::factory()->create();
    $otherEmission = Emission::factory()->create();
    createdObligationModalApprovedSuggestion($emission);
    $foreignSuggestion = createdObligationModalApprovedSuggestion($otherEmission, ['title' => 'Obrigação sigilosa de outra emissão']);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->call('mountAction', 'view_obligation', [], ['table' => true, 'recordKey' => (string) $foreignSuggestion->getKey()])
        ->assertActionNotMounted()
        ->assertDontSee('Obrigação sigilosa de outra emissão');
});

it('opens the source document on the page of the current obligation, in a new tab', function () {
    $emission = Emission::factory()->create();
    $document = createdObligationModalDocument($emission);
    $suggestion = createdObligationModalApprovedSuggestion($emission, [
        'document_id' => $document->id,
        'source_clause' => '7.1.1, (xix)',
        'source_page' => 12,
    ]);
    $suggestion->obligation->update(['source_page' => 39]);
    $this->actingAs(createdObligationModalViewer());

    $component = createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee(['Fundamentação', '7.1.1, (xix)', 'Termo de Securitização', 'Abrir no documento'])
        ->assertMountedActionModalSeeHtml('href="'.e(route('admin.documents.preview', $document).'#page=39').'"')
        ->assertMountedActionModalDontSee('#page=12');

    $documentLink = collect($component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->getFlatComponents())
        ->filter(fn (mixed $schemaComponent): bool => $schemaComponent instanceof Actions)
        ->flatMap(fn (Actions $actions): array => $actions->getChildComponents())
        ->first(fn (mixed $action): bool => $action instanceof Action && $action->getName() === 'open_created_obligation_source');

    expect($documentLink?->shouldOpenUrlInNewTab())->toBeTrue();
});

it('shows the source reference without a document link to users who cannot view documents', function () {
    $emission = Emission::factory()->create();
    $document = createdObligationModalDocument($emission);
    $suggestion = createdObligationModalApprovedSuggestion($emission, [
        'document_id' => $document->id,
        'source_clause' => '9.2',
        'source_page' => 18,
    ]);
    $this->actingAs(createdObligationModalViewer([
        AccessPermission::EmissionsView->value,
        AccessPermission::ObligationsView->value,
    ]));

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee(['Fundamentação', '9.2', '18'])
        ->assertMountedActionModalDontSee('Abrir no documento');
});

it('does not link a document that belongs to another emission', function () {
    $emission = Emission::factory()->create();
    $foreignDocument = createdObligationModalDocument(Emission::factory()->create());
    $suggestion = createdObligationModalApprovedSuggestion($emission, [
        'document_id' => $foreignDocument->id,
        'source_page' => 5,
    ]);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalDontSee(['Abrir no documento', 'Termo de Securitização']);
});

it('renders the modal when the obligation has no source reference', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission, [
        'title' => 'Obrigação sem referência',
        'document_id' => null,
        'description' => null,
        'source_clause' => null,
        'source_page' => null,
        'source_excerpt' => null,
    ]);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertActionMounted(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee(['Obrigação sem referência', 'Prazo e recorrência', 'Origem'])
        ->assertMountedActionModalDontSee(['Fundamentação', 'Abrir no documento']);
});

it('links the full page to the obligations tab of the emission', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission);
    $relationKey = array_search(ObligationsRelationManager::class, EmissionResource::getRelations(), true);
    $expectedUrl = EmissionResource::getUrl('edit', ['record' => $emission, 'relation' => $relationKey]);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Abrir página completa')
        ->assertMountedActionModalSeeHtml('href="'.e($expectedUrl).'"');

    Livewire::withQueryParams(['relation' => (string) $relationKey])
        ->test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertSet('activeRelationManager', (string) $relationKey);
});

it('hides the full page link from users who cannot open the emission', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission, ['title' => 'Obrigação sem acesso à emissão']);
    $this->actingAs(createdObligationModalViewer([AccessPermission::ObligationsView->value]));

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Obrigação sem acesso à emissão')
        ->assertMountedActionModalDontSee('Abrir página completa');
});

it('shows the recurrence of a recurring suggestion and falls back to the series when no occurrence is linked', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission, [
        'title' => 'Enviar relatório mensal ao Agente Fiduciário',
        'recurrence' => 'Mensal',
    ]);
    $this->actingAs(createdObligationModalViewer());

    expect($suggestion->obligation)->not->toBeNull()
        ->and($suggestion->obligationSeries)->not->toBeNull();

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee([
            'Enviar relatório mensal ao Agente Fiduciário',
            'Em dia',
            'Mensal',
            'Situação da recorrência',
            'Aguardando configuração',
            'Regra executável',
        ])
        ->assertMountedActionModalDontSee('Próxima ocorrência');

    $suggestion->obligation->delete();
    $seriesKey = array_search(ObligationSeriesRelationManager::class, EmissionResource::getRelations(), true);

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee(['Enviar relatório mensal ao Agente Fiduciário', 'Aguardando configuração'])
        ->assertMountedActionModalDontSee(['Obrigação não encontrada', 'Situação da recorrência'])
        ->assertMountedActionModalSeeHtml('href="'.e(EmissionResource::getUrl('edit', ['record' => $emission, 'relation' => $seriesKey])).'"');
});

it('keeps search, sorting, filters and pagination after opening and closing the modal', function () {
    $emission = Emission::factory()->create();
    $approved = collect(range(1, 12))->map(function (int $position) use ($emission): ExtractedObligation {
        $suggestion = ExtractedObligation::factory()->for($emission)->approved()->create([
            'title' => sprintf('Comprovar destinação %02d', $position),
            'recurrence' => 'Única',
            'confidence_score' => 1 - ($position / 100),
            'reviewed_at' => now()->subMinutes($position),
        ]);
        Obligation::factory()->for($emission)->create([
            'title' => $suggestion->title,
            'extracted_obligation_id' => $suggestion->id,
        ]);

        return $suggestion;
    });
    ExtractedObligation::factory()->for($emission)->create(['title' => 'Comprovar pendente']);
    ExtractedObligation::factory()->for($emission)->approved()->create(['title' => 'Outra aprovada']);
    $pageOne = $approved->take(10)->all();
    $pageTwo = $approved->slice(10)->all();
    $this->actingAs(createdObligationModalViewer());

    $component = createdObligationModalTable($emission)
        ->set('tableRecordsPerPage', 10)
        ->sortTable('reviewed_at', 'desc')
        ->searchTable('Comprovar')
        ->filterTable('status', ExtractedObligation::STATUS_APPROVED)
        ->call('setPage', 2)
        ->assertCanSeeTableRecords($pageTwo)
        ->assertCanNotSeeTableRecords($pageOne)
        ->mountAction(createdObligationModalAction($pageTwo[array_key_first($pageTwo)]))
        ->assertActionMounted(createdObligationModalAction($pageTwo[array_key_first($pageTwo)]))
        ->unmountAction()
        ->assertActionNotMounted()
        ->assertSet('tableSearch', 'Comprovar')
        ->assertSet('tableSort', 'reviewed_at:desc')
        ->assertSet('tableFilters.status.value', ExtractedObligation::STATUS_APPROVED)
        ->assertCanSeeTableRecords($pageTwo)
        ->assertCanNotSeeTableRecords($pageOne);

    expect((int) $component->instance()->getTablePage())->toBe(2);
});

it('does not query the created obligation per row while rendering the table', function () {
    $emission = Emission::factory()->create();
    collect(range(1, 4))->each(fn (int $position): ExtractedObligation => createdObligationModalApprovedSuggestion($emission));
    $this->actingAs(createdObligationModalViewer());

    DB::enableQueryLog();
    createdObligationModalTable($emission)->assertOk();
    $perRowLookups = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, '"extracted_obligation_id" = ?'));
    DB::disableQueryLog();

    expect($perRowLookups)->toBeEmpty();
});

it('does not render the created obligation through a replayed lazy-load request after the permission is revoked', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission);
    $suggestion->obligation->update(['responsible_area' => 'Área visível só na obrigação']);
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo([AccessPermission::EmissionsView->value, AccessPermission::ObligationsView->value]);
    $this->actingAs($user);

    $relationKey = array_search(ObligationSuggestionsRelationManager::class, EmissionResource::getRelations(), true);
    $page = $this->get(EmissionResource::getUrl('edit', ['record' => $emission, 'relation' => $relationKey]))->assertSuccessful();
    [$snapshot, $lazyLoadArgument] = createdObligationModalLazySnapshot($page->getContent());

    $user->revokePermissionTo(AccessPermission::ObligationsView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // O pedido de carregamento preguiçoso pula os hooks de hydrate — onde vive a
    // checagem de canViewForRecord — e aplica os updates antes de montar.
    \Livewire\trigger('flush-state');
    $response = $this->withHeaders(['X-Livewire' => '1'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['mountedActions' => [[
                'name' => 'view_obligation',
                'arguments' => [],
                'context' => ['table' => true, 'recordKey' => (string) $suggestion->getKey()],
            ]]],
            'calls' => [['method' => '__lazyLoad', 'params' => [$lazyLoadArgument], 'metadata' => []]],
        ]],
    ]);

    $rendered = collect(Arr::dot((array) data_get($response->json(), 'components.0.effects')))
        ->filter(fn (mixed $value): bool => is_string($value))
        ->implode(PHP_EOL);

    expect($response->status())->toBe(200)
        ->and($rendered)->toContain('Obrigação criada')
        ->and($rendered)->not->toContain('Área visível só na obrigação');
});

it('ignores a series that belongs to another emission', function () {
    $emission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->approved()->create(['recurrence' => 'Mensal']);
    ObligationSeries::factory()->for(Emission::factory()->create())->create([
        'title' => 'Série sigilosa de outra emissão',
        'extracted_obligation_id' => $suggestion->id,
    ]);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Obrigação não encontrada')
        ->assertMountedActionModalDontSee('Série sigilosa de outra emissão');
});

it('shows the current description when it was edited to another text', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission, ['description' => 'Descrição sugerida pela IA']);
    $suggestion->obligation->update(['description' => 'Descrição ajustada pela equipe']);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Descrição ajustada pela equipe')
        ->assertMountedActionModalDontSee('Descrição sugerida pela IA');
});

it('hides the action from users without obligations.view', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission);
    $this->actingAs(createdObligationModalViewer([AccessPermission::EmissionsView->value]));

    createdObligationModalTable($emission)
        ->assertActionHidden(createdObligationModalAction($suggestion));
});

it('shows the oldest obligation when two share the foreign key', function () {
    $emission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->approved()->create(['recurrence' => 'Única']);
    Obligation::factory()->for($emission)->create(['extracted_obligation_id' => $suggestion->id, 'responsible_area' => 'Área da mais antiga']);
    Obligation::factory()->for($emission)->create(['extracted_obligation_id' => $suggestion->id, 'responsible_area' => 'Área da mais nova']);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Área da mais antiga')
        ->assertMountedActionModalDontSee('Área da mais nova');
});

it('hides the action on pending and rejected suggestions without a created target', function () {
    $emission = Emission::factory()->create();
    $pending = ExtractedObligation::factory()->for($emission)->create(['status' => ExtractedObligation::STATUS_SUGGESTED]);
    $rejected = ExtractedObligation::factory()->for($emission)->rejected()->create();
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->assertActionHidden(createdObligationModalAction($pending))
        ->assertActionHidden(createdObligationModalAction($rejected));
});

it('still offers the modal for a suggestion not marked approved that has a linked obligation', function () {
    $emission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->create(['status' => ExtractedObligation::STATUS_SUGGESTED]);
    Obligation::factory()->for($emission)->create(['extracted_obligation_id' => $suggestion->id, 'title' => 'Obrigação legada vinculada']);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->assertActionVisible(createdObligationModalAction($suggestion))
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Obrigação legada vinculada');
});

it('does not link a document whose malware scan is not clean', function () {
    $emission = Emission::factory()->create();
    $document = createdObligationModalDocument($emission);
    $document->forceFill(['scan_status' => MalwareScanStatus::Pending])->saveQuietly();
    $suggestion = createdObligationModalApprovedSuggestion($emission, ['document_id' => $document->id, 'source_page' => 4]);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Termo de Securitização')
        ->assertMountedActionModalDontSee('Abrir no documento');
});

it('links the series document of a recurring suggestion whose own document is gone', function () {
    $emission = Emission::factory()->create();
    $document = createdObligationModalDocument($emission);
    $suggestion = createdObligationModalApprovedSuggestion($emission, [
        'recurrence' => 'Mensal',
        'document_id' => $document->id,
        'source_page' => 7,
    ]);
    $suggestion->forceFill(['document_id' => null])->saveQuietly();
    $this->actingAs(createdObligationModalViewer());

    expect($suggestion->obligationSeries->document_id)->toBe($document->id);

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee(['Termo de Securitização', 'Abrir no documento']);
});

it('does not log a warning when the created obligation exists', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission);
    Log::spy();
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertActionMounted(createdObligationModalAction($suggestion));

    Log::shouldNotHaveReceived('warning');
});

it('keeps Fechar and the full page link in the footer, the link opening in a new tab', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission);
    $this->actingAs(createdObligationModalViewer());

    $footer = collect(createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->instance()
        ->getMountedAction()
        ->getVisibleModalFooterActions());

    expect($footer->map(fn (Action $action): ?string => $action->getLabel())->all())
        ->toBe(['open_created_obligation_page' => 'Abrir página completa', 'cancel' => 'Fechar'])
        ->and($footer['open_created_obligation_page']->shouldOpenUrlInNewTab())->toBeTrue();
});

it('shows the last update only after the obligation was edited', function () {
    $emission = Emission::factory()->create();
    $suggestion = createdObligationModalApprovedSuggestion($emission);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee('Criada em')
        ->assertMountedActionModalDontSee('Última atualização');
});

it('shows the rule version and the next occurrence of an active series', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-18 10:00:00'));
    $emission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->approved()->create(['recurrence' => 'Mensal']);
    ObligationSeries::factory()->monthly('2026-01-01', '2026-12-31', dueDay: 10, dueOffsetMonths: 1)->for($emission)->create([
        'title' => 'Enviar relatório mensal ao Agente Fiduciário',
        'extracted_obligation_id' => $suggestion->id,
    ]);
    $this->actingAs(createdObligationModalViewer());

    createdObligationModalTable($emission)
        ->mountAction(createdObligationModalAction($suggestion))
        ->assertMountedActionModalSee([
            'Enviar relatório mensal ao Agente Fiduciário',
            'Ativa',
            'Versão da regra',
            'Versão 1 · vigente a partir de 01/01/2026',
            'Próxima ocorrência',
            '10/09/2026 · comp. 08/2026',
        ]);
});

/**
 * Snapshot do RelationManager ainda não carregado e o argumento do seu
 * `__lazyLoad`, lidos do HTML da página.
 *
 * @return array{0: string, 1: string}
 */
function createdObligationModalLazySnapshot(string $html): array
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();

    foreach ((new DOMXPath($dom))->query("//*[@*[name()='wire:snapshot']]") ?: [] as $node) {
        $snapshot = $node->getAttribute('wire:snapshot');
        $isSuggestionsManager = data_get(json_decode($snapshot, true), 'memo.name') === ObligationSuggestionsRelationManager::class;

        if ($isSuggestionsManager && preg_match("/__lazyLoad\\('([^']+)'\\)/", $node->getAttribute('x-intersect').$node->getAttribute('x-init'), $matches)) {
            return [$snapshot, $matches[1]];
        }
    }

    throw new RuntimeException('Snapshot preguiçoso do RelationManager de sugestões não encontrado na página.');
}
