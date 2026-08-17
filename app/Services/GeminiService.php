<?php

namespace App\Services;

use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Models\Document;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

class GeminiService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/';

    private const FILES_UPLOAD_URL = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

    private const DOCUMENT_MIME_TYPE = 'application/pdf';

    /** Intervalo entre consultas ao estado de um arquivo ainda em PROCESSING. */
    private const FILE_POLL_SECONDS = 2;

    /**
     * Respostas em que repetir a chamada tem chance de mudar o resultado.
     *
     * São sinais de indisponibilidade momentânea do lado do modelo — o 503
     * "This model is currently experiencing high demand" é de longe o mais
     * frequente —, não de pedido malformado. Um 400 repetido três vezes volta
     * 400 nas três, e insistir só atrasaria o erro que o operador precisa ver.
     */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** Teto da espera entre tentativas, para que `max_attempts` alto não vire uma pausa de minutos. */
    private const RETRY_MAX_DELAY_MS = 30_000;

    /** Faixa do jitter somado a cada espera. */
    private const RETRY_JITTER_MS = 1_000;

    private const SECURITIZATION_PROMPT = <<<'PROMPT'
Você é um especialista em análise de documentos financeiros brasileiros, especificamente Termos de Securitização de CRI/CRA.

Analise o Termo de Securitização anexado e extraia as seguintes informações. Para cada item, retorne EXATAMENTE o número da cláusula (ex: "Cláusula 5ª") e o texto integral dela, sem resumir ou parafrasear.

Retorne o resultado em JSON com a seguinte estrutura:

{
  "objeto_social": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "destinacao_dos_recursos": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "forma_subscricao_integralizacao_preco": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "repactuacao": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "calendario_pagamento_amortizacao": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "calendario_pagamento_remuneracao": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "resgate_antecipado_facultativo": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "amortizacao_antecipada": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "calculo_remuneracao": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "patrimonio_separado": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "descricao_imovel": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "garantias": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  },
  "covenants": {
    "clausula": "Cláusula Xª – [título]",
    "texto": "[texto integral]"
  }
}

Regras importantes:
- Copie o texto da cláusula fielmente, incluindo subcláusulas e alíneas quando fizerem parte do mesmo artigo
- Se uma informação estiver distribuída em mais de uma cláusula, inclua todas, separadas por "\n\n"
- Para o item "covenants", busque também cláusulas ou seções intituladas "Covenants", "Obrigações", "Obrigações Garantidas" e/ou "Obrigações do Devedor"
- Se uma cláusula não for encontrada no documento, retorne: { "clausula": null, "texto": "Não encontrado" }
- Não adicione interpretações ou comentários fora do JSON
- Retorne apenas o JSON, sem texto antes ou depois
PROMPT;

    private const OBLIGATIONS_PROMPT = <<<'PROMPT'
Você é um especialista em direito do mercado de capitais brasileiro, com foco em operações de securitização (CRI, CRA, Debêntures, Notas Comerciais).

Sua tarefa é extrair as obrigações contratuais previstas no Termo de Securitização anexado.

REGRAS DE FUNDAMENTAÇÃO (CRÍTICAS):
- Extraia SOMENTE o que está explicitamente escrito no documento. Não use conhecimento externo nem inferências.
- Não crie obrigações a partir de definições, conceitos ou da mera descrição de fluxos normais de pagamento.
- `due_rule` deve reproduzir o prazo LITERALMENTE como escrito, vindo da mesma cláusula/parágrafo da obrigação. Se o prazo não estiver na mesma cláusula, defina `due_rule` como null.
- `source_excerpt` deve ser uma citação LITERAL do texto (máximo 300 caracteres) que comprove a obrigação. Não parafraseie.
- Se `responsible_party` ou `required_evidence` não estiverem explícitos, defina como null.
- Não duplique obrigações: se a mesma obrigação aparecer em mais de uma cláusula, extraia apenas uma vez (a versão mais completa).

O QUE É UMA OBRIGAÇÃO VÁLIDA (extraia APENAS se houver):
- uma AÇÃO concreta e acionável a ser realizada por uma parte (ex.: enviar, pagar, manter, constituir, comunicar, comprovar, reforçar, não fazer X);
- uma parte obrigada identificável (responsible_party) OU um destinatário claro do dever;
- e pelo menos um destes: prazo/periodicidade/condição de acionamento, evidência exigida, ou consequência operacional/de acompanhamento.

