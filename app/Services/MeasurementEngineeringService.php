<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementPhysicalProgress;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MeasurementEngineeringService
{
    public const SNAPSHOT_SCHEMA_VERSION = 2;

    public function __construct(
        private MeasurementFileValidationService $fileValidation,
        private MeasurementPhysicalProgressService $physicalProgress,
        private MeasurementPlanVersionResolver $versions,
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
     *         plan_version_id: int|null,
     *         plan_version_number: int|null,
     *         plan_line_id: int,
     *         plan_line_lineage_key: string,
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

        $coversOwnFilesOnly = $measurement->payments()->exists();
        $planSets = $this->requiredPlanSets($measurement, $coversOwnFilesOnly);

        if ($planSets->isEmpty()) {
            $errors['plan'] = $coversOwnFilesOnly
                ? 'Os arquivos desta medição não correspondem a nenhum empreendimento da operação.'
                : 'A operação precisa possuir ao menos um plano de medição.';
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
            $errors['assets.coverage'] = $coversOwnFilesOnly
                ? 'Cada arquivo desta medição precisa corresponder a um empreendimento da operação, um arquivo por empreendimento.'
                : 'Envie exatamente um arquivo para cada empreendimento da operação.';
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
            } catch (ValidationException $refusal) {
                $errors["assets.{$planSet->getKey()}"] = "O arquivo de medição de {$label} não foi encontrado no armazenamento.";
                $this->logIntegrityRefusal($measurement, $asset, 'arquivo_ausente_ou_invalido', $refusal);
            }

            if (! is_string($asset->sha256) || mb_strlen($asset->sha256) !== 64) {
                $errors["assets.{$planSet->getKey()}.sha256"] = "O arquivo de medição de {$label} não possui SHA-256 válido.";
                $this->logIntegrityRefusal($measurement, $asset, 'sha256_invalido');
            }

            if (! is_string($asset->mime_type)
                || $asset->mime_type === ''
                || ! is_int($asset->size)
                || $asset->size < 1) {
                $errors["assets.{$planSet->getKey()}.metadata"] = "O arquivo de medição de {$label} não possui MIME e tamanho válidos.";
                $this->logIntegrityRefusal($measurement, $asset, 'metadados_invalidos');
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

            // A medição é aprovada contra a versão em que foi enviada: a linha
            // precisa ser dela, mesmo que outra versão já esteja vigente. Todo
            // arquivo de plano grava a versão (no envio, ou na migração das
            // versões para os anteriores a elas); sem ela não há o que aprovar.
            if ((int) $line->plan_version_id !== (int) $asset->plan_version_id) {
                $errors["plan_line.{$planSet->getKey()}"] = "A linha do cronograma de {$label} não é da versão do plano em que a medição foi enviada.";

                continue;
            }

            $version = MeasurementPlanVersion::query()->find($asset->plan_version_id);

            if ($line->measurement_date === null) {
                $errors["measurement_date.{$planSet->getKey()}"] = "Informe a data da medição de {$label}.";
            } elseif ($measurement->reference_month !== null
                && $line->measurement_date->format('Y-m') !== $measurement->reference_month->format('Y-m')) {
                $errors["measurement_date.{$planSet->getKey()}"] = "A data da medição de {$label} não corresponde à competência informada.";
            } elseif (! $coversOwnFilesOnly && (! $version instanceof MeasurementPlanVersion || ! $this->versions->governs($version, $line->measurement_date))) {
                // A versão congelada no envio é a que rege a competência, e a
                // ativação nunca desloca a de uma medição de pé. Aqui a defesa
                // cobre o arquivo gravado fora dessa regra: a Engenharia não
                // aprova uma competência contra o cronograma e o Fundo de Obra
                // de outra versão. Com pagamento, não: o contexto pago não muda
                // mais (arquivo, linha e competência estão presos a ele) e a
                // medição não tem recusa terminal -- recusá-la aqui a deixaria
                // sem saída. Pela regra atual, nenhuma medição paga chega a
                // este estado; só o arquivo gravado pela regra anterior.
                $errors["plan_line.{$planSet->getKey()}"] = sprintf(
                    'A medição de %s foi enviada sob a %s do plano, que não vale para a competência %s: recuse esta medição e envie uma nova.',
                    $label,
                    $version?->label() ?? 'versão',
                    $line->measurement_date->format('m/Y'),
                );

                continue;
            }

            $monthlyError = $this->monthlyProgressError($monthlyProgress[$planSet->getKey()] ?? null, $label);

            if ($monthlyError !== null) {
                $errors["realized.{$planSet->getKey()}"] = $monthlyError;

                continue;
            }

            $monthly = (int) $this->basisPoints($monthlyProgress[$planSet->getKey()]);
            $progress = $physicalProgress[(int) $planSet->getKey()];
            $physicalError = $this->physicalProgressError($progress, $line, $label, $monthly)
                ?? $this->lineClaimError($measurement, $asset, $line, $label);

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

            $validatedLines[] = compact('planSet', 'construction', 'line', 'asset', 'version', 'monthly', 'cumulative', 'prior', 'initial');
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
     * Os empreendimentos que esta aprovação precisa cobrir, um arquivo para
     * cada, travados em ordem de id.
     *
     * Sem pagamento, todos os planos da operação que já estavam em vigor
     * quando a medição foi enviada e preveem medição na competência dela --
     * pela versão que regia a competência naquele momento: a
     * Engenharia confirma a competência da obra inteira. O plano que só passou
     * a valer depois do envio não é exigido -- o arquivo dele não se acrescenta
     * pela edição, e a medição ficaria sem saída a cada volta à Engenharia; ele
     * é medido pelas medições seguintes. O plano sem medição prevista na
     * competência também não: não há linha dele para o arquivo. O plano ainda
     * em rascunho não recebe medição. Uma revisão ativada depois do envio não
     * muda nada aqui: o plano é o mesmo, e o arquivo continua na versão em que
     * foi enviado.
     *
     * Com pagamento, só os empreendimentos dos arquivos da própria medição. O
     * pagamento foi registrado sobre esse contexto, os arquivos dele não podem
     * mais sair ({@see MeasurementAsset::PAID_FILE_REMOVAL_REFUSAL}) e a
     * medição paga devolvida à Engenharia não tem recusa terminal: exigir a
     * obra que entrou na operação depois a deixaria sem saída, porque o Editar
     * não acrescenta arquivo. A obra nova é medida pelas medições seguintes.
     *
     * A leitura dos arquivos acontece sob o lock da Operation e da medição que
     * a aprovação já detém ({@see MeasurementWorkflow::approve()}); os arquivos
     * são travados logo depois, e a cobertura confere os dois lados.
     *
     * @return Collection<int, MeasurementPlanSet>
     */
    private function requiredPlanSets(Measurement $measurement, bool $coversOwnFilesOnly): Collection
    {
        $planSets = $measurement->operation?->planSets();

        if ($planSets === null) {
            return new Collection;
        }

        if ($coversOwnFilesOnly) {
            $planSets->whereKey($measurement->assets()
                ->whereNotNull('plan_set_id')
                ->distinct()
                ->pluck('plan_set_id')
                ->map(fn (mixed $planSetId): int => (int) $planSetId)
                ->all());
        } else {
            // Os planos em vigor quando a medição foi enviada: o envio os cobre
            // (CreateMeasurement confere sob o lock da Operation), e o arquivo
            // de um plano ativado depois não se acrescenta pela edição. Um
            // plano vale para a medição se alguma versão dele foi ativada antes
            // dela -- a ativação guarda a última medição da operação até ali,
            // e as duas se serializam pela Operation, então o id separa antes
            // e depois. Plano com arquivo nesta medição também é exigido.
            $measurementId = (int) $measurement->getKey();
            $competence = $measurement->reference_month;

            // E só o plano que prevê medição na competência pela versão que a
            // regia no envio ({@see MeasurementPlanVersionResolver}, com o
            // histórico até esta medição): o arquivo de um plano tem de ser de
            // uma medição prevista do mês da medição, e o plano sem nenhuma ali
            // (a obra que começa depois, ou que já terminou) deixaria a
            // competência sem como ser medida. Uma revisão ativada depois do
            // envio, que acrescente medição prevista no mês, não muda o que a
            // medição já enviada precisa cobrir.
            $planSets->where(fn (Builder $required): Builder => $required
                ->whereHas('assets', fn (Builder $assets): Builder => $assets->where('measurement_id', $measurementId))
                ->orWhere(fn (Builder $inForce): Builder => $competence === null
                    ? $inForce->whereHas('versions', fn (Builder $versions): Builder => $versions
                        ->whereNotNull('activated_at')
                        ->where(fn (Builder $before): Builder => $before
                            ->whereNull('last_measurement_id_at_activation')
                            ->orWhere('last_measurement_id_at_activation', '<', $measurementId)))
                    : $inForce->whereHas('lines', fn (Builder $lines): Builder => $lines
                        ->governingTheirCompetence($measurementId)
                        ->inCompetence($competence))));
        }

        return $planSets->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * A recusa de integridade de um arquivo da Engenharia vai para o log.
     *
     * A pessoa lê a recusa no modal, mas ela é uma `ValidationException`, que
     * não é reportada: um arquivo que some do armazenamento -- blob apagado,
     * volume que não montou -- ou cujos metadados não conferem só aparecia
     * pela reclamação de quem não consegue aprovar. É sinal de incidente de
     * infraestrutura, não erro de quem envia; o registro leva o que a operação
     * precisa para achar o arquivo.
     */
    private function logIntegrityRefusal(Measurement $measurement, MeasurementAsset $asset, string $reason, ?ValidationException $refusal = null): void
    {
        Log::warning('Arquivo de medição recusado na conferência de integridade da Engenharia.', [
            'reason' => $reason,
            'measurement_id' => (int) $measurement->getKey(),
            'asset_id' => (int) $asset->getKey(),
            'plan_set_id' => $asset->plan_set_id === null ? null : (int) $asset->plan_set_id,
            'disk' => $asset->resolved_storage_disk,
            'relative_path' => $asset->storage_path,
            'validation' => $refusal === null ? null : collect($refusal->errors())->flatten()->first(),
        ]);
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

        // A ocupação é da medição prevista, em qualquer versão do plano: a cópia
        // na V2 de uma linha medida na V1 continua ocupada.
        $claimant = $progress->lineageClaimant((string) $line->lineage_key);

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
     * O arquivo precisa ocupar a medição prevista que a Engenharia vai
     * aprovar (`line_claim_key`): a ocupação nasce no envio e é única no
     * banco. Arquivo anterior a ela, sem ocupação gravada, ocupa agora se a
     * linhagem estiver livre; se outra medição de pé já a ocupa, esta é a que
     * sobrou de uma corrida e não é aprovada sobre ela.
     */
    private function lineClaimError(Measurement $measurement, MeasurementAsset $asset, MeasurementPlanLine $line, string $label): ?string
    {
        if ($asset->line_claim_key === $line->lineage_key) {
            return null;
        }

        $holder = MeasurementAsset::query()
            ->where('line_claim_key', $line->lineage_key)
            ->whereKeyNot($asset->getKey())
            ->value('measurement_id');

        if ($holder !== null && (int) $holder !== (int) $measurement->getKey()) {
            return sprintf(
                'A medição %s (%s) do cronograma de %s está ocupada pela medição #%d. Recuse esta medição ou corrija a linha do cronograma escolhida no envio.',
                str_pad((string) $line->sequence_number, 2, '0', STR_PAD_LEFT),
                $line->measurement_date?->format('m/Y') ?? 'sem data',
                $label,
                (int) $holder,
            );
        }

        MeasurementAsset::query()->whereKey($asset->getKey())->update(['line_claim_key' => $line->lineage_key]);
        $asset->setRawAttributes(['line_claim_key' => $line->lineage_key] + $asset->getAttributes(), sync: true);

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
     * O Fundo de Obra é o da versão congelada no arquivo -- a que rege a
     * competência --: uma revisão de custo ativada depois, ou vigente quando
     * uma competência anterior foi enviada com atraso, não alcança esta
     * medição.
     *
     * @param  array{planSet: MeasurementPlanSet, construction: Construction|null, line: MeasurementPlanLine, asset: MeasurementAsset, version: MeasurementPlanVersion|null, monthly: string, cumulative: string, prior: string, initial: string}  $validated
     * @return array<string, bool|int|string|null>
     */
    private function snapshotPlanSet(array $validated): array
    {
        $planSet = $validated['planSet'];
        $construction = $validated['construction'];
        $line = $validated['line'];
        $asset = $validated['asset'];
        $version = $validated['version'];

        return [
            'plan_set_id' => (int) $planSet->getKey(),
            'construction_id' => $planSet->construction_id === null ? null : (int) $planSet->construction_id,
            'construction_emission_id' => $construction?->emission_id === null ? null : (int) $construction->emission_id,
            'construction_cnpj' => $construction?->development_cnpj,
            'plan_set_name' => (string) $planSet->name,
            'construction_name' => $construction?->development_name,
            'is_default' => (bool) $planSet->is_default,
            'construction_fund_amount' => $this->nullableDecimal($version?->construction_fund_amount),
            'initial_incurred_amount' => $this->nullableDecimal($planSet->initial_incurred_amount),
            'plan_version_id' => $version instanceof MeasurementPlanVersion ? (int) $version->getKey() : null,
            'plan_version_number' => $version instanceof MeasurementPlanVersion ? (int) $version->version_number : null,
            'plan_line_id' => (int) $line->getKey(),
            'plan_line_lineage_key' => (string) $line->lineage_key,
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
