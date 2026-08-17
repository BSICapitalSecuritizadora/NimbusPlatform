<?php

use App\Models\Document;
use App\Services\GeminiService;
use Carbon\CarbonInterval;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    config(['services.gemini.key' => 'test-key']);

    // O serviço espera entre as tentativas; sem o fake a suíte pagaria o backoff em tempo real.
    Sleep::fake();
});

/**
 * Documento cujo conteúdo no disco é controlado pelo teste.
 */
function fakeDocument(string $contents = '%PDF-1.4 fake content', int $id = 7): Document
{
    Storage::fake('local');
    Storage::disk('local')->put('contracts/test.pdf', $contents);

    $document = mock(Document::class);
    $document->shouldReceive('getAttribute')->with('resolved_storage_disk')->andReturn('local');
    $document->shouldReceive('getAttribute')->with('file_path')->andReturn('contracts/test.pdf');
    $document->shouldReceive('getAttribute')->with('id')->andReturn($id);

    return $document;
}

/**
 * Corpo de `generateContent` com o JSON já no formato que o serviço espera.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function geminiJsonBody(array $payload): array
{
    return [
        'candidates' => [[
            'content' => ['parts' => [['text' => json_encode($payload)]]],
        ]],
    ];
}

/**
 * Resposta de `generateContent` com o JSON já no formato que o serviço espera.
 *
 * @param  array<string, mixed>  $payload
 */
function geminiJsonResponse(array $payload): PromiseInterface
{
    return Http::response(geminiJsonBody($payload));
}

/**
 * Corpo do 503 que o Gemini devolve quando o modelo está sobrecarregado.
 *
 * @return array<string, mixed>
 */
function geminiOverloadedBody(): array
{
    return ['error' => [
        'code' => 503,
        'message' => 'This model is currently experiencing high demand. Please try again later.',
        'status' => 'UNAVAILABLE',
    ]];
}

it('sends the pdf as inline base64 to the generation endpoint', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => geminiJsonResponse([
            'objeto_social' => ['clausula' => 'Cláusula 1ª', 'texto' => 'Objeto social texto'],
            'covenants' => ['clausula' => 'Cláusula 9ª', 'texto' => 'Texto dos covenants'],
        ]),
    ]);

    $result = app(GeminiService::class)->extractSecuritizationClauses(fakeDocument());

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), ':generateContent')) {
            return false;
        }

        $prompt = $request->data()['contents'][0]['parts'][0]['text'] ?? null;
        $part = $request->data()['contents'][0]['parts'][1] ?? null;

        return isset($part['inline_data']['data'])
            && $part['inline_data']['mime_type'] === 'application/pdf'
            && str_contains($prompt ?? '', '"Covenants"')
            && str_contains($prompt ?? '', '"Obrigações"');
    });

    expect($result['corporate_purpose'])->toBe("Cláusula 1ª\n\nObjeto social texto")
        ->and($result['covenants'])->toBe("Cláusula 9ª\n\nTexto dos covenants");
});

/**
 * A chave ia na query string (`?key=...`), padrão da API do Gemini, mas que a
 * deixa registrada em log de proxy, telemetria de saída e no histórico do
 * próprio cliente HTTP.
 */
it('sends the api key in a header and never in the request url', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => geminiJsonResponse([]),
    ]);

    app(GeminiService::class)->extractSecuritizationClauses(fakeDocument());

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'test-key')
        && ! str_contains($request->url(), 'key=')
        && ! str_contains($request->url(), 'test-key'));
});

/**
 * O modelo é configurável para permitir rollback sem deploy quando uma troca
 * degrada a extração.
 */
it('targets the model configured for the service', function (): void {
    config(['services.gemini.model' => 'gemini-3.7-flash']);

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => geminiJsonResponse([]),
    ]);

    app(GeminiService::class)->extractSecuritizationClauses(fakeDocument());

    Http::assertSent(fn ($request): bool => $request->url()
        === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.7-flash:generateContent');
});

it('returns null for clauses not found in the document', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => geminiJsonResponse([
            'objeto_social' => ['clausula' => null, 'texto' => 'Não encontrado'],
        ]),
    ]);

    $result = app(GeminiService::class)->extractSecuritizationClauses(fakeDocument());

    expect($result['corporate_purpose'])->toBeNull();
});

/**
 * Os modelos da linha 3 raciocinam por padrão. Ler `parts.0` cegamente pegaria
 * a parte de raciocínio, o json_decode falharia e todo campo viraria null sem
 * erro nenhum.
 */
