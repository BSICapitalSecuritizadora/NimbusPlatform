<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Domain\PuCalculator\Services\PuOperationalSeverityPolicy;

/**
 * Catálogo das condições operacionais do PU (Fase 6).
 *
 * Cada condição é um estado de domínio lido das fontes que já decidem esse
 * estado -- atualidade da curva oficial ({@see PuOfficialCurveFreshnessService}),
 * obrigações e conciliação da Fase 5, pedidos de atualização, tentativas de
 * sincronização. Nenhuma é recalculada aqui. A urgência (INFO/WARNING/CRITICAL)
 * não mora no tipo: a política decide, com o contexto
 * ({@see PuOperationalSeverityPolicy}); o
 * tipo só traz o padrão.
 *
 * A verificação ({@see self::check()}) diz quem produz a condição: um incidente
 * só é resolvido quando a verificação dele rodou inteira e não a encontrou.
 */
enum PuOperationalConditionType: string
{
    // Curva oficial e índice, por emissão.
    case NoOfficialCurve = 'no_official_curve';
    case CandidateAwaitingReview = 'candidate_awaiting_review';
    case OfficialCurveStale = 'official_curve_stale';
    case OfficialCurveMissingIndex = 'official_curve_missing_index';
    case OfficialExtensionFailed = 'official_extension_failed';
    case ReprocessingRequired = 'reprocessing_required';
    case NewVersionRequired = 'new_version_required';
    case ContractualChangePending = 'contractual_change_pending';
    case UnsupportedIndexer = 'unsupported_indexer';
    case UnsupportedIndexerOfficialCurve = 'unsupported_indexer_official_curve';
    case CurveGenerationFailed = 'curve_generation_failed';
    case CurveGenerationStuck = 'curve_generation_stuck';

    // Sincronização do índice, por indexador.
    case IndexAwaitingSynchronization = 'index_awaiting_synchronization';
    case IndexSynchronizationOverdue = 'index_synchronization_overdue';
    case IndexSyncFailed = 'index_sync_failed';
    case IndexObservationMissing = 'index_observation_missing';
    case IndexRateConflict = 'index_rate_conflict';

    // Obrigações, liquidação e conciliação (Fase 5).
    case ObligationsUnsettledPastDue = 'obligations_unsettled_past_due';
    case SettlementDivergent = 'settlement_divergent';
    case SettlementConflictOpen = 'settlement_conflict_open';
    case ReconciliationIndeterminate = 'reconciliation_indeterminate';
    case ObligationUnsupportedEffect = 'obligation_unsupported_effect';
    case ObligationReprocessingRequired = 'obligation_reprocessing_required';
    case ObligationAwaitingIndex = 'obligation_awaiting_index';

    // Atualização durável das obrigações.
    case ObligationRefreshPending = 'obligation_refresh_pending';
    case ObligationRefreshStalled = 'obligation_refresh_stalled';
    case ObligationRefreshRetrying = 'obligation_refresh_retrying';
    case ObligationRefreshExhausted = 'obligation_refresh_exhausted';
    case ObligationRefreshBlocked = 'obligation_refresh_blocked';

    // Fila.
    case QueuedJobsFailed = 'queued_jobs_failed';

    public const CHECK_CURVE = 'curve';

    public const CHECK_INDEX = 'index';

    public const CHECK_OBLIGATIONS = 'obligations';

    public const CHECK_REFRESH = 'refresh';

    public const CHECK_SYSTEM = 'system';

    public function check(): string
    {
        return match ($this) {
            self::IndexAwaitingSynchronization,
            self::IndexSynchronizationOverdue,
            self::IndexSyncFailed,
            self::IndexObservationMissing,
            self::IndexRateConflict => self::CHECK_INDEX,
            self::ObligationsUnsettledPastDue,
            self::SettlementDivergent,
            self::SettlementConflictOpen,
            self::ReconciliationIndeterminate,
            self::ObligationUnsupportedEffect,
            self::ObligationReprocessingRequired,
            self::ObligationAwaitingIndex => self::CHECK_OBLIGATIONS,
            self::ObligationRefreshPending,
            self::ObligationRefreshStalled,
            self::ObligationRefreshRetrying,
            self::ObligationRefreshExhausted,
            self::ObligationRefreshBlocked => self::CHECK_REFRESH,
            self::QueuedJobsFailed => self::CHECK_SYSTEM,
            default => self::CHECK_CURVE,
        };
    }

