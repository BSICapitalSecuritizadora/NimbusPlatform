<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveChangeAssessment;
use App\Domain\PuCalculator\DTOs\PuCurveExtensionResult;
use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Extensão diária da curva com o índice realizado recém-publicado.
 *
 * Em vez de gravar a curva inteira a cada CDI publicado, a rotina recalcula a
 * curva em memória, prova que o trecho já gravado continua idêntico e grava só
 * os dias novos na MESMA versão. A curva é encadeada -- cada dia parte do
 * anterior --, então anexar em cima de um passado que mudou (CDI corrigido,
 * evento editado, feriado, parâmetro, integralização retroativa) produziria uma
 * curva errada com cara de certa. Por isso a comparação é dia a dia, com a
 * mesma canonicalização do checksum da curva. Os dias anexados são só os que o
 * índice REALIZADO sustenta: a engine para na primeira observação exigida que
 * ainda não existe, então a extensão nunca grava futuro.
 *
 * Duas finalidades, nunca confundidas:
 *
 *  - extensão OFICIAL ({@see self::extendOfficial()}): avança a curva
 *    homologada vigente, a que as outras áreas leem. Uma versão mais nova,
 *    gerada, validada ou com erro, não a desloca nem a bloqueia;
 *  - extensão de TRABALHO ({@see self::extendWorking()}): avança a versão
 *    operacional não homologada mais recente, em revisão. Sem curva oficial, é
 *    o mesmo comportamento de antes desta separação.
 *
 * Quando o passado diverge:
 *  - numa curva comum, a extensão devolve `diverged` e quem chamou gera a curva
 *    inteira numa versão nova, como antes;
 *  - numa curva governada (homologada ou promovida pela revisão), nada é trocado:
 *    a divergência fica registrada na versão, a extensão fica suspensa e o
 *    reprocessamento passa a ser decisão humana.
 *
 * Uma extensão que não consegue rodar (pré-requisito bloqueado ou erro de
 * cálculo) fica registrada na própria versão (`extension_failed_at`): é o que
 * separa "a curva ainda não foi estendida" de "a extensão falhou".
 *
 * Fase 4 -- índice novo estende, contrato novo não. A curva é recalculada a partir
 * do RETRATO de insumos aprovado na versão, nunca das tabelas vivas: um evento,
 * parâmetro ou integralização que a versão não aprovou nunca entra nela. Antes de
 * anexar, os insumos vivos são comparados com o retrato
 * ({@see PuCurveChangeImpactClassifier}):
 *
 *  - iguais: a extensão segue (inclusive aplicando, na data, o evento futuro que a
 *    versão já aprovou);
 *  - mudança só depois do último dia gravado: a versão avança no máximo até a
 *    véspera da data afetada e fica registrada como pendente de versão nova;
 *  - mudança que alcança dias gravados: nada é anexado; a versão governada fica
 *    marcada para reprocessamento, e a de trabalho comum é regerada.
 *
 * A mesma comparação é refeita dentro da transação, com os insumos lidos sob
 * trava compartilhada: uma mudança contratual concorrente ou comitou antes (e é
 * vista) ou espera o commit da extensão. Versão sem retrato (anterior à Fase 4)
 * não é estendida: não há o que provar.
 */
final class PuCurveExtensionService
{
    public const PURPOSE_OFFICIAL = 'official';

    public const PURPOSE_WORKING = 'working';

    public const ACTION_EXTENDED = 'extended';

    public const ACTION_UP_TO_DATE = 'up_to_date';

    public const ACTION_NO_CURRENT_VERSION = 'no_current_version';

    public const ACTION_NO_OFFICIAL_VERSION = 'no_official_version';

    public const ACTION_NO_WORKING_VERSION = 'no_working_version';

    public const ACTION_NOT_EXTENDABLE = 'not_extendable';

    public const ACTION_PREREQUISITES_BLOCKED = 'prerequisites_blocked';

    public const ACTION_DIVERGED = 'diverged';

    public const ACTION_DIVERGED_GOVERNED = 'diverged_governed';

    public const ACTION_CONTRACTUAL_CHANGE_PENDING = 'contractual_change_pending';

    public const CAUSE_CONTRACTUAL_INPUT_CHANGED = 'contractual_input_changed';

    /** @var list<PuCurveStatus> */
    private const EXTENDABLE_STATUSES = [
        PuCurveStatus::Generated,
        PuCurveStatus::Validated,
        PuCurveStatus::Divergent,
        PuCurveStatus::Homologated,
    ];

