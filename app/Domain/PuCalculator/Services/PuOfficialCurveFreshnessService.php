<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Contracts\RealizedIndexRateProvider;
use App\Domain\PuCalculator\DTOs\PuCurveChangeAssessment;
use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\DTOs\PuOfficialCurveStatus;
use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuParameter;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Atualidade da curva OFICIAL frente ao índice realizado.
 *
 * Determinístico e somente leitura. Responde, para a homologada vigente:
 *
 *  1. até onde ela é realizada (a última linha gravada -- toda linha gravada é
 *     sustentada por observação realizada);
 *  2. até onde ela chegaria com o índice que já existe no banco, percorrendo a
 *     partir da fronteira as mesmas exigências da engine
 *     ({@see PuIndexRateRequirementResolver});
 *  3. o que impede de ir além: observação ainda não divulgada (normal), buraco
 *     no histórico, ou divulgação atrasada -- a observação que, pelo calendário de
 *     divulgação e pela defasagem configurada, já deveria estar no banco.
 *
 * Precedência quando mais de um vale: reprocessamento (o passado mudou) →
 * índice ausente (causa nos dados) → falha da extensão → atrasada → atual.
 *
 * Fase 4: a curva oficial é lida pelo RETRATO de insumos que a homologação
 * aprovou -- o horizonte (vencimento + pagamento deslocado), as regras de busca do
 * índice e o indexador vêm dele, nunca dos parâmetros vivos. E os insumos vivos
 * são comparados com o retrato ({@see PuCurveChangeImpactClassifier}): mudança
 * contratual que alcança o trecho gravado é reprocessamento; mudança só no futuro
 * limita a oficial à véspera da data afetada, e a oficial que chegou lá fica
 * "nova versão necessária".
 */
final class PuOfficialCurveFreshnessService
{
    public function __construct(
        private readonly PuIndexRateRequirementResolver $requirements,
        private readonly RealizedIndexRateProvider $realizedRates,
        private readonly BusinessDayCalendar $calendar,
        private readonly PuCurveInputSnapshotService $snapshots,
        private readonly PuCurveChangeImpactClassifier $classifier,
    ) {}

