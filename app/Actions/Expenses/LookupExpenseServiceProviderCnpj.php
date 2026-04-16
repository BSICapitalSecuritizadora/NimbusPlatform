<?php

namespace App\Actions\Expenses;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LookupExpenseServiceProviderCnpj
{
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

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get("https://publica.cnpj.ws/cnpj/{$cnpj}");
        } catch (\Throwable $exception) {
            Log::warning('Falha ao consultar CNPJ para prestador de serviço.', [
                'cnpj' => $cnpj,
                'message' => $exception->getMessage(),
            ]);

            return [
                'status' => 502,
                'payload' => [
                    'error' => 'Não foi possível consultar o CNPJ agora. Você pode preencher o nome manualmente.',
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

        return [
            'status' => 200,
            'payload' => [
                'data' => [
                    'cnpj' => $cnpj,
                    'name' => $name,
                    'official_name' => $legalName,
                ],
            ],
        ];
    }
}
