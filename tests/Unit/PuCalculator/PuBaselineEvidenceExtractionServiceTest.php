<?php

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Services\PuBaselineEvidenceExtractionService;
use App\Services\GeminiService;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Regras determinísticas da extração assistida: nenhum caso toca o banco nem o
 * processador externo. O que se prova é que valor, status e confiança saem das
 * regras da aplicação, e não do que o modelo declara sobre si mesmo.
 */

function evidenceExtractionService(): PuBaselineEvidenceExtractionService
{
    return new PuBaselineEvidenceExtractionService(Mockery::mock(GeminiService::class));
}

/** @param array<string, mixed> $overrides */
function explicitDatePayload(array $overrides = []): array
{
    return array_replace([
        'found' => true,
        'value' => '2026-08-15',
        'value_as_written' => '15 de agosto de 2026',
        'page' => 17,
        'reference' => 'Cláusula 4.2',
        'excerpt' => 'A primeira integralização ocorreu em 15 de agosto de 2026.',
        'evidence_level' => 'explicit',
        'alternatives' => [],
        'emission_mentioned' => true,
        'document_type' => 'b3_settlement_statement',
        'observations' => 'A data está expressamente indicada na cláusula 4.2.',
        'confidence' => 0.95,
    ], $overrides);
}

it('accepts an explicit, located and validated value with high confidence', function () {
    $result = evidenceExtractionService()->normalize(PuBaselineEvidenceType::FirstIntegralizationDate, explicitDatePayload());

    expect($result['status'])->toBe('found')
        ->and($result['form'])->toBe([
            'document_type' => 'b3_settlement_statement',
            'evidenced_value' => '2026-08-15',
            'reference' => 'Página 17 · Cláusula 4.2',
            'confidence' => 'high',
            'notes' => 'A data está expressamente indicada na cláusula 4.2.',
        ])
        ->and($result['suggestion']['page'])->toBe(17)
        ->and($result['suggestion']['excerpt'])->toBe('A primeira integralização ocorreu em 15 de agosto de 2026.');
});

it('normalizes textual and brazilian dates to YYYY-MM-DD', function (string $raw, string $expected) {
    $result = evidenceExtractionService()->normalize(
        PuBaselineEvidenceType::FirstIntegralizationDate,
        explicitDatePayload(['value' => $raw]),
    );

    expect($result['status'])->toBe('found')
        ->and($result['form']['evidenced_value'])->toBe($expected);
})->with([
    'extenso' => ['15 de agosto de 2026', '2026-08-15'],
    'extenso com ordinal e maiúscula' => ['1º de Março de 2026', '2026-03-01'],
    'dd/mm/aaaa' => ['05/09/2026', '2026-09-05'],
    'ISO' => ['2026-08-15', '2026-08-15'],
]);

it('never fills a value that fails the same validation used on creation', function (PuBaselineEvidenceType $type, string $raw) {
    $result = evidenceExtractionService()->normalize($type, explicitDatePayload(['value' => $raw]));

    expect($result['status'])->toBe('partial')
        ->and($result['form']['evidenced_value'])->toBeNull()
        ->and($result['form']['confidence'])->toBe('low')
        ->and($result['form']['reference'])->toBe('Página 17 · Cláusula 4.2')
        ->and($result['suggestion']['value_valid'])->toBeFalse()
        ->and($result['form']['notes'])->toContain("O valor lido pela IA (\"{$raw}\")");
})->with([
    'data inexistente' => [PuBaselineEvidenceType::FirstIntegralizationDate, '31/02/2026'],
    'ano de dois dígitos' => [PuBaselineEvidenceType::FirstIntegralizationDate, '15/08/26'],
    'quantidade zero' => [PuBaselineEvidenceType::IntegralizedQuantity, '0'],
    'quantidade com texto' => [PuBaselineEvidenceType::IntegralizedQuantity, '1.500 CRIs'],
    'gabarito fora do enum' => [PuBaselineEvidenceType::ExternalPuReference, 'aprovado'],
]);

