<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Correção de uma observação de índice já registrada.
 *
 * É ato de governança, nunca sincronização: exige motivo (e, na correção manual,
 * quem corrige), guarda o valor e a origem anteriores no livro de correções e
 * registra a trilha. A curva que já usou a observação NÃO é recalculada nem
 * reescrita: cada versão operacional cujas linhas dependem da data é listada na
 * correção, e a governada (homologada ou promovida) fica marcada para
 * reprocessamento -- o mesmo estado em que a extensão diária a deixaria ao
 * perceber o passado diferente ({@see PuCurveExtensionService}). Daí em diante a
 * extensão dela fica suspensa até uma nova versão ser gerada, revisada e
 * homologada; o PU oficial já homologado continua rastreável à taxa que usou.
 *
 * Travas: emissões afetadas (por id) → observação → versões, a mesma ordem
 * emissão → versão da homologação e da extensão.
 */
final class IndexRateCorrectionService
{
    public function __construct(
        private readonly IndexRateObservationRecorder $recorder,
        private readonly PuCurveExtensionService $extensions,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function correct(
        PuIndexer $indexer,
        string $rateDate,
        string $newValue,
        string $reason,
        ?int $correctedByUserId,
        string $origin = IndexRateCorrection::ORIGIN_MANUAL_CORRECTION,
        ?string $newSource = null,
        ?string $newSourceReference = null,
    ): IndexRateCorrection {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Informe o motivo da correção: corrigir taxa histórica exige justificativa.');
        }

        if ($origin === IndexRateCorrection::ORIGIN_MANUAL_CORRECTION && $correctedByUserId === null) {
            throw new InvalidArgumentException('A correção manual exige o usuário que corrige.');
        }

        if (! in_array($origin, [IndexRateCorrection::ORIGIN_MANUAL_CORRECTION, IndexRateCorrection::ORIGIN_PROVIDER_REVISION], true)) {
            throw new InvalidArgumentException(sprintf('Origem de correção desconhecida: %s.', $origin));
        }

        $date = CarbonImmutable::parse($rateDate)->startOfDay();
        $value = $this->recorder->normalizeValue($indexer, $newValue, $date);
        $this->recorder->assertObservationDateIsNotFuture($date);

        $affectedEmissionIds = $this->dependentRowsQuery($indexer, $date->toDateString())
            ->distinct()
            ->pluck('v.emission_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $correction = DB::transaction(function () use (
            $indexer,
            $date,
            $value,
            $reason,
            $correctedByUserId,
            $origin,
            $newSource,
            $newSourceReference,
            $affectedEmissionIds,
        ): IndexRateCorrection {
            if ($affectedEmissionIds !== []) {
                Emission::query()->whereKey($affectedEmissionIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }

            $rate = IndexRate::query()
                ->forIndexer($indexer)
                ->whereDate('rate_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $rate instanceof IndexRate) {
                throw new InvalidArgumentException(sprintf(
                    'Não há observação de %s em %s para corrigir; observação nova entra pela sincronização ou pela importação.',
                    $indexer->value,
                    $date->toDateString(),
                ));
            }

            if ($rate->isProjectedRate()) {
                throw new InvalidArgumentException(sprintf(
                    'A linha de %s é projetada: projeção não é corrigida como taxa realizada.',
                    $date->toDateString(),
                ));
            }

            $source = $newSource !== null && trim($newSource) !== '' ? trim($newSource) : (string) $rate->source;
            $sourceReference = $newSourceReference !== null && trim($newSourceReference) !== ''
                ? trim($newSourceReference)
                : $rate->source_reference;

            if (bccomp((string) $rate->rate_value, $value, IndexRateObservationRecorder::VALUE_SCALE) === 0
                && $source === (string) $rate->source
                && $sourceReference === $rate->source_reference) {
                throw new InvalidArgumentException(sprintf(
                    'A observação de %s já vale %s com a mesma origem: não há o que corrigir.',
                    $date->toDateString(),
                    $value,
                ));
            }

            // Relida sob as travas das emissões: nenhuma linha nova dessas curvas entra
            // até o commit. Uma versão de outra emissão gerada no intervalo com a taxa
            // antiga é pega pela extensão diária, que verá o passado divergente.
            $affected = $this->dependentVersionsQuery($indexer, $date->toDateString())
                ->get()
                ->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'emission_id' => (int) $row->emission_id,
                    'calculation_version' => (string) $row->calculation_version,
                    'status' => (string) $row->status,
                    'first_dependent_date' => CarbonImmutable::parse((string) $row->first_dependent_date)->toDateString(),
                ])
                ->values()
                ->all();

            $correction = IndexRateCorrection::query()->create([
                'index_rate_id' => $rate->id,
                'indexer' => $indexer->value,
                'rate_date' => $date,
                'origin' => $origin,
                'previous_rate_value' => (string) $rate->rate_value,
                'new_rate_value' => $value,
                'previous_source' => $rate->source,
                'new_source' => $source,
                'previous_source_reference' => $rate->source_reference,
                'new_source_reference' => $sourceReference,
                'reason' => $reason,
                'corrected_by' => $correctedByUserId,
                'affected_curve_versions' => $affected,
                'corrected_at' => now(),
            ]);

            $rate->forceFill([
                'rate_value' => $value,
                'source' => $source,
                'source_reference' => $sourceReference,
            ])->save();

            $this->markGovernedVersionsForReprocessing($correction, $affected);

            return $correction;
        });

        $this->auditLog->logIndexRateCorrected($correction);

        return $correction;
    }

    /**
     * A versão governada que usou a observação fica com a extensão suspensa e o
     * motivo registrado: as linhas homologadas não mudam, e a próxima versão é
     * decisão humana.
     *
     * @param  list<array{id:int,emission_id:int,calculation_version:string,status:string,first_dependent_date:string}>  $affected
     */
    private function markGovernedVersionsForReprocessing(IndexRateCorrection $correction, array $affected): void
    {
        foreach ($affected as $dependent) {
            $version = EmissionPuCurveVersion::query()->find($dependent['id']);

            if (! $version instanceof EmissionPuCurveVersion || ! $this->extensions->isGoverned($version)) {
                continue;
            }

            $version->forceFill([
                'extension_diverged_at' => $version->extension_diverged_at ?? now(),
                'extension_divergence' => [
                    'cause' => 'index_rate_corrected',
                    'index_rate_correction_id' => $correction->id,
                    'indexer' => $correction->indexer,
                    'rate_date' => $correction->rate_date?->toDateString(),
                    'previous_rate_value' => (string) $correction->previous_rate_value,
                    'new_rate_value' => (string) $correction->new_rate_value,
                    'first_divergent_date' => $dependent['first_dependent_date'],
                    'reason' => sprintf(
                        'O %s de %s usado pela curva foi corrigido de %s para %s; as linhas homologadas foram mantidas e a curva precisa de nova versão governada.',
                        $correction->indexer,
                        $correction->rate_date?->toDateString(),
                        (string) $correction->previous_rate_value,
                        (string) $correction->new_rate_value,
                    ),
                    'checked_at' => now()->toIso8601String(),
                ],
            ])->save();
        }
    }

    /**
     * Linhas de versões operacionais vivas que usaram a observação do indexador.
     */
    private function dependentRowsQuery(PuIndexer $indexer, string $rateDate): Builder
    {
        return DB::table('emission_pu_daily_curves as c')
            ->join('emission_pu_curve_versions as v', 'v.id', '=', 'c.curve_version_id')
            ->join('emission_pu_parameters as p', 'p.emission_id', '=', 'v.emission_id')
            ->where('p.indexer', $indexer->value)
            ->where('v.curve_role', PuCurveRole::Operational->value)
            ->where('v.status', '!=', PuCurveStatus::Obsolete->value)
            ->whereDate('c.index_rate_date', $rateDate);
    }

    /**
     * Versões dependentes, com a primeira data da curva que usou a observação.
     */
    private function dependentVersionsQuery(PuIndexer $indexer, string $rateDate): Builder
    {
        return $this->dependentRowsQuery($indexer, $rateDate)
            ->groupBy('v.id', 'v.emission_id', 'v.calculation_version', 'v.status')
            ->orderBy('v.id')
            ->select([
                'v.id',
                'v.emission_id',
                'v.calculation_version',
                'v.status',
                DB::raw('MIN(c.curve_date) as first_dependent_date'),
            ]);
    }
}