    public function __construct(
        private readonly PuCurvePrerequisiteService $prerequisites,
        private readonly PuCurveGeneratorService $generator,
        private readonly PuPersistedCurveChecksumService $checksums,
        private readonly PuOperationalProfileGuard $operationalProfiles,
        private readonly PuAuditLogService $auditLog,
        private readonly PuFinancialObligationService $obligations,
        private readonly PuCurveInputSnapshotService $snapshots,
        private readonly PuCurveChangeImpactClassifier $classifier,
    ) {}

    /**
     * A curva oficial quando existe; sem ela, a versão de trabalho -- o mesmo
     * alvo que a rotina diária escolhe primeiro.
     */
    public function extend(Emission $emission): PuCurveExtensionResult
    {
        return $emission->officialPuCurveVersion() instanceof EmissionPuCurveVersion
            ? $this->extendOfficial($emission)
            : $this->extendWorking($emission);
    }

    /**
     * Avança a curva homologada vigente com o índice realizado novo. Sem curva
     * oficial não faz nada: nenhuma rotina torna uma curva oficial.
     */
    public function extendOfficial(Emission $emission): PuCurveExtensionResult
    {
        $official = $emission->officialPuCurveVersion();

        if (! $official instanceof EmissionPuCurveVersion) {
            return new PuCurveExtensionResult(
                action: self::ACTION_NO_OFFICIAL_VERSION,
                purpose: self::PURPOSE_OFFICIAL,
            );
        }

        return $this->extendVersion($emission, $official, self::PURPOSE_OFFICIAL);
    }

    /**
     * Avança a versão de trabalho (operacional, não homologada, a vigente mais
     * recente). Nunca toca a oficial: se a vigente é a própria homologada, não há
     * versão de trabalho a estender.
     */
    public function extendWorking(Emission $emission): PuCurveExtensionResult
    {
        $current = $emission->currentPuCurveVersion();

        if (! $current instanceof EmissionPuCurveVersion) {
            return new PuCurveExtensionResult(
                action: self::ACTION_NO_CURRENT_VERSION,
                purpose: self::PURPOSE_WORKING,
            );
        }

        if ($current->status->isOfficial()) {
            return new PuCurveExtensionResult(
                action: self::ACTION_NO_WORKING_VERSION,
                versionId: $current->id,
                purpose: self::PURPOSE_WORKING,
            );
        }

        return $this->extendVersion($emission, $current, self::PURPOSE_WORKING);
    }

    /**
     * A versão de trabalho além da oficial, ou nula.
     */
    public function workingVersion(Emission $emission): ?EmissionPuCurveVersion
    {
        $current = $emission->currentPuCurveVersion();

        return $current instanceof EmissionPuCurveVersion && ! $current->status->isOfficial()
            ? $current
            : null;
    }

    /**
     * Homologada ou promovida pela revisão: o conteúdo foi aprovado por alguém e
     * não pode ser trocado pela rotina.
     */
    public function isGoverned(EmissionPuCurveVersion $version): bool
    {
        return $version->status === PuCurveStatus::Homologated
            || $version->review_status === PuCurveReviewStatus::Approved;
    }

