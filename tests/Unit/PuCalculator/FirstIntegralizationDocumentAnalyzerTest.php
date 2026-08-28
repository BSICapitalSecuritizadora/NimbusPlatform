<?php

use App\Domain\PuCalculator\Services\FirstIntegralizationDocumentAnalyzer;
use App\Models\Document;
use App\Models\Emission;
use App\Services\GeminiService;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Validação determinística de `normalize()`: nenhum destes casos toca o banco
 * nem o extrator externo. O que se prova aqui é que o veredito documental sai
 * das regras da aplicação, e não da confiança declarada pelo modelo.
 */

function integralizationAnalyzer(): FirstIntegralizationDocumentAnalyzer
{
    return new FirstIntegralizationDocumentAnalyzer(Mockery::mock(GeminiService::class));
}

function altoBellevueEmissionStub(): Emission
{
    return new Emission([
        'name' => 'CRI Alto Bellevue',
        'if_code' => '26E0017614',
        'isin_code' => 'BRALBLCRI008',
        'bsi_code' => 'ALTOBELLEVUE01',
    ]);
}

function settlementProofPayload(array $overrides = []): array
{
    return array_replace([
        'document_type' => 'b3_settlement_statement',
        'document_date' => '2026-05-15',
        'issuer' => [
            'name' => 'B3 S.A. — Brasil, Bolsa, Balcão',
            'role' => 'b3',
            'excerpt' => 'B3 S.A. certifica a liquidação financeira da série.',
        ],
        'emission_identifiers' => [[
            'type' => 'if_code',
            'value' => '26E0017614',
            'excerpt' => 'Código IF: 26E0017614.',
        ]],
        'dates' => [[
            'date' => '2026-05-15',
            'semantic' => 'settlement_date',
            'source' => 'Corpo do documento, linha do evento de liquidação',
            'excerpt' => 'Primeira liquidação financeira efetivada em 15/05/2026.',
        ]],
        'candidate_integration_date' => '2026-05-15',
        'candidate_event_semantic' => 'settlement_date',
        'date_source' => 'Corpo do documento, linha do evento de liquidação',
        'excerpt' => 'Primeira liquidação financeira efetivada em 15/05/2026.',
        'proves_effective_settlement' => true,
        'ambiguous' => false,
        'ambiguity_reason' => null,
        'ambiguity_resolution' => null,
        'integralized_quantity' => '4000',
        'quantity_excerpt' => 'Quantidade liquidada informada no extrato.',
        'confidence' => 0.97,
    ], $overrides);
}

function analyzeIntegralizationPayload(array $overrides = [], string $title = 'Extrato de liquidação'): array
{
    return integralizationAnalyzer()->normalize(
        altoBellevueEmissionStub(),
        new Document(['title' => $title]),
        settlementProofPayload($overrides),
    );
}

it('classifies a primary settlement proof from an authoritative issuer as eligible', function () {
    $analysis = analyzeIntegralizationPayload();

    expect($analysis['analysis_status'])->toBe('eligible')
        ->and($analysis['strength'])->toBe('strong')
        ->and($analysis['candidate_integration_date'])->toBe('2026-05-15')
        ->and($analysis['candidate_event_semantic'])->toBe('settlement_date')
        ->and($analysis['emission_match'])->toBeTrue()
        ->and($analysis['matched_identifiers'])->toHaveCount(1)
        ->and($analysis['date_source'])->not->toBeNull()
        ->and($analysis['excerpt'])->not->toBeNull()
        ->and($analysis['confidence'])->toBe('high')
        ->and($analysis['proves_effective_settlement'])->toBeTrue();
});

it('accepts integration and remuneration start as material events and rejects merely formal ones', function (string $semantic, string $expected) {
    $analysis = analyzeIntegralizationPayload([
        'dates' => [[
            'date' => '2026-05-15',
            'semantic' => $semantic,
            'source' => 'Corpo do documento',
            'excerpt' => 'Evento datado em 15/05/2026.',
        ]],
        'candidate_event_semantic' => $semantic,
    ]);

    expect($analysis['analysis_status'])->toBe($expected);
})->with([
    ['settlement_date', 'eligible'],
    ['integration_date', 'eligible'],
    ['remuneration_start_date', 'eligible'],
    ['subscription_date', 'document_does_not_prove_integration_date'],
    ['registration_date', 'document_does_not_prove_integration_date'],
    ['issuance_date', 'document_does_not_prove_integration_date'],
    ['custody_date', 'document_does_not_prove_integration_date'],
    ['document_date', 'document_does_not_prove_integration_date'],
    ['offering_date', 'document_does_not_prove_integration_date'],
]);

it('refuses a document whose issuer is not responsible for settlement, bookkeeping or custody', function (array $issuer) {
    $analysis = analyzeIntegralizationPayload(['issuer' => $issuer]);

    expect($analysis['analysis_status'])->toBe('document_issuer_not_authoritative')
        ->and($analysis['strength'])->toBe('weak');
})->with([
    'emissor desconhecido' => [['name' => null, 'role' => 'unknown', 'excerpt' => null]],
    'securitizadora emissora' => [['name' => 'Securitizadora S.A.', 'role' => 'issuer', 'excerpt' => 'Emitido pela securitizadora.']],
    'sem trecho literal' => [['name' => 'B3 S.A.', 'role' => 'b3', 'excerpt' => null]],
    'sem nome identificado' => [['name' => null, 'role' => 'b3', 'excerpt' => 'Documento emitido pela bolsa.']],
]);

