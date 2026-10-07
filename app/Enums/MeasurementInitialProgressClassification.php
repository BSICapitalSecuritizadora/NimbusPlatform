<?php

namespace App\Enums;

use ValueError;

/**
 * Situação do avanço físico inicial legado de um plano de medição diante da
 * migração 2026_10_05_170828, que copia o "Realiz. inicial" da 1ª linha do
 * cronograma para o plano só quando o histórico aprovado prova que ele estava
 * em vigor.
 *
 * Os valores são códigos estáveis que saem no JSON de
 * `measurements:initial-progress-report`: quem guarda a saída de antes do
 * deploy compara com a de depois por eles. Os quatro primeiros espelham a
 * decisão que a migration grava na trilha (copiado, ou o motivo de não copiar);
 * os demais cobrem o que ela pula sem trilha e o que só aparece depois dela.
 */
enum MeasurementInitialProgressClassification: string
{
    case SafeBackfilled = 'SAFE_BACKFILLED';

    case DeclaredInitialWithNoCurrentApproval = 'DECLARED_INITIAL_WITH_NO_CURRENT_APPROVAL';

    case ApprovalStartedBelowDeclaredInitial = 'APPROVAL_STARTED_BELOW_DECLARED_INITIAL';

    case InitialPlusMeasuredAbove100 = 'INITIAL_PLUS_MEASURED_ABOVE_100';

    /**
     * A 1ª linha tem "Realiz. inicial" zero, ou o plano não tem linhas: não há
     * o que copiar, e a migração passa sem trilha.
     */
    case NoLegacyInitialProgress = 'NO_LEGACY_INITIAL_PROGRESS';

    /**
     * O "Realiz. inicial" da 1ª linha está fora de 0% a 100% (ou ilegível): a
     * migração pula o plano sem trilha, e o caso é para investigar.
     */
    case LegacyInitialOutOfRange = 'LEGACY_INITIAL_OUT_OF_RANGE';

    /**
     * O plano já tem avanço inicial ou data de referência, gravados na criação:
     * a migração não o toca.
     */
    case InitialInformedAtCreation = 'INITIAL_INFORMED_AT_CREATION';

    /**
     * Depois da migração, um plano que ela precisava decidir não tem a decisão
     * gravada -- ou tem avanço inicial que nenhuma decisão nem data explica.
     */
    case DecisionMissing = 'DECISION_MISSING';

    /**
     * Descrição da activity que a migração grava quando copia.
     */
    public const TRAIL_BACKFILLED = 'initial_physical_progress_backfilled';

    /**
     * Descrição da activity que a migração grava quando não copia.
     */
    public const TRAIL_NOT_BACKFILLED = 'initial_physical_progress_not_backfilled';

    public function label(): string
    {
        return match ($this) {
            self::SafeBackfilled => 'Provado pelo histórico aprovado',
            self::DeclaredInitialWithNoCurrentApproval => 'Sem aprovação vigente que o prove',
            self::ApprovalStartedBelowDeclaredInitial => 'Aprovação partiu de base menor que o inicial',
            self::InitialPlusMeasuredAbove100 => 'Inicial + medido acima de 100%',
            self::NoLegacyInitialProgress => 'Sem avanço inicial legado',
            self::LegacyInitialOutOfRange => 'Inicial legado fora de 0% a 100%',
            self::InitialInformedAtCreation => 'Informado na criação do plano',
            self::DecisionMissing => 'Decisão não registrada',
        };
    }

    /**
     * O plano tem "Realiz. inicial" que a migração não copia: o avanço inicial
     * fica em 0,00%, e decidir o que vale é do dono.
     */
    public function requiresOwnerDecision(): bool
    {
        return in_array($this, [
            self::DeclaredInitialWithNoCurrentApproval,
            self::ApprovalStartedBelowDeclaredInitial,
            self::InitialPlusMeasuredAbove100,
            self::LegacyInitialOutOfRange,
        ], true);
    }

    /**
     * A classificação do motivo que a migração dá para não copiar; sem motivo, a
     * cópia segura.
     *
     * @throws ValueError para um motivo que a migração não grava
     */
    public static function fromMigrationReason(?string $reason): self
    {
        return self::tryFromMigrationReason($reason)
            ?? throw new ValueError(sprintf('"%s" não é um motivo que a migração grava.', $reason));
    }

    /**
     * Como {@see self::fromMigrationReason()}, mas `null` para um motivo que a
     * migração não grava.
     */
    public static function tryFromMigrationReason(?string $reason): ?self
    {
        return match ($reason) {
            null => self::SafeBackfilled,
            'no_current_approval' => self::DeclaredInitialWithNoCurrentApproval,
            'approval_started_below_initial' => self::ApprovalStartedBelowDeclaredInitial,
            'initial_plus_measured_above_100' => self::InitialPlusMeasuredAbove100,
            default => null,
        };
    }

    /**
     * A classificação gravada na trilha, pela descrição e pelo motivo. `null`
     * quando os dois não combinam -- cópia com motivo, recusa sem motivo ou
     * motivo desconhecido --, o que nenhuma execução da migração grava.
     */
    public static function fromTrail(string $description, mixed $reason): ?self
    {
        if ($reason !== null && ! is_string($reason)) {
            return null;
        }

        $classification = self::tryFromMigrationReason($reason);

        return match ($description) {
            self::TRAIL_BACKFILLED => $classification === self::SafeBackfilled ? $classification : null,
            self::TRAIL_NOT_BACKFILLED => $classification === self::SafeBackfilled ? null : $classification,
            default => null,
        };
    }
}