    private function extendVersion(Emission $emission, EmissionPuCurveVersion $version, string $purpose): PuCurveExtensionResult
    {
        if (! in_array($version->status, self::EXTENDABLE_STATUSES, true)) {
            return new PuCurveExtensionResult(
                action: self::ACTION_NOT_EXTENDABLE,
                versionId: $version->id,
                reason: sprintf('A versão %s está em %s.', $version->calculation_version, $version->status->label()),
                purpose: $purpose,
            );
        }

        try {
            $approved = $this->snapshots->forVersion($version);
        } catch (PuCurveInputsException $exception) {
            return $this->refuseWithoutInputs($version, $exception->getMessage(), $purpose);
        }

        if (! $approved instanceof PuCurveInputSnapshot) {
            return $this->refuseWithoutInputs(
                $version,
                sprintf(
                    'A versão %s não tem retrato de insumos contratuais (gerada antes da Fase 4): a extensão não tem como provar o que ela aprovou. Gere uma nova versão.',
                    $version->calculation_version,
                ),
                $purpose,
            );
        }

        try {
            $prerequisites = $this->prerequisites->handle($emission);

            if (! $prerequisites->passes()) {
                $reason = $prerequisites->blockingSummary();
                $this->recordFailure($version, self::ACTION_PREREQUISITES_BLOCKED, $reason, $purpose);

                return new PuCurveExtensionResult(
                    action: self::ACTION_PREREQUISITES_BLOCKED,
                    versionId: $version->id,
                    reason: $reason,
                    purpose: $purpose,
                );
            }

            $assessment = $this->classifier->compare(
                $approved,
                $this->snapshots->capture($emission),
                $this->classifier->lastPersistedDate($version),
            );

            if (($contractual = $this->contractualOutcome($version, $assessment, $purpose)) instanceof PuCurveExtensionResult) {
                return $contractual;
            }

            // Calculada a partir do retrato aprovado: o que a versão não aprovou não entra.
            $computedRows = $this->generator->handle($this->snapshots->hydrate($emission, $approved))->rows;
            $this->operationalProfiles->assertOperational($computedRows, 'a extensão da curva operacional');
            $persistedRows = $this->checksums->persistedRows($version);
        } catch (Throwable $exception) {
            $this->recordFailure($version, 'error', $exception->getMessage(), $purpose);

            throw $exception;
        }

        if ($persistedRows === []) {
            return $this->diverged($version, null, 'A versão não tem linhas gravadas.', $purpose);
        }

        $lastPersistedDate = $persistedRows[array_key_last($persistedRows)]->date->toDateString();
        $prefix = array_values(array_filter(
            $computedRows,
            fn (PuDailyCurveRowData $row): bool => $row->date->toDateString() <= $lastPersistedDate,
        ));
        $tail = array_values(array_filter(
            $computedRows,
            fn (PuDailyCurveRowData $row): bool => $row->date->toDateString() > $lastPersistedDate,
        ));

        if (! hash_equals($this->checksums->checksumForRows($persistedRows), $this->checksums->checksumForRows($prefix))) {
            return $this->diverged(
                $version,
                $this->firstDivergentDate($persistedRows, $prefix),
                'O recálculo não reproduz o trecho já gravado da curva.',
                $purpose,
            );
        }

        if ($version->extension_diverged_at !== null || $version->extension_failed_at !== null) {
            $wasDiverged = $version->extension_diverged_at !== null;
            $version->forceFill([
                'extension_diverged_at' => null,
                'extension_divergence' => null,
                'extension_failed_at' => null,
                'extension_failure' => null,
            ])->save();

            // O passado voltou a ser reproduzível: o esperado das obrigações volta
            // a ser confiável.
            if ($wasDiverged && $version->status->isOfficial()) {
                $this->obligations->refreshAfterCommit((int) $version->emission_id, 'official_curve_reproducible');
            }
        }

        $this->syncContractualChange($version, $assessment);
        $limitedTail = $this->limitTail($tail, $assessment);

        if ($limitedTail === []) {
            return $tail !== [] && $assessment->hasContractualChange()
                ? new PuCurveExtensionResult(
                    action: self::ACTION_CONTRACTUAL_CHANGE_PENDING,
                    versionId: $version->id,
                    firstDivergentDate: $assessment->earliestAffectedDate?->toDateString(),
                    reason: $assessment->summary(),
                    purpose: $purpose,
                )
                : new PuCurveExtensionResult(self::ACTION_UP_TO_DATE, $version->id, purpose: $purpose);
        }

        // O status visto antes do recálculo não vale como final: sob a trava da
        // emissão a versão é relida, e uma versão invalidada ou substituída no
        // meio do caminho não recebe dia novo -- nem a oficial que deixou de ser
        // oficial. Os insumos contratuais também são relidos, sob trava
        // compartilhada: o que mudou entre o cálculo e aqui decide o que ainda pode
        // entrar. Anexar não publica nada no legado; numa curva oficial, os
        // pagamentos dos dias novos são conciliados na mesma transação -- ou
        // entram as linhas e os pagamentos, ou nada.
        $lockedAssessment = null;
        $extended = DB::transaction(function () use ($emission, $version, $limitedTail, $purpose, $approved, &$lockedAssessment): array|string {
            $lockedEmission = Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();
            $locked = EmissionPuCurveVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::EXTENDABLE_STATUSES, true)) {
                return sprintf('A versão %s mudou para %s durante a extensão.', $locked->calculation_version, $locked->status->label());
            }

            if ($purpose === self::PURPOSE_OFFICIAL && ! $this->isStillOfficial($locked)) {
                return sprintf('A versão %s deixou de ser a oficial durante a extensão.', $locked->calculation_version);
            }

            $lockedAssessment = $this->classifier->compare(
                $approved,
                $this->snapshots->capture($lockedEmission, lockForShare: true),
                $this->classifier->lastPersistedDate($locked),
            );

            if ($this->blocksExtension($locked, $lockedAssessment, $purpose)) {
                return sprintf(
                    'Os insumos contratuais mudaram durante a extensão; nada foi anexado nesta rodada. %s',
                    $lockedAssessment->summary(),
                );
            }

            $tail = $this->limitTail($limitedTail, $lockedAssessment);

            if ($tail === []) {
                return sprintf('Os insumos contratuais mudaram durante a extensão; nada foi anexado nesta rodada. %s', $lockedAssessment->summary());
            }

            if (($changedObservation = $this->changedTailObservation($lockedEmission, $tail)) !== null) {
                return $changedObservation;
            }

            $timestamp = now();
            $rows = array_map(fn (PuDailyCurveRowData $row): array => [
                ...$row->toPersistenceArray($emission->id, $locked->calculation_version),
                'curve_version_id' => $locked->id,
                'extended_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $tail);

            foreach (array_chunk($rows, 500) as $chunk) {
                EmissionPuDailyCurve::query()->insert($chunk);
            }

            $locked->forceFill([
                'extended_rows_count' => $locked->extended_rows_count + count($rows),
                'last_extended_at' => $timestamp,
            ])->save();

            // Dias novos da curva oficial podem tornar obrigações calculáveis: o
            // esperado delas entra na mesma rodada (a liquidação não muda).
            if ($locked->status->isOfficial()) {
                $this->obligations->refresh($lockedEmission, 'curve_extended');
            }

            return [$locked, $tail];
        });

        if (! is_array($extended)) {
            if ($lockedAssessment instanceof PuCurveChangeAssessment
                && ($contractual = $this->contractualOutcome($version->fresh() ?? $version, $lockedAssessment, $purpose)) instanceof PuCurveExtensionResult) {
                return $contractual;
            }

            return new PuCurveExtensionResult(
                action: self::ACTION_NOT_EXTENDABLE,
                versionId: $version->id,
                reason: $extended,
                purpose: $purpose,
            );
        }

        [$extendedVersion, $appended] = $extended;
        $fromDate = $appended[0]->date->toDateString();
        $toDate = $appended[array_key_last($appended)]->date->toDateString();
        $this->auditLog->logCurveExtended($emission, $extendedVersion, count($appended), $fromDate, $toDate);

        return new PuCurveExtensionResult(
            action: self::ACTION_EXTENDED,
            versionId: $version->id,
            appendedRows: count($appended),
            fromDate: $fromDate,
            toDate: $toDate,
            purpose: $purpose,
        );
    }