    public function status(Emission $emission, ?CarbonInterface $now = null): PuOfficialCurveStatus
    {
        $official = EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->official()
            ->first();

        if (! $official instanceof EmissionPuCurveVersion) {
            return new PuOfficialCurveStatus(
                freshness: PuOfficialCurveFreshness::NoOfficialCurve,
                reason: 'Nenhuma versão homologada: não há PU oficial, e nenhuma rotina torna uma curva oficial.',
            );
        }

        $lastRowDate = EmissionPuDailyCurve::query()
            ->where('curve_version_id', $official->id)
            ->max('curve_date');
        $realizedThrough = $lastRowDate !== null ? CarbonImmutable::parse((string) $lastRowDate)->startOfDay() : null;

        try {
            $approved = $this->snapshots->forVersion($official);
        } catch (PuCurveInputsException $exception) {
            return new PuOfficialCurveStatus(
                freshness: PuOfficialCurveFreshness::ReprocessingRequired,
                versionId: $official->id,
                calculationVersion: $official->calculation_version,
                realizedThrough: $realizedThrough,
                reason: $exception->getMessage(),
            );
        }

        // Com retrato, tudo vem do que foi aprovado; sem retrato (anterior à Fase 4),
        // dos parâmetros vivos, como antes -- a extensão diária recusa essa versão.
        $parameter = $approved instanceof PuCurveInputSnapshot
            ? $this->snapshots->hydrate($emission, $approved)->puParameter
            : EmissionPuParameter::query()->where('emission_id', $emission->id)->first();
        $curveEnd = $parameter?->curve_end_date !== null
            ? CarbonImmutable::instance($parameter->curve_end_date)->startOfDay()
            : null;
        $contractual = $approved instanceof PuCurveInputSnapshot
            ? $this->contractualAssessment($emission, $approved, $realizedThrough)
            : null;
        $pendingFrom = $contractual?->impact === PuCurveChangeImpact::FutureVersionRequired
            ? $contractual->earliestAffectedDate
            : null;

        $base = [
            'versionId' => $official->id,
            'calculationVersion' => $official->calculation_version,
            'realizedThrough' => $realizedThrough,
            'curveEndDate' => $curveEnd,
            'contractualChangeFrom' => $pendingFrom,
        ];

        if ($official->extension_diverged_at !== null) {
            $divergence = is_array($official->extension_divergence) ? $official->extension_divergence : [];
            $firstDivergentDate = trim((string) ($divergence['first_divergent_date'] ?? ''));

            return $this->make([
                ...$base,
                'freshness' => PuOfficialCurveFreshness::ReprocessingRequired,
                'expectedRealizedThrough' => $realizedThrough,
                'reason' => (string) ($divergence['reason'] ?? 'O passado da curva oficial mudou.'),
                'reprocessingFrom' => $firstDivergentDate !== ''
                    ? CarbonImmutable::parse($firstDivergentDate)->startOfDay()
                    : null,
            ]);
        }

        if ($contractual?->impact === PuCurveChangeImpact::HistoricalReprocessRequired) {
            return $this->make([
                ...$base,
                'freshness' => PuOfficialCurveFreshness::ReprocessingRequired,
                'expectedRealizedThrough' => $realizedThrough,
                'reason' => $contractual->summary(),
                'reprocessingFrom' => $contractual->earliestAffectedDate,
            ]);
        }

        if ($pendingFrom !== null && $curveEnd !== null) {
            // A oficial só pode chegar à véspera da mudança não homologada -- e, se a
            // mudança vem depois do fim aprovado (vencimento prorrogado, pagamento
            // novo), a oficial completa também não responde pelo contrato de agora.
            $curveEnd = $pendingFrom->subDay()->min($curveEnd);

            if ($realizedThrough !== null && $realizedThrough->gte($curveEnd)) {
                return $this->make([
                    ...$base,
                    'freshness' => PuOfficialCurveFreshness::NewVersionRequired,
                    'expectedRealizedThrough' => $realizedThrough,
                    'reason' => $contractual?->summary(),
                ]);
            }
        }

        if ($realizedThrough !== null && $curveEnd !== null && $realizedThrough->gte($curveEnd)) {
            return $this->make([
                ...$base,
                'freshness' => PuOfficialCurveFreshness::Complete,
                'expectedRealizedThrough' => $realizedThrough,
            ]);
        }

        if (! $parameter instanceof EmissionPuParameter
            || $parameter->indexer_enum !== PuIndexer::Cdi
            || $realizedThrough === null
            || $curveEnd === null) {
            return $this->make([
                ...$base,
                'freshness' => PuOfficialCurveFreshness::NotTracked,
                'expectedRealizedThrough' => $realizedThrough,
                'reason' => 'A extensão diária por índice realizado só acompanha curvas de CDI.',
            ]);
        }

        $latestRealized = $this->realizedRates->latestRealizedRateDate(PuIndexer::Cdi);

        try {
            [$expectedThrough, $next] = $this->walkRealizedTail($parameter, $realizedThrough, $curveEnd);
            $expectedLatest = $this->expectedLatestRateDate($parameter, $now ?? CarbonImmutable::now());
        } catch (Throwable $exception) {
            return $this->make([
                ...$base,
                'freshness' => PuOfficialCurveFreshness::ExtensionFailed,
                'latestRealizedRateDate' => $latestRealized,
                'reason' => sprintf('Não foi possível resolver as próximas datas exigidas: %s', $exception->getMessage()),
            ]);
        }

        $context = [
            ...$base,
            'expectedRealizedThrough' => $expectedThrough,
            'nextRequiredRateDate' => $next?->requiredRateDate(),
            'latestRealizedRateDate' => $latestRealized,
            'expectedLatestRateDate' => $expectedLatest,
        ];

        if ($next instanceof PuIndexRateRequirement && $next->isMissingHistoricalObservation()) {
            return $this->make([
                ...$context,
                'freshness' => PuOfficialCurveFreshness::MissingIndex,
                'reason' => sprintf(
                    'Falta a observação de %s, dentro do histórico já divulgado (última observação: %s).',
                    $next->requiredRateDate()?->toDateString(),
                    $latestRealized?->toDateString() ?? 'nenhuma',
                ),
            ]);
        }

        if ($next instanceof PuIndexRateRequirement
            && $expectedLatest !== null
            && $next->requiredRateDate() !== null
            && $next->requiredRateDate()->lte($expectedLatest)) {
            return $this->make([
                ...$context,
                'freshness' => PuOfficialCurveFreshness::MissingIndex,
                'reason' => sprintf(
                    'A observação de %s já deveria ter sido divulgada (esperada até %s), e a mais recente registrada é %s.',
                    $next->requiredRateDate()->toDateString(),
                    $expectedLatest->toDateString(),
                    $latestRealized?->toDateString() ?? 'nenhuma',
                ),
            ]);
        }

        if ($official->extension_failed_at !== null) {
            return $this->make([
                ...$context,
                'freshness' => PuOfficialCurveFreshness::ExtensionFailed,
                'reason' => is_array($official->extension_failure)
                    ? (string) ($official->extension_failure['reason'] ?? 'A extensão diária falhou.')
                    : 'A extensão diária falhou.',
            ]);
        }

        if ($expectedThrough->gt($realizedThrough)) {
            return $this->make([
                ...$context,
                'freshness' => PuOfficialCurveFreshness::Stale,
                'reason' => sprintf(
                    'Há índice realizado para levar a curva oficial de %s até %s, e ela ainda não foi estendida.',
                    $realizedThrough->toDateString(),
                    $expectedThrough->toDateString(),
                ),
            ]);
        }

        return $this->make([
            ...$context,
            'freshness' => PuOfficialCurveFreshness::Current,
            'reason' => $next !== null
                ? sprintf('A próxima observação exigida (%s) ainda não foi divulgada.', $next->requiredRateDate()?->toDateString())
                : null,
        ]);
    }

