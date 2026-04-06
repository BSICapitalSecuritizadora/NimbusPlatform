<?php

namespace App\Actions\Nimbus;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LookupNimbusCnpj
{
    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function handle(string $cnpj): array
    {
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get("https://publica.cnpj.ws/cnpj/{$cnpj}");
        } catch (\Throwable $exception) {
            Log::warning('Falha ao consultar CNPJ no portal Nimbus.', [
                'cnpj' => $cnpj,
                'message' => $exception->getMessage(),
            ]);

            return [
                'status' => 502,
                'payload' => [
                    'error' => 'Nao foi possivel consultar o CNPJ agora. Tente novamente em instantes.',
                ],
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => 422,
                'payload' => [
                    'error' => 'Nao foi possivel localizar dados para este CNPJ.',
                ],
            ];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return [
                'status' => 422,
                'payload' => [
                    'error' => 'Nao foi possivel localizar dados para este CNPJ.',
                ],
            ];
        }

        $establishment = $payload['estabelecimento'] ?? [];

        return [
            'status' => 200,
            'payload' => [
                'data' => [
                    'name' => (string) ($payload['razao_social'] ?? ''),
                    'main_activity' => (string) data_get($establishment, 'atividade_principal.descricao', ''),
                    'phone' => $this->formatPhone(
                        (string) ($establishment['ddd1'] ?? ''),
                        (string) ($establishment['telefone1'] ?? ''),
                    ),
                    'website' => $this->normalizeWebsite((string) ($establishment['site'] ?? '')),
                ],
            ],
        ];
    }

    protected function formatPhone(string $ddd, string $phone): string
    {
        $digits = Str::digitsOnly($ddd.$phone);

        if (strlen($digits) === 10) {
            return preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $digits) ?: $digits;
        }

        if (strlen($digits) === 11) {
            return preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $digits) ?: $digits;
        }

        return $digits;
    }

    protected function normalizeWebsite(string $website): string
    {
        if ($website === '') {
            return '';
        }

        if (str_starts_with($website, 'http://') || str_starts_with($website, 'https://')) {
            return $website;
        }

        return 'https://'.$website;
    }
}