    /**
     * O que a mudança contratual faz com esta extensão, ou nulo quando ela pode
     * seguir (sem mudança, ou mudança só no futuro de uma versão governada).
     *
     *  - versão governada com o passado alcançado: marcada para reprocessamento,
     *    nada anexado;
     *  - versão de trabalho comum com qualquer mudança: regerada inteira, como
     *    qualquer divergência de curva comum.
     */
    private function contractualOutcome(
        EmissionPuCurveVersion $version,
        PuCurveChangeAssessment $assessment,
        string $purpose,
    ): ?PuCurveExtensionResult {
        if (! $assessment->hasContractualChange()) {
            return null;
        }

        if (! $this->isGoverned($version)) {
            $this->syncContractualChange($version, $assessment);

            return $this->diverged($version, $assessment->earliestAffectedDate?->toDateString(), $assessment->summary(), $purpose);
        }

        if ($assessment->impact !== PuCurveChangeImpact::HistoricalReprocessRequired) {
            return null;
        }

        $this->syncContractualChange($version, $assessment);
        $existing = is_array($version->extension_divergence) ? $version->extension_divergence : [];
        $earliest = $assessment->earliestAffectedDate?->toDateString();
        $previousFirst = $existing['first_divergent_date'] ?? null;
        $version->forceFill([
            'extension_diverged_at' => $version->extension_diverged_at ?? now(),
            'extension_divergence' => [
                ...$existing,
                'cause' => $existing['cause'] ?? self::CAUSE_CONTRACTUAL_INPUT_CHANGED,
                'contractual_input_changed' => true,
                'first_divergent_date' => $previousFirst !== null && $earliest !== null
                    ? min((string) $previousFirst, $earliest)
                    : ($earliest ?? $previousFirst),
                'reason' => $assessment->summary(),
                'checked_at' => now()->toIso8601String(),
            ],
        ])->save();
        $this->auditLog->logCurveExtensionDiverged($version, $earliest, $assessment->summary(), true);
        // O esperado que a oficial calculou a partir dessa data deixou de ser
        // confiável: a conciliação das obrigações fica indeterminada até nova versão.
        $this->obligations->refreshAfterCommit((int) $version->emission_id, 'official_curve_diverged');

        return new PuCurveExtensionResult(
            action: self::ACTION_DIVERGED_GOVERNED,
            versionId: $version->id,
            firstDivergentDate: $earliest,
            reason: $assessment->summary(),
            purpose: $purpose,
        );
    }