it('skips reasoning parts when reading the answer', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'Vou procurar a cláusula de objeto social...', 'thought' => true],
                    ['text' => json_encode([
                        'objeto_social' => ['clausula' => 'Cláusula 1ª', 'texto' => 'Objeto social texto'],
                    ])],
                ]],
            ]],
        ]),
    ]);

    $result = app(GeminiService::class)->extractSecuritizationClauses(fakeDocument());

    expect($result['corporate_purpose'])->toBe("Cláusula 1ª\n\nObjeto social texto");
});

it('throws when the generation request fails', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['error' => 'fail'], 500),
    ]);

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses(fakeDocument()))
        ->toThrow(Exception::class);
});

/**
 * O 503 de sobrecarga é a falha mais comum da geração e é transitória: sem
 * repetir, um pico momentâneo do lado do Google derrubava a execução inteira e
 * o operador só via "Não foi possível concluir a geração das obrigações".
 */
it('retries the generation when the model reports transient overload', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::sequence()
            ->push(geminiOverloadedBody(), 503)
            ->push(geminiJsonBody([
                'objeto_social' => ['clausula' => 'Cláusula 1ª', 'texto' => 'Objeto social texto'],
            ])),
    ]);

    $result = app(GeminiService::class)->extractSecuritizationClauses(fakeDocument());

    expect($result['corporate_purpose'])->toBe("Cláusula 1ª\n\nObjeto social texto");

    Http::assertSentCount(2);
});

it('retries a rate limited response as well', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::sequence()
            ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)
            ->push(geminiJsonBody([
                'objeto_social' => ['clausula' => 'Cláusula 1ª', 'texto' => 'Objeto social texto'],
            ])),
    ]);

    $result = app(GeminiService::class)->extractSecuritizationClauses(fakeDocument());

    expect($result['corporate_purpose'])->toBe("Cláusula 1ª\n\nObjeto social texto");

    Http::assertSentCount(2);
});

it('stops after the configured number of attempts and surfaces the failure', function (): void {
    config(['services.gemini.max_attempts' => 3]);

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(geminiOverloadedBody(), 503),
    ]);

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses(fakeDocument()))
        ->toThrow(RequestException::class);

    Http::assertSentCount(3);
});

/**
 * Um pedido malformado volta o mesmo erro em toda tentativa. Repetir só atrasa
 * o diagnóstico e multiplica o envio do documento ao processador.
 */
it('does not retry a response the api rejected as invalid', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(
            ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT']],
            400,
        ),
    ]);

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses(fakeDocument()))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});

/**
 * A espera cresce a cada tentativa para não insistir contra um modelo que ainda
 * está congestionado. O jitter mantém cada pausa dentro de uma faixa, e não num
 * valor exato, para que gerações disparadas juntas não voltem sincronizadas.
 */
it('waits longer before each retry', function (): void {
    config([
        'services.gemini.max_attempts' => 3,
        'services.gemini.retry_base_seconds' => 2,
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(geminiOverloadedBody(), 503),
    ]);

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses(fakeDocument()))
        ->toThrow(RequestException::class);

    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn (CarbonInterval $waited): bool => $waited->totalMilliseconds >= 2000
        && $waited->totalMilliseconds <= 3000);
    Sleep::assertSlept(fn (CarbonInterval $waited): bool => $waited->totalMilliseconds >= 4000
        && $waited->totalMilliseconds <= 5000);
});

it('throws when the document file does not exist', function (): void {
    Storage::fake('local');

    $document = mock(Document::class);
    $document->shouldReceive('getAttribute')->with('resolved_storage_disk')->andReturn('local');
    $document->shouldReceive('getAttribute')->with('file_path')->andReturn('missing.pdf');

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses($document))
        ->toThrow(RuntimeException::class);
});

/**
 * Acima do limite de inline a requisição estouraria o teto de tamanho da API e
 * voltaria 400 — o documento precisa subir antes pela File API.
 */
