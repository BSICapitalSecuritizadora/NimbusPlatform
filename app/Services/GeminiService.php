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
  }
}

Regras importantes:
- Copie o texto da cláusula fielmente, incluindo subcláusulas e alíneas quando fizerem parte do mesmo artigo
- Se uma informação estiver distribuída em mais de uma cláusula, inclua todas, separadas por "\n\n"
- Se uma cláusula não for encontrada no documento, retorne: { "clausula": null, "texto": "Não encontrado" }
- Não adicione interpretações ou comentários fora do JSON
- Retorne apenas o JSON, sem texto antes ou depois
PROMPT;

    /** @return array<string, string|null> */
    public function extractSecuritizationClauses(Document $document): array
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

        $response = Http::timeout(180)
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
}
