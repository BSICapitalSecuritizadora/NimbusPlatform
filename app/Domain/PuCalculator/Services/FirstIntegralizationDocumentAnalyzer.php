<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Models\Document;
use App\Models\Emission;
use App\Services\GeminiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class FirstIntegralizationDocumentAnalyzer
{
    private const STRONG_DOCUMENT_TYPES = [
        'b3_settlement_statement',
        'registrar_position',
        'custodian_position',
        'subscription_bulletin',
        'settling_bank_receipt',
        'official_settlement_proof',
    ];

    private const MEDIUM_DOCUMENT_TYPES = [
        'acceptance_document',
        'final_distribution_map',
        'closing_announcement',
    ];

    private const RESPONSIBLE_ISSUER_ROLES = [
        'b3',
        'registrar',
        'bookkeeper',
        'custodian',
        'settling_bank',
        'lead_coordinator',
        'distributor',
        'responsible_settlement_participant',
    ];

    private const INTEGRATION_EVENT_SEMANTICS = [
        'settlement_date',
        'integration_date',
        'remuneration_start_date',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $analysisCache = [];

    public function __construct(
        private readonly GeminiService $gemini,
    ) {}

    /**
     * A falha de extração nunca é cacheada nem propagada: o Gate C precisa de um
     * veredito determinístico, e a mensagem da exception fica no log interno
     * porque pode carregar credencial, caminho de storage ou payload da requisição.
     *
     * @return array<string, mixed>
     */
    public function analyze(Emission $emission, Document $document): array
    {
        $cacheKey = implode(':', [
            $emission->getKey(),
            $document->getKey(),
            $document->checksum ?? 'without-checksum',
        ]);

        if (array_key_exists($cacheKey, $this->analysisCache)) {
            return $this->analysisCache[$cacheKey];
        }

        try {
            $analysis = $this->normalize(
                $emission,
                $document,
                $this->gemini->extractFromDocumentWithPrompt(
                    $document,
                    $this->prompt($emission),
                ),
            );
        } catch (Throwable $exception) {
            Log::error('Falha ao extrair a evidência documental da primeira integralização.', [
                'emission_id' => $emission->getKey(),
                'document_id' => $document->getKey(),
                'exception' => $exception,
            ]);

            return $this->extractionFailure($document);
        }

        return $this->analysisCache[$cacheKey] = $analysis;
    }

    /**
     * Normalization is public so deterministic payload validation can be tested
     * without sending a document to the external processor.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(Emission $emission, Document $document, array $payload): array
    {
        $documentType = $this->documentType($payload['document_type'] ?? null);
        $dates = $this->dates($payload['dates'] ?? []);
        $identifiers = $this->identifiers($payload['emission_identifiers'] ?? []);
        $matchedIdentifiers = $this->matchedIdentifiers($emission, $identifiers);
        $issuer = $this->issuer($payload['issuer'] ?? null);
        $candidateDate = $this->date($payload['candidate_integration_date'] ?? null);
        $candidateSemantic = $this->nullableString($payload['candidate_event_semantic'] ?? null);
        $candidateEvent = collect($dates)->first(fn (array $date): bool => $date['date'] === $candidateDate
            && $date['semantic'] === $candidateSemantic
        );
        $candidateExcerpt = $this->nullableString(
            $payload['excerpt'] ?? ($candidateEvent['excerpt'] ?? null),
            2000,
        );
        $dateSource = $this->nullableString(
            $payload['date_source'] ?? ($candidateEvent['source'] ?? null),
            255,
        );
        $confidenceScore = $this->confidenceScore($payload['confidence'] ?? null);
        $ambiguityReason = $this->ambiguityReason($payload, $dates);

        // Ambiguidade declarada ou detectada não expõe data candidata: o extrator
        // pode devolver as duas coisas ao mesmo tempo, e o Gate C leria a data
        // como proposta daquilo que o documento justamente não resolve.
        if ($ambiguityReason !== null) {
            $candidateDate = null;
            $candidateSemantic = null;
        }

        $provesEffectiveSettlement = ($payload['proves_effective_settlement'] ?? false) === true;
        $issuerResponsible = in_array($issuer['role'], self::RESPONSIBLE_ISSUER_ROLES, true)
            && $issuer['name'] !== null
            && $issuer['excerpt'] !== null;
        $emissionMatches = $matchedIdentifiers !== [];
        $candidateReliable = $candidateDate !== null
            && in_array($candidateSemantic, self::INTEGRATION_EVENT_SEMANTICS, true)
            && is_array($candidateEvent)
            && $candidateExcerpt !== null
            && $dateSource !== null
            && $ambiguityReason === null;
        $primaryDocument = in_array($documentType, self::STRONG_DOCUMENT_TYPES, true);
        $mediumDocument = in_array($documentType, self::MEDIUM_DOCUMENT_TYPES, true);
        $strong = $primaryDocument
            && $issuerResponsible
            && $emissionMatches
            && $provesEffectiveSettlement
            && $candidateReliable
            && $confidenceScore >= 0.85;
        $strength = $strong ? 'strong' : ($mediumDocument ? 'medium' : 'weak');
        $analysisStatus = $this->analysisStatus(
            mediumDocument: $mediumDocument,
            primaryDocument: $primaryDocument,
            issuerResponsible: $issuerResponsible,
            emissionMatches: $emissionMatches,
            provesEffectiveSettlement: $provesEffectiveSettlement,
            candidateDate: $candidateDate,
            candidateReliable: $candidateReliable,
            ambiguityReason: $ambiguityReason,
            confidenceScore: $confidenceScore,
        );

        return [
            'document_id' => $document->getKey(),
            'title' => $document->title,
            'document_type' => $documentType,
            'document_date' => $this->date($payload['document_date'] ?? null),
            'candidate_integration_date' => $candidateDate,
            'candidate_event_semantic' => $candidateSemantic,
            'date_source' => $dateSource,
            'excerpt' => $candidateExcerpt,
            'confidence' => $this->confidenceLabel($confidenceScore),
            'confidence_score' => $confidenceScore,
            'strength' => $strength,
            'reason' => $this->reason($analysisStatus),
            'analysis_status' => $analysisStatus,
            'emission_match' => $emissionMatches,
            'identifiers_found' => $identifiers,
            'matched_identifiers' => $matchedIdentifiers,
            'issuer' => $issuer,
            'dates' => $dates,
            'ambiguity_reason' => $ambiguityReason,
            'ambiguity_resolution' => $this->nullableString($payload['ambiguity_resolution'] ?? null, 1000),
            'proves_effective_settlement' => $provesEffectiveSettlement,
            'quantity' => $this->quantity($payload['integralized_quantity'] ?? null),
            'quantity_excerpt' => $this->nullableString($payload['quantity_excerpt'] ?? null, 2000),
        ];
    }

    private function prompt(Emission $emission): string
    {
        $identity = json_encode([
            'name' => $emission->name,
            'if_code' => $emission->if_code,
            'isin_code' => $emission->isin_code,
            'bsi_code' => $emission->bsi_code,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $prompt = <<<'PROMPT'
Você analisa evidência documental da primeira integralização de um CRI brasileiro.

Use SOMENTE o conteúdo do documento anexado. O nome do arquivo e o título cadastral não são prova. Não complete dados ausentes, não use datas candidatas externas e não trate subscrição como liquidação financeira.

Emissão esperada: __EMISSION_IDENTITY__

Classifique document_type com EXATAMENTE um valor:
- b3_settlement_statement
- registrar_position
- custodian_position
- subscription_bulletin
- settling_bank_receipt
- official_settlement_proof
- acceptance_document
- final_distribution_map
- closing_announcement
- start_announcement
- indicative_schedule
- other

Classifique issuer.role com EXATAMENTE um valor:
- b3
- registrar
- bookkeeper
- custodian
- settling_bank
- lead_coordinator
- distributor
- responsible_settlement_participant
- issuer
- unknown

Extraia TODAS as datas materialmente relacionadas e atribua uma semântica EXATA:
- subscription_date
- settlement_date
- integration_date
- remuneration_start_date
- registration_date
- issuance_date
- custody_date
- document_date
- offering_date
- other

candidate_integration_date só pode ser preenchida quando o documento provar, sem ambiguidade, a data efetiva da primeira liquidação/integralização a partir da qual a remuneração correu para os títulos integralizados. Uma data de subscrição, emissão, registro, assinatura, custódia, documento ou oferta, isoladamente, nunca serve.

proves_effective_settlement deve ser true somente se o corpo trouxer prova efetiva da liquidação/integralização, não mera previsão. Se houver duas ou mais datas plausíveis para a primeira integralização sem regra expressa que resolva qual rege o início da remuneração, defina ambiguous=true, explique ambiguity_reason e deixe candidate_integration_date null. Se o próprio documento trouxer regra expressa que resolva a aparente multiplicidade, transcreva-a em ambiguity_resolution.

Cada identificador, emissor e data deve conter excerpt literal e localização em source. Não invente página ou linha. confidence é um número de 0 a 1. A quantidade, se houver, é apenas informativa e não decide a data.

Retorne SOMENTE JSON:
{
  "document_type": "string",
  "document_date": "YYYY-MM-DD|null",
  "issuer": {"name": "string|null", "role": "string", "excerpt": "string|null"},
  "emission_identifiers": [
    {"type": "if_code|isin_code|bsi_code|emission_name|other", "value": "string", "excerpt": "string"}
  ],
  "dates": [
    {"date": "YYYY-MM-DD", "semantic": "string", "source": "string", "excerpt": "string"}
  ],
  "candidate_integration_date": "YYYY-MM-DD|null",
  "candidate_event_semantic": "settlement_date|integration_date|remuneration_start_date|null",
  "date_source": "string|null",
  "excerpt": "string|null",
  "proves_effective_settlement": false,
  "ambiguous": false,
  "ambiguity_reason": "string|null",
  "ambiguity_resolution": "string|null",
  "integralized_quantity": "string|null",
  "quantity_excerpt": "string|null",
  "confidence": 0.0
}
PROMPT;

        return str_replace('__EMISSION_IDENTITY__', $identity, $prompt);
    }

    /** @return list<array{date: string, semantic: string, source: string, excerpt: string}> */
    private function dates(mixed $dates): array
    {
        if (! is_array($dates)) {
            return [];
        }

        return collect($dates)
            ->filter(fn (mixed $date): bool => is_array($date))
            ->map(function (array $date): ?array {
                $value = $this->date($date['date'] ?? null);
                $semantic = $this->nullableString($date['semantic'] ?? null);
                $source = $this->nullableString($date['source'] ?? null, 500);
                $excerpt = $this->nullableString($date['excerpt'] ?? null, 2000);

                if ($value === null || $semantic === null || $source === null || $excerpt === null) {
                    return null;
                }

                return ['date' => $value, 'semantic' => $semantic, 'source' => $source, 'excerpt' => $excerpt];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array{type: string, value: string, excerpt: string}> */
    private function identifiers(mixed $identifiers): array
    {
        if (! is_array($identifiers)) {
            return [];
        }

        return collect($identifiers)
            ->filter(fn (mixed $identifier): bool => is_array($identifier))
            ->map(function (array $identifier): ?array {
                $type = $this->nullableString($identifier['type'] ?? null);
                $value = $this->nullableString($identifier['value'] ?? null, 255);
                $excerpt = $this->nullableString($identifier['excerpt'] ?? null, 2000);

                return $type !== null && $value !== null && $excerpt !== null
                    ? compact('type', 'value', 'excerpt')
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array{type: string, value: string, excerpt: string}>  $identifiers
     * @return list<array{type: string, value: string, excerpt: string}>
     */
    private function matchedIdentifiers(Emission $emission, array $identifiers): array
    {
        $expected = array_filter([
            'if_code' => $emission->if_code,
            'isin_code' => $emission->isin_code,
            'bsi_code' => $emission->bsi_code,
            'emission_name' => $emission->name,
        ], fn (mixed $value): bool => filled($value));

        return collect($identifiers)
            ->filter(function (array $identifier) use ($expected): bool {
                $expectedValue = $expected[$identifier['type']] ?? null;

                return is_string($expectedValue)
                    && $this->normalizeIdentity($expectedValue) === $this->normalizeIdentity($identifier['value']);
            })
            ->values()
            ->all();
    }

    /** @return array{name: ?string, role: string, excerpt: ?string} */
    private function issuer(mixed $issuer): array
    {
        if (! is_array($issuer)) {
            return ['name' => null, 'role' => 'unknown', 'excerpt' => null];
        }

        return [
            'name' => $this->nullableString($issuer['name'] ?? null, 255),
            'role' => $this->nullableString($issuer['role'] ?? null) ?? 'unknown',
            'excerpt' => $this->nullableString($issuer['excerpt'] ?? null, 2000),
        ];
    }

    /** @param list<array{date: string, semantic: string, source: string, excerpt: string}> $dates */
    private function ambiguityReason(array $payload, array $dates): ?string
    {
        if (($payload['ambiguous'] ?? false) === true) {
            return $this->nullableString($payload['ambiguity_reason'] ?? null, 1000)
                ?? 'O documento contém mais de uma data plausível para a primeira integralização.';
        }

        $plausibleDates = collect($dates)
            ->whereIn('semantic', self::INTEGRATION_EVENT_SEMANTICS)
            ->pluck('date')
            ->unique()
            ->values();

        $resolution = $this->nullableString($payload['ambiguity_resolution'] ?? null, 1000);

        return $plausibleDates->count() > 1 && $resolution === null
            ? 'O documento contém múltiplas datas de liquidação, integralização ou início da remuneração sem uma regra inequívoca de prevalência.'
            : null;
    }

    private function analysisStatus(
        bool $mediumDocument,
        bool $primaryDocument,
        bool $issuerResponsible,
        bool $emissionMatches,
        bool $provesEffectiveSettlement,
        ?string $candidateDate,
        bool $candidateReliable,
        ?string $ambiguityReason,
        float $confidenceScore,
    ): string {
        if ($mediumDocument) {
            return 'manual_documentary_review_required';
        }

        if (! $primaryDocument) {
            return 'insufficient_documentary_evidence';
        }

        if (! $emissionMatches) {
            return 'integration_document_does_not_match_emission';
        }

        if (! $issuerResponsible) {
            return 'document_issuer_not_authoritative';
        }

        if ($ambiguityReason !== null) {
            return 'ambiguous_integration_date';
        }

        if (! $provesEffectiveSettlement || $candidateDate === null || ! $candidateReliable) {
            return 'document_does_not_prove_integration_date';
        }

        if ($confidenceScore < 0.85) {
            return 'documentary_confidence_insufficient';
        }

        return 'eligible';
    }

    /**
     * Mesma forma de `normalize()`, para que o Gate C leia um único contrato.
     *
     * @return array<string, mixed>
     */
    private function extractionFailure(Document $document): array
    {
        return [
            'document_id' => $document->getKey(),
            'title' => $document->title,
            'document_type' => null,
            'document_date' => null,
            'candidate_integration_date' => null,
            'candidate_event_semantic' => null,
            'date_source' => null,
            'excerpt' => null,
            'confidence' => $this->confidenceLabel(0.0),
            'confidence_score' => 0.0,
            'strength' => 'weak',
            'reason' => $this->reason('document_analysis_failed'),
            'analysis_status' => 'document_analysis_failed',
            'emission_match' => false,
            'identifiers_found' => [],
            'matched_identifiers' => [],
            'issuer' => ['name' => null, 'role' => 'unknown', 'excerpt' => null],
            'dates' => [],
            'ambiguity_reason' => null,
            'ambiguity_resolution' => null,
            'proves_effective_settlement' => false,
            'quantity' => null,
            'quantity_excerpt' => null,
        ];
    }

    private function reason(string $analysisStatus): string
    {
        return match ($analysisStatus) {
            'eligible' => 'Fonte primária emitida por participante responsável, vinculada à emissão e com evento/data efetivos comprovados no conteúdo.',
            'manual_documentary_review_required' => 'Fonte secundária ou confirmatória: requer revisão documental manual e não pode fechar automaticamente o Gate C.',
            'integration_document_does_not_match_emission' => 'O conteúdo não contém identificador verificável desta emissão.',
            'document_issuer_not_authoritative' => 'O conteúdo não comprova emissão por participante responsável pela liquidação, escrituração ou custódia.',
            'ambiguous_integration_date' => 'O conteúdo apresenta mais de uma data material possível sem regra inequívoca para a primeira integralização.',
            'document_does_not_prove_integration_date' => 'O conteúdo não comprova a data efetiva da primeira liquidação/integralização que inicia a remuneração.',
            'documentary_confidence_insufficient' => 'A extração documental não atingiu a confiança mínima para proposta automática.',
            'document_analysis_failed' => 'O conteúdo do documento não pôde ser analisado; consulte o log da aplicação e tente novamente com o arquivo íntegro.',
            default => 'Documento indicativo, cadastral ou sem natureza probatória suficiente.',
        };
    }

    private function documentType(mixed $value): ?string
    {
        $documentType = $this->nullableString($value);
        $supported = [
            ...array_column(PuBaselineEvidenceDocumentType::cases(), 'value'),
            'start_announcement',
            'indicative_schedule',
        ];

        return in_array($documentType, $supported, true) ? $documentType : 'other';
    }

    private function confidenceScore(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return min(1.0, max(0.0, (float) $value));
    }

    private function confidenceLabel(float $score): string
    {
        return match (true) {
            $score >= 0.85 => 'high',
            $score >= 0.60 => 'medium',
            default => 'low',
        };
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->toDateString() === trim($value)
            ? $date->toDateString()
            : null;
    }

    private function quantity(mixed $value): ?string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return trim((string) $value);
    }

    private function normalizeIdentity(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    private function nullableString(mixed $value, ?int $limit = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = Str::squish((string) $value);

        if ($normalized === '') {
            return null;
        }

        return $limit === null ? $normalized : Str::limit($normalized, $limit, '');
    }
}
