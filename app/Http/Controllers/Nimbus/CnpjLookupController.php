<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Http\Requests\LookupNimbusCnpjRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CnpjLookupController extends Controller
{
    public function __invoke(LookupNimbusCnpjRequest $request): JsonResponse
    {
        $cnpj = (string) $request->validated('cnpj');

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get("https://publica.cnpj.ws/cnpj/{$cnpj}");
        } catch (\Throwable $exception) {
            Log::warning('Falha ao consultar CNPJ no portal Nimbus.', [
                'cnpj' => $cnpj,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => 'Nao foi possivel consultar o CNPJ agora. Tente novamente em instantes.',
            ], 502);
        }

        if (! $response->successful()) {
            return response()->json([
                'error' => 'Nao foi possivel localizar dados para este CNPJ.',
            ], 422);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return response()->json([
                'error' => 'Nao foi possivel localizar dados para este CNPJ.',
            ], 422);
        }

        $establishment = $payload['estabelecimento'] ?? [];

        return response()->json([
            'data' => [
                'name' => (string) ($payload['razao_social'] ?? ''),
                'main_activity' => (string) data_get($establishment, 'atividade_principal.descricao', ''),
                'phone' => $this->formatPhone(
                    (string) ($establishment['ddd1'] ?? ''),
                    (string) ($establishment['telefone1'] ?? ''),
                ),
                'website' => $this->normalizeWebsite((string) ($establishment['site'] ?? '')),
            ],
        ]);
    }

    private function formatPhone(string $ddd, string $phone): string
    {
        $digits = preg_replace('/\D/', '', $ddd.$phone) ?? '';

        if (strlen($digits) === 10) {
            return preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $digits) ?: $digits;
        }

        if (strlen($digits) === 11) {
            return preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $digits) ?: $digits;
        }

        return $digits;
    }

    private function normalizeWebsite(string $website): string
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