it('refuses a strong document that carries no verifiable identifier of this emission', function (array $identifiers) {
    $analysis = analyzeIntegralizationPayload(['emission_identifiers' => $identifiers]);

    expect($analysis['analysis_status'])->toBe('integration_document_does_not_match_emission')
        ->and($analysis['emission_match'])->toBeFalse()
        ->and($analysis['matched_identifiers'])->toBe([]);
})->with([
    'nenhum identificador' => [[]],
    'ISIN de outra emissão' => [[['type' => 'isin_code', 'value' => 'BROTHERCRI999', 'excerpt' => 'ISIN BROTHERCRI999.']]],
    'IF de outra emissão' => [[['type' => 'if_code', 'value' => '26E0000000', 'excerpt' => 'Código IF: 26E0000000.']]],
]);

it('matches the emission by ISIN, BSI code or name regardless of accent and case', function (array $identifier) {
    $analysis = analyzeIntegralizationPayload(['emission_identifiers' => [$identifier]]);

    expect($analysis['emission_match'])->toBeTrue()
        ->and($analysis['analysis_status'])->toBe('eligible');
})->with([
    'ISIN' => [['type' => 'isin_code', 'value' => 'BRALBLCRI008', 'excerpt' => 'ISIN BRALBLCRI008.']],
    'código BSI' => [['type' => 'bsi_code', 'value' => 'altobellevue01', 'excerpt' => 'Código interno ALTOBELLEVUE01.']],
    'nome da emissão' => [['type' => 'emission_name', 'value' => 'CRI ALTO BELLEVUE', 'excerpt' => 'Emissão: CRI Alto Bellevue.']],
]);

it('refuses a document that does not prove effective settlement even with a date in the body', function () {
    $analysis = analyzeIntegralizationPayload(['proves_effective_settlement' => false]);

    expect($analysis['analysis_status'])->toBe('document_does_not_prove_integration_date')
        ->and($analysis['strength'])->toBe('weak');
});

it('refuses a proven date that has no literal excerpt or source location', function (array $overrides) {
    $analysis = analyzeIntegralizationPayload($overrides);

    expect($analysis['analysis_status'])->toBe('document_does_not_prove_integration_date');
})->with([
    'sem excerpt' => [['excerpt' => null, 'dates' => [[
        'date' => '2026-05-15',
        'semantic' => 'settlement_date',
        'source' => 'Corpo do documento',
        'excerpt' => '   ',
    ]]]],
    'sem source' => [['date_source' => null, 'dates' => [[
        'date' => '2026-05-15',
        'semantic' => 'settlement_date',
        'source' => '   ',
        'excerpt' => 'Liquidação em 15/05/2026.',
    ]]]],
    'data candidata sem evento correspondente' => [['dates' => []]],
]);

it('holds a low confidence extraction back without letting the score override any structural rule', function () {
    $analysis = analyzeIntegralizationPayload(['confidence' => 0.80]);

    expect($analysis['analysis_status'])->toBe('documentary_confidence_insufficient')
        ->and($analysis['confidence'])->toBe('medium')
        ->and($analysis['confidence_score'])->toBe(0.8);
});

it('never lets a perfect confidence score outrank document type, issuer, emission or settlement', function (array $overrides, string $expected) {
    $analysis = analyzeIntegralizationPayload(['confidence' => 1.0] + $overrides);

    expect($analysis['analysis_status'])->toBe($expected)
        ->and($analysis['confidence'])->toBe('high');
})->with([
    'tipo indicativo' => [['document_type' => 'indicative_schedule'], 'insufficient_documentary_evidence'],
    'tipo desconhecido' => [['document_type' => 'other'], 'insufficient_documentary_evidence'],
    'emissor sem autoridade' => [['issuer' => ['name' => 'Portal', 'role' => 'unknown', 'excerpt' => 'Portal de distribuição.']], 'document_issuer_not_authoritative'],
    'outra emissão' => [['emission_identifiers' => []], 'integration_document_does_not_match_emission'],
    'sem liquidação efetiva' => [['proves_effective_settlement' => false], 'document_does_not_prove_integration_date'],
]);

it('treats a confirmatory secondary source as medium and sends it to manual documentary review', function (string $documentType) {
    $analysis = analyzeIntegralizationPayload(['document_type' => $documentType]);

    expect($analysis['analysis_status'])->toBe('manual_documentary_review_required')
        ->and($analysis['strength'])->toBe('medium');
})->with([
    ['acceptance_document'],
    ['final_distribution_map'],
    ['closing_announcement'],
]);

