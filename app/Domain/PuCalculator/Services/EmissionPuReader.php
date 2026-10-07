<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuOfficialCurveStatus;
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
 *
 * A curva oficial só tem linhas REALIZADAS: termina no último dia sustentado por
 * índice divulgado, nunca no vencimento por repetição da última taxa. Daí três
 * formas de ler, e a diferença entre elas é a política dos consumidores oficiais:
 *
 *  - {@see self::officialReadingAt()}: o PU oficial EXATAMENTE da data, ou nada,
 *    com a situação da curva dizendo por quê. Nunca carrega valor para a frente;
 *  - {@see self::readingOn()} / {@see self::readingWithin()}: o último PU
 *    conhecido até a data, com a data a que ele pertence, a data pedida e a
 *    situação da curva oficial ({@see PuReading::isCarriedForward()},
 *    {@see PuReading::freshness()}). Quem mostra a posição ao lado do valor
 *    (relatório mensal) usa assim; quem não mostra (saldo devedor das garantias)
 *    exige {@see PuReading::standsForRequestedDate()};
 *  - {@see self::latestReadings()}: a lista do site, que apresenta o primeiro
 *    como "PU atual" e por isso já vem vazia quando ele não pode responder pela
 *    data pedida.
 *
 * Em todas, a linha que o reprocessamento pôs em dúvida (o passado da curva
 * oficial mudou a partir de uma data) não é devolvida -- e não cai no Histórico
 * de PU, que só responde pelas datas que a curva oficial não cobre.
 */
final class EmissionPuReader
{
    public function __construct(
        private readonly PuOfficialCurveFreshnessService $freshness,
    ) {}

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
     * PU OFICIAL exatamente da data: só da curva homologada vigente, só dentro da
     * fronteira realizada. Fora dela a leitura vem nula, com a situação da curva
     * oficial explicando por quê (sem curva oficial, índice ainda não divulgado,
     * índice ausente, curva atrasada, extensão falhou, reprocessamento). Nunca cai
     * no Histórico de PU nem numa versão não homologada.
     *
     * @return array{reading: PuReading|null, status: PuOfficialCurveStatus}
     */
    public function officialReadingAt(Emission $emission, CarbonInterface $date, ?CarbonInterface $now = null): array
    {
        $status = $this->freshness->status($emission, $now);
        $version = $this->officialVersion($emission, fresh: true);

        if (! $version instanceof EmissionPuCurveVersion || ! $status->covers($date) || ! $status->isReliableAt($date)) {
            return ['reading' => null, 'status' => $status];
        }

        $row = EmissionPuDailyCurve::query()
            ->where('curve_version_id', $version->id)
            ->whereDate('curve_date', $date->toDateString())
            ->first(['curve_date', 'residual_unit_value']);

        return [
            'reading' => $row instanceof EmissionPuDailyCurve
                ? new PuReading(
                    date: CarbonImmutable::parse((string) $row->curve_date)->startOfDay(),
                    unitValue: (string) $row->residual_unit_value,
                    source: PuReading::SOURCE_OFFICIAL_CURVE,
                    calculationVersion: $version->calculation_version,
                    requestedDate: CarbonImmutable::parse($date->toDateString())->startOfDay(),
                    officialStatus: $status,
                )
                : null,
            'status' => $status,
        ];
    }

    /**
     * Situação da curva oficial frente ao índice realizado.
     */
    public function officialStatus(Emission $emission, ?CarbonInterface $now = null): PuOfficialCurveStatus
    {
        return $this->freshness->status($emission, $now);
    }

    /**
     * Último PU conhecido até a data (inclusive).
     */
    public function readingOn(Emission $emission, CarbonInterface $date, ?CarbonInterface $now = null): ?PuReading
    {
        return $this->readingWithin($emission, null, $date, $now);
    }

