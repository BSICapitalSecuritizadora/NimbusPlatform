<?php

use App\Models\Document;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    config(['services.gemini.key' => 'test-key']);
});

it('sends the pdf as inline base64 to the generation endpoint', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('contracts/test.pdf', '%PDF-1.4 fake content');

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'objeto_social' => ['clausula' => 'Cláusula 1ª', 'texto' => 'Objeto social texto'],
                    'covenants' => ['clausula' => 'Cláusula 9ª', 'texto' => 'Texto dos covenants'],
                ])]]],
            ]],
        ]),
    ]);

    $document = mock(Document::class);
    $document->shouldReceive('getAttribute')->with('resolved_storage_disk')->andReturn('local');
    $document->shouldReceive('getAttribute')->with('file_path')->andReturn('contracts/test.pdf');

    $result = app(GeminiService::class)->extractSecuritizationClauses($document);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), ':generateContent')) {
            return false;
        }

        $part = $request->data()['contents'][0]['parts'][1] ?? null;

        return isset($part['inline_data']['data'])
            && $part['inline_data']['mime_type'] === 'application/pdf';
    });

    expect($result['corporate_purpose'])->toBe("Cláusula 1ª\n\nObjeto social texto")
        ->and($result['covenants'])->toBe("Cláusula 9ª\n\nTexto dos covenants");
});

it('returns null for clauses not found in the document', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('contracts/test.pdf', '%PDF-1.4 fake content');

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'objeto_social' => ['clausula' => null, 'texto' => 'Não encontrado'],
                ])]]],
            ]],
        ]),
    ]);

    $document = mock(Document::class);
    $document->shouldReceive('getAttribute')->with('resolved_storage_disk')->andReturn('local');
    $document->shouldReceive('getAttribute')->with('file_path')->andReturn('contracts/test.pdf');

    $result = app(GeminiService::class)->extractSecuritizationClauses($document);

    expect($result['corporate_purpose'])->toBeNull();
});

it('throws when the generation request fails', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('contracts/test.pdf', '%PDF-1.4 fake content');

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['error' => 'fail'], 500),
    ]);

    $document = mock(Document::class);
    $document->shouldReceive('getAttribute')->with('resolved_storage_disk')->andReturn('local');
    $document->shouldReceive('getAttribute')->with('file_path')->andReturn('contracts/test.pdf');

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses($document))
        ->toThrow(\Exception::class);
});

it('throws when the document file does not exist', function (): void {
    Storage::fake('local');

    $document = mock(Document::class);
    $document->shouldReceive('getAttribute')->with('resolved_storage_disk')->andReturn('local');
    $document->shouldReceive('getAttribute')->with('file_path')->andReturn('missing.pdf');

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses($document))
        ->toThrow(\RuntimeException::class);
});
