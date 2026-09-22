<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceConfidence;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceValueOrigin;
use App\Enums\AccessPermission;
use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\MalwareScanStatus;
use App\Models\Document;
use App\Models\Emission;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Extração assistida por IA do valor que uma evidência do baseline comprova.
 *
 * Sugere, nunca decide: o resultado preenche o formulário "Associar evidência
 * ao baseline" e a evidência criada segue pendente de revisão como qualquer
 * outra. O transporte é o {@see GeminiService} de sempre; aqui ficam o pedido
 * estruturado por tipo de valor, a validação do retorno pela mesma regra da
 * criação e a régua de confiança.
 *
 * Cada análise vira um registro no cache com id próprio. O formulário carrega
 * só esse id; página, trecho e modelo gravados na evidência vêm daqui, não do
 * estado do navegador — do contrário a proveniência seria o que o cliente
 * dissesse que ela é.
 */
final class PuBaselineEvidenceExtractionService
{
    public const PROMPT_VERSION = 'pu-baseline-evidence/v1';

    public const STATUS_FOUND = 'found';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_FAILED = 'failed';

    /** Campos do formulário que a extração pode preencher. */
    public const FORM_FIELDS = ['document_type', 'evidenced_value', 'reference', 'confidence', 'notes'];

    /** Valor que o formulário tem antes de qualquer análise; igualar a ele não é edição. */
    private const FORM_DEFAULTS = ['confidence' => 'high'];

    private const CACHE_PREFIX = 'pu-baseline-evidence-extraction:';

    private const CACHE_TTL_HOURS = 24;

    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'marco' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
        'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    private const EXTERNAL_STATUS_SYNONYMS = [
        'aguardando_comparacao' => 'available_pending_comparison',
        'disponivel' => 'available_pending_comparison',
        'aderente' => 'matched',
        'conferido' => 'matched',
        'divergente' => 'divergent',
    ];

    private const PROMPT = <<<'PROMPT'
Você localiza, em um documento de uma emissão brasileira de títulos (CRI, CRA ou debêntures), a prova documental de UM valor específico do baseline de PU.

Use SOMENTE o conteúdo do documento anexado. O nome do arquivo e o título cadastral não são prova. Não complete dados ausentes, não use conhecimento externo e não invente página, cláusula ou trecho.

Emissão esperada: __EMISSION__

Valor a comprovar (especificação estruturada):
__REQUEST__

Regras de resposta:
- found = true somente se o documento contiver a informação solicitada, para esta emissão.
- value: o valor normalizado EXATAMENTE no tipo e formato da especificação; null se não houver valor confiável.
- value_as_written: o valor como aparece escrito no documento.
- page: número sequencial da página dentro do arquivo PDF (1 = primeira página do arquivo), não o número impresso no rodapé; null se não souber.
- reference: rótulo da posição como aparece no documento (ex.: "Cláusula 4.2", "Item 3.1", "Quadro Resumo"); null se não houver.
- excerpt: transcrição literal e curta (até 400 caracteres) do trecho que comprova o valor.
- evidence_level: "explicit" se o valor está escrito literalmente; "inferred" se exigiu interpretação ou cálculo; "conflicting" se o documento traz valores diferentes para a mesma informação sem regra que resolva; "not_found" se não localizou.
- alternatives: outras ocorrências plausíveis com valor DIFERENTE do escolhido; lista vazia se não houver.
- emission_mentioned: true somente se o documento identificar expressamente esta emissão (nome, código IF, ISIN ou série).
- document_type: classifique o documento com EXATAMENTE um destes valores:
__DOCUMENT_TYPES__
- observations: de 1 a 3 frases objetivas em português: o que foi encontrado, em que contexto e qualquer ambiguidade ou interpretação.
- confidence: número de 0 a 1 com a sua certeza.