    /**
     * Domínio de negócio da condição (para leitura e agrupamento).
     */
    public function domain(): string
    {
        return match ($this) {
            self::ContractualChangePending, self::NewVersionRequired, self::ReprocessingRequired => 'contractual_lifecycle',
            self::SettlementDivergent, self::SettlementConflictOpen, self::ObligationsUnsettledPastDue => 'settlement',
            self::ReconciliationIndeterminate => 'reconciliation',
            default => $this->check() === self::CHECK_CURVE ? 'curve_index' : $this->check(),
        };
    }

    /**
     * @return list<self>
     */
    public static function forCheck(string $check): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->check() === $check));
    }

    public function defaultSeverity(): PuOperationalSeverity
    {
        return match ($this) {
            self::NoOfficialCurve,
            self::CandidateAwaitingReview,
            self::OfficialCurveStale,
            self::ContractualChangePending,
            self::UnsupportedIndexer,
            self::IndexAwaitingSynchronization,
            self::ObligationReprocessingRequired,
            self::ObligationAwaitingIndex,
            self::ObligationRefreshPending => PuOperationalSeverity::Info,
            self::ReprocessingRequired,
            self::UnsupportedIndexerOfficialCurve,
            self::SettlementConflictOpen,
            self::ObligationRefreshExhausted,
            self::ObligationRefreshBlocked => PuOperationalSeverity::Critical,
            default => PuOperationalSeverity::Warning,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NoOfficialCurve => 'Sem curva oficial (ainda não lançada)',
            self::CandidateAwaitingReview => 'Versão aguardando revisão/homologação',
            self::OfficialCurveStale => 'Curva oficial atrasada: há índice realizado não incorporado',
            self::OfficialCurveMissingIndex => 'Curva oficial parada por índice exigido ausente',
            self::OfficialExtensionFailed => 'Extensão da curva oficial falhou',
            self::ReprocessingRequired => 'Reprocessamento da curva oficial necessário',
            self::NewVersionRequired => 'Nova versão necessária: mudança contratual não homologada',
            self::ContractualChangePending => 'Mudança contratual futura ainda não homologada',
            self::UnsupportedIndexer => 'Indexador sem homologação operacional',
            self::UnsupportedIndexerOfficialCurve => 'Curva oficial com indexador sem homologação operacional',
            self::CurveGenerationFailed => 'Geração de curva falhou',
            self::CurveGenerationStuck => 'Geração de curva travada em processamento',
            self::IndexAwaitingSynchronization => 'Divulgação esperada, sincronização ainda não rodou',
            self::IndexSynchronizationOverdue => 'Sincronização do índice atrasada',
            self::IndexSyncFailed => 'Sincronização do índice falhou',
            self::IndexObservationMissing => 'Observação do índice ausente depois da divulgação esperada',
            self::IndexRateConflict => 'Fonte informa valor diferente do registrado',
            self::ObligationsUnsettledPastDue => 'Obrigações vencidas sem liquidação registrada',
            self::SettlementDivergent => 'Liquidação divergente do esperado',
            self::SettlementConflictOpen => 'Conflito de liquidação aguardando decisão',
            self::ReconciliationIndeterminate => 'Conciliação indeterminada',
            self::ObligationUnsupportedEffect => 'Efeito financeiro sem regra (cálculo bloqueado)',
            self::ObligationReprocessingRequired => 'Esperado indisponível: curva em reprocessamento',
            self::ObligationAwaitingIndex => 'Obrigação aguardando índice',
            self::ObligationRefreshPending => 'Atualização das obrigações pendente',
            self::ObligationRefreshStalled => 'Atualização das obrigações parada',
            self::ObligationRefreshRetrying => 'Atualização das obrigações falhou e será repetida',
            self::ObligationRefreshExhausted => 'Atualização das obrigações esgotou as tentativas',
            self::ObligationRefreshBlocked => 'Atualização das obrigações bloqueada por falha permanente',
            self::QueuedJobsFailed => 'Jobs do PU com falha na fila',
        };
    }
}
