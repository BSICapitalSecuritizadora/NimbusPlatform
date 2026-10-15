<?php

namespace App\Services\Funds;

use App\Exceptions\Funds\ContaAzulBillIngestionException;
use App\Services\ContaAzulClient;
use Illuminate\Support\Facades\Http;

/**
 * Isolated Conta Azul search-endpoint adapter for the staging pipeline.
 *
 * The shared ContaAzulClient cannot expose pagination metadata without
 * changing its contract: getBillsByAccount() merges every page into one
 * array and silently truncates it on HTTP failure (`break` with no error
 * signal). Changing that method would alter the legacy synchronization
 * job's behavior, so this adapter replicates the request loop instead —
 * same endpoint, same parameters — with explicit per-page accounting:
 *
 * - every fetched page is yielded verbatim to the caller;
 * - an HTTP failure throws instead of truncating, carrying the failed page
 *   number and how many records were received before it;
 * - failure messages are sanitized (status code only, never bodies, URLs
 *   or tokens).
 *
 * Phase 3A builds and tests this adapter against faked HTTP only. It is
 * not wired to any schedule or job and must not call the live API without
 * separate authorization.
 */
class ContaAzulPaginatedBillAdapter
{
    public function __construct(private readonly ContaAzulClient $client) {}

    /**
     * Yield raw search-endpoint pages for one financial account.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function fetchPages(string $accountId, string $from, string $to): iterable
    {
        $token = $this->client->getAccessToken();
        $received = 0;
        $page = 1;

        do {
            $response = Http::withToken($token)
                ->get(config('conta-azul.base_url').'/v1/financeiro/eventos-financeiros/contas-a-pagar/buscar', [
                    'pagina' => $page,
                    'tamanho_pagina' => 100,
                    'data_vencimento_de' => $from,
                    'data_vencimento_ate' => $to,
                    'ids_contas_financeiras' => $accountId,
                ]);

            if ($response->failed()) {
                throw ContaAzulBillIngestionException::sourceFailed($page, $received);
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw ContaAzulBillIngestionException::invalidPayload("página {$page} fora do formato esperado");
            }

            $items = $data['itens'] ?? [];
            $total = $data['itens_totais'] ?? 0;

            if (! is_array($items) || ! is_int($total)) {
                throw ContaAzulBillIngestionException::invalidPayload("página {$page} sem itens ou total de itens");
            }

            $received += count($items);

            yield ['itens' => $items, 'itens_totais' => $total];

            $page++;
        } while ($received < $total);
    }
}
