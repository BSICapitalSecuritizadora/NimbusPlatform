<?php

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceValueOrigin;
use App\Domain\PuCalculator\Services\PuBaselineEvidenceExtractionService;
use App\Domain\PuCalculator\Services\PuBaselineEvidenceReviewService;
use App\Enums\AccessPermission;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\MalwareScanStatus;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuBaselineEvidencesRelationManager;
use App\Filament\Resources\Emissions\Pages\ViewEmission;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentField;
use App\Models\User;
use App\Services\GeminiService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{0: User, 1: Emission, 2: Document} */
function aiEvidenceScenario(): array
{
    $user = makeAdminUser();
    $user->givePermissionTo([
        AccessPermission::PuParametersConfigure->value,
        AccessPermission::PuCurveView->value,
    ]);

    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Alpha',
        'type' => 'CRI',
        'if_code' => 'IF-ALPHA-01',
    ]);
    // O relation manager só existe para emissão com contexto de PU; um campo de
    // configuração do dossiê basta, sem criar evidência que distorça as contagens.
    LegalInstrumentField::factory()
        ->for(LegalInstrument::factory()->create(['emission_id' => $emission->id]), 'instrument')
        ->create([
            'field_key' => LegalInstrumentFieldKey::DayCountRule,
            'value_type' => LegalInstrumentFieldKey::DayCountRule->valueType(),
            'value' => 'du_252',
            'value_numeric' => null,
            'value_date' => null,
        ]);
    Storage::fake('local');
    Storage::disk('local')->put('documents/extrato-b3.pdf', '%PDF-1.4 extrato de liquidação');
    $document = Document::factory()->create([
        'title' => 'Extrato de liquidação B3',
        'file_path' => 'documents/extrato-b3.pdf',
        'storage_disk' => 'local',
        'scan_status' => MalwareScanStatus::Clean,
    ]);
    $emission->documents()->attach($document);

    return [$user, $emission, $document];
}

/** @param array<string, mixed> $overrides */
function settlementExtractionPayload(array $overrides = []): array
{
    return array_replace([
        'found' => true,
        'value' => '15 de agosto de 2026',
        'value_as_written' => '15 de agosto de 2026',
        'page' => 17,
        'reference' => 'Cláusula 4.2',
        'excerpt' => 'A primeira integralização ocorreu em 15 de agosto de 2026.',
        'evidence_level' => 'explicit',
        'alternatives' => [],
        'emission_mentioned' => true,
        'document_type' => 'b3_settlement_statement',
        'observations' => 'A data está expressamente indicada na cláusula 4.2.',
        'confidence' => 0.93,
    ], $overrides);
}

/**
 * Cada item de `$responses` atende uma chamada, na ordem; um Throwable é
 * lançado em vez de devolvido.
 *
 * @param  list<array<string, mixed>|Throwable>  $responses
 */
function expectGeminiExtractions(array $responses): void
{
    $gemini = test()->mock(GeminiService::class);
    $gemini->shouldReceive('model')->andReturn('gemini-test-model');
    $expectation = $gemini->shouldReceive('extractFromDocumentWithPrompt')->times(count($responses));
    $queue = $responses;
    $expectation->andReturnUsing(function () use (&$queue): array {
        $next = array_shift($queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    });
}

function evidenceModal(Emission $emission): Testable
{
    return Livewire::test(PuBaselineEvidencesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => ViewEmission::class,
    ])->mountAction(TestAction::make('create')->table());
}