    /**
     * Último PU dentro do intervalo; sem início, qualquer data até o fim serve.
     *
     * A leitura da curva oficial pode ser CARREGADA de uma data anterior ao fim
     * do intervalo e traz a situação da curva: quem não mostra a posição ao lado
     * do valor confere {@see PuReading::standsForRequestedDate()}. Linha que o
     * reprocessamento pôs em dúvida não volta -- nem é trocada pelo Histórico.
     */
    public function readingWithin(
        Emission $emission,
        ?CarbonInterface $from,
        CarbonInterface $to,
        ?CarbonInterface $now = null,
    ): ?PuReading {
        $curve = $this->curveReadings($emission, $from, $to, 1, requested: $to, now: $now);

        if ($curve->isNotEmpty()) {
            $reading = $curve->first();

            return $reading->officialStatus?->isReliableAt($reading->date) === true ? $reading : null;
        }

        return $this->historyReadings($emission, $from, $to, 1, requested: $to)->first();
    }

    /**
     * Os últimos PUs até a data, do mais recente para o mais antigo -- a lista
     * pública, cujo primeiro é apresentado como "PU atual" sem a data ao lado.
     *
     * Da curva oficial, a lista só sai quando o mais recente pode responder pela
     * data pedida ({@see PuReading::standsForRequestedDate()}) e o valor dele
     * continua valendo; senão ela vem vazia, e o PU fica indisponível em vez de
     * aparecer como atual sem ser. Cada leitura mantém a própria data.
     *
     * @return Collection<int, PuReading>
     */
    public function latestReadings(Emission $emission, CarbonInterface $until, int $limit, ?CarbonInterface $now = null): Collection
    {
        $curve = $this->curveReadings($emission, null, $until, $limit, requested: $until, now: $now);

        if ($curve->isEmpty()) {
            return $this->historyReadings($emission, null, $until, $limit, requested: $until);
        }

        /** @var PuReading $latest */
        $latest = $curve->first();

        if (! $latest->standsForRequestedDate() || $latest->officialStatus?->isReliableAt($latest->date) !== true) {
            return collect();
        }

        return $curve
            ->filter(fn (PuReading $reading): bool => $reading->officialStatus?->isReliableAt($reading->date) === true)
            ->values();
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
    private function curveReadings(
        Emission $emission,
        ?CarbonInterface $from,
        CarbonInterface $to,
        int $limit,
        ?CarbonInterface $requested = null,
        ?CarbonInterface $now = null,
    ): Collection {
        $version = $this->officialVersion($emission);

        if (! $version instanceof EmissionPuCurveVersion) {
            return collect();
        }

        $rows = EmissionPuDailyCurve::query()
            ->where('curve_version_id', $version->id)
            ->when($from !== null, fn ($query) => $query->whereDate('curve_date', '>=', $from->toDateString()))
            ->whereDate('curve_date', '<=', $to->toDateString())
            ->orderByDesc('curve_date')
            ->limit($limit)
            ->get(['curve_date', 'residual_unit_value']);

        if ($rows->isEmpty()) {
            return collect();
        }

        // Uma situação por leitura, tirada no mesmo instante para todas as linhas.
        $status = $this->freshness->status($emission, $now);

        return $rows
            ->map(fn (EmissionPuDailyCurve $row): PuReading => new PuReading(
                date: CarbonImmutable::parse((string) $row->curve_date)->startOfDay(),
                unitValue: (string) $row->residual_unit_value,
                source: PuReading::SOURCE_OFFICIAL_CURVE,
                calculationVersion: $version->calculation_version,
                requestedDate: $requested !== null ? CarbonImmutable::parse($requested->toDateString())->startOfDay() : null,
                officialStatus: $status,
            ))
            ->values();
    }

    /**
     * @return Collection<int, PuReading>
     */
    private function historyReadings(
        Emission $emission,
        ?CarbonInterface $from,
        CarbonInterface $to,
        int $limit,
        ?CarbonInterface $requested = null,
    ): Collection {
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
                requestedDate: $requested !== null ? CarbonImmutable::parse($requested->toDateString())->startOfDay() : null,
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