    /**
     * Dentro da transação: a mudança contratual relida sob trava impede anexar?
     */
    private function blocksExtension(EmissionPuCurveVersion $locked, PuCurveChangeAssessment $assessment, string $purpose): bool
    {
        if (! $assessment->hasContractualChange()) {
            return false;
        }

        return ! $this->isGoverned($locked)
            || $assessment->impact === PuCurveChangeImpact::HistoricalReprocessRequired;
    }

    /**
     * Só os dias até a véspera da primeira data afetada por uma mudança contratual
     * ainda não aprovada.
     *
     * @param  list<PuDailyCurveRowData>  $tail
     * @return list<PuDailyCurveRowData>
     */
    private function limitTail(array $tail, PuCurveChangeAssessment $assessment): array
    {
        $limit = $assessment->extensionLimit()?->toDateString();

        if ($limit === null) {
            return $tail;
        }

        return array_values(array_filter(
            $tail,
            fn (PuDailyCurveRowData $row): bool => $row->date->toDateString() <= $limit,
        ));
    }

    /**
     * Grava (ou limpa) na versão o estado "insumos vivos diferentes dos aprovados".
     * Só reescreve quando o estado muda, e registra na trilha cada detecção nova.
     */
    private function syncContractualChange(EmissionPuCurveVersion $version, PuCurveChangeAssessment $assessment): void
    {
        if (! $assessment->hasContractualChange()) {
            if ($version->contractual_change_detected_at !== null) {
                $version->forceFill([
                    'contractual_change_detected_at' => null,
                    'contractual_change' => null,
                ])->save();
            }

            return;
        }

        $current = is_array($version->contractual_change) ? $version->contractual_change : [];
        $sameChange = ($current['live_fingerprint'] ?? null) === $assessment->liveFingerprint
            && ($current['impact'] ?? null) === $assessment->impact->value;

        if ($sameChange && ($current['last_persisted_date'] ?? null) === $assessment->lastPersistedDate?->toDateString()) {
            return;
        }

        $version->forceFill([
            'contractual_change_detected_at' => $version->contractual_change_detected_at ?? now(),
            'contractual_change' => [
                ...$assessment->toArray(),
                'checked_at' => now()->toIso8601String(),
            ],
        ])->save();

        // A versão avançar até a véspera não é detecção nova: a trilha registra cada
        // mudança contratual (ou mudança de impacto) uma vez.
        if (! $sameChange) {
            $this->auditLog->logContractualChangeDetected($version, $assessment);
        }
    }

    /**
     * Sem retrato (anterior à Fase 4) ou com retrato que não se explica: a versão de
     * trabalho comum é regerada; a governada fica com a falha registrada.
     */
    private function refuseWithoutInputs(EmissionPuCurveVersion $version, string $reason, string $purpose): PuCurveExtensionResult
    {
        if (! $this->isGoverned($version)) {
            return $this->diverged($version, null, $reason, $purpose);
        }

        $this->recordFailure($version, self::ACTION_NOT_EXTENDABLE, $reason, $purpose);

        return new PuCurveExtensionResult(
            action: self::ACTION_NOT_EXTENDABLE,
            versionId: $version->id,
            reason: $reason,
            purpose: $purpose,
        );
    }

