<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class MeasurementEngineeringService
{
    public const SNAPSHOT_SCHEMA_VERSION = 2;

    public function __construct(
        private MeasurementFileValidationService $fileValidation,
        private MeasurementPhysicalProgressService $physicalProgress,
    ) {}

    /**
     * Validate and persist the Engineering data while the caller holds the
     * measurement workflow transaction.
     *
     * @param  array<int|string, mixed>  $monthlyProgress
     * @return array{
     *     schema_version: int,
     *     measurement_id: int,
     *     operation_id: int,
     *     emission_id: int|null,
     *     reference_month: string,
     *     plan_sets: array<int, array{
     *         plan_set_id: int,
     *         construction_id: int|null,
     *         construction_emission_id: int|null,
     *         construction_cnpj: string|null,
     *         plan_set_name: string,
     *         construction_name: string|null,
     *         is_default: bool,
     *         construction_fund_amount: string|null,
     *         initial_incurred_amount: string|null,
     *         plan_line_id: int,
     *         measurement_date: string,
     *         sequence_number: int,
     *         planned_monthly_percent: string,
     *         planned_cumulative_percent: string,
     *         initial_realized_cumulative_percent: string,
     *         realized_monthly_percent: string,
     *         realized_cumulative_percent: string,
     *         plan_initial_physical_progress_percent: string,
     *         prior_realized_cumulative_percent: string,
     *         asset_id: int,
     *         storage_path: string,
     *         storage_disk: string,
     *         sha256: string,
     *         mime_type: string,
     *         file_size: int
     *     }>
     * }
     */
    public function validateAndRecord(Measurement $measurement, array $monthlyProgress): array
    {
        $measurement->loadMissing('operation');
        $errors = [];

        if ($measurement->reference_month === null) {
            $errors['reference_month'] = 'Informe a competência da medição antes de aprovar a Engenharia.';
        }

        $planSets = $measurement->operation?->planSets()
            ->orderBy('id')
            ->lockForUpdate()
            ->get() ?? collect();

        if ($planSets->isEmpty()) {
            $errors['plan'] = 'A operação precisa possuir ao menos um plano de medição.';
        }

        $constructions = $this->lockConstructions($planSets);
        $assets = $measurement->assets()->orderBy('id')->lockForUpdate()->get();
        $assetsByPlanSet = $assets->keyBy('plan_set_id');
        // Leitura comum depois do lock da Operation (MeasurementWorkflow::lockMeasurementWithOperation):
        // enxerga toda aprovação anterior da operação, e nenhuma outra commita até o fim desta.
        $physicalProgress = $this->physicalProgress->compose(
            $this->physicalProgress->sources((int) $measurement->operation_id),
            $planSets,
            (int) $measurement->getKey(),
        );
        $validatedLines = [];

        if ($assets->count() !== $planSets->count()
            || $assetsByPlanSet->count() !== $planSets->count()) {
            $errors['assets.coverage'] = 'Envie exatamente um arquivo para cada empreendimento da operação.';
        }

        foreach ($planSets as $planSet) {
            $construction = filled($planSet->construction_id)
                ? $constructions->get((int) $planSet->construction_id)
                : null;
            $asset = $assetsByPlanSet->get($planSet->getKey());
            $label = $this->planSetLabel($planSet, $construction);

            if (filled($planSet->construction_id) && ! $construction instanceof Construction) {
                $errors["construction.{$planSet->getKey()}"] = "O empreendimento de {$label} não está mais disponível.";
            }

            if (! $asset instanceof MeasurementAsset) {
                $errors["assets.{$planSet->getKey()}"] = "Envie o arquivo da medição para {$label}.";

                continue;
            }

            try {
                $this->fileValidation->validateStoredAsset($asset->storage_path, $asset->resolved_storage_disk);
            } catch (ValidationException) {
                $errors["assets.{$planSet->getKey()}"] = "O arquivo de medição de {$label} não foi encontrado no armazenamento.";
            }

            if (! is_string($asset->sha256) || mb_strlen($asset->sha256) !== 64) {
                $errors["assets.{$planSet->getKey()}.sha256"] = "O arquivo de medição de {$label} não possui SHA-256 válido.";
            }

            if (! is_string($asset->mime_type)
                || $asset->mime_type === ''
                || ! is_int($asset->size)
                || $asset->size < 1) {
                $errors["assets.{$planSet->getKey()}.metadata"] = "O arquivo de medição de {$label} não possui MIME e tamanho válidos.";
            }

            $line = MeasurementPlanLine::query()
                ->whereKey($asset->plan_line_id)
                ->lockForUpdate()
                ->first();

            if (! $line instanceof MeasurementPlanLine
                || (int) $line->plan_set_id !== (int) $planSet->getKey()
                || (int) $line->operation_id !== (int) $measurement->operation_id) {
                $errors["plan_line.{$planSet->getKey()}"] = "Selecione uma linha válida do plano para {$label}.";

                continue;
            }

            if ($line->measurement_date === null) {
                $errors["measurement_date.{$planSet->getKey()}"] = "Informe a data da medição de {$label}.";
            } elseif ($measurement->reference_month !== null
                && $line->measurement_date->format('Y-m') !== $measurement->reference_month->format('Y-m')) {
                $errors["measurement_date.{$planSet->getKey()}"] = "A data da medição de {$label} não corresponde à competência informada.";
            }

            $monthlyError = $this->monthlyProgressError($monthlyProgress[$planSet->getKey()] ?? null, $label);

            if ($monthlyError !== null) {
                $errors["realized.{$planSet->getKey()}"] = $monthlyError;

                continue;
            }

            $monthly = (int) $this->basisPoints($monthlyProgress[$planSet->getKey()]);
            $progress = $physicalProgress[(int) $planSet->getKey()];
            $physicalError = $this->physicalProgressError($progress, $line, $label, $monthly);

            if ($physicalError !== null) {
                $errors["realized.{$planSet->getKey()}"] = $physicalError;

                continue;
            }

            // Acumulado na posição desta linha: o avanço inicial do plano mais o
            // que as medições vigentes registraram até aqui -- nunca o valor de
            // uma linha anterior não medida.
            $prior = $progress->cumulativeThroughPosition($line->measurement_date, (int) $line->sequence_number);
            $cumulative = MeasurementPhysicalProgress::decimal($prior + $monthly);
            $prior = MeasurementPhysicalProgress::decimal($prior);
            $initial = $progress->initialPercent();
            $monthly = MeasurementPhysicalProgress::decimal($monthly);

            $validatedLines[] = compact('planSet', 'construction', 'line', 'asset', 'monthly', 'cumulative', 'prior', 'initial');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($validatedLines as $validated) {
            $validated['line']->forceFill([
                'realized_monthly_percent' => $validated['monthly'],
                'realized_cumulative_percent' => $validated['cumulative'],
                'measurement_id' => $measurement->getKey(),
            ])->save();
        }

        return [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'measurement_id' => (int) $measurement->getKey(),
            'operation_id' => (int) $measurement->operation_id,
            'emission_id' => $measurement->operation?->emission_id === null
                ? null
                : (int) $measurement->operation->emission_id,
            'reference_month' => $measurement->reference_month?->toDateString() ?? '',
            'plan_sets' => collect($validatedLines)
                ->map(fn (array $validated): array => $this->snapshotPlanSet($validated))
                ->sortBy('plan_set_id')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, MeasurementPlanSet>  $planSets
     * @return Collection<int, Construction>
     */
    private function lockConstructions(Collection $planSets): Collection
    {
        $ids = $planSets->pluck('construction_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return Construction::query()
            ->whereKey($ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Por que o avanço informado não pode valer, ou `null` quando pode.
     *
     * As recusas usam a chave `realized.{plano}` para aparecerem embaixo do
     * campo do empreendimento no modal. O teto é conferido aqui, dentro da
     * transação da aprovação e depois do lock da Operation: a leitura já
     * enxerga a aprovação anterior da mesma operação, e duas aprovações
     * simultâneas não somam a partir da mesma base.
     */
    private function physicalProgressError(MeasurementPhysicalProgress $progress, MeasurementPlanLine $line, string $label, int $monthly): ?string
    {
        if (! $progress->isVerifiable()) {
            return sprintf(
                'Não foi possível conferir o limite de 100%% de %s: a medição #%d tem a Engenharia aprovada sem o avanço físico registrado.',
                $label,
                $progress->unverifiedMeasurementIds[0],
            );
        }

        // Só o avanço positivo contaria duas vezes; 0% numa competência coberta
        // não soma nada, e recusá-lo travaria a medição dos outros empreendimentos.
        if ($monthly > 0 && $line->measurement_date !== null && $progress->initialProgressCovers($line->measurement_date)) {
            return sprintf(
                'A competência %s de %s já está coberta pelo avanço físico inicial de %s (referência %s).',
                $line->measurement_date->format('m/Y'),
                $label,
                MeasurementPhysicalProgress::format($progress->initialBasisPoints),
                $progress->initialReferenceDate?->format('d/m/Y'),
            );
        }

        $claimant = $progress->lineClaimant((int) $line->getKey());

        if ($claimant !== null) {
            return sprintf(
                'A medição %s (%s) do cronograma de %s já está vinculada à medição #%d, aprovada pela Engenharia. Recuse esta medição ou corrija a linha do cronograma escolhida no envio.',
                str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT),
                $line->measurement_date?->format('m/Y') ?? 'sem data',
                $label,
                $claimant,
            );
        }

        if ($progress->exceedsLimitWith($monthly)) {
            return $progress->limitExceededMessage($label, $monthly);
        }

        return null;
    }

    /**
     * Zero é um mês sem avanço medido -- não um mês sem medição. Negativo não é
     * correção: a medição aprovada errada volta à Engenharia pelo fluxo.
     */
    private function monthlyProgressError(mixed $value, string $label): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return "Informe o percentual realizado de {$label}.";
        }

        $basisPoints = $this->basisPoints($value);

        if ($basisPoints === null) {
            return "Informe para {$label} o percentual realizado com no máximo duas casas decimais.";
        }

        if ($basisPoints < 0) {
            return "O percentual realizado de {$label} não pode ser negativo. Para corrigir uma medição já aprovada, devolva-a à Engenharia.";
        }

        if ($basisPoints > MeasurementPhysicalProgress::LIMIT_BASIS_POINTS) {
            return "Informe para {$label} um percentual realizado de no máximo 100%.";
        }

        return null;
    }

    /**
     * Percentual informado em basis points, sem float; `null` para formato
     * inválido ou mais de duas casas decimais.
     */
    private function basisPoints(mixed $value): ?int
    {
        return MeasurementPhysicalProgress::basisPoints($value);
    }

    /**
     * Além do que a Finalização confere, o snapshot guarda a base que decidiu
     * o acumulado e o teto: o avanço inicial do plano e o acumulado vigente
     * antes desta medição na mesma posição. Chaves acrescentadas sem trocar a
     * versão: os leitores conferem só as chaves que conhecem.
     *
     * @param  array{planSet: MeasurementPlanSet, construction: Construction|null, line: MeasurementPlanLine, asset: MeasurementAsset, monthly: string, cumulative: string, prior: string, initial: string}  $validated
     * @return array<string, bool|int|string|null>
     */
    private function snapshotPlanSet(array $validated): array
    {
        $planSet = $validated['planSet'];
        $construction = $validated['construction'];
        $line = $validated['line'];
        $asset = $validated['asset'];

        return [
            'plan_set_id' => (int) $planSet->getKey(),
            'construction_id' => $planSet->construction_id === null ? null : (int) $planSet->construction_id,
            'construction_emission_id' => $construction?->emission_id === null ? null : (int) $construction->emission_id,
            'construction_cnpj' => $construction?->development_cnpj,
            'plan_set_name' => (string) $planSet->name,
            'construction_name' => $construction?->development_name,
            'is_default' => (bool) $planSet->is_default,
            'construction_fund_amount' => $this->nullableDecimal($planSet->construction_fund_amount),
            'initial_incurred_amount' => $this->nullableDecimal($planSet->initial_incurred_amount),
            'plan_line_id' => (int) $line->getKey(),
            'measurement_date' => $line->measurement_date?->toDateString() ?? '',
            'sequence_number' => (int) $line->sequence_number,
            'planned_monthly_percent' => $this->decimal($line->planned_monthly_percent),
            'planned_cumulative_percent' => $this->decimal($line->planned_cumulative_percent),
            'initial_realized_cumulative_percent' => $this->decimal($line->initial_realized_cumulative_percent),
            'realized_monthly_percent' => $this->decimal($line->realized_monthly_percent),
            'realized_cumulative_percent' => $this->decimal($line->realized_cumulative_percent),
            'plan_initial_physical_progress_percent' => $validated['initial'],
            'prior_realized_cumulative_percent' => $validated['prior'],
            'asset_id' => (int) $asset->getKey(),
            'storage_path' => (string) $asset->storage_path,
            'storage_disk' => $asset->resolved_storage_disk,
            'sha256' => (string) $asset->sha256,
            'mime_type' => (string) $asset->mime_type,
            'file_size' => (int) $asset->size,
        ];
    }

    private function planSetLabel(MeasurementPlanSet $planSet, ?Construction $construction): string
    {
        return $construction?->development_name ?? $planSet->name;
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function nullableDecimal(mixed $value): ?string
    {
        return $value === null ? null : $this->decimal($value);
    }
}