function analyzedEvidenceModal(Emission $emission, Document $document): Testable
{
    return evidenceModal($emission)
        ->fillForm(['evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate->value])
        ->fillForm(['document_id' => $document->id]);
}

it('analyzes the selected document and fills the form with the validated suggestion', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([settlementExtractionPayload()]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->assertActionDataSet([
            'document_type' => 'b3_settlement_statement',
            'evidenced_value' => '2026-08-15',
            'reference' => 'Página 17 · Cláusula 4.2',
            'confidence' => 'high',
            'notes' => 'A data está expressamente indicada na cláusula 4.2.',
        ])
        ->assertMountedActionModalSee('Evidência encontrada · confiança alta')
        ->assertMountedActionModalSee('Fonte identificada pela IA')
        ->assertMountedActionModalSee('A primeira integralização ocorreu em 15 de agosto de 2026.')
        ->assertMountedActionModalSee('Preenchido automaticamente por IA')
        ->assertMountedActionModalSeeHtml('wire:target="mountedActions.0.data.evidence_type, mountedActions.0.data.document_id"');
});

it('sends the requested value, expected type and format to the extractor', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    $gemini = $this->mock(GeminiService::class);
    $gemini->shouldReceive('model')->andReturn('gemini-test-model');
    $gemini->shouldReceive('extractFromDocumentWithPrompt')
        ->once()
        ->withArgs(fn (Document $sent, string $prompt): bool => $sent->is($document)
            && str_contains($prompt, '"valor_a_comprovar": "Quantidade efetivamente integralizada"')
            && str_contains($prompt, '"tipo_esperado": "positive_number"')
            && str_contains($prompt, 'IF-ALPHA-01'))
        ->andReturn(settlementExtractionPayload(['value' => '1.500', 'document_type' => 'registrar_position']));
    $this->actingAs($user);

    evidenceModal($emission)
        ->fillForm(['evidence_type' => PuBaselineEvidenceType::IntegralizedQuantity->value])
        ->fillForm(['document_id' => $document->id])
        ->assertActionDataSet(['evidenced_value' => '1500']);
});

it('persists the extraction provenance and keeps the evidence pending human review', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([settlementExtractionPayload()]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $evidence = EmissionPuBaselineEvidence::query()->sole();

    expect($evidence->status)->toBe(PuBaselineEvidenceStatus::PendingReview)
        ->and($evidence->reviewed_by)->toBeNull()
        ->and($evidence->evidenced_value)->toBe('2026-08-15')
        ->and($evidence->reference)->toBe('Página 17 · Cláusula 4.2')
        ->and($evidence->page)->toBe(17)
        ->and($evidence->excerpt)->toBe('A primeira integralização ocorreu em 15 de agosto de 2026.')
        ->and($evidence->confidence)->toBe('high')
        ->and($evidence->value_origin)->toBe(PuBaselineEvidenceValueOrigin::AiExtracted)
        ->and($evidence->extraction['status'])->toBe('found')
        ->and($evidence->extraction['model'])->toBe('gemini-test-model')
        ->and($evidence->extraction['prompt_version'])->toBe(PuBaselineEvidenceExtractionService::PROMPT_VERSION)
        ->and($evidence->extraction['analyzed_at'])->not->toBeNull()
        ->and($evidence->extraction['requested_by'])->toBe($user->id)
        ->and($evidence->extraction['document_id'])->toBe($document->id)
        ->and($evidence->extraction['document_checksum'])->toBe(hash('sha256', '%PDF-1.4 extrato de liquidação'))
        ->and($evidence->extraction['suggestion']['raw_value'])->toBe('15 de agosto de 2026')
        ->and($evidence->extraction['suggestion']['excerpt'])->toBe('A primeira integralização ocorreu em 15 de agosto de 2026.')
        ->and($evidence->extraction['edited_fields'])->toBe([]);

    $analysis = Activity::query()->where('event', 'ai_extraction_found')->sole();
    $creation = Activity::query()->where('event', 'created_pending_review')->sole();

    expect($analysis->causer_id)->toBe($user->id)
        ->and($analysis->properties['document_id'])->toBe($document->id)
        ->and($analysis->properties['extraction_id'])->toBe($evidence->extraction['id'])
        ->and($analysis->properties->has('excerpt'))->toBeFalse()
        ->and($creation->properties['value_origin'])->toBe('ai_extracted')
        ->and($creation->properties['extraction_id'])->toBe($evidence->extraction['id']);
});

it('records a value corrected by the user as extracted and edited, without the ai location', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([settlementExtractionPayload()]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->fillForm(['evidenced_value' => '2026-08-16', 'reference' => 'Página 18'])
        ->assertMountedActionModalSee('Editado após sugestão da IA')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $evidence = EmissionPuBaselineEvidence::query()->sole();

    expect($evidence->value_origin)->toBe(PuBaselineEvidenceValueOrigin::AiExtractedEdited)
        ->and($evidence->evidenced_value)->toBe('2026-08-16')
        ->and($evidence->page)->toBeNull()
        ->and($evidence->excerpt)->toBeNull()
        ->and($evidence->extraction['edited_fields'])->toBe(['evidenced_value', 'reference'])
        ->and($evidence->extraction['suggestion']['value'])->toBe('2026-08-15');
});

it('does not accept a value that fails validation and flags the result as partial', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([settlementExtractionPayload(['value' => '31/02/2026'])]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->assertActionDataSet([
            'evidenced_value' => null,
            'reference' => 'Página 17 · Cláusula 4.2',
            'confidence' => 'low',
        ])
        ->assertMountedActionModalSee('Resultado parcial')
        ->callMountedAction()
        ->assertHasFormErrors(['evidenced_value' => 'required']);

    expect(EmissionPuBaselineEvidence::query()->count())->toBe(0);
});

it('tells the user when nothing is found and leaves the fields for manual entry', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([[
        'found' => false,
        'value' => null,
        'page' => null,
        'reference' => null,
        'excerpt' => null,
        'evidence_level' => 'not_found',
        'observations' => 'Não foi possível localizar com segurança a informação solicitada.',
        'confidence' => 0.2,
    ]]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->assertActionDataSet(['evidenced_value' => null, 'reference' => null, 'confidence' => 'high'])
        ->assertMountedActionModalSee('Informação não localizada')
        ->assertMountedActionModalSee('Não foi possível localizar automaticamente a informação solicitada neste documento.')
        ->assertActionHidden(TestAction::make('openEvidenceSource')->schemaComponent('aiExtraction'))
        ->fillForm([
            'document_type' => 'b3_settlement_statement',
            'evidenced_value' => '2026-08-15',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $evidence = EmissionPuBaselineEvidence::query()->sole();

    expect($evidence->value_origin)->toBe(PuBaselineEvidenceValueOrigin::Manual)
        ->and($evidence->extraction['status'])->toBe('not_found');
});

it('lists conflicting occurrences with links to their pages and fills no value', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([settlementExtractionPayload([
        'found' => false,
        'value' => null,
        'page' => null,
        'reference' => null,
        'excerpt' => null,
        'evidence_level' => 'conflicting',
        'alternatives' => [
            ['value' => '2026-08-15', 'page' => 2, 'excerpt' => 'Primeira integralização em 15 de agosto de 2026.'],
            ['value' => '2026-08-20', 'page' => 3, 'excerpt' => 'Retificação: primeira integralização em 20 de agosto de 2026.'],
        ],
    ])]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->assertActionDataSet(['evidenced_value' => null, 'confidence' => 'low'])
        ->assertMountedActionModalSee('Resultado parcial')
        ->assertMountedActionModalSee('Ocorrências com valores diferentes')
        ->assertMountedActionModalSeeHtml('href="'.e(route('admin.documents.preview', $document).'#page=3').'"');
});

it('opens the document on the page where the evidence was found', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([settlementExtractionPayload()]);
    $this->actingAs($user);
    $expectedUrl = route('admin.documents.preview', $document).'#page=17';

    analyzedEvidenceModal($emission, $document)
        ->assertActionVisible(TestAction::make('openEvidenceSource')->schemaComponent('aiExtraction'))
        ->assertActionHasUrl(TestAction::make('openEvidenceSource')->schemaComponent('aiExtraction'), $expectedUrl)
        ->assertActionShouldOpenUrlInNewTab(TestAction::make('openEvidenceSource')->schemaComponent('aiExtraction'))
        ->callMountedAction();

    $evidence = EmissionPuBaselineEvidence::query()->sole();

    expect($evidence->source_url)->toBe($expectedUrl);

    Livewire::test(PuBaselineEvidencesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => ViewEmission::class,
    ])->assertSeeHtml('href="'.e($expectedUrl).'"');
});

it('hides the open action while the document has not passed the malware scan', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    $document->update(['scan_status' => MalwareScanStatus::Pending]);
    expectGeminiExtractions([settlementExtractionPayload()]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->assertActionHidden(TestAction::make('openEvidenceSource')->schemaComponent('aiExtraction'))
        ->assertMountedActionModalSee('Abertura do arquivo indisponível');
});

it('never silently overwrites a field the user edited when the analysis runs again', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    $quantityDocument = Document::factory()->create([
        'title' => 'Posição do escriturador',
        'scan_status' => MalwareScanStatus::Clean,
    ]);
    $emission->documents()->attach($quantityDocument);
    expectGeminiExtractions([
        settlementExtractionPayload(),
        settlementExtractionPayload(['value' => '2026-08-20', 'page' => 3, 'reference' => null, 'observations' => 'Posição consolidada.']),
        settlementExtractionPayload(['value' => '2026-08-20', 'page' => 3, 'reference' => null, 'observations' => 'Posição consolidada.']),
    ]);
    $this->actingAs($user);

    $modal = analyzedEvidenceModal($emission, $document)
        ->fillForm(['evidenced_value' => '2026-08-14'])
        // Trocar o documento reanalisa automaticamente: o que a IA preencheu
        // acompanha o novo documento, o que o usuário digitou fica.
        ->fillForm(['document_id' => $quantityDocument->id])
        ->assertActionDataSet([
            'evidenced_value' => '2026-08-14',
            'reference' => 'Página 3',
            'notes' => 'Posição consolidada.',
        ])
        ->assertMountedActionModalSee('Campos que você editou foram mantidos')
        ->mountAction(TestAction::make('reanalyzeWithAi')->schemaComponent('aiExtraction'));

    // O campo editado exige confirmação explícita antes de ser substituído.
    $modal->assertActionMounted(TestAction::make('reanalyzeWithAi')->schemaComponent('aiExtraction', schema: 'mountedActionSchema0'))
        ->assertMountedActionModalSee('Você editou: Valor demonstrado.')
        ->callMountedAction()
        ->assertActionDataSet(['evidenced_value' => '2026-08-20']);
});