it('reads brazilian thousand separators as a count, never as a fraction', function (string $raw, string $expected) {
    $result = evidenceExtractionService()->normalize(
        PuBaselineEvidenceType::IntegralizedQuantity,
        explicitDatePayload(['value' => $raw, 'document_type' => 'registrar_position']),
    );

    expect($result['form']['evidenced_value'])->toBe($expected);
})->with([
    ['1.500', '1500'],
    ['1.500,00', '1500'],
    ['35000', '35000'],
]);

it('maps portuguese synonyms of the external reference status onto the accepted values', function () {
    $result = evidenceExtractionService()->normalize(
        PuBaselineEvidenceType::ExternalPuReference,
        explicitDatePayload(['value' => 'Aguardando comparação', 'document_type' => 'official_pu_memory']),
    );

    expect($result['form']['evidenced_value'])->toBe('available_pending_comparison')
        ->and($result['form']['document_type'])->toBe('official_pu_memory');
});

it('handles found=false without proposing anything', function () {
    $result = evidenceExtractionService()->normalize(PuBaselineEvidenceType::FirstIntegralizationDate, [
        'found' => false,
        'value' => null,
        'page' => null,
        'reference' => null,
        'excerpt' => null,
        'evidence_level' => 'not_found',
        'observations' => 'Não foi possível localizar com segurança a informação solicitada.',
        'confidence' => 0.1,
    ]);

    expect($result['status'])->toBe('not_found')
        ->and($result['message'])->toBe('Não foi possível localizar automaticamente a informação solicitada neste documento.')
        ->and(array_filter($result['form']))->toBe([]);
});

it('classifies confidence from explicit criteria, capped by the model score', function (array $overrides, string $expected) {
    $result = evidenceExtractionService()->normalize(
        PuBaselineEvidenceType::FirstIntegralizationDate,
        explicitDatePayload($overrides),
    );

    expect($result['form']['confidence'])->toBe($expected);
})->with([
    'tudo explícito' => [[], 'high'],
    'valor interpretado' => [['evidence_level' => 'inferred'], 'medium'],
    'sem página' => [['page' => null], 'medium'],
    'emissão não identificada' => [['emission_mentioned' => false], 'medium'],
    'modelo pouco seguro' => [['confidence' => 0.7], 'medium'],
    'modelo inseguro' => [['confidence' => 0.3], 'low'],
    'sem trecho' => [['excerpt' => null], 'low'],
    'sem localização alguma' => [['page' => null, 'reference' => null], 'low'],
    'modelo sem nota' => [['confidence' => 'alta'], 'low'],
]);

it('does not expose a value when the document has competing occurrences', function () {
    $result = evidenceExtractionService()->normalize(
        PuBaselineEvidenceType::FirstIntegralizationDate,
        explicitDatePayload(['alternatives' => [
            ['value' => '15/08/2026', 'page' => 3, 'excerpt' => 'Repetição da mesma data.'],
            ['value' => '2026-08-20', 'page' => 21, 'excerpt' => 'Liquidação do saldo em 20 de agosto de 2026.'],
        ]]),
    );

    expect($result['status'])->toBe('partial')
        ->and($result['form']['evidenced_value'])->toBeNull()
        ->and($result['form']['confidence'])->toBe('low')
        ->and($result['suggestion']['alternatives'])->toHaveCount(1)
        ->and($result['form']['notes'])->toContain('2026-08-20 (página 21)');
});