O QUE NÃO É OBRIGAÇÃO (NÃO extraia — ignore por completo):
- definições, conceitos e glossário;
- descrições genéricas, considerandos e contexto jurídico sem tarefa prática;
- cláusulas meramente informativas ou declaratórias;
- mera descrição do fluxo normal de remuneração/amortização sem um dever de fazer associado;
- repetições de uma obrigação já extraída;
- trechos sem ação prática clara para acompanhar.

Em caso de dúvida sobre ser ou não uma obrigação acionável, NÃO extraia. Prefira uma lista curta, objetiva e confiável a uma lista longa e ruidosa.

CAMPOS:
- title: título conciso em português imperativo (ex.: "Enviar relatório mensal ao Agente Fiduciário").
- obligation_type: tipo específico (ex.: "Relatório Periódico", "Covenant Financeiro", "Comunicação ao Agente Fiduciário").
- obligation_category: escolha EXATAMENTE uma de: "Informacional", "Covenants", "Fundos", "Garantias", "Recebíveis / Lastro", "Obras", "Condições Precedentes", "Assembleia / Waiver", "Vencimento Antecipado", "Patrimônio Separado", "Regulatória", "Financeira / Pagamento", "Outro".
- description: descrição baseada exclusivamente no texto-fonte.
- responsible_party: parte responsável explícita (ex.: "Emissora", "Agente Fiduciário") ou null.
- responsible_area: uma de: "Jurídico", "Gestão", "Emissões", "Financeiro", "Escrituração", "Compliance", "Risco", "Engenharia", "Outro".
- recurrence: uma de: "Única", "Mensal", "Trimestral", "Semestral", "Anual", "Sob demanda", "Outro".
- due_rule: prazo literal do texto-fonte ou null.
- due_date: data fixa no formato YYYY-MM-DD ou null.
- priority: "low", "medium", "high" ou "critical".
- required_evidence: evidência exigida explicitamente ou null.
- source_clause: referência da cláusula (ex.: "Cláusula 8.1.2") ou null.
- source_page: número da página (inteiro) ou null.
- source_excerpt: citação literal do texto (máx. 300 caracteres). OBRIGATÓRIO.
- confidence_score: número entre 0.0 e 1.0 (>=0.80 explícita, 0.60–0.79 inferida, <0.60 incerta).
- review_notes: observações para o revisor (prazos ausentes, ambiguidades) ou null.

Retorne SOMENTE um JSON com a estrutura:

{
  "obligations": [
    {
      "title": "string",
      "obligation_type": "string",
      "obligation_category": "string",
      "description": "string",
      "responsible_party": "string|null",
      "responsible_area": "string",
      "recurrence": "string",
      "due_rule": "string|null",
      "due_date": "YYYY-MM-DD|null",
      "priority": "low|medium|high|critical",
      "required_evidence": "string|null",
      "source_clause": "string|null",
      "source_page": 0,
      "source_excerpt": "string",
      "confidence_score": 0.0,
      "review_notes": "string|null"
    }
  ]
}

Se não houver obrigações no documento, retorne: {"obligations": []}
Não adicione texto antes ou depois do JSON.
PROMPT;

    private const GUARANTEES_PROMPT = <<<'PROMPT'
Você é um especialista em direito do mercado de capitais brasileiro, com foco nas garantias de operações de securitização (CRI, CRA, CR).

Sua tarefa é identificar as GARANTIAS previstas no documento anexado, que pode ser um Termo de Securitização, um aditamento, um contrato de alienação/cessão fiduciária, um instrumento de reforço, substituição ou liberação de garantia, ou outro instrumento da operação.

REGRAS DE FUNDAMENTAÇÃO (CRÍTICAS — a violação invalida a extração):
- Extraia SOMENTE o que está escrito no documento. Nunca use conhecimento externo.
- NUNCA invente matrícula, valor, percentual, CNPJ, conta, data, cláusula ou página. Se o dado não estiver no documento, use null.
- Ausência de informação JAMAIS vira zero. Um valor não localizado é null, não 0.
- `source_excerpt` deve ser citação LITERAL do documento (máx. 400 caracteres) que comprove a garantia. Não parafraseie.
- Para cada campo preenchido, classifique em `field_evidence` como:
  - "explicit": o documento afirma o dado literalmente;
  - "inferred": você deduziu a partir da relação entre cláusulas;
  - "not_found": não consta.
- Prefira uma lista curta e confiável a uma lista longa e ruidosa. Na dúvida, não extraia.

