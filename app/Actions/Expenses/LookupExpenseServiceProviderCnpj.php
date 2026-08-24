<?php

namespace App\Actions\Expenses;

use App\Services\Security\PiiPseudonymizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LookupExpenseServiceProviderCnpj
{
    /**
     * The public registry allows only a handful of queries per minute, so every
     * resolved CNPJ is cached. Registry names change rarely -- the upstream data
     * itself is refreshed monthly -- and the cache is what keeps the form usable
     * across the several fields that consult it.
     */
    private const CACHE_PREFIX = 'cnpj-lookup:';

    private const CACHE_TTL_DAYS = 30;

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function handle(string $cnpj): array
    {
        $cnpj = Str::digitsOnly($cnpj);

        if (strlen($cnpj) !== 14) {
            return [
                'status' => 422,
                'payload' => [
                    'error' => 'Informe um CNPJ válido com 14 dígitos.',
                ],
            ];
        }

        $cached = Cache::get(self::CACHE_PREFIX.$cnpj);

        if (is_array($cached)) {
            return [
                'status' => 200,
                'payload' => [
                    'data' => $cached,
                ],
            ];
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get("https://publica.cnpj.ws/cnpj/{$cnpj}");
        } catch (\Throwable $exception) {
            Log::warning('Falha ao consultar CNPJ para prestador de serviço.', [
                'cnpj_hash' => PiiPseudonymizer::document($cnpj),
                'message' => $exception->getMessage(),
            ]);

            return [
                'status' => 502,
                'payload' => [
                    'error' => 'Não foi possível consultar o CNPJ agora. Você pode preencher o nome manualmente.',
                ],
            ];
        }

        if ($response->status() === 429) {
            Log::warning('Limite de consultas públicas de CNPJ excedido.', [
                'cnpj_hash' => PiiPseudonymizer::document($cnpj),
                'details' => (string) data_get($response->json(), 'detalhes', ''),
            ]);

            return [
                'status' => 429,
                'payload' => [
                    'error' => 'Limite de consultas públicas de CNPJ excedido (3 por minuto). Aguarde alguns instantes e tente novamente, ou preencha o nome manualmente.',
                ],
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => 422,
                'payload' => [
                    'error' => 'Não foi possível localizar dados para este CNPJ.',
                ],
            ];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return [
                'status' => 422,
                'payload' => [
                    'error' => 'Não foi possível localizar dados para este CNPJ.',
                ],
            ];
        }

        $tradeName = trim((string) data_get($payload, 'estabelecimento.nome_fantasia', ''));
        $legalName = trim((string) ($payload['razao_social'] ?? ''));
        $name = $tradeName !== '' ? $tradeName : $legalName;

        if ($name === '') {
            return [
                'status' => 422,
                'payload' => [
                    'error' => 'O CNPJ foi localizado, mas não retornou um nome utilizável.',
                ],
            ];
        }

        $data = [
            'cnpj' => $cnpj,
            'name' => $name,
            'official_name' => $legalName,
            'trade_name' => $tradeName !== '' ? $tradeName : null,
        ];

        Cache::put(self::CACHE_PREFIX.$cnpj, $data, now()->addDays(self::CACHE_TTL_DAYS));

        return [
            'status' => 200,
            'payload' => [
                'data' => $data,
            ],
        ];
    }
}