it('analyzes again for the new requirement when the value to prove changes', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    $gemini = $this->mock(GeminiService::class);
    $gemini->shouldReceive('model')->andReturn('gemini-test-model');
    $gemini->shouldReceive('extractFromDocumentWithPrompt')
        ->twice()
        ->andReturnUsing(fn (Document $sent, string $prompt): array => str_contains($prompt, '"tipo_esperado": "positive_number"')
            ? settlementExtractionPayload(['value' => '35000', 'page' => 4, 'reference' => 'Quadro II', 'document_type' => 'registrar_position'])
            : settlementExtractionPayload());
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->assertActionDataSet(['evidenced_value' => '2026-08-15'])
        ->fillForm(['evidence_type' => PuBaselineEvidenceType::IntegralizedQuantity->value])
        ->assertActionDataSet([
            'evidenced_value' => '35000',
            'reference' => 'Página 4 · Quadro II',
            'document_type' => 'registrar_position',
        ]);
});

it('reanalyzes without confirmation and without the cache when nothing was edited', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([
        settlementExtractionPayload(),
        settlementExtractionPayload(['confidence' => 0.7]),
    ]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->callAction(TestAction::make('reanalyzeWithAi')->schemaComponent('aiExtraction'))
        ->assertActionDataSet(['confidence' => 'medium']);
});

