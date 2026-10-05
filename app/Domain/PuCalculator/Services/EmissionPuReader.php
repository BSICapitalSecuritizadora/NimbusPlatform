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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fonte única do PU de uma emissão para o resto do sistema: relatório mensal,
 * saldo devedor das garantias e site.
 *
 * A curva oficial é a versão operacional HOMOLOGADA mais recente. Enquanto ela
 * existe e cobre a data, é ela que vale. Uma curva gerada, validada, divergente,
 * com erro ou em processamento nunca chega às outras áreas, por mais nova que
 * seja.
 *
 * Sem leitura da curva oficial, o PU vem do Histórico de PU -- mas só do que é
 * legado legítimo, e a regra muda com o tipo de emissão:
 *
 * - emissão LEGADA (o motor de curvas nunca foi acionado para ela: nenhuma
 *   versão operacional): o Histórico inteiro, como sempre foi, e o `current_pu`
 *   cadastrado como último recurso;
 * - emissão GOVERNADA (existe versão operacional): só as linhas importadas ou
 *   lançadas à mão ({@see PuHistory::LEGITIMATE_SOURCES}) e as linhas sem origem
 *   registrada gravadas pela última vez ANTES da primeira versão de curva --
 *   até a Fase 2 de governança a geração também gravava no Histórico, e uma
 *   linha sem origem alterada depois disso pode ser projeção de curva não
 *   homologada. O `current_pu` não responde por emissão governada pelo mesmo
 *   motivo.
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
     * Início da governança por emissão (criação da primeira versão operacional).
     *
     * @var array<int, CarbonImmutable|null>
     */
    private array $governedSince = [];

    /**
     * @param  bool  $fresh  ignora o memo; quem acabou de homologar ou invalidar precisa do estado atual
     */
    public function officialVersion(Emission $emission, bool $fresh = false): ?EmissionPuCurveVersion
    {
        if ($fresh || ! array_key_exists($emission->id, $this->officialVersions)) {
            $this->officialVersions[$emission->id] = EmissionPuCurveVersion::query()
                ->where('emission_id', $emission->id)
                ->official()
                ->first();
        }

        return $this->officialVersions[$emission->id];
    }

    /**
     * Emissão governada: o motor de curvas já foi acionado para ela. A partir
     * daí o PU oficial só vem da curva homologada ou do legado legítimo.
     */
    public function isGoverned(Emission $emission): bool
    {
        return $this->governedSince($emission) !== null;
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
     * O `current_pu` cadastrado, último recurso de quem não achou leitura. Só
     * responde por emissão legada: numa governada ele pode ter sido gravado por
     * uma curva que nunca foi homologada.
     */
    public function legacyCurrentUnitValue(Emission $emission): ?string
    {
        if ($this->isGoverned($emission) || $emission->current_pu === null) {
            return null;
        }

        return (string) $emission->current_pu;
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
        $governedSince = $this->governedSince($emission);

        return PuHistory::query()
            ->where('emission_id', $emission->id)
            ->whereNotNull('date')
            ->when($governedSince !== null, fn (Builder $query) => $query->where(
                fn (Builder $legitimate) => $legitimate
                    ->whereIn('source', PuHistory::LEGITIMATE_SOURCES)
                    ->orWhere(fn (Builder $preGovernance) => $preGovernance
                        ->whereNull('source')
                        ->where('updated_at', '<', $governedSince)),
            ))
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

    private function governedSince(Emission $emission): ?CarbonImmutable
    {
        if (! array_key_exists($emission->id, $this->governedSince)) {
            $firstVersionAt = EmissionPuCurveVersion::query()
                ->where('emission_id', $emission->id)
                ->operational()
                ->min('created_at');

            $this->governedSince[$emission->id] = $firstVersionAt !== null
                ? CarbonImmutable::parse((string) $firstVersionAt)
                : null;
        }

        return $this->governedSince[$emission->id];
    }
}