TIPO DO EVENTO (`event_type`) — o que o documento faz com a garantia:
- "constitution": constitui a garantia ("Fica constituída...", "Passa a integrar a garantia...", "Fica incluída...")
- "amendment": altera condição existente ("Passa a vigorar com a seguinte redação...", "O percentual mínimo passa de X para Y...")
- "reinforcement": reforça/adiciona bem à garantia existente
- "substitution": substitui bem ou garantia ("Fica substituído...", "A matrícula X é substituída pela matrícula Y...")
- "release": libera a garantia ("A garantia prevista na cláusula X será liberada...", "Fica excluída...")
Se o documento for aditamento e a cláusula alterar garantia já existente, use "amendment", "substitution" ou "release" — NÃO use "constitution".

TIPOS DE GARANTIA (`type`) — use EXATAMENTE um destes valores:
af_imovel, af_quotas, cf_recebiveis, cf_direitos_creditorios, promessa_cessao_fiduciaria, hipoteca, penhor, aval, fianca, fundo_reserva, fundo_juros, fundo_obras, conta_reserva, conta_vinculada, recebiveis, estoque, unidades, carta_fianca, seguro_garantia, aplicacao_financeira, outra

IDENTIFICAÇÃO (`identification`) — objeto com as chaves aplicáveis ao tipo, apenas com o que constar:
- Imóvel: registration_number (matrícula), registry_office (cartório), city, state, owner, construction, unit, area
- Quotas: company, tax_id (CNPJ), quota_quantity, pledged_percentage, nominal_value, grantor
- Recebíveis: portfolio, construction, contracts, assigned_percentage, receiving_account, eligibility_criteria
- Fundos/contas: fund_type, bank, agency, account, composition_rule
- Outros: identification

REGRA CONTRATUAL DO MÍNIMO EXIGIDO:
- `requirement_basis`: "absolute" (valor fixo em R$), "percentage" (percentual sobre uma base), "formula" (contagem, ex.: 3 PMTs) ou "none" (o documento não fixa mínimo).
- `requirement_value`: valor absoluto em número, quando basis = absolute.
- `requirement_percentage`: fração decimal (120% => 1.2), quando basis = percentage.
- `requirement_base`: "outstanding_balance" (saldo devedor), "issued_volume", "integralized_value", "next_installments", "interest_months" ou "custom".
- `requirement_multiplier`: número, quando basis = formula (ex.: 3 para "3 próximas PMTs").
- `requirement_formula`: o texto literal da regra contratual, sempre que houver.

DEMAIS CAMPOS:
- name: nome curto da garantia (ex.: "Alienação Fiduciária de Imóvel — Matrícula 45.721").
- description: descrição baseada exclusivamente no texto-fonte.
- contracted_value: valor da garantia na contratação, em número, ou null.
- documentary_value: valor nominal/documental expresso no contrato, ou null.
- validity_start_date / validity_end_date / effective_date: "YYYY-MM-DD" ou null. effective_date é a data a partir da qual o evento produz efeito.
- source_clause: referência da cláusula (ex.: "8.3.1") ou null.
- source_page: número inteiro da página ou null.
- confidence_score: número entre 0.0 e 1.0 para a extração como um todo.
- field_confidences: objeto opcional com confiança por campo (0.0 a 1.0).
- review_notes: observações para o revisor (ambiguidades, dados ausentes) ou null.

Retorne SOMENTE um JSON com esta estrutura:

{
  "guarantees": [
    {
      "event_type": "constitution",
      "type": "af_imovel",
      "name": "string",
      "description": "string|null",
      "identification": {},
      "contracted_value": 0,
      "documentary_value": null,
      "requirement_basis": "none",
      "requirement_value": null,
      "requirement_percentage": null,
      "requirement_base": null,
      "requirement_multiplier": null,
      "requirement_formula": null,
      "validity_start_date": null,
      "validity_end_date": null,
      "effective_date": null,
      "source_clause": "string|null",
      "source_page": 0,
      "source_excerpt": "string",
      "confidence_score": 0.0,
      "field_evidence": {},
      "field_confidences": {},
      "review_notes": null
    }
  ]
}