it('reuses the previous analysis of the same file instead of sending it again', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([settlementExtractionPayload()]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document);
    analyzedEvidenceModal($emission, $document)
        ->assertActionDataSet(['evidenced_value' => '2026-08-15'])
        ->assertMountedActionModalSee('reaproveitado de análise anterior do mesmo arquivo');

    expect(Activity::query()->where('event', 'ai_extraction_found')->pluck('properties')->pluck('source')->all())
        ->toBe(['gemini', 'cache']);
});

it('only analyzes documents of the emission for users allowed to configure the baseline', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    $foreignDocument = Document::factory()->create(['scan_status' => MalwareScanStatus::Clean]);
    Emission::factory()->create()->documents()->attach($foreignDocument);
    $viewer = makeAdminUser();
    $viewer->syncRoles([]);
    $viewer->givePermissionTo(AccessPermission::PuCurveView->value);
    $gemini = $this->mock(GeminiService::class);
    $gemini->shouldNotReceive('extractFromDocumentWithPrompt');
    $service = app(PuBaselineEvidenceExtractionService::class);

    expect(fn () => $service->analyze($emission, $foreignDocument->id, PuBaselineEvidenceType::FirstIntegralizationDate, $user))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->analyze($emission, $document->id, PuBaselineEvidenceType::FirstIntegralizationDate, $viewer))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($user);

    evidenceModal($emission)
        ->fillForm(['evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate->value])
        ->fillForm(['document_id' => $foreignDocument->id])
        ->assertNotified('Não foi possível analisar este documento.')
        ->assertActionDataSet(['extraction_id' => null]);
});

