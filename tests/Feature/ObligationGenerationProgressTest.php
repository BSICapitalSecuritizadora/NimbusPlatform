<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationSuggestionsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Jobs\GenerateEmissionObligationsJob;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ObligationGenerationRun;
use App\Models\User;
use App\Services\GeminiService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeProgressTermDocument(Emission $emission): Document
{
    Storage::fake(Document::defaultStorageDisk());
    Storage::disk(Document::defaultStorageDisk())->put('documents/term.pdf', '%PDF-1.4 fake term');

    $document = Document::factory()->create([
        'title' => 'Termo de Securitização',
        'category' => 'documentos_operacao',
        'file_path' => 'documents/term.pdf',
        'storage_disk' => Document::defaultStorageDisk(),
    ]);

    $emission->documents()->attach($document);

    return $document;
}

function fakeProgressGeminiResponse(array $obligations): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode(['obligations' => $obligations])]]],
            ]],
        ]),
    ]);
}

function makeGenerationUserWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('marks the run as running and then completed while persisting counts', function () {
    $emission = Emission::factory()->create();
    $document = makeProgressTermDocument($emission);

    $run = ObligationGenerationRun::factory()->for($emission)->create([
        'document_id' => $document->id,
    ]);

    fakeProgressGeminiResponse([
        ['title' => 'Enviar relatório mensal', 'source_excerpt' => 'x', 'confidence_score' => 0.90],
        ['title' => 'Constituir fundo de reserva', 'source_excerpt' => 'x', 'confidence_score' => 0.88],
    ]);

    (new GenerateEmissionObligationsJob($emission->id, $document->id, $run->id))
        ->handle(app(GeminiService::class));

    $run->refresh();

    expect($run->status)->toBe(ObligationGenerationRun::STATUS_COMPLETED)
        ->and($run->generated_count)->toBe(2)
        ->and($run->current_step)->toBe('completed')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('marks the run as failed and records a safe error when extraction throws', function () {
    $emission = Emission::factory()->create();
    $document = makeProgressTermDocument($emission);

    $run = ObligationGenerationRun::factory()->for($emission)->create([
        'document_id' => $document->id,
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response('upstream error', 500),
    ]);

    expect(fn () => (new GenerateEmissionObligationsJob($emission->id, $document->id, $run->id))
        ->handle(app(GeminiService::class)))->toThrow(Exception::class);

    $run->refresh();

    expect($run->status)->toBe(ObligationGenerationRun::STATUS_FAILED)
        ->and($run->error_message)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('creates a pending run and dispatches the job when generation starts', function () {
    Queue::fake();
    $this->actingAs(makeGenerationUserWithPermissions([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsGenerate->value,
    ]));

    $emission = Emission::factory()->create();
    $document = makeProgressTermDocument($emission);

    Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->callTableAction('generate_obligations');

    $run = $emission->obligationGenerationRuns()->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(ObligationGenerationRun::STATUS_PENDING)
        ->and($run->document_id)->toBe($document->id);

    Queue::assertPushed(GenerateEmissionObligationsJob::class);
});

it('hides the generate action from users without the generate permission', function () {
    $this->actingAs(makeGenerationUserWithPermissions([
        AccessPermission::ObligationsView->value,
    ]));

    $emission = Emission::factory()->create();

    Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->assertTableActionHidden('generate_obligations');
});

it('disables the generate action while a generation is in progress', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    makeProgressTermDocument($emission);
    ObligationGenerationRun::factory()->for($emission)->running()->create();

    Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->assertTableActionDisabled('generate_obligations');
});

it('enables the generate action when there is no active generation', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    makeProgressTermDocument($emission);
    ObligationGenerationRun::factory()->for($emission)->completed()->create();

    Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->assertTableActionEnabled('generate_obligations');
});

it('polls and shows the progress banner while a run is active', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    ObligationGenerationRun::factory()->for($emission)->running()->create();

    Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSee('Extraindo obrigações do documento...')
        ->assertSeeHtml('wire:poll');
});

it('stops polling and shows the success banner once completed', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    ObligationGenerationRun::factory()->for($emission)->completed()->create();

    Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSee('Geração concluída com sucesso.')
        ->assertDontSeeHtml('wire:poll.4s');
});

it('does not expose the suggestions tab to users without permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $emission = Emission::factory()->create();

    expect(ObligationSuggestionsRelationManager::canViewForRecord($emission, EditEmission::class))
        ->toBeFalse();
});