Se o documento não previr garantias, retorne: {"guarantees": []}
Não adicione texto antes ou depois do JSON.
PROMPT;

    /**
     * Identifica as garantias previstas num documento jurídico da operação.
     *
     * O retorno é proposta de cadastro, nunca garantia oficial: cada item ainda
     * passa por revisão humana antes de existir como garantia da emissão (§4 do
     * escopo do módulo).
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractGuarantees(Document $document): array
    {
        $json = $this->generateFromDocument(self::GUARANTEES_PROMPT, $document);

        $guarantees = $json['guarantees'] ?? [];

        if (! is_array($guarantees)) {
            return [];
        }

        $normalized = array_values(array_filter(array_map(
            fn (mixed $item): ?array => is_array($item) ? $this->normalizeGuaranteeProposal($item) : null,
            $guarantees,
        )));

        Log::info('GeminiService: garantias extraídas', [
            'document_id' => $document->id,
            'detected' => count($normalized),
        ]);

        return $normalized;
    }

    /**
     * Normaliza uma proposta de garantia, descartando o que não é utilizável.
     *
     * Toda conversão numérica passa por {@see self::nullableNumber()}: a string
     * vazia e o texto não numérico viram null, nunca zero — a diferença entre
     * "o contrato não fixa valor" e "o contrato fixa zero" é o que impede uma
     * garantia sem valor declarado de entrar como garantia sem valor nenhum.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalizeGuaranteeProposal(array $item): ?array
    {
        $name = trim((string) ($item['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $excerpt = $this->nullableString($item['source_excerpt'] ?? null, 2000);

        // Sem trecho literal não há como um revisor conferir a extração contra
        // o documento, e uma garantia sem comprovação não deve ser proposta.
        if ($excerpt === null) {
            return null;
        }

        $confidence = $item['confidence_score'] ?? null;
        $confidence = is_numeric($confidence) ? max(0, min(1, (float) $confidence)) : null;

        return [
            'event_type' => $this->enumValue($item['event_type'] ?? null, GuaranteeEventType::class, GuaranteeEventType::Constitution->value),
            'type' => $this->enumValue($item['type'] ?? null, GuaranteeType::class, null),
            'name' => mb_substr($name, 0, 255),
            'description' => $this->nullableString($item['description'] ?? null),
            'identification' => is_array($item['identification'] ?? null) ? $item['identification'] : null,
            'contracted_value' => $this->nullableNumber($item['contracted_value'] ?? null),
            'documentary_value' => $this->nullableNumber($item['documentary_value'] ?? null),
            'requirement_basis' => $this->enumValue($item['requirement_basis'] ?? null, GuaranteeRequirementBasis::class, GuaranteeRequirementBasis::None->value),
            'requirement_value' => $this->nullableNumber($item['requirement_value'] ?? null),
            'requirement_percentage' => $this->nullableNumber($item['requirement_percentage'] ?? null),
            'requirement_base' => $this->enumValue($item['requirement_base'] ?? null, GuaranteeRequirementBase::class, null),
            'requirement_multiplier' => $this->nullableNumber($item['requirement_multiplier'] ?? null),
            'requirement_formula' => $this->nullableString($item['requirement_formula'] ?? null),
            'validity_start_date' => $this->nullableDate($item['validity_start_date'] ?? null),
            'validity_end_date' => $this->nullableDate($item['validity_end_date'] ?? null),
            'effective_date' => $this->nullableDate($item['effective_date'] ?? null),
            'source_clause' => $this->nullableString($item['source_clause'] ?? null, 255),
            'source_page' => is_numeric($item['source_page'] ?? null) ? (int) $item['source_page'] : null,
            'source_excerpt' => $excerpt,
            'confidence_score' => $confidence,
            'field_evidence' => $this->normalizeFieldEvidence($item['field_evidence'] ?? null),
            'field_confidences' => is_array($item['field_confidences'] ?? null) ? $item['field_confidences'] : null,
            'review_notes' => $this->nullableString($item['review_notes'] ?? null),
        ];
    }

    /**
     * Mantém em `field_evidence` apenas classificações reconhecidas. Um rótulo
     * inventado pelo modelo seria lido como "não localizada" na revisão, o que
     * é mais seguro do que aceitá-lo como evidência válida.
     *
     * @return array<string, string>|null
     */
    private function normalizeFieldEvidence(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = [];

        foreach ($value as $field => $level) {
            if (! is_string($field) || ! is_string($level)) {
                continue;
            }

            if (GuaranteeEvidenceLevel::tryFrom($level) === null) {
                continue;
            }

            $normalized[$field] = $level;
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  class-string<\BackedEnum>  $enumClass
     */
    private function enumValue(mixed $value, string $enumClass, ?string $default): ?string
    {
        if (! is_string($value)) {
            return $default;
        }

        return $enumClass::tryFrom(trim($value))?->value ?? $default;
    }

    private function nullableNumber(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }

    /** @return array<string, string|null> */
    public function extractSecuritizationClauses(Document $document): array
    {
        return $this->mapToFormFields(
            $this->generateFromDocument(self::SECURITIZATION_PROMPT, $document),
        );
    }

    /** @return array<string, string|null> */
    private function mapToFormFields(array $json): array
    {
        $map = [
            'objeto_social' => 'corporate_purpose',
            'destinacao_dos_recursos' => 'use_of_proceeds',
            'forma_subscricao_integralizacao_preco' => 'subscription_and_integralization_terms',
            'repactuacao' => 'repactuation',
            'calendario_pagamento_amortizacao' => 'amortization_payment_schedule',
            'calendario_pagamento_remuneracao' => 'remuneration_payment_schedule',
            'resgate_antecipado_facultativo' => 'optional_early_redemption',
            'amortizacao_antecipada' => 'early_amortization',
            'calculo_remuneracao' => 'remuneration_calculation',
            'patrimonio_separado' => 'segregated_estate',
            'descricao_imovel' => 'property_description',
            'garantias' => 'guarantees_description',
            'covenants' => 'covenants',
        ];

        $result = [];

        foreach ($map as $jsonKey => $fieldKey) {
            $item = $json[$jsonKey] ?? null;

            if (! $item || ($item['texto'] ?? '') === 'Não encontrado') {
                $result[$fieldKey] = null;

                continue;
            }

            $clause = $item['clausula'] ?? null;
            $text = $item['texto'] ?? null;

            $result[$fieldKey] = filled($clause) ? "{$clause}\n\n{$text}" : $text;
        }

        return $result;
    }

    /**
     * Extract contractual obligations from the securitization term document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractObligations(Document $document): array
    {
        $json = $this->generateFromDocument(self::OBLIGATIONS_PROMPT, $document);

        $obligations = $json['obligations'] ?? [];

        if (! is_array($obligations)) {
            return [];
        }

        $normalized = array_values(array_filter(array_map(
            fn (mixed $item): ?array => is_array($item) ? $this->normalizeObligationProposal($item) : null,
            $obligations,
        )));

        $rawCount = count($normalized);

        [$confident, $weak] = $this->partitionByConfidence($normalized);

        [$deduped, $duplicates] = $this->dedupeProposals($confident);

        Log::info('GeminiService: obrigações extraídas e filtradas', [
            'document_id' => $document->id,
            'raw' => $rawCount,
            'discarded_low_confidence' => count($weak),
            'discarded_duplicates' => count($duplicates),
            'kept' => count($deduped),
            'min_confidence' => $this->obligationsMinConfidence(),
            'low_confidence_titles' => array_map(static fn (array $i): ?string => $i['title'] ?? null, $weak),
            'duplicate_titles' => array_map(static fn (array $i): ?string => $i['title'] ?? null, $duplicates),
        ]);

        return $deduped;
    }

    private function obligationsMinConfidence(): float
    {
        return (float) config('services.gemini.obligations_min_confidence', 0.6);
    }

    /**
     * Split proposals into those meeting the minimum confidence and the weak ones.
     * A null confidence_score is treated as weak (unverifiable strength).
     *
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function partitionByConfidence(array $proposals): array
    {
        $min = $this->obligationsMinConfidence();
        $confident = [];
        $weak = [];

        foreach ($proposals as $proposal) {
            $score = $proposal['confidence_score'] ?? null;

            if (is_numeric($score) && (float) $score >= $min) {
                $confident[] = $proposal;
            } else {
                $weak[] = $proposal;
            }
        }

        return [$confident, $weak];
    }

    /**
     * Collapse near-duplicate obligations, keeping the most complete version of each.
     *
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function dedupeProposals(array $proposals): array
    {
        /** @var array<int, array<string, mixed>> $kept */
        $kept = [];
        $duplicates = [];

        foreach ($proposals as $proposal) {
            $matchIndex = null;

            foreach ($kept as $index => $existing) {
                if ($this->areObligationsSimilar($existing, $proposal)) {
                    $matchIndex = $index;

                    break;
                }
            }

            if ($matchIndex === null) {
                $kept[] = $proposal;

                continue;
            }

            if ($this->obligationCompleteness($proposal) > $this->obligationCompleteness($kept[$matchIndex])) {
                $duplicates[] = $kept[$matchIndex];
                $kept[$matchIndex] = $proposal;
            } else {
                $duplicates[] = $proposal;
            }
        }

        return [array_values($kept), $duplicates];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function areObligationsSimilar(array $a, array $b): bool
    {
        $titleA = $this->normalizeForComparison((string) ($a['title'] ?? ''));
        $titleB = $this->normalizeForComparison((string) ($b['title'] ?? ''));

        if ($titleA === '' || $titleB === '') {
            return false;
        }

        $clauseA = $this->normalizeForComparison((string) ($a['source_clause'] ?? ''));
        $clauseB = $this->normalizeForComparison((string) ($b['source_clause'] ?? ''));

        if ($titleA === $titleB && ($clauseA === $clauseB || $clauseA === '' || $clauseB === '')) {
            return true;
        }

        $sameParty = $this->normalizeForComparison((string) ($a['responsible_party'] ?? ''))
            === $this->normalizeForComparison((string) ($b['responsible_party'] ?? ''));
        $sameRecurrence = $this->normalizeForComparison((string) ($a['recurrence'] ?? ''))
            === $this->normalizeForComparison((string) ($b['recurrence'] ?? ''));

        if (! $sameParty || ! $sameRecurrence) {
            return false;
        }

        similar_text($titleA, $titleB, $percent);

        return $percent >= 88.0;
    }

    /**
     * Higher is more complete; used to pick which of two duplicates to keep.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function obligationCompleteness(array $proposal): float
    {
        $score = is_numeric($proposal['confidence_score'] ?? null) ? (float) $proposal['confidence_score'] : 0.0;
        $filledFields = 0;

        foreach (['description', 'due_rule', 'responsible_party', 'required_evidence', 'source_clause', 'source_excerpt'] as $field) {
            if (filled($proposal[$field] ?? null)) {
                $filledFields++;
            }
        }

        $descriptionLength = mb_strlen((string) ($proposal['description'] ?? ''));

        return ($score * 100) + ($filledFields * 10) + min($descriptionLength / 100, 10);
    }

    private function normalizeForComparison(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value);

        return (string) preg_replace('/\s+/u', ' ', $value);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalizeObligationProposal(array $item): ?array
    {
        $title = trim((string) ($item['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $priority = (string) ($item['priority'] ?? 'medium');
        $priority = in_array($priority, ['low', 'medium', 'high', 'critical'], true) ? $priority : 'medium';

        $dueDate = $item['due_date'] ?? null;
        $dueDate = (is_string($dueDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) ? $dueDate : null;

        $confidence = $item['confidence_score'] ?? null;
        $confidence = is_numeric($confidence) ? max(0, min(1, (float) $confidence)) : null;

        return [
            'title' => mb_substr($title, 0, 255),
            'obligation_type' => $this->nullableString($item['obligation_type'] ?? null, 255),
            'obligation_category' => $this->nullableString($item['obligation_category'] ?? null, 255),
            'description' => $this->nullableString($item['description'] ?? null),
            'responsible_party' => $this->nullableString($item['responsible_party'] ?? null, 255),
            'responsible_area' => $this->nullableString($item['responsible_area'] ?? null, 255),
            'recurrence' => $this->nullableString($item['recurrence'] ?? null, 255),
            'due_rule' => $this->nullableString($item['due_rule'] ?? null),
            'due_date' => $dueDate,
            'priority' => $priority,
            'required_evidence' => $this->nullableString($item['required_evidence'] ?? null),
            'source_clause' => $this->nullableString($item['source_clause'] ?? null),
            'source_page' => is_numeric($item['source_page'] ?? null) ? (int) $item['source_page'] : null,
            'source_excerpt' => $this->nullableString($item['source_excerpt'] ?? null),
            'confidence_score' => $confidence,
            'review_notes' => $this->nullableString($item['review_notes'] ?? null),
        ];
    }

    private function nullableString(mixed $value, ?int $maxLength = null): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $maxLength === null ? $value : mb_substr($value, 0, $maxLength);
    }

    /**
     * Envia o documento com um prompt e devolve o JSON decodificado da resposta.
     *
     * Documentos pequenos vão embutidos na própria requisição; acima de
     * `inline_max_bytes` a requisição estouraria o limite de tamanho da API, e o
     * arquivo sobe antes pela File API. O upload é sempre apagado ao final: a
     * File API retém o arquivo por 48h e, tratando-se de documento de operação,
     * a cópia no processador não pode sobreviver à chamada que a justificou.
     *
     * @return array<string, mixed>
     */
    private function generateFromDocument(string $prompt, Document $document): array
    {
        $contents = $this->readDocumentContents($document);
        $uploadedFileName = null;

        try {
            if (strlen($contents) > $this->inlineMaxBytes()) {
                $file = $this->uploadDocument($contents, $document);
                $uploadedFileName = $file['name'];
                $documentPart = ['file_data' => [
                    'mime_type' => self::DOCUMENT_MIME_TYPE,
                    'file_uri' => $file['uri'],
                ]];
            } else {
                $documentPart = ['inline_data' => [
                    'mime_type' => self::DOCUMENT_MIME_TYPE,
                    'data' => base64_encode($contents),
                ]];
            }

            $response = $this->pendingRequest()
                ->post($this->generateContentUrl(), [
                    'contents' => [[
                        'parts' => [
                            ['text' => $prompt],
                            $documentPart,
                        ],
                    ]],
                    'generationConfig' => [
                        'response_mime_type' => 'application/json',
                    ],
                ]);

            $response->throw();

            $decoded = json_decode($this->firstAnswerText($response->json()) ?? '{}', true);

            return is_array($decoded) ? $decoded : [];
        } finally {
            if ($uploadedFileName !== null) {
                $this->deleteUploadedFile($uploadedFileName);
            }
        }
    }

    /**
     * Primeiro trecho de texto que seja resposta, e não raciocínio.
     *
     * Os modelos da linha 3 raciocinam por padrão e podem emitir partes com
     * `thought: true` antes da resposta. Ler `parts.0.text` cegamente pegaria o
     * raciocínio, o `json_decode` falharia e cada campo viraria null — falha
     * silenciosa, já que o chamador trata ausência de chave como "não
     * encontrado" em vez de erro.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function firstAnswerText(?array $payload): ?string
    {
        /** @var array<int, array<string, mixed>> $parts */
        $parts = data_get($payload, 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (($part['thought'] ?? false) === true) {
                continue;
            }

            $text = $part['text'] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Sobe o documento pela File API usando o protocolo resumível e devolve o
     * arquivo já em estado ACTIVE.
     *
     * O `display_name` carrega só o id do documento: o nome original do arquivo
     * costuma trazer razão social e número de operação, e não há motivo para
     * replicar isso nos metadados do processador.
     *
     * @return array{name: string, uri: string}
     */
    private function uploadDocument(string $contents, Document $document): array
    {
        $size = strlen($contents);

        $start = $this->pendingRequest()
            ->withHeaders([
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $size,
                'X-Goog-Upload-Header-Content-Type' => self::DOCUMENT_MIME_TYPE,
            ])
            ->post(self::FILES_UPLOAD_URL, [
                'file' => ['display_name' => "document-{$document->id}"],
            ]);

        $start->throw();

        $uploadUrl = $start->header('x-goog-upload-url');

        if ($uploadUrl === '') {
            throw new \RuntimeException('A File API não devolveu a URL de upload (cabeçalho x-goog-upload-url ausente).');
        }

        $upload = $this->pendingRequest()
            ->withHeaders([
                'Content-Length' => (string) $size,
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])
            ->withBody($contents, self::DOCUMENT_MIME_TYPE)
            ->post($uploadUrl);

        $upload->throw();

        /** @var array<string, mixed> $file */
        $file = $upload->json('file') ?? [];

        return $this->awaitActiveFile($file, $document);
    }

    /**
     * Aguarda o arquivo sair de PROCESSING. Um PDF grande não fica utilizável
     * imediatamente após o upload, e referenciá-lo antes da hora faz o
     * `generateContent` falhar com 400.
     *
     * @param  array<string, mixed>  $file
     * @return array{name: string, uri: string}
     */
    private function awaitActiveFile(array $file, Document $document): array
    {
        $name = (string) ($file['name'] ?? '');

        if ($name === '') {
            throw new \RuntimeException('A File API não devolveu o identificador do arquivo enviado.');
        }

        $deadline = microtime(true) + $this->fileActivationTimeout();

        while (($file['state'] ?? '') === 'PROCESSING') {
            if (microtime(true) >= $deadline) {
                $this->deleteUploadedFile($name);

                throw new \RuntimeException(
                    "O documento {$document->id} continuou em processamento na File API além de {$this->fileActivationTimeout()}s."
                );
            }

            Sleep::for(self::FILE_POLL_SECONDS)->seconds();

            $poll = $this->pendingRequest()->get(self::BASE_URL.$name);
            $poll->throw();

            /** @var array<string, mixed> $file */
            $file = $poll->json() ?? [];
        }

        $state = (string) ($file['state'] ?? '');

        if ($state !== 'ACTIVE') {
            $this->deleteUploadedFile($name);

            throw new \RuntimeException("A File API devolveu o arquivo em estado '{$state}' para o documento {$document->id}.");
        }

        $uri = (string) ($file['uri'] ?? '');

        if ($uri === '') {
            $this->deleteUploadedFile($name);

            throw new \RuntimeException('A File API não devolveu a URI do arquivo enviado.');
        }

        return ['name' => $name, 'uri' => $uri];
    }

    /**
     * Remove a cópia do documento no processador. A falha é registrada mas não
     * propagada: o arquivo expira sozinho em 48h e derrubar aqui descartaria uma
     * extração que já foi concluída com sucesso.
     */
    private function deleteUploadedFile(string $name): void
    {
        try {
            $this->pendingRequest()->delete(self::BASE_URL.$name)->throw();
        } catch (\Throwable $e) {
            Log::warning('GeminiService: falha ao remover arquivo da File API', [
                'file' => $name,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function generateContentUrl(): string
    {
        return self::BASE_URL.'models/'.$this->model().':generateContent';
    }

    private function model(): string
    {
        return (string) config('services.gemini.model', 'gemini-3.7-flash');
    }

    private function inlineMaxBytes(): int
    {
        return (int) config('services.gemini.inline_max_bytes', 12 * 1024 * 1024);
    }

    private function fileActivationTimeout(): int
    {
        return (int) config('services.gemini.file_activation_timeout', 120);
    }

    /**
     * Cliente HTTP compartilhado pelas chamadas ao Gemini.
     *
     * A chave vai no cabeçalho `x-goog-api-key`, não na query string: uma chave
     * em `?key=` é registrada por proxies reversos, telemetria de saída e pelo
     * histórico do próprio cliente HTTP — inclusive em `Http::recorded()`, que
     * os testes inspecionam.
     *
     * ATENÇÃO (LGPD): os métodos que usam este cliente enviam o documento
     * integral para um processador fora do país — embutido na requisição ou,
     * acima de `inline_max_bytes`, pela File API, que o retém por até 48h (por
     * isso `generateFromDocument` sempre apaga o upload ao final). Só devem ser
     * disparados por ação explícita do usuário sobre documentos cuja
     * transferência internacional esteja registrada no inventário de
     * tratamento — nunca de forma automática.
     *
     * O retry cobre só as respostas de `RETRYABLE_STATUSES`, e deliberadamente
     * não cobre erro de conexão: o timeout de leitura é de 360s e o de conexão
     * de 15s, mas os dois chegam aqui como o mesmo cURL 28, indistinguíveis. Ao
     * repetir, o pior caso de uma leitura estourada passaria de 360s para mais
     * de 18 minutos e atravessaria o `--timeout` do worker, trocando um erro
     * legível por um job morto no meio. Como o que derruba a geração na prática
     * é o 503 de sobrecarga — que volta em segundos —, limitar às respostas com
     * status mantém o pior caso previsível.
     */
    private function pendingRequest(): PendingRequest
    {
        return Http::timeout(360)
            ->connectTimeout(15)
            ->retry(
                $this->maxAttempts(),
                fn (int $attempt, \Throwable $exception): int => $this->retryDelayMilliseconds($attempt, $exception),
                fn (\Throwable $exception): bool => $exception instanceof RequestException
                    && in_array($exception->response->status(), self::RETRYABLE_STATUSES, true),
            )
            ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')]);
    }

    /**
     * Espera antes da próxima tentativa: exponencial sobre a base configurada,
     * com jitter.
     *
     * O jitter não é enfeite. Uma emissão dispara extração de cláusulas,
     * garantias e obrigações sobre o mesmo Termo, e uma sobrecarga do modelo
     * derruba as três quase no mesmo instante; sem o desvio aleatório, as
     * tentativas voltariam sincronizadas e recriariam o pico que causou o 503.
     *
     * O log aqui é o que torna o retry observável: esta closure só roda quando
     * uma nova tentativa vai de fato acontecer, então a contagem de warnings é
     * a contagem de repetições.
     */
    private function retryDelayMilliseconds(int $attempt, \Throwable $exception): int
    {
        $delay = min(
            $this->retryBaseSeconds() * 1000 * (2 ** ($attempt - 1)),
            self::RETRY_MAX_DELAY_MS,
        ) + random_int(0, self::RETRY_JITTER_MS);

        Log::warning('GeminiService: resposta transitória, repetindo a chamada', [
            'attempt' => $attempt,
            'status' => $exception instanceof RequestException ? $exception->response->status() : null,
            'delay_ms' => $delay,
        ]);

        return (int) $delay;
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('services.gemini.max_attempts', 3));
    }

    private function retryBaseSeconds(): int
    {
        return max(1, (int) config('services.gemini.retry_base_seconds', 2));
    }

    private function readDocumentContents(Document $document): string
    {
        $disk = $document->resolved_storage_disk;
        $path = $document->file_path;

        if (! Storage::disk($disk)->exists($path)) {
            $defaultDisk = config('filesystems.default', 'local');

            if ($defaultDisk !== $disk && Storage::disk($defaultDisk)->exists($path)) {
                $disk = $defaultDisk;
            } else {
                throw new \RuntimeException("Arquivo não encontrado: {$path} (discos verificados: {$disk}, {$defaultDisk})");
            }
        }

        $contents = Storage::disk($disk)->get($path);

        if (empty($contents)) {
            throw new \RuntimeException("Arquivo vazio no disco '{$disk}': {$path}");
        }

        return $contents;
    }
}