Retorne SOMENTE JSON:
{
  "found": false,
  "value": "string|null",
  "value_as_written": "string|null",
  "page": 1,
  "reference": "string|null",
  "excerpt": "string|null",
  "evidence_level": "explicit|inferred|conflicting|not_found",
  "alternatives": [{"value": "string", "page": 1, "excerpt": "string"}],
  "emission_mentioned": false,
  "document_type": "string",
  "observations": "string",
  "confidence": 0.0
}
PROMPT;

    public function __construct(
        private readonly GeminiService $gemini,
    ) {}

    /**
     * Analisa o documento para o valor pedido e devolve o registro da análise.
     *
     * A mesma combinação de documento (pelo checksum), valor e modelo reaproveita
     * a análise anterior em vez de reenviar o arquivo; `$reuseCached = false` é
     * o "analisar novamente" explícito. Falha nunca é reaproveitada nem
     * propagada: vira um registro `failed` com mensagem amigável, e o detalhe
     * fica no log interno porque pode carregar caminho de storage ou payload.
     *
     * @return array<string, mixed>
     */
    public function analyze(
        Emission $emission,
        int|string $documentId,
        PuBaselineEvidenceType $evidenceType,
        User $actor,
        bool $reuseCached = true,
    ): array {
        $this->authorize($actor);
        $document = $emission->documents()->whereKey((int) $documentId)->first();

        if (! $document instanceof Document) {
            throw ValidationException::withMessages([
                'document_id' => 'Selecione um documento já vinculado a esta emissão.',
            ]);
        }

        $contentKey = $this->contentKey($emission, $document, $evidenceType);
        $cached = $reuseCached ? Cache::get($contentKey) : null;
        $cached = is_array($cached) && is_array($cached['payload'] ?? null) ? $cached : null;

        // O cache guarda a resposta crua do modelo, não a normalizada: uma
        // correção na regra de validação vale na hora, sem esperar o TTL.
        if (is_array($cached)) {
            $record = $this->record($emission, $document, $evidenceType, $actor, $this->normalize($evidenceType, $cached['payload']), [
                'source' => 'cache',
                'cached_from' => $cached['id'],
                'analyzed_at' => $cached['analyzed_at'],
            ]);
        } else {
            $payload = $this->extract($emission, $document, $evidenceType);
            $record = $this->record(
                $emission,
                $document,
                $evidenceType,
                $actor,
                $payload === null ? $this->failure($this->failureMessage($document)) : $this->normalize($evidenceType, $payload),
                ['source' => 'gemini', 'cached_from' => null, 'analyzed_at' => now()->toIso8601String()],
            );

            if ($payload !== null) {
                Cache::put($contentKey, [
                    'id' => $record['id'],
                    'analyzed_at' => $record['analyzed_at'],
                    'payload' => $payload,
                ], now()->addHours(self::CACHE_TTL_HOURS));
            }
        }

        Cache::put(self::CACHE_PREFIX.$record['id'], $record, now()->addHours(self::CACHE_TTL_HOURS));
        $this->audit($emission, $actor, $record);

        return $record;
    }

    /** @return array<string, mixed>|null */
    public function find(mixed $extractionId): ?array
    {
        if (! is_string($extractionId) || ! Str::isUuid($extractionId)) {
            return null;
        }

        $record = Cache::get(self::CACHE_PREFIX.$extractionId);

        return is_array($record) ? $record : null;
    }

    /**
     * Valida e normaliza o JSON do modelo. Público para que a regra seja
     * testada sem enviar documento ao processador externo.
     *
     * Critério de confiança, sempre limitado pela nota do próprio modelo:
     * - alta: valor validado, escrito literalmente, com página, trecho, sem
     *   ocorrência concorrente e com a emissão identificada no documento;
     * - média: valor validado com trecho e alguma localização, mas interpretado,
     *   sem página ou sem identificação expressa da emissão;
     * - baixa: todo o resto — valor não validado, ocorrências divergentes, sem
     *   trecho ou sem localização.
     *
     * Ocorrências divergentes não expõem valor: o formulário receberia como
     * proposta justamente aquilo que o documento não resolve.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, message: string, suggestion: array<string, mixed>, form: array<string, ?string>}
     */
    public function normalize(PuBaselineEvidenceType $evidenceType, array $payload): array
    {
        $rawValue = $this->nullableString($payload['value'] ?? null, 255);
        $validValue = $this->validValue($evidenceType, $rawValue);
        $page = $this->page($payload['page'] ?? null);
        $reference = $this->nullableString($payload['reference'] ?? null, 200);
        $excerpt = $this->nullableString($payload['excerpt'] ?? null, 2000);
        $level = GuaranteeEvidenceLevel::tryFrom((string) ($payload['evidence_level'] ?? ''))
            ?? GuaranteeEvidenceLevel::Inferred;
        $alternatives = $this->alternatives($evidenceType, $payload['alternatives'] ?? [], $validValue);
        $ambiguous = $level === GuaranteeEvidenceLevel::Conflicting || $alternatives !== [];
        $emissionMentioned = ($payload['emission_mentioned'] ?? false) === true;
        $modelScore = is_numeric($payload['confidence'] ?? null)
            ? min(1.0, max(0.0, (float) $payload['confidence']))
            : 0.0;
        $acceptedValue = $ambiguous ? null : $validValue;
        $hasLocation = $page !== null || $reference !== null || $excerpt !== null || $alternatives !== [];
        $declaredFound = ($payload['found'] ?? false) === true && $level !== GuaranteeEvidenceLevel::NotFound;

        // O modelo costuma responder found=false quando acha o valor duas vezes,
        // com datas diferentes: para ele "não há valor confiável". Para quem
        // revisa, as ocorrências localizadas são o resultado mais útil da
        // análise, então conflito com localização é parcial, não "nada achado".
        $status = match (true) {
            $declaredFound && $acceptedValue !== null => self::STATUS_FOUND,
            $declaredFound && ($hasLocation || $rawValue !== null) => self::STATUS_PARTIAL,
            $ambiguous && $hasLocation => self::STATUS_PARTIAL,
            default => self::STATUS_NOT_FOUND,
        };

        $confidence = match (true) {
            $status !== self::STATUS_FOUND,
            $excerpt === null,
            $page === null && $reference === null => PuBaselineEvidenceConfidence::Low,
            $level === GuaranteeEvidenceLevel::Explicit && $page !== null && $emissionMentioned => PuBaselineEvidenceConfidence::High,
            default => PuBaselineEvidenceConfidence::Medium,
        };
        $confidence = $confidence->cappedBy(PuBaselineEvidenceConfidence::fromScore($modelScore));

        $documentType = PuBaselineEvidenceDocumentType::tryFrom((string) ($payload['document_type'] ?? ''));
        $suggestedDocumentType = $documentType !== null
            && $documentType !== PuBaselineEvidenceDocumentType::Other
            && $evidenceType->acceptsDocumentType($documentType)
                ? $documentType->value
                : null;

        $referenceLabel = $this->referenceLabel($page, $reference);
        $observations = $this->observations(
            $evidenceType,
            $status,
            $this->nullableString($payload['observations'] ?? null, 1200),
            $rawValue,
            $validValue,
            $alternatives,
            $level,
            $emissionMentioned,
        );

        $suggestion = [
            'value' => $acceptedValue,
            'raw_value' => $rawValue,
            'value_as_written' => $this->nullableString($payload['value_as_written'] ?? null, 255),
            'value_valid' => $validValue !== null,
            'page' => $page,
            'reference' => $reference,
            'reference_label' => $referenceLabel,
            'excerpt' => $excerpt,
            'evidence_level' => $level->value,
            'ambiguous' => $ambiguous,
            'alternatives' => $alternatives,
            'emission_mentioned' => $emissionMentioned,
            'model_confidence_score' => $modelScore,
            'confidence' => $confidence->value,
            'document_type' => $suggestedDocumentType,
            'observations' => $observations,
        ];

        $proposes = in_array($status, [self::STATUS_FOUND, self::STATUS_PARTIAL], true);

        return [
            'status' => $status,
            'message' => $status === self::STATUS_PARTIAL && $ambiguous
                ? 'O documento traz valores diferentes para esta informação. Nenhum valor foi preenchido: confira as ocorrências e informe o valor.'
                : $this->message($status),
            'suggestion' => $suggestion,
            'form' => [
                'document_type' => $proposes ? $suggestedDocumentType : null,
                'evidenced_value' => $acceptedValue,
                'reference' => $proposes ? $referenceLabel : null,
                'confidence' => $proposes ? $confidence->value : null,
                'notes' => $proposes ? $observations : null,
            ],
        ];
    }

    /**
     * O que uma nova análise faz com o formulário.
     *
     * Campo que o usuário editou — difere do que a análise anterior (ou o
     * padrão do formulário) colocou ali — só é substituído com
     * `$replaceEdited`, que a interface só passa depois de confirmação. Mesmo
     * assim nunca é apagado: substituir por "nada" seria perder dado digitado.
     * Campo ainda igual ao que a análise anterior propôs pertence àquela
     * análise, e é limpo quando a nova não propõe nada — senão o valor de um
     * documento ficaria no formulário como se fosse do outro.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, ?string>|null  $previous
     * @param  array<string, ?string>  $next
     * @return array{values: array<string, ?string>, preserved: list<string>}
     */
    public function formFill(array $current, ?array $previous, array $next, bool $replaceEdited): array
    {
        $values = [];
        $preserved = [];

        foreach (self::FORM_FIELDS as $field) {
            $currentValue = $this->formValue($current[$field] ?? null);
            $previousValue = $previous[$field] ?? null;
            $nextValue = $next[$field] ?? null;

            if ($this->isEdited($field, $currentValue, $previousValue)) {
                if ($nextValue === null || $currentValue === $nextValue) {
                    continue;
                }

                if (! $replaceEdited) {
                    $preserved[] = $field;

                    continue;
                }
            }

            if ($nextValue !== null) {
                $values[$field] = $nextValue;
            } elseif ($currentValue !== null && $currentValue === $previousValue) {
                $values[$field] = null;
            }
        }

        return ['values' => $values, 'preserved' => $preserved];
    }

    /**
     * Campos com valor diferente do que a análise aplicada propôs.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, ?string>|null  $applied
     * @return list<string>
     */
    public function editedFields(array $current, ?array $applied): array
    {
        return array_values(array_filter(
            self::FORM_FIELDS,
            fn (string $field): bool => $this->isEdited($field, $this->formValue($current[$field] ?? null), $applied[$field] ?? null),
        ));
    }

    /**
     * Proveniência que acompanha a evidência criada, decidida no servidor.
     *
     * Página e trecho descrevem onde o documento sustenta a referência gravada,
     * então só permanecem enquanto a referência for a que a IA localizou. Uma
     * análise que não existe mais, ou que foi feita para outro documento,
     * valor ou usuário, não empresta proveniência a nada: o valor conta como
     * manual.
     *
     * @param  array<string, mixed>  $final
     * @return array{value_origin: PuBaselineEvidenceValueOrigin, page: ?int, excerpt: ?string, extraction: ?array<string, mixed>}
     */
    public function provenance(
        mixed $extractionId,
        Emission $emission,
        Document $document,
        PuBaselineEvidenceType $evidenceType,
        User $actor,
        array $final,
    ): array {
        $manual = [
            'value_origin' => PuBaselineEvidenceValueOrigin::Manual,
            'page' => null,
            'excerpt' => null,
            'extraction' => null,
        ];

        if (blank($extractionId)) {
            return $manual;
        }

        $record = $this->find($extractionId);

        if ($record === null) {
            return [...$manual, 'extraction' => ['id' => (string) $extractionId, 'status' => 'unavailable']];
        }

        if ((int) $record['emission_id'] !== (int) $emission->getKey()
            || (int) $record['document_id'] !== (int) $document->getKey()
            || $record['evidence_type'] !== $evidenceType->value
            || (int) $record['requested_by'] !== (int) $actor->getKey()) {
            return $manual;
        }

        $form = $record['form'];
        $editedFields = array_values(array_filter(
            self::FORM_FIELDS,
            fn (string $field): bool => $form[$field] !== null && $this->formValue($final[$field] ?? null) !== $form[$field],
        ));
        $origin = match (true) {
            $form['evidenced_value'] === null => PuBaselineEvidenceValueOrigin::Manual,
            in_array('evidenced_value', $editedFields, true) => PuBaselineEvidenceValueOrigin::AiExtractedEdited,
            default => PuBaselineEvidenceValueOrigin::AiExtracted,
        };
        $keepsLocation = $form['reference'] !== null && ! in_array('reference', $editedFields, true);

        return [
            'value_origin' => $origin,
            'page' => $keepsLocation ? $record['suggestion']['page'] : null,
            'excerpt' => $keepsLocation ? $record['suggestion']['excerpt'] : null,
            'extraction' => [
                ...Arr::only($record, [
                    'id', 'status', 'model', 'prompt_version', 'analyzed_at', 'source', 'cached_from',
                    'requested_by', 'document_id', 'document_checksum', 'evidence_type',
                ]),
                'suggestion' => $record['suggestion'],
                'edited_fields' => $editedFields,
            ],
        ];
    }

    /**
     * URL que abre o documento na página citada — a mesma rota inline que o
     * dossiê usa, porque o download força `attachment` e o navegador ignoraria
     * a âncora `#page=`.
     */
    public static function sourceUrl(Document $document, ?int $page): ?string
    {
        if ($document->scan_status !== MalwareScanStatus::Clean) {
            return null;
        }

        $url = route('admin.documents.preview', $document);

        return $page === null ? $url : $url.'#page='.$page;
    }

    /**
     * Resposta crua do modelo, ou null quando não houve análise — documento
     * bloqueado pela varredura (que nem sai para o processador) ou falha.
     *
     * @return array<string, mixed>|null
     */
    private function extract(Emission $emission, Document $document, PuBaselineEvidenceType $evidenceType): ?array
    {
        if ($this->isBlocked($document)) {
            return null;
        }

        try {
            return $this->gemini->extractFromDocumentWithPrompt($document, $this->prompt($emission, $evidenceType));
        } catch (Throwable $exception) {
            Log::error('Falha na extração assistida da evidência do baseline de PU.', [
                'emission_id' => $emission->getKey(),
                'document_id' => $document->getKey(),
                'evidence_type' => $evidenceType->value,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function isBlocked(Document $document): bool
    {
        return in_array($document->scan_status, [MalwareScanStatus::Infected, MalwareScanStatus::Rejected], true);
    }

    private function failureMessage(Document $document): string
    {
        return $this->isBlocked($document)
            ? 'O documento está bloqueado pela verificação de segurança e não pode ser analisado.'
            : $this->message(self::STATUS_FAILED);
    }

    /**
     * @param  array{status: string, message: string, suggestion: array<string, mixed>, form: array<string, ?string>}  $outcome
     * @param  array{source: string, cached_from: ?string, analyzed_at: string}  $origin
     * @return array<string, mixed>
     */
    private function record(
        Emission $emission,
        Document $document,
        PuBaselineEvidenceType $evidenceType,
        User $actor,
        array $outcome,
        array $origin,
    ): array {
        return [
            'id' => (string) Str::uuid(),
            ...$outcome,
            'emission_id' => $emission->getKey(),
            'document_id' => $document->getKey(),
            'document_checksum' => $document->checksum,
            'evidence_type' => $evidenceType->value,
            'requested_by' => $actor->getKey(),
            'model' => $this->gemini->model(),
            'prompt_version' => self::PROMPT_VERSION,
            ...$origin,
        ];
    }

    /** @return array{status: string, message: string, suggestion: array<string, mixed>, form: array<string, ?string>} */
    private function failure(string $message): array
    {
        return [
            'status' => self::STATUS_FAILED,
            'message' => $message,
            'suggestion' => [],
            'form' => array_fill_keys(self::FORM_FIELDS, null),
        ];
    }

    /**
     * Um registro por análise pedida, inclusive as servidas do cache: quem
     * pediu, sobre qual documento e com que desfecho. O valor e o trecho não
     * entram — ficam na evidência, se ela for criada.
     *
     * @param  array<string, mixed>  $record
     */
    private function audit(Emission $emission, User $actor, array $record): void
    {
        activity(PuBaselineEvidenceReviewService::LOG_NAME)
            ->event('ai_extraction_'.$record['status'])
            ->performedOn($emission)
            ->causedBy($actor)
            ->withProperties([
                'extraction_id' => $record['id'],
                'emission_id' => $record['emission_id'],
                'document_id' => $record['document_id'],
                'evidence_type' => $record['evidence_type'],
                'status' => $record['status'],
                'confidence' => $record['suggestion']['confidence'] ?? null,
                'model' => $record['model'],
                'prompt_version' => $record['prompt_version'],
                'source' => $record['source'],
            ])
            ->log('Análise de documento com IA para evidência do baseline.');
    }

    private function contentKey(Emission $emission, Document $document, PuBaselineEvidenceType $evidenceType): string
    {
        return self::CACHE_PREFIX.'content:'.sha1(implode('|', [
            $emission->getKey(),
            $document->getKey(),
            $document->checksum ?? 'without-checksum:'.$document->updated_at?->getTimestamp(),
            $evidenceType->value,
            $this->gemini->model(),
            self::PROMPT_VERSION,
        ]));
    }

    private function prompt(Emission $emission, PuBaselineEvidenceType $evidenceType): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        return strtr(self::PROMPT, [
            '__EMISSION__' => json_encode(array_filter([
                'name' => $emission->name,
                'if_code' => $emission->if_code,
                'isin_code' => $emission->isin_code,
                'bsi_code' => $emission->bsi_code,
            ], fn (mixed $value): bool => filled($value)), $flags),
            '__REQUEST__' => json_encode($this->specification($evidenceType), $flags | JSON_PRETTY_PRINT),
            '__DOCUMENT_TYPES__' => collect(PuBaselineEvidenceDocumentType::cases())
                ->map(fn (PuBaselineEvidenceDocumentType $type): string => "  - {$type->value} ({$type->label()})")
                ->implode("\n"),
        ]);
    }

    /**
     * O pedido que orienta a busca: o que provar, em que tipo e formato, e o
     * que não serve. Espelha {@see PuBaselineEvidenceType::valueViolation()},
     * que é quem decide no fim.
     *
     * @return array<string, mixed>
     */
    private function specification(PuBaselineEvidenceType $evidenceType): array
    {
        return ['valor_a_comprovar' => $evidenceType->label(), 'codigo' => $evidenceType->value] + match ($evidenceType) {
            PuBaselineEvidenceType::FirstIntegralizationDate => [
                'tipo_esperado' => 'date',
                'formato' => 'YYYY-MM-DD',
                'restricoes' => [
                    'data de calendário válida',
                    'data EFETIVA da primeira liquidação financeira/integralização dos títulos desta emissão, a partir da qual a remuneração corre',
                    'data de subscrição, emissão, registro, assinatura, custódia, documento ou oferta, isoladamente, não serve',
                    'data apenas prevista ou indicativa não é prova: se for só o que existe, use evidence_level "inferred"',
                ],
            ],
            PuBaselineEvidenceType::IntegralizedQuantity => [
                'tipo_esperado' => 'positive_number',
                'formato' => 'número inteiro sem separador de milhar (ex.: 1500)',
                'restricoes' => [
                    'maior que zero',
                    'quantidade de títulos efetivamente integralizados/liquidados, não a quantidade emitida, ofertada ou subscrita',
                    'quantidade de títulos, não valor financeiro em reais',
                ],
            ],
            PuBaselineEvidenceType::ExternalPuReference => [
                'tipo_esperado' => 'enum',
                'valores_aceitos' => PuBaselineEvidenceType::EXTERNAL_REFERENCE_STATUSES,
                'restricoes' => [
                    'o documento precisa ser memória ou planilha oficial de PU desta emissão, produzida por terceiro independente (agente fiduciário, B3, escriturador ou calculadora)',
                    'use available_pending_comparison quando o documento apenas disponibiliza os PUs',
                    'use matched ou divergent somente se o próprio documento registrar expressamente o resultado da conferência contra o PU calculado internamente',
                ],
            ],
        };
    }

    private function validValue(PuBaselineEvidenceType $evidenceType, ?string $rawValue): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        $normalized = match ($evidenceType) {
            PuBaselineEvidenceType::FirstIntegralizationDate => $this->normalizeDate($rawValue),
            PuBaselineEvidenceType::IntegralizedQuantity => $this->normalizeQuantity($rawValue),
            PuBaselineEvidenceType::ExternalPuReference => $this->normalizeExternalStatus($rawValue),
        };

        return $normalized !== null && $evidenceType->valueViolation($normalized) === null
            ? $normalized
            : null;
    }

    /** Aceita ISO, DD/MM/AAAA e "15 de agosto de 2026"; ano com dois dígitos é ambíguo e fica de fora. */
    private function normalizeDate(string $value): ?string
    {
        $value = Str::of($value)->ascii()->lower()->squish()->rtrim('.;,')->toString();

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            [, $year, $month, $day] = $matches;
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $value, $matches) === 1) {
            [, $day, $month, $year] = $matches;
        } elseif (preg_match('/^(\d{1,2})(?:o)? de ([a-z]+) de (\d{4})$/', $value, $matches) === 1
            && isset(self::MONTHS[$matches[2]])) {
            [$day, $month, $year] = [$matches[1], self::MONTHS[$matches[2]], $matches[3]];
        } else {
            return null;
        }

        return checkdate((int) $month, (int) $day, (int) $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    /**
     * Número no formato do documento ou puro. Só com vírgula o ponto é
     * separador de milhar; sem vírgula, só quando agrupa exatamente de três em
     * três ("1.500") — quantidade de títulos é contagem, e "1.500" lido como
     * 1,5 seria o erro mais caro possível aqui.
     */
    private function normalizeQuantity(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));

        if (preg_match('/^\d[\d.,]*$/', $value) !== 1) {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function normalizeExternalStatus(string $value): ?string
    {
        $value = Str::of($value)->ascii()->lower()->squish()->replace([' ', '-'], '_')->toString();

        return self::EXTERNAL_STATUS_SYNONYMS[$value] ?? $value;
    }

    private function page(mixed $value): ?int
    {
        $page = match (true) {
            is_int($value) => $value,
            is_float($value) && floor($value) === $value => (int) $value,
            is_string($value) && ctype_digit(trim($value)) => (int) trim($value),
            default => null,
        };

        return $page !== null && $page >= 1 && $page <= 100000 ? $page : null;
    }

    /**
     * Ocorrências concorrentes com valor diferente do escolhido. Repetir o
     * mesmo valor em outra página não é ambiguidade.
     *
     * @return list<array{value: string, page: ?int, excerpt: ?string}>
     */
    private function alternatives(PuBaselineEvidenceType $evidenceType, mixed $alternatives, ?string $chosenValue): array
    {
        if (! is_array($alternatives)) {
            return [];
        }

        return collect($alternatives)
            ->filter(fn (mixed $alternative): bool => is_array($alternative))
            ->map(function (array $alternative) use ($evidenceType): ?array {
                $raw = $this->nullableString($alternative['value'] ?? null, 255);

                return $raw === null ? null : [
                    'value' => $this->validValue($evidenceType, $raw) ?? $raw,
                    'page' => $this->page($alternative['page'] ?? null),
                    'excerpt' => $this->nullableString($alternative['excerpt'] ?? null, 500),
                ];
            })
            ->filter(fn (?array $alternative): bool => $alternative !== null && $alternative['value'] !== $chosenValue)
            ->unique('value')
            ->take(5)
            ->values()
            ->all();
    }

    private function referenceLabel(?int $page, ?string $reference): ?string
    {
        $mentionsPage = $reference !== null && preg_match('/\bp(a|á)g/iu', $reference) === 1;
        $parts = array_filter([
            $page !== null && ! $mentionsPage ? "Página {$page}" : null,
            $reference,
        ]);

        return $parts === [] ? null : Str::limit(implode(' · ', $parts), 255, '');
    }

    /** @param list<array{value: string, page: ?int, excerpt: ?string}> $alternatives */
    private function observations(
        PuBaselineEvidenceType $evidenceType,
        string $status,
        ?string $modelObservations,
        ?string $rawValue,
        ?string $validValue,
        array $alternatives,
        GuaranteeEvidenceLevel $level,
        bool $emissionMentioned,
    ): ?string {
        if ($status === self::STATUS_NOT_FOUND) {
            return $modelObservations;
        }

        $notes = [$modelObservations];

        if ($rawValue !== null && $validValue === null) {
            $notes[] = sprintf(
                'O valor lido pela IA ("%s") não é %s e não foi preenchido.',
                $rawValue,
                match ($evidenceType) {
                    PuBaselineEvidenceType::FirstIntegralizationDate => 'uma data válida',
                    PuBaselineEvidenceType::IntegralizedQuantity => 'uma quantidade positiva',
                    PuBaselineEvidenceType::ExternalPuReference => 'um dos status aceitos para o gabarito',
                },
            );
        }

        if ($alternatives !== []) {
            $notes[] = 'Há ocorrências com valores diferentes no documento: '
                .collect($alternatives)
                    ->map(fn (array $alternative): string => $alternative['value'].($alternative['page'] !== null ? " (página {$alternative['page']})" : ''))
                    ->implode('; ')
                .'. O valor não foi preenchido automaticamente.';
        } elseif ($level === GuaranteeEvidenceLevel::Conflicting) {
            $notes[] = 'O documento traz informações divergentes para este valor. O valor não foi preenchido automaticamente.';
        }

        if ($status === self::STATUS_FOUND && $level === GuaranteeEvidenceLevel::Inferred) {
            $notes[] = 'O valor foi interpretado a partir do contexto, não transcrito literalmente.';
        }

        if (! $emissionMentioned) {
            $notes[] = 'O documento não identifica expressamente esta emissão; confirme a correspondência.';
        }

        $text = implode(' ', array_filter($notes));

        return $text === '' ? null : Str::limit($text, 2000, '');
    }

    private function message(string $status): string
    {
        return match ($status) {
            self::STATUS_FOUND => 'Revise os campos preenchidos antes de criar a evidência.',
            self::STATUS_PARTIAL => 'A referência foi localizada, mas o valor não pôde ser validado com segurança. Confira o documento e informe o valor.',
            self::STATUS_NOT_FOUND => 'Não foi possível localizar automaticamente a informação solicitada neste documento.',
            default => 'Não foi possível analisar o documento neste momento. Tente novamente.',
        };
    }

    private function isEdited(string $field, ?string $current, ?string $applied): bool
    {
        if ($current === null) {
            return false;
        }

        return $current !== ($applied ?? self::FORM_DEFAULTS[$field] ?? null);
    }

    private function formValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->isApproved() || ! $actor->can(AccessPermission::PuParametersConfigure->value)) {
            throw new AuthorizationException('Você não possui permissão para analisar documentos de evidência do baseline.');
        }
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $normalized = Str::squish((string) $value);

        return $normalized === '' ? null : Str::limit($normalized, $limit, '');
    }
}