it('uploads oversized documents through the file api instead of inlining them', function (): void {
    config(['services.gemini.inline_max_bytes' => 10]);

    Http::fake([
        'https://generativelanguage.googleapis.com/upload/v1beta/files*' => Http::sequence()
            ->push('', 200, ['x-goog-upload-url' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=fake'])
            ->push(['file' => [
                'name' => 'files/abc123',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
                'state' => 'ACTIVE',
            ]]),
        'https://generativelanguage.googleapis.com/v1beta/files/*' => Http::response('', 200),
        'https://generativelanguage.googleapis.com/v1beta/models/*' => geminiJsonResponse([
            'objeto_social' => ['clausula' => 'Cláusula 1ª', 'texto' => 'Objeto social texto'],
        ]),
    ]);

    $contents = str_repeat('A', 64);
    $result = app(GeminiService::class)->extractSecuritizationClauses(fakeDocument($contents));

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Goog-Upload-Command', 'start')
        && $request->data()['file']['display_name'] === 'document-7');

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Goog-Upload-Command', 'upload, finalize')
        && $request->body() === $contents);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), ':generateContent')) {
            return false;
        }

        $part = $request->data()['contents'][0]['parts'][1] ?? null;

        return ($part['file_data']['file_uri'] ?? null) === 'https://generativelanguage.googleapis.com/v1beta/files/abc123'
            && ! isset($part['inline_data']);
    });

    expect($result['corporate_purpose'])->toBe("Cláusula 1ª\n\nObjeto social texto");
});

/**
 * A File API retém o arquivo por 48h. Como se trata de documento de operação
 * num processador fora do país, a cópia não pode sobreviver à chamada.
 */
it('deletes the uploaded file after the extraction succeeds', function (): void {
    config(['services.gemini.inline_max_bytes' => 10]);

    Http::fake([
        'https://generativelanguage.googleapis.com/upload/v1beta/files*' => Http::sequence()
            ->push('', 200, ['x-goog-upload-url' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=fake'])
            ->push(['file' => [
                'name' => 'files/abc123',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
                'state' => 'ACTIVE',
            ]]),
        'https://generativelanguage.googleapis.com/v1beta/files/*' => Http::response('', 200),
        'https://generativelanguage.googleapis.com/v1beta/models/*' => geminiJsonResponse([]),
    ]);

    app(GeminiService::class)->extractSecuritizationClauses(fakeDocument(str_repeat('A', 64)));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/files/abc123');
});

it('deletes the uploaded file even when the generation fails', function (): void {
    config(['services.gemini.inline_max_bytes' => 10]);

    Http::fake([
        'https://generativelanguage.googleapis.com/upload/v1beta/files*' => Http::sequence()
            ->push('', 200, ['x-goog-upload-url' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=fake'])
            ->push(['file' => [
                'name' => 'files/abc123',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
                'state' => 'ACTIVE',
            ]]),
        'https://generativelanguage.googleapis.com/v1beta/files/*' => Http::response('', 200),
        'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['error' => 'fail'], 500),
    ]);

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses(fakeDocument(str_repeat('A', 64))))
        ->toThrow(Exception::class);

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/files/abc123');
});

/**
 * Um PDF grande não fica utilizável logo após o upload; referenciá-lo em
 * PROCESSING faz o generateContent voltar 400.
 */
it('waits for a processing upload to become active before generating', function (): void {
    config(['services.gemini.inline_max_bytes' => 10]);

    Http::fake([
        'https://generativelanguage.googleapis.com/upload/v1beta/files*' => Http::sequence()
            ->push('', 200, ['x-goog-upload-url' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=fake'])
            ->push(['file' => [
                'name' => 'files/abc123',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
                'state' => 'PROCESSING',
            ]]),
        'https://generativelanguage.googleapis.com/v1beta/files/*' => Http::sequence()
            ->push([
                'name' => 'files/abc123',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
                'state' => 'PROCESSING',
            ])
            ->push([
                'name' => 'files/abc123',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
                'state' => 'ACTIVE',
            ])
            ->push('', 200),
        'https://generativelanguage.googleapis.com/v1beta/models/*' => geminiJsonResponse([]),
    ]);

    app(GeminiService::class)->extractSecuritizationClauses(fakeDocument(str_repeat('A', 64)));

    Sleep::assertSlept(fn (): bool => true, times: 2);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), ':generateContent'));
});

it('throws when the upload never leaves the processing state', function (): void {
    config([
        'services.gemini.inline_max_bytes' => 10,
        'services.gemini.file_activation_timeout' => 0,
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/upload/v1beta/files*' => Http::sequence()
            ->push('', 200, ['x-goog-upload-url' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=fake'])
            ->push(['file' => [
                'name' => 'files/abc123',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
                'state' => 'PROCESSING',
            ]]),
        'https://generativelanguage.googleapis.com/v1beta/files/*' => Http::response('', 200),
    ]);

    expect(fn () => app(GeminiService::class)->extractSecuritizationClauses(fakeDocument(str_repeat('A', 64))))
        ->toThrow(RuntimeException::class);

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), ':generateContent'));
});
