<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationSuggestionsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Jobs\GenerateEmissionObligationsJob;
use App\Models\Document;
use App\Models\Emission;
use App\Models\ExtractedObligation;
use App\Models\Obligation;
use App\Models\User;
use App\Services\GeminiService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function fakeGeminiObligations(array $obligations): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => json_encode(['obligations' => $obligations])],
                    ],
                ],
            ]],
        ]),
    ]);
}

function makeTermDocument(Emission $emission): Document
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

it('registers the obligations permissions in the access enum', function () {
    expect(AccessPermission::values())->toContain(
        'obligations.view',
        'obligations.create',
        'obligations.update',
        'obligations.delete',
        'obligations.generate',
        'obligations.view_dashboard',
        'obligations.submit_for_review',
        'obligations.complete',
        'obligations.mark_not_applicable',
        'obligations.reopen',
        'obligations.upload_evidence',
        'obligations.view_evidence',
        'obligations.download_evidence',
        'obligations.delete_evidence',
        'obligations.view_history',
        'obligations.send_notifications',
        'obligations.export',
    );
});

it('exposes obligation relations on the emission model', function () {
    $emission = Emission::factory()->create();
    Obligation::factory()->for($emission)->count(2)->create();
    ExtractedObligation::factory()->for($emission)->create();

    expect($emission->obligations()->count())->toBe(2)
        ->and($emission->extractedObligations()->count())->toBe(1);
});

it('parses and normalizes obligations returned by the GeminiService', function () {
    $emission = Emission::factory()->create();
    $document = makeTermDocument($emission);

    fakeGeminiObligations([
        [
            'title' => 'Enviar relatório mensal ao Agente Fiduciário',
            'obligation_type' => 'Relatório Periódico',
            'obligation_category' => 'Informacional',
            'description' => 'A Emissora deverá enviar relatório mensal.',
            'responsible_party' => 'Emissora',
            'responsible_area' => 'Gestão',
            'recurrence' => 'Mensal',
            'due_rule' => 'até o 10º dia útil de cada mês',
            'due_date' => 'data inválida',
            'priority' => 'urgentíssima',
            'source_excerpt' => 'a Emissora elaborará relatório mensal de acompanhamento',
            'source_page' => '12',
            'confidence_score' => 0.91,
        ],
        ['description' => 'Sem título — deve ser descartada'],
    ]);

    $proposals = app(GeminiService::class)->extractObligations($document);

    expect($proposals)->toHaveCount(1);
    expect($proposals[0]['title'])->toBe('Enviar relatório mensal ao Agente Fiduciário')
        ->and($proposals[0]['priority'])->toBe('medium')
        ->and($proposals[0]['due_date'])->toBeNull()
        ->and($proposals[0]['source_page'])->toBe(12)
        ->and($proposals[0]['confidence_score'])->toBe(0.91);
});

it('stores suggestions and replaces previous pending ones when the job runs', function () {
    $emission = Emission::factory()->create();
    $document = makeTermDocument($emission);

    $stalePending = ExtractedObligation::factory()->for($emission)->create(['status' => 'suggested']);
    $approved = ExtractedObligation::factory()->for($emission)->approved()->create();

    fakeGeminiObligations([
        [
            'title' => 'Nova obrigação sugerida',
            'priority' => 'high',
            'source_excerpt' => 'trecho literal',
            'confidence_score' => 0.85,
        ],
    ]);

    (new GenerateEmissionObligationsJob($emission->id, $document->id))
        ->handle(app(GeminiService::class));

    expect(ExtractedObligation::find($stalePending->id))->toBeNull()
        ->and(ExtractedObligation::find($approved->id))->not->toBeNull();

    $created = $emission->extractedObligations()->where('status', 'suggested')->get();

    expect($created)->toHaveCount(1);
    expect($created->first()->title)->toBe('Nova obrigação sugerida')
        ->and($created->first()->document_id)->toBe($document->id)
        ->and($created->first()->priority)->toBe('high');
});

it('discards low-confidence obligations during extraction', function () {
    $emission = Emission::factory()->create();
    $document = makeTermDocument($emission);

    fakeGeminiObligations([
        ['title' => 'Obrigação confiável', 'source_excerpt' => 'x', 'confidence_score' => 0.92],
        ['title' => 'Obrigação fraca', 'source_excerpt' => 'x', 'confidence_score' => 0.40],
        ['title' => 'Obrigação sem score', 'source_excerpt' => 'x'],
    ]);

    $proposals = app(GeminiService::class)->extractObligations($document);

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['title'])->toBe('Obrigação confiável');
});

