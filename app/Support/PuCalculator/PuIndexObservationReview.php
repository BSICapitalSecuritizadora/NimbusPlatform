<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\Enums\PuIndexSyncOutcome;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Models\IndexRateCorrection;
use App\Models\PuIndexSyncAttempt;
use App\Models\PuOperationalIncident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Fila de revisão das observações de índice (Fase 8, UI-5), lida do que já é
 * durável: incidentes de índice do monitor, sincronizações com valor divergente,
 * importações com valor retido ou em conflito (trilha de auditoria) e as
 * correções governadas. Não aprova nada sozinha: valor retido só entra por nova
 * importação confirmada; valor divergente, só por correção com motivo.
 */
final class PuIndexObservationReview
{
    private const LIMIT = 15;

    /**
     * @return array{incidents: Collection<int, PuOperationalIncident>, syncs: Collection<int, PuIndexSyncAttempt>, imports: list<array<string, mixed>>, corrections: Collection<int, IndexRateCorrection>}
     */
    public static function build(): array
    {
        return [
            'incidents' => PuOperationalIncident::query()
                ->open()
                ->whereIn('type', [
                    PuOperationalConditionType::IndexRateConflict->value,
                    PuOperationalConditionType::IndexObservationMissing->value,
                    PuOperationalConditionType::IndexSyncFailed->value,
                    PuOperationalConditionType::IndexSynchronizationOverdue->value,
                ])
                ->orderByDesc('last_detected_at')
                ->limit(self::LIMIT)
                ->get(),
            'syncs' => PuIndexSyncAttempt::query()
                ->where(fn ($query) => $query->where('conflicts', '>', 0)->orWhere('outcome', PuIndexSyncOutcome::RateConflict->value))
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get(),
            'imports' => self::imports(),
            'corrections' => IndexRateCorrection::query()
                ->with('correctedBy:id,name')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get(),
        ];
    }

    /**
     * Importações cuja trilha registrou valor retido (salto suspeito) ou conflito.
     *
     * @return list<array<string, mixed>>
     */
    private static function imports(): array
    {
        return Activity::query()
            ->where('log_name', PuAuditLogService::LOG_NAME)
            ->where('event', 'index_rates_imported')
            ->with('causer')
            ->orderByDesc('id')
            ->limit(self::LIMIT * 2)
            ->get()
            ->map(function (Activity $activity): ?array {
                $properties = $activity->properties?->toArray() ?? [];
                $retained = (array) ($properties['needs_confirmation'] ?? []);
                $conflicts = (array) ($properties['conflicts'] ?? []);
                $rejected = (array) ($properties['rejected'] ?? []);

                if ($retained === [] && $conflicts === [] && $rejected === []) {
                    return null;
                }

                $rows = [];

                foreach ([['Retido para confirmação', $retained], ['Conflita com o registrado', $conflicts], ['Recusado', $rejected]] as [$label, $items]) {
                    foreach ($items as $item) {
                        $rows[] = [
                            'situation' => $label,
                            'date' => isset($item['date']) ? CarbonImmutable::parse((string) $item['date'])->format('d/m/Y') : '—',
                            'value' => $item['value'] ?? null,
                            'existing' => $item['existing_value'] ?? null,
                            'reason' => $item['reason'] ?? null,
                        ];
                    }
                }

                return [
                    'when' => PuDisplay::dateTime($activity->created_at),
                    'who' => $activity->causer?->name ?? '—',
                    'indexer' => $properties['indexer'] ?? '—',
                    'file' => $properties['file'] ?? '—',
                    'confirmed_outliers' => (bool) ($properties['confirmed_outliers'] ?? false),
                    'rows' => array_slice($rows, 0, 20),
                    'more' => max(0, count($rows) - 20),
                ];
            })
            ->filter()
            ->take(self::LIMIT)
            ->values()
            ->all();
    }
}