it('treats conflicting occurrences as a partial result even when the model answers found=false', function () {
    // Forma real devolvida pelo Gemini na validação manual: sem valor escolhido,
    // com as duas datas concorrentes localizadas.
    $result = evidenceExtractionService()->normalize(PuBaselineEvidenceType::FirstIntegralizationDate, [
        'found' => false,
        'value' => null,
        'page' => null,
        'reference' => null,
        'excerpt' => null,
        'evidence_level' => 'conflicting',
        'alternatives' => [
            ['value' => '2026-08-15', 'page' => 2, 'excerpt' => 'A primeira integralização dos CRI ocorreu em 15 de agosto de 2026.'],
            ['value' => '2026-08-20', 'page' => 3, 'excerpt' => 'Retificação: a primeira integralização dos CRI ocorreu em 20 de agosto de 2026.'],
        ],
        'emission_mentioned' => true,
        'document_type' => 'official_settlement_proof',
        'observations' => 'O documento retifica a data sem indicar qual prevalece.',
        'confidence' => 0.98,
    ]);

    expect($result['status'])->toBe('partial')
        ->and($result['message'])->toStartWith('O documento traz valores diferentes para esta informação.')
        ->and($result['form']['evidenced_value'])->toBeNull()
        ->and($result['form']['confidence'])->toBe('low')
        ->and($result['suggestion']['alternatives'])->toHaveCount(2)
        ->and($result['form']['notes'])->toContain('2026-08-15 (página 2); 2026-08-20 (página 3)');
});

it('only suggests a document type the requirement accepts', function () {
    $service = evidenceExtractionService();

    $puMemoryForDate = $service->normalize(
        PuBaselineEvidenceType::FirstIntegralizationDate,
        explicitDatePayload(['document_type' => 'official_pu_memory']),
    );
    $unknownType = $service->normalize(
        PuBaselineEvidenceType::FirstIntegralizationDate,
        explicitDatePayload(['document_type' => 'aditamento']),
    );

    expect($puMemoryForDate['form']['document_type'])->toBeNull()
        ->and($unknownType['form']['document_type'])->toBeNull();
});

it('keeps fields the user edited and replaces only what the previous analysis filled', function () {
    $service = evidenceExtractionService();
    $previous = [
        'document_type' => 'b3_settlement_statement',
        'evidenced_value' => '2026-08-15',
        'reference' => 'Página 17',
        'confidence' => 'high',
        'notes' => 'Primeira análise.',
    ];
    $next = [
        'document_type' => 'registrar_position',
        'evidenced_value' => '2026-08-16',
        'reference' => 'Página 2',
        'confidence' => 'medium',
        'notes' => 'Segunda análise.',
    ];
    $current = [...$previous, 'evidenced_value' => '2026-08-14', 'notes' => 'Conferi no extrato.'];

    $automatic = $service->formFill($current, $previous, $next, replaceEdited: false);
    $confirmed = $service->formFill($current, $previous, $next, replaceEdited: true);

    expect($automatic['values'])->toBe([
        'document_type' => 'registrar_position',
        'reference' => 'Página 2',
        'confidence' => 'medium',
    ])
        ->and($automatic['preserved'])->toBe(['evidenced_value', 'notes'])
        ->and($confirmed['values'])->toBe($next)
        ->and($confirmed['preserved'])->toBe([]);
});

it('clears stale suggestions but never erases typed data when the new analysis proposes nothing', function () {
    $service = evidenceExtractionService();
    $previous = [
        'document_type' => null,
        'evidenced_value' => '2026-08-15',
        'reference' => 'Página 17',
        'confidence' => 'medium',
        'notes' => null,
    ];
    $nothing = array_fill_keys(PuBaselineEvidenceExtractionService::FORM_FIELDS, null);
    $current = [...$previous, 'reference' => 'Página 18 (conferida)', 'notes' => 'Digitado pelo usuário.'];

    $fill = $service->formFill($current, $previous, $nothing, replaceEdited: true);

    expect($fill['values'])->toBe([
        'evidenced_value' => null,
        'confidence' => null,
    ]);
});

it('treats the untouched confidence default as not edited before any analysis', function () {
    $service = evidenceExtractionService();

    expect($service->editedFields(['confidence' => 'high'], null))->toBe([])
        ->and($service->editedFields(['confidence' => 'low', 'evidenced_value' => '10'], null))
        ->toBe(['evidenced_value', 'confidence']);
});