    /**
     * Insumos vivos contra o retrato aprovado. Insumo vivo que nem se deixa
     * retratar (parâmetros apagados) conta como mudança no passado: na dúvida, o
     * gravado deixa de responder.
     */
    private function contractualAssessment(
        Emission $emission,
        PuCurveInputSnapshot $approved,
        ?CarbonImmutable $realizedThrough,
    ): PuCurveChangeAssessment {
        try {
            return $this->classifier->compare($approved, $this->snapshots->capture($emission), $realizedThrough);
        } catch (PuCurveInputsException $exception) {
            return new PuCurveChangeAssessment(
                impact: PuCurveChangeImpact::HistoricalReprocessRequired,
                earliestAffectedDate: $approved->curveStartDate(),
                changes: [],
                approvedFingerprint: $approved->fingerprint,
                liveFingerprint: '',
                lastPersistedDate: $realizedThrough,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(array $attributes): PuOfficialCurveStatus
    {
        return new PuOfficialCurveStatus(...$attributes);
    }

    /**
     * A observação mais recente que já deveria estar no banco: a do dia útil de
     * divulgação cuja sincronização já passou, recuado da defasagem de divulgação.
     */
    public function expectedLatestRateDate(EmissionPuParameter $parameter, CarbonInterface $now): ?CarbonImmutable
    {
        // O mesmo calendário de divulgação que decide a data de observação na engine.
        $calendarCode = $this->requirements->observationCalendarCode($parameter);
        $lag = max(0, (int) config('pu_indexes.bcb.series.cdi.publication_lag_business_days', 1));
        $availableAfter = (string) config('pu_indexes.bcb.series.cdi.available_after', '07:00');
        $local = BusinessTime::at($now);
        $today = CarbonImmutable::parse($local->toDateString())->startOfDay();
        $publishedToday = $local->format('H:i') >= $availableAfter
            && $this->calendar->isBusinessDay($today, $calendarCode);
        $publicationDay = $publishedToday ? $today : $this->calendar->shiftBusinessDays($today, -1, $calendarCode);

        return $lag === 0
            ? $publicationDay
            : $this->calendar->shiftBusinessDays($publicationDay, -$lag, $calendarCode);
    }

    /**
     * Percorre, a partir da fronteira realizada, as mesmas exigências da engine
     * até a primeira observação que falta.
     *
     * @return array{0: CarbonImmutable, 1: PuIndexRateRequirement|null}
     */
    private function walkRealizedTail(
        EmissionPuParameter $parameter,
        CarbonImmutable $realizedThrough,
        CarbonImmutable $curveEnd,
    ): array {
        $expectedThrough = $realizedThrough;

        for ($date = $realizedThrough->addDay(); $date->lte($curveEnd); $date = $date->addDay()) {
            $requirement = $this->requirements->resolve($parameter, $date);

            if ($requirement->endsRealizedCurve()) {
                return [$expectedThrough, $requirement];
            }

            $expectedThrough = $date;
        }

        return [$expectedThrough, null];
    }
}
