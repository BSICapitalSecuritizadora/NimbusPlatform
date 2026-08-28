<?php

namespace App\Filament\Widgets\IndexRates;

use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class IndexSyncOverview extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.index-rates.index-sync-overview';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $indexes = [
            'CDI' => [
                'name' => 'CDI',
                'description' => 'Taxa DI over (B3 / Bacen SGS 12 e 4389)',
                'source_label' => 'Banco Central do Brasil (SGS)',
                'series_code' => config('pu_indexes.bcb.series.cdi.code', 4389),
                'cache_key' => 'pu_index_sync_cdi_status',
            ],
            'IPCA' => [
                'name' => 'IPCA',
                'description' => 'Índice Nacional de Preços ao Consumidor Amplo (IBGE / Bacen SGS 433)',
                'source_label' => 'Banco Central do Brasil (SGS)',
                'series_code' => config('pu_indexes.bcb.series.ipca.code', 433),
                'cache_key' => 'pu_index_sync_ipca_status',
            ],
        ];

        $cards = [];

        foreach ($indexes as $key => $meta) {
            $latestPublished = IndexRate::query()
                ->where('indexer', $key)
                ->where('is_projected', false)
                ->latest('rate_date')
                ->first();

            $totalCount = IndexRate::query()
                ->where('indexer', $key)
                ->count();

            $projectedCount = IndexRate::query()
                ->where('indexer', $key)
                ->where('is_projected', true)
                ->count();

            $syncStatus = Cache::get($meta['cache_key']);
            $lastFetched = IndexRate::query()
                ->where('source', 'bcb_sgs')
                ->where('indexer', $key)
                ->max('fetched_at');

            $lastSyncedAt = null;
            if (is_array($syncStatus) && ($syncStatus['status'] ?? null) === 'completed' && ! empty($syncStatus['synced_at'])) {
                $lastSyncedAt = CarbonImmutable::parse($syncStatus['synced_at']);
            } elseif ($lastFetched !== null) {
                $lastSyncedAt = CarbonImmutable::parse($lastFetched);
            }

            $state = 'pending';
            $stateLabel = 'Pendente';
            $stateColor = 'warning';

            if (is_array($syncStatus) && ($syncStatus['status'] ?? null) === 'failed') {
                $state = 'failed';
                $stateLabel = 'Falha na sincronização';
                $stateColor = 'danger';
            } elseif ($lastSyncedAt !== null) {
                $state = 'synced';
                $stateLabel = 'Sincronizado';
                $stateColor = 'success';
            }

            $cards[$key] = [
                'name' => $meta['name'],
                'description' => $meta['description'],
                'source_label' => $meta['source_label'],
                'series_code' => $meta['series_code'],
                'latest_rate_date' => $latestPublished?->rate_date?->format('d/m/Y') ?? '—',
                'latest_rate_value' => $latestPublished ? (
                    $key === 'CDI'
                        ? number_format((float) $latestPublished->rate_value, 8, ',', '.').' % a.a.'
                        : number_format((float) $latestPublished->rate_value, 8, ',', '.')
                ) : '—',
                'last_synced_at' => $lastSyncedAt ? $lastSyncedAt->format('d/m/Y · H:i') : 'Nunca sincronizado',
                'state' => $state,
                'state_label' => $stateLabel,
                'state_color' => $stateColor,
                'error' => is_array($syncStatus) ? ($syncStatus['error'] ?? null) : null,
                'total_count' => $totalCount,
                'projected_count' => $projectedCount,
            ];
        }

        return [
            'cards' => $cards,
        ];
    }
}
