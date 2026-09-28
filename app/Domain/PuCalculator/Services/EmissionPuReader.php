<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuReading;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\PuHistory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Fonte única do PU de uma emissão para o resto do sistema: relatório mensal,
 * saldo devedor das garantias e site.
 *
 * A curva oficial é a versão operacional homologada mais recente. Enquanto ela
 * existe e cobre a data, é ela que vale; emissões sem curva homologada (ou datas
 * anteriores ao início dela) continuam no Histórico de PU importado. Uma curva
 * gerada e ainda não homologada nunca chega às outras áreas.
 *
 * O PU lido é o residual do dia: depois dos pagamentos daquela data, a mesma
 * semântica que a projeção legada gravava no Histórico de PU.
 */
final class EmissionPuReader
{
    /**
     * Memo por instância: um relatório lê a mesma emissão várias vezes.
     *
     * @var array<int, EmissionPuCurveVersion|null>
     */
    private array $officialVersions = [];

    /**
     * @param  bool  $fresh  ignora o memo; quem acabou de homologar ou invalidar precisa do estado atual
     */
    public function officialVersion(Emission $emission, bool $fresh = false): ?EmissionPuCurveVersion
    {
        if ($fresh || ! array_key_exists($emission->id, $this->officialVersions)) {
            $this->officialVersions[$emission->id] = EmissionPuCurveVersion::query()
                ->where('emission_id', $emission->id)
                ->operational()
                ->homologated()
                ->orderByDesc('id')
                ->first();
        }

        return $this->officialVersions[$emission->id];
    }

    /**
     * Último PU conhecido até a data (inclusive).
     */
    public function readingOn(Emission $emission, CarbonInterface $date): ?PuReading
    {
        return $this->readingWithin($emission, null, $date);
    }

    /**
     * Último PU dentro do intervalo; sem início, qualquer data até o fim serve.
     */
    public function readingWithin(Emission $emission, ?CarbonInterface $from, CarbonInterface $to): ?PuReading
    {
        return $this->curveReadings($emission, $from, $to, 1)->first()
            ?? $this->historyReadings($emission, $from, $to, 1)->first();
    }

    /**
     * Os últimos PUs até a data, do mais recente para o mais antigo.
     *
     * @return Collection<int, PuReading>
     */
    public function latestReadings(Emission $emission, CarbonInterface $until, int $limit): Collection
    {
        $curve = $this->curveReadings($emission, null, $until, $limit);

        return $curve->isNotEmpty() ? $curve : $this->historyReadings($emission, null, $until, $limit);
    }

    /**
     * @return Collection<int, PuReading>
     */
    private function curveReadings(Emission $emission, ?CarbonInterface $from, CarbonInterface $to, int $limit): Collection
    {
        $version = $this->officialVersion($emission);

        if (! $version instanceof EmissionPuCurveVersion) {
            return collect();
        }

        return EmissionPuDailyCurve::query()
            ->where('curve_version_id', $version->id)
            ->when($from !== null, fn ($query) => $query->whereDate('curve_date', '>=', $from->toDateString()))
            ->whereDate('curve_date', '<=', $to->toDateString())
            ->orderByDesc('curve_date')
            ->limit($limit)
            ->get(['curve_date', 'residual_unit_value'])
            ->map(fn (EmissionPuDailyCurve $row): PuReading => new PuReading(
                date: CarbonImmutable::parse((string) $row->curve_date)->startOfDay(),
                unitValue: (string) $row->residual_unit_value,
                source: PuReading::SOURCE_OFFICIAL_CURVE,
                calculationVersion: $version->calculation_version,
            ))
            ->values();
    }

    /**
     * @return Collection<int, PuReading>
     */
    private function historyReadings(Emission $emission, ?CarbonInterface $from, CarbonInterface $to, int $limit): Collection
    {
        return PuHistory::query()
            ->where('emission_id', $emission->id)
            ->whereNotNull('date')
            ->when($from !== null, fn ($query) => $query->whereDate('date', '>=', $from->toDateString()))
            ->whereDate('date', '<=', $to->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['date', 'unit_value'])
            ->map(fn (PuHistory $history): PuReading => new PuReading(
                date: CarbonImmutable::parse((string) $history->date)->startOfDay(),
                unitValue: (string) $history->unit_value,
                source: PuReading::SOURCE_PU_HISTORY,
            ))
            ->values();
    }
}