it('deduplicates near-identical obligations keeping the most complete', function () {
    $emission = Emission::factory()->create();
    $document = makeTermDocument($emission);

    fakeGeminiObligations([
        [
            'title' => 'Enviar relatório mensal ao Agente Fiduciário',
            'source_clause' => 'Cláusula 8.1',
            'responsible_party' => 'Emissora',
            'recurrence' => 'Mensal',
            'description' => 'curto',
            'source_excerpt' => 'x',
            'confidence_score' => 0.80,
        ],
        [
            'title' => 'Enviar relatório mensal ao Agente Fiduciário.',
            'source_clause' => 'Cláusula 8.1',
            'responsible_party' => 'Emissora',
            'recurrence' => 'Mensal',
            'description' => 'A Emissora deverá enviar ao Agente Fiduciário relatório mensal detalhado de acompanhamento da carteira.',
            'due_rule' => 'até o 10º dia útil',
            'source_excerpt' => 'x',
            'confidence_score' => 0.95,
        ],
        [
            'title' => 'Constituir fundo de reserva',
            'responsible_party' => 'Emissora',
            'recurrence' => 'Única',
            'source_excerpt' => 'x',
            'confidence_score' => 0.90,
        ],
    ]);

    $proposals = app(GeminiService::class)->extractObligations($document);

    expect($proposals)->toHaveCount(2);

    $relatorio = collect($proposals)->firstWhere('due_rule', 'até o 10º dia útil');

    expect($relatorio)->not->toBeNull()
        ->and($relatorio['confidence_score'])->toBe(0.95)
        ->and(collect($proposals)->pluck('title'))->toContain('Constituir fundo de reserva');
});

it('persists obligations with long clause text without truncation errors', function () {
    $emission = Emission::factory()->create();
    $document = makeTermDocument($emission);

    $longDueRule = str_repeat('até o 5º Dia Útil após a Data de Integralização; ', 12);
    $longSourceClause = str_repeat('Cláusula 16.8 (v) do Termo de Securitização; ', 12);

    expect(mb_strlen($longDueRule))->toBeGreaterThan(255)
        ->and(mb_strlen($longSourceClause))->toBeGreaterThan(255);

    fakeGeminiObligations([
        [
            'title' => 'Pagar remuneração anual do Agente Fiduciário',
            'due_rule' => $longDueRule,
            'source_clause' => $longSourceClause,
            'source_excerpt' => 'trecho literal',
            'confidence_score' => 0.90,
        ],
    ]);

    (new GenerateEmissionObligationsJob($emission->id, $document->id))
        ->handle(app(GeminiService::class));

    $created = $emission->extractedObligations()->where('status', 'suggested')->first();

    expect($created)->not->toBeNull()
        ->and($created->due_rule)->toBe(trim($longDueRule))
        ->and($created->source_clause)->toBe(trim($longSourceClause))
        ->and(mb_strlen($created->due_rule))->toBeGreaterThan(255);
});

it('consolidates an approved suggestion into an obligation', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();
    $suggestion = ExtractedObligation::factory()->for($emission)->create([
        'status' => 'suggested',
        'title' => 'Comprovar destinação de recursos',
    ]);

    Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->callTableAction('approve', $suggestion);

    $suggestion->refresh();

    expect($suggestion->status)->toBe('approved')
        ->and($suggestion->reviewed_at)->not->toBeNull();

    $obligation = $emission->obligations()->first();

    expect($obligation)->not->toBeNull()
        ->and($obligation->title)->toBe('Comprovar destinação de recursos')
        ->and($obligation->extracted_obligation_id)->toBe($suggestion->id)
        ->and($obligation->status)->toBe('em_dia');
});

it('shows the generate obligations action on the suggestions tab', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsGenerate->value,
    ]);
    $this->actingAs($user);

    $emission = Emission::factory()->create();

    Livewire::test(ObligationSuggestionsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertTableActionExists('generate_obligations')
        ->assertTableActionHasLabel('generate_obligations', 'Gerar obrigações do Termo');
});