it('does not borrow provenance from an analysis made by someone else', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    $otherUser = makeAdminUser();
    $otherUser->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    expectGeminiExtractions([settlementExtractionPayload()]);
    $foreignExtraction = app(PuBaselineEvidenceExtractionService::class)
        ->analyze($emission, $document->id, PuBaselineEvidenceType::FirstIntegralizationDate, $otherUser);

    $evidence = app(PuBaselineEvidenceReviewService::class)->create($emission, [
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate->value,
        'document_type' => 'b3_settlement_statement',
        'evidenced_value' => '2026-08-15',
        'reference' => 'Página 17 · Cláusula 4.2',
        'confidence' => 'high',
        'extraction_id' => $foreignExtraction['id'],
    ], $user);

    expect($evidence->value_origin)->toBe(PuBaselineEvidenceValueOrigin::Manual)
        ->and($evidence->page)->toBeNull()
        ->and($evidence->extraction)->toBeNull();
});

it('turns a gemini failure into a friendly state without breaking the form', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([new ConnectionException('cURL error 28: Operation timed out')]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->assertHasNoErrors()
        ->assertMountedActionModalSee('Análise indisponível')
        ->assertMountedActionModalSee('Não foi possível analisar o documento neste momento. Tente novamente.')
        ->assertMountedActionModalDontSee('cURL error 28')
        ->fillForm([
            'document_type' => 'b3_settlement_statement',
            'evidenced_value' => '2026-08-15',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $evidence = EmissionPuBaselineEvidence::query()->sole();

    expect($evidence->status)->toBe(PuBaselineEvidenceStatus::PendingReview)
        ->and($evidence->value_origin)->toBe(PuBaselineEvidenceValueOrigin::Manual)
        ->and($evidence->extraction['status'])->toBe('failed')
        ->and(Activity::query()->where('event', 'ai_extraction_failed')->count())->toBe(1);
});

it('never sends a document blocked by the malware scan to the extractor', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    $document->update(['scan_status' => MalwareScanStatus::Infected]);
    $gemini = $this->mock(GeminiService::class);
    $gemini->shouldReceive('model')->andReturn('gemini-test-model');
    $gemini->shouldNotReceive('extractFromDocumentWithPrompt');
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->assertMountedActionModalSee('Análise indisponível')
        ->assertMountedActionModalSee('O documento está bloqueado pela verificação de segurança e não pode ser analisado.');
});

it('keeps the previous suggestion when a reanalysis of the same document fails', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    expectGeminiExtractions([
        settlementExtractionPayload(),
        new RuntimeException('503 high demand'),
    ]);
    $this->actingAs($user);

    analyzedEvidenceModal($emission, $document)
        ->callAction(TestAction::make('reanalyzeWithAi')->schemaComponent('aiExtraction'))
        ->assertNotified('Não foi possível analisar o documento neste momento. Tente novamente.')
        ->assertActionDataSet([
            'evidenced_value' => '2026-08-15',
            'reference' => 'Página 17 · Cláusula 4.2',
        ]);
});

it('rejects a malformed date typed by hand with a validation message instead of an error page', function () {
    [$user, $emission, $document] = aiEvidenceScenario();
    $this->mock(GeminiService::class)->shouldNotReceive('extractFromDocumentWithPrompt');
    $this->actingAs($user);

    expect(fn () => app(PuBaselineEvidenceReviewService::class)->create($emission, [
        'document_id' => $document->id,
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate->value,
        'document_type' => 'b3_settlement_statement',
        'evidenced_value' => '15/08/2026',
        'confidence' => 'high',
    ], $user))->toThrow(ValidationException::class, 'Informe a data comprovada no formato AAAA-MM-DD.');
});
