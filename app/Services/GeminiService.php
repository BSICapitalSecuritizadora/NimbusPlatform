<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GeminiService
{
    private const MODEL = 'gemini-2.5-flash';

    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

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
- Não duplique obrigações: se a mesma obrigação aparecer em mais de uma cláusula, extraia apenas uma vez.

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

    /** @return array<string, string|null> */
    public function extractSecuritizationClauses(Document $document): array
    {
        $contents = $this->readDocumentContents($document);

        $response = Http::timeout(360)->connectTimeout(15)
            ->post(self::API_URL.self::MODEL.':generateContent?key='.config('services.gemini.key'), [
                'contents' => [[
                    'parts' => [
                        ['text' => self::SECURITIZATION_PROMPT],
                        ['inline_data' => [
                            'mime_type' => 'application/pdf',
                            'data' => base64_encode($contents),
                        ]],
                    ],
                ]],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                ],
            ]);

        $response->throw();

        $json = json_decode(
            $response->json('candidates.0.content.parts.0.text') ?? '{}',
            true,
        );

        return $this->mapToFormFields($json ?? []);
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
        $contents = $this->readDocumentContents($document);

        $response = Http::timeout(360)->connectTimeout(15)
            ->post(self::API_URL.self::MODEL.':generateContent?key='.config('services.gemini.key'), [
                'contents' => [[
                    'parts' => [
                        ['text' => self::OBLIGATIONS_PROMPT],
                        ['inline_data' => [
                            'mime_type' => 'application/pdf',
                            'data' => base64_encode($contents),
                        ]],
                    ],
                ]],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                ],
            ]);

        $response->throw();

        $json = json_decode(
            $response->json('candidates.0.content.parts.0.text') ?? '{}',
            true,
        );

        $obligations = $json['obligations'] ?? [];

        if (! is_array($obligations)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?array => is_array($item) ? $this->normalizeObligationProposal($item) : null,
            $obligations,
        )));
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