it('blocks two plausible material dates as ambiguous when the document states no prevailing rule', function () {
    $analysis = analyzeIntegralizationPayload([
        'dates' => [
            [
                'date' => '2026-05-15',
                'semantic' => 'settlement_date',
                'source' => 'Corpo do documento, linha de liquidação',
                'excerpt' => 'Liquidação em 15/05/2026.',
            ],
            [
                'date' => '2026-05-18',
                'semantic' => 'integration_date',
                'source' => 'Corpo do documento, linha de integralização',
                'excerpt' => 'Integralização em 18/05/2026.',
            ],
        ],
    ]);

    expect($analysis['analysis_status'])->toBe('ambiguous_integration_date')
        ->and($analysis['candidate_integration_date'])->toBeNull()
        ->and($analysis['ambiguity_reason'])->not->toBeNull()
        ->and($analysis['dates'])->toHaveCount(2);
});

it('blocks ambiguity declared by the extractor even when a single date was returned', function () {
    $analysis = analyzeIntegralizationPayload([
        'ambiguous' => true,
        'ambiguity_reason' => 'O documento não informa qual evento inicia a remuneração.',
    ]);

    expect($analysis['analysis_status'])->toBe('ambiguous_integration_date')
        ->and($analysis['ambiguity_reason'])->toBe('O documento não informa qual evento inicia a remuneração.');
});

it('accepts multiple material dates when the document itself carries the prevailing rule', function () {
    $analysis = analyzeIntegralizationPayload([
        'dates' => [
            [
                'date' => '2026-05-15',
                'semantic' => 'settlement_date',
                'source' => 'Corpo do documento, linha de liquidação',
                'excerpt' => 'Liquidação em 15/05/2026.',
            ],
            [
                'date' => '2026-05-18',
                'semantic' => 'integration_date',
                'source' => 'Corpo do documento, linha de integralização',
                'excerpt' => 'Integralização em 18/05/2026.',
            ],
        ],
        'ambiguity_resolution' => 'Cláusula 4.2: a remuneração corre a partir da data de liquidação financeira, ainda que o registro da integralização ocorra em data posterior.',
    ]);

    expect($analysis['analysis_status'])->toBe('eligible')
        ->and($analysis['candidate_integration_date'])->toBe('2026-05-15')
        ->and($analysis['ambiguity_reason'])->toBeNull()
        ->and($analysis['ambiguity_resolution'])->toContain('Cláusula 4.2');
});

it('does not accept a resolution invented outside the document to override declared ambiguity', function () {
    $analysis = analyzeIntegralizationPayload([
        'ambiguous' => true,
        'ambiguity_reason' => 'Duas datas materiais sem regra de prevalência.',
        'ambiguity_resolution' => 'Assumindo a primeira data.',
    ]);

    expect($analysis['analysis_status'])->toBe('ambiguous_integration_date');
});

it('keeps the integralized quantity as information and never as a unit price', function () {
    $analysis = analyzeIntegralizationPayload();

    expect($analysis['quantity'])->toBe('4000')
        ->and($analysis['quantity_excerpt'])->not->toBeNull()
        ->and($analysis)->not->toHaveKey('initial_unit_value')
        ->and($analysis)->not->toHaveKey('evidenced_value');
});

it('discards malformed dates, non numeric quantities and unsupported document types', function () {
    $analysis = analyzeIntegralizationPayload([
        'document_type' => 'a_type_that_does_not_exist',
        'document_date' => '15/05/2026',
        'candidate_integration_date' => '2026-13-45',
        'integralized_quantity' => 'quatro mil',
        'dates' => [
            ['date' => '15/05/2026', 'semantic' => 'settlement_date', 'source' => 'x', 'excerpt' => 'y'],
            ['date' => '2026-05-15', 'semantic' => null, 'source' => 'x', 'excerpt' => 'y'],
        ],
    ]);

    expect($analysis['document_type'])->toBe('other')
        ->and($analysis['document_date'])->toBeNull()
        ->and($analysis['candidate_integration_date'])->toBeNull()
        ->and($analysis['quantity'])->toBeNull()
        ->and($analysis['dates'])->toBe([])
        ->and($analysis['analysis_status'])->toBe('insufficient_documentary_evidence');
});

it('reports the title only as discovery metadata and never as proof', function () {
    $analysis = analyzeIntegralizationPayload(
        [
            'document_type' => 'other',
            'issuer' => ['name' => null, 'role' => 'unknown', 'excerpt' => null],
            'emission_identifiers' => [],
            'dates' => [],
            'candidate_integration_date' => null,
            'candidate_event_semantic' => null,
            'date_source' => null,
            'excerpt' => null,
            'proves_effective_settlement' => false,
        ],
        'Extrato de Liquidação B3 — 15/05/2026 — 26E0017614',
    );

    expect($analysis['title'])->toBe('Extrato de Liquidação B3 — 15/05/2026 — 26E0017614')
        ->and($analysis['strength'])->toBe('weak')
        ->and($analysis['analysis_status'])->toBe('insufficient_documentary_evidence')
        ->and($analysis['candidate_integration_date'])->toBeNull()
        ->and($analysis['emission_match'])->toBeFalse();
});
