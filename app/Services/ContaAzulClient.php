<?php

namespace App\Services;

use App\Models\ContaAzulToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ContaAzulClient
{
    private function tokenUrl(): string
    {
        return config('conta-azul.token_url');
    }

    private function baseUrl(): string
    {
        return config('conta-azul.base_url');
    }

    private function credentials(): string
    {
        return base64_encode(config('conta-azul.client_id').':'.config('conta-azul.client_secret'));
    }

    public function storeTokenFromCode(string $code): ContaAzulToken
    {
        $response = Http::withHeaders([
            'Authorization' => 'Basic '.$this->credentials(),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->asForm()->post($this->tokenUrl(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('conta-azul.redirect_uri'),
        ]);

        return $this->persistToken($response);
    }

    public function getAccessToken(): string
    {
        $token = ContaAzulToken::query()->latest()->first();

        if (! $token) {
            throw new RuntimeException('Conta Azul não autorizado. Execute: php artisan conta-azul:authorize');
        }

        if ($token->isExpired()) {
            $token = $this->refreshToken($token->refresh_token);
        }

        return $token->access_token;
    }

    private function refreshToken(string $refreshToken): ContaAzulToken
    {
        $response = Http::withHeaders([
            'Authorization' => 'Basic '.$this->credentials(),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->asForm()->post($this->tokenUrl(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return $this->persistToken($response);
    }

    private function persistToken(Response $response): ContaAzulToken
    {
        if ($response->failed()) {
            throw new RuntimeException('Falha ao obter token do Conta Azul: '.$response->body());
        }

        $data = $response->json();

        ContaAzulToken::query()->delete();

        return ContaAzulToken::query()->create([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds($data['expires_in'] - 60),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getBillsByAccount(string $accountId, string $from, string $to): array
    {
        $token = $this->getAccessToken();
        $bills = [];
        $page = 1;

        do {
            $response = Http::withToken($token)
                ->get($this->baseUrl().'/v1/financeiro/eventos-financeiros/contas-a-pagar/buscar', [
                    'pagina' => $page,
                    'tamanho_pagina' => 100,
                    'data_vencimento_de' => $from,
                    'data_vencimento_ate' => $to,
                    'ids_contas_financeiras' => $accountId,
                ]);

            if ($response->failed()) {
                break;
            }

            $data = $response->json();
            $items = $data['itens'] ?? [];
            $bills = array_merge($bills, $items);
            $total = $data['itens_totais'] ?? 0;
            $page++;
        } while (count($bills) < $total);

        return $bills;
    }

    public function getAuthorizationUrl(): string
    {
        return config('conta-azul.auth_url').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('conta-azul.client_id'),
            'redirect_uri' => config('conta-azul.redirect_uri'),
            'scope' => config('conta-azul.scope'),
            'state' => bin2hex(random_bytes(8)),
        ]);
    }
}
