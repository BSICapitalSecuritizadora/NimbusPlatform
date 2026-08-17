<?php

namespace App\Actions\Proposals;

use App\Services\Security\PiiPseudonymizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FetchAddressFromCep
{
    /**
     * @return array{address: ?array{street:string,neighborhood:string,city:string,state:string}, message: ?string}
     */
    public function handle(string $postalCode): array
    {
        $postalCode = preg_replace('/\D+/', '', $postalCode) ?? '';

        if (strlen($postalCode) !== 8) {
            return $this->failure('Informe um CEP válido para realizar a busca.');
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(6)
                ->acceptJson()
                ->get("https://viacep.com.br/ws/{$postalCode}/json/");
        } catch (Throwable $exception) {
            $this->logFailure($postalCode, 'connection_error', $exception::class);

            return $this->failure('Não foi possível consultar o CEP agora. Continue preenchendo o endereço manualmente.');
        }

        if (! $response->successful()) {
            $this->logFailure($postalCode, 'http_error', (string) $response->status());

            return $this->failure('Não foi possível consultar o CEP agora. Continue preenchendo o endereço manualmente.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->logFailure($postalCode, 'unexpected_payload');

            return $this->failure('O serviço de CEP retornou uma resposta inesperada. Preencha o endereço manualmente.');
        }

        if (($payload['erro'] ?? false) === true || ($payload['erro'] ?? null) === 'true') {
            return $this->failure('CEP não localizado. Confira o número ou preencha o endereço manualmente.');
        }

        return [
            'address' => [
                'street' => (string) ($payload['logradouro'] ?? ''),
                'neighborhood' => (string) ($payload['bairro'] ?? ''),
                'city' => (string) ($payload['localidade'] ?? ''),
                'state' => Str::upper((string) ($payload['uf'] ?? '')),
            ],
            'message' => null,
        ];
    }

    /** @return array{address:null,message:string} */
    private function failure(string $message): array
    {
        return ['address' => null, 'message' => $message];
    }

    private function logFailure(string $postalCode, string $reason, ?string $detail = null): void
    {
        Log::warning('Falha ao consultar endereço pelo CEP.', array_filter([
            'postal_code_hash' => PiiPseudonymizer::document($postalCode),
            'reason' => $reason,
            'detail' => $detail,
        ]));
    }
}
