<?php

namespace App\DTOs\Measurements;

use App\Models\MeasurementPlanVersion;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * O que muda de uma versão do plano para a seguinte, no que importa para
 * decidir a ativação: custo previsto, término previsto, avanço físico e as
 * medições previstas do cronograma.
 *
 * O avanço físico é um só para as duas versões -- o do plano: o inicial mais as
 * medições com Engenharia vigente. Replanejar muda o que se espera daqui em
 * diante, nunca o que já foi executado; por isso ele aparece "inalterado".
 * As linhas são casadas pela linhagem (`lineage_key`), a identidade da medição
 * prevista através das versões. Dinheiro em centavos e percentuais em basis
 * points, sem float.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MeasurementPlanVersionComparison implements Arrayable
{
    /**
     * @param  list<array{sequence_number: int, measurement_date: string|null, planned_monthly_percent: string, planned_cumulative_percent: string}>  $addedLines
     * @param  list<array{sequence_number: int, measurement_date: string|null, planned_monthly_percent: string, planned_cumulative_percent: string}>  $removedLines
     * @param  list<array{lineage_key: string, before: array{sequence_number: int, measurement_date: string|null, planned_monthly_percent: string, planned_cumulative_percent: string}, after: array{sequence_number: int, measurement_date: string|null, planned_monthly_percent: string, planned_cumulative_percent: string}, changes: list<string>}>  $changedLines
     * @param  list<int>  $openMeasurementIdsOnBase  medições em andamento ligadas à versão anterior, que continuam nela
     * @param  int|null  $pendingBeforeEffectiveBasisPoints  rascunho: previsto de competências anteriores ao mês da ativação ainda não medidas, que continua a medir e entra no teto antes do previsto da revisão; `null` para versão já ativada
     */
    public function __construct(
        public ?MeasurementPlanVersion $base,
        public MeasurementPlanVersion $candidate,
        public ?int $baseFundCents,
        public ?int $candidateFundCents,
        public ?CarbonImmutable $baseCompletion,
        public ?CarbonImmutable $candidateCompletion,
        public int $currentBasisPoints,
        public int $remainingBasisPoints,
        public ?int $baseFinalCumulativeBasisPoints,
        public ?int $candidateFinalCumulativeBasisPoints,
        public array $addedLines,
        public array $removedLines,
        public array $changedLines,
        public int $unchangedLines,
        public array $openMeasurementIdsOnBase,
        public ?int $pendingBeforeEffectiveBasisPoints = null,
    ) {}

    /**
     * Variação do custo previsto (Fundo de Obra), em centavos; `null` quando
     * uma das versões não tem valor.
     */
    public function fundVariationCents(): ?int
    {
        return $this->baseFundCents === null || $this->candidateFundCents === null
            ? null
            : $this->candidateFundCents - $this->baseFundCents;
    }

    /**
     * Variação percentual do custo previsto sobre o da versão anterior, em
     * basis points arredondados meio para longe do zero; `null` sem base
     * positiva -- de zero para qualquer valor não há percentual.
     */
    public function fundVariationBasisPoints(): ?int
    {
        $variation = $this->fundVariationCents();

        return $variation === null || $this->baseFundCents === null
            ? null
            : IntegerMoney::shareInBasisPoints($variation, $this->baseFundCents);
    }

    /**
     * Dias entre o término previsto de uma versão e o da outra (último dia do
     * mês da última medição prevista). Positivo é atraso.
     */
    public function completionVariationDays(): ?int
    {
        return $this->baseCompletion === null || $this->candidateCompletion === null
            ? null
            : (int) $this->baseCompletion->diffInDays($this->candidateCompletion);
    }

    public function hasLineChanges(): bool
    {
        return $this->addedLines !== [] || $this->removedLines !== [] || $this->changedLines !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'base_version_id' => $this->base?->getKey(),
            'base_version_number' => $this->base?->version_number,
            'candidate_version_id' => $this->candidate->getKey(),
            'candidate_version_number' => $this->candidate->version_number,
            'base_effective_from' => $this->base?->effective_from?->toDateString(),
            'candidate_effective_from' => $this->candidate->effective_from?->toDateString(),
            'base_construction_fund_amount' => $this->baseFundCents === null ? null : IntegerMoney::decimalString($this->baseFundCents),
            'candidate_construction_fund_amount' => $this->candidateFundCents === null ? null : IntegerMoney::decimalString($this->candidateFundCents),
            'construction_fund_variation_amount' => ($variation = $this->fundVariationCents()) === null ? null : IntegerMoney::decimalString($variation),
            'construction_fund_variation_percent' => ($share = $this->fundVariationBasisPoints()) === null ? null : IntegerMoney::decimalString($share),
            'base_completion' => $this->baseCompletion?->toDateString(),
            'candidate_completion' => $this->candidateCompletion?->toDateString(),
            'completion_variation_days' => $this->completionVariationDays(),
            'current_physical_progress_percent' => MeasurementPhysicalProgress::decimal($this->currentBasisPoints),
            'remaining_physical_progress_percent' => MeasurementPhysicalProgress::decimal($this->remainingBasisPoints),
            'base_final_planned_cumulative_percent' => $this->baseFinalCumulativeBasisPoints === null ? null : MeasurementPhysicalProgress::decimal($this->baseFinalCumulativeBasisPoints),
            'candidate_final_planned_cumulative_percent' => $this->candidateFinalCumulativeBasisPoints === null ? null : MeasurementPhysicalProgress::decimal($this->candidateFinalCumulativeBasisPoints),
            'added_lines' => $this->addedLines,
            'removed_lines' => $this->removedLines,
            'changed_lines' => $this->changedLines,
            'unchanged_lines' => $this->unchangedLines,
            'open_measurement_ids_on_base' => $this->openMeasurementIdsOnBase,
            'pending_before_effective_percent' => $this->pendingBeforeEffectiveBasisPoints === null ? null : MeasurementPhysicalProgress::decimal($this->pendingBeforeEffectiveBasisPoints),
        ];
    }
}