    /**
     * As observações de índice que a cauda calculada usou, relidas sob trava
     * compartilhada, ainda valem o mesmo? Uma correção que comitou entre o
     * cálculo e a gravação faria a curva oficial ganhar dias calculados com a taxa
     * antiga sem que ninguém fosse avisado. Com a trava, a correção concorrente ou
     * já comitou (e a cauda é recusada nesta rodada) ou espera esta transação e
     * enxerga as linhas novas como dependentes da observação que corrige.
     *
     * @param  list<PuDailyCurveRowData>  $tail
     */
    private function changedTailObservation(Emission $emission, array $tail): ?string
    {
        $indexer = EmissionPuParameter::query()->where('emission_id', $emission->id)->first()?->indexer_enum;

        if ($indexer !== PuIndexer::Cdi) {
            return null;
        }

        $used = [];

        foreach ($tail as $row) {
            if ($row->indexRateDate !== null && $row->indexRateValue !== null) {
                $used[$row->indexRateDate->toDateString()] = $row->indexRateValue;
            }
        }

        if ($used === []) {
            return null;
        }

        ksort($used);
        $current = IndexRate::query()
            ->forIndexer($indexer)
            ->whereBetween('rate_date', [
                CarbonImmutable::parse(array_key_first($used))->startOfDay(),
                CarbonImmutable::parse(array_key_last($used))->endOfDay(),
            ])
            ->sharedLock()
            ->get()
            ->filter(fn (IndexRate $rate): bool => ! $rate->isProjectedRate())
            ->keyBy(fn (IndexRate $rate): string => (string) $rate->rate_date?->toDateString());

        foreach ($used as $date => $value) {
            $stored = $current->get($date);

            if (! $stored instanceof IndexRate
                || bccomp((string) $stored->rate_value, (string) $value, IndexRateObservationRecorder::VALUE_SCALE) !== 0) {
                return sprintf(
                    'O índice de %s mudou durante a extensão (calculado com %s, agora %s); nada foi anexado nesta rodada.',
                    $date,
                    (string) $value,
                    $stored instanceof IndexRate ? (string) $stored->rate_value : 'ausente',
                );
            }
        }

        return null;
    }

    /**
     * Relida sob a trava: a versão continua sendo a homologada vigente.
     */
    private function isStillOfficial(EmissionPuCurveVersion $locked): bool
    {
        return EmissionPuCurveVersion::query()
            ->where('emission_id', $locked->emission_id)
            ->official()
            ->value('id') === $locked->id;
    }

    /**
     * A extensão não rodou: fica gravado na versão por quê, até a próxima que
     * rodar limpar.
     */
    private function recordFailure(EmissionPuCurveVersion $version, string $action, string $reason, string $purpose): void
    {
        $version->forceFill([
            'extension_failed_at' => $version->extension_failed_at ?? now(),
            'extension_failure' => [
                'action' => $action,
                'purpose' => $purpose,
                'reason' => $reason,
                'checked_at' => now()->toIso8601String(),
            ],
        ])->save();

        $this->auditLog->logCurveExtensionFailed($version, $action, $reason, $purpose);
    }

    private function diverged(
        EmissionPuCurveVersion $version,
        ?string $firstDivergentDate,
        string $reason,
        string $purpose,
    ): PuCurveExtensionResult {
        $governed = $this->isGoverned($version);

        if ($governed) {
            $version->forceFill([
                'extension_diverged_at' => $version->extension_diverged_at ?? now(),
                'extension_divergence' => [
                    ...(is_array($version->extension_divergence) ? $version->extension_divergence : []),
                    'first_divergent_date' => $firstDivergentDate,
                    'reason' => $reason,
                    'checked_at' => now()->toIso8601String(),
                ],
            ])->save();
        }

        $this->auditLog->logCurveExtensionDiverged($version, $firstDivergentDate, $reason, $governed);

        if ($governed) {
            $this->obligations->refreshAfterCommit((int) $version->emission_id, 'official_curve_diverged');
        }

        return new PuCurveExtensionResult(
            action: $governed ? self::ACTION_DIVERGED_GOVERNED : self::ACTION_DIVERGED,
            versionId: $version->id,
            firstDivergentDate: $firstDivergentDate,
            reason: $reason,
            purpose: $purpose,
        );
    }

    /**
     * @param  list<PuDailyCurveRowData>  $persistedRows
     * @param  list<PuDailyCurveRowData>  $computedRows
     */
    private function firstDivergentDate(array $persistedRows, array $computedRows): ?string
    {
        $computedByDate = [];

        foreach ($computedRows as $row) {
            $computedByDate[$row->date->toDateString()] = $row;
        }

        foreach ($persistedRows as $row) {
            $date = $row->date->toDateString();
            $computed = $computedByDate[$date] ?? null;

            if (! $computed instanceof PuDailyCurveRowData
                || ! hash_equals($this->checksums->checksumForRows([$row]), $this->checksums->checksumForRows([$computed]))) {
                return $date;
            }

            unset($computedByDate[$date]);
        }

        $extraDates = array_keys($computedByDate);
        sort($extraDates);

        return $extraDates[0] ?? null;
    }
}
