<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCertification;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponentStatus;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementConflictStatus;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Support\BusinessTime;
use DateTimeInterface;

/**
 * Vocabulário e formatação únicos das telas do PU (Fase 8).
 *
 * Só apresenta: nunca arredonda um valor que volte ao banco, nunca calcula um
 * número financeiro e nunca transforma "indisponível" em zero. Quem não tem o
 * valor recebe nulo e mostra o motivo -- `R$ 0,00` só aparece quando o domínio
 * gravou zero.
 *
 * Cor nunca é a única pista: todo status tem rótulo e ícone próprios.
 */
final class PuDisplay
{
    /** Sinal de menos tipográfico (U+2212): "−R$ 5,00" não se confunde com hífen. */
    public const MINUS = "\u{2212}";

    public const NOT_SETTLED = 'Não liquidada';

    /**
     * "R$ 1.234.567,89" a partir do decimal em string, sem passar por float.
     */
    public static function money(string|int|float|null $value): ?string
    {
        $formatted = self::decimal($value, 2);

        if ($formatted === null) {
            return null;
        }

        return str_starts_with($formatted, '-')
            ? self::MINUS.'R$ '.substr($formatted, 1)
            : 'R$ '.$formatted;
    }

    /**
     * Diferença financeira: "−R$ 5,00", "+R$ 5,00" ou "R$ 0,00".
     */
    public static function signedMoney(string|int|float|null $value): ?string
    {
        $formatted = self::decimal($value, 2);

        if ($formatted === null) {
            return null;
        }

        return match (true) {
            str_starts_with($formatted, '-') => self::MINUS.'R$ '.substr($formatted, 1),
            $formatted === '0,00' => 'R$ 0,00',
            default => '+R$ '.$formatted,
        };
    }

    /**
     * Valor unitário (PU) na escala do relatório, sem alterar a precisão gravada.
     */
    public static function unitValue(string|int|float|null $value, int $scale = 8): ?string
    {
        $formatted = self::decimal($value, $scale);

        return $formatted === null ? null : 'R$ '.$formatted;
    }

    /**
     * Fração da base (0.98) como percentual legível (98,00%).
     */
    public static function fractionAsPercent(string|int|float|null $fraction): ?string
    {
        if ($fraction === null || $fraction === '' || ! is_numeric($fraction)) {
            return null;
        }

        return self::decimal(bcmul(app(DecimalRounder::class)->round($fraction, 16), '100', 16), 2).'%';
    }

    /**
     * Número decimal em pt-BR (milhar ".", decimal ","), arredondado só para exibir.
     */
    public static function decimal(string|int|float|null $value, int $scale): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $rounded = app(DecimalRounder::class)->round($value, $scale);

        if (bccomp($rounded, '0', $scale) === 0) {
            $rounded = ltrim($rounded, '-');
        }

        $negative = str_starts_with($rounded, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($rounded, '-'), 2), 2, '');
        $grouped = strrev(implode('.', str_split(strrev($integer === '' ? '0' : $integer), 3)));

        return ($negative ? '-' : '').$grouped.($scale > 0 ? ','.str_pad($fraction, $scale, '0') : '');
    }

    /**
     * Data civil (contratual, de vencimento, de liquidação, de posição): dd/mm/aaaa.
     */
    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('d/m/Y');
    }

    /**
     * Instante de registro (gravado em UTC), lido no fuso de negócio.
     */
    public static function dateTime(?DateTimeInterface $instant): ?string
    {
        return $instant !== null ? BusinessTime::at($instant)->format('d/m/Y H:i') : null;
    }

    public static function reconciliationColor(?PuReconciliationStatus $status): string
    {
        return match ($status) {
            PuReconciliationStatus::Matched => 'success',
            PuReconciliationStatus::Divergent, PuReconciliationStatus::Conflict => 'danger',
            PuReconciliationStatus::Indeterminate => 'warning',
            default => 'gray',
        };
    }

    public static function reconciliationIcon(?PuReconciliationStatus $status): string
    {
        return match ($status) {
            PuReconciliationStatus::Matched => 'heroicon-m-check-circle',
            PuReconciliationStatus::Divergent => 'heroicon-m-exclamation-triangle',
            PuReconciliationStatus::Conflict => 'heroicon-m-scale',
            PuReconciliationStatus::Indeterminate => 'heroicon-m-question-mark-circle',
            PuReconciliationStatus::NotApplicable => 'heroicon-m-minus-circle',
            default => 'heroicon-m-clock',
        };
    }

    /**
     * O que a situação da conciliação quer dizer, em uma frase.
     */
    public static function reconciliationExplanation(?PuReconciliationStatus $status): string
    {
        return match ($status) {
            PuReconciliationStatus::Matched => 'O valor liquidado, a data e os componentes informados batem com o esperado oficial (2 casas).',
            PuReconciliationStatus::Divergent => 'Liquidação fechada com valor, data ou componentes diferentes do esperado oficial. Não existe liquidação parcial: a diferença fica registrada como divergência.',
            PuReconciliationStatus::Conflict => 'Chegou um fato de liquidação que contradiz o registrado. Nada foi sobrescrito: alguém autorizado precisa decidir.',
            PuReconciliationStatus::Indeterminate => 'Há liquidação registrada, mas o esperado oficial não é confiável agora; a conciliação volta quando o cálculo voltar.',
            PuReconciliationStatus::NotApplicable => 'Obrigação superada e nunca liquidada: não há o que conciliar.',
            default => 'Ainda não há liquidação registrada para esta obrigação.',
        };
    }

    public static function calculationStateColor(?PuObligationCalculationState $state): string
    {
        return match ($state) {
            PuObligationCalculationState::Calculated => 'success',
            PuObligationCalculationState::AwaitingIndex => 'info',
            PuObligationCalculationState::AwaitingNewVersion, PuObligationCalculationState::ReprocessingRequired => 'warning',
            PuObligationCalculationState::Unsupported => 'danger',
            default => 'gray',
        };
    }

    public static function calculationStateIcon(?PuObligationCalculationState $state): string
    {
        return match ($state) {
            PuObligationCalculationState::Calculated => 'heroicon-m-check-badge',
            PuObligationCalculationState::AwaitingIndex => 'heroicon-m-clock',
            PuObligationCalculationState::AwaitingNewVersion => 'heroicon-m-document-plus',
            PuObligationCalculationState::ReprocessingRequired => 'heroicon-m-arrow-path',
            PuObligationCalculationState::Unsupported => 'heroicon-m-no-symbol',
            default => 'heroicon-m-minus-circle',
        };
    }

    /**
     * Por que o esperado não está disponível, para o lugar onde o valor estaria.
     */
    public static function unavailableExpected(?PuObligationCalculationState $state): string
    {
        return match ($state) {
            PuObligationCalculationState::AwaitingIndex => 'Aguardando índice',
            PuObligationCalculationState::AwaitingNewVersion => 'Aguardando nova versão',
            PuObligationCalculationState::ReprocessingRequired => 'Em reprocessamento',
            PuObligationCalculationState::Unsupported => 'Sem regra de cálculo',
            PuObligationCalculationState::NoOfficialCalculation => 'Sem curva oficial',
            default => 'Indisponível',
        };
    }

    public static function componentStatusColor(?PuObligationComponentStatus $status): string
    {
        return match ($status) {
            PuObligationComponentStatus::Calculated => 'success',
            PuObligationComponentStatus::Informed => 'info',
            PuObligationComponentStatus::Unsupported => 'danger',
            default => 'gray',
        };
    }

    public static function settlementStatusColor(?PuSettlementStatus $status): string
    {
        return match ($status) {
            PuSettlementStatus::Active => 'success',
            PuSettlementStatus::Reversal => 'warning',
            default => 'gray',
        };
    }

    public static function conflictStatusColor(?PuSettlementConflictStatus $status): string
    {
        return match ($status) {
            PuSettlementConflictStatus::Open => 'danger',
            PuSettlementConflictStatus::Accepted => 'success',
            default => 'gray',
        };
    }

    public static function severityColor(?PuOperationalSeverity $severity): string
    {
        return match ($severity) {
            PuOperationalSeverity::Critical => 'danger',
            PuOperationalSeverity::Warning => 'warning',
            default => 'gray',
        };
    }

    public static function severityIcon(?PuOperationalSeverity $severity): string
    {
        return match ($severity) {
            PuOperationalSeverity::Critical => 'heroicon-m-shield-exclamation',
            PuOperationalSeverity::Warning => 'heroicon-m-exclamation-triangle',
            default => 'heroicon-m-information-circle',
        };
    }

    public static function incidentStatusColor(?PuIncidentStatus $status): string
    {
        return match ($status) {
            PuIncidentStatus::Active => 'danger',
            PuIncidentStatus::Acknowledged => 'warning',
            default => 'success',
        };
    }

    public static function incidentStatusIcon(?PuIncidentStatus $status): string
    {
        return match ($status) {
            PuIncidentStatus::Active => 'heroicon-m-bell-alert',
            PuIncidentStatus::Acknowledged => 'heroicon-m-eye',
            default => 'heroicon-m-check-circle',
        };
    }

    public static function refreshStatusColor(?PuObligationRefreshStatus $status): string
    {
        return match ($status) {
            PuObligationRefreshStatus::Exhausted, PuObligationRefreshStatus::Blocked => 'danger',
            PuObligationRefreshStatus::RetryScheduled => 'warning',
            PuObligationRefreshStatus::Pending, PuObligationRefreshStatus::Running => 'info',
            default => 'success',
        };
    }

    public static function activationColor(?PuOperationalActivationStatus $status): string
    {
        return match ($status) {
            PuOperationalActivationStatus::Activated => 'success',
            PuOperationalActivationStatus::Suspended => 'warning',
            default => 'gray',
        };
    }

    public static function activationIcon(?PuOperationalActivationStatus $status): string
    {
        return match ($status) {
            PuOperationalActivationStatus::Activated => 'heroicon-m-play-circle',
            PuOperationalActivationStatus::Suspended => 'heroicon-m-pause-circle',
            PuOperationalActivationStatus::Inactive => 'heroicon-m-stop-circle',
            default => 'heroicon-m-minus-circle',
        };
    }

    public static function certificationColor(?PuIndexerCertification $certification): string
    {
        return match ($certification) {
            PuIndexerCertification::Certified => 'success',
            PuIndexerCertification::ConditionallyCertified => 'warning',
            PuIndexerCertification::NotCertified => 'danger',
            default => 'gray',
        };
    }

    /**
     * Rótulo do indexador para quem escolhe ou lê a configuração: o nome e o uso
     * que a certificação permite. O rótulo do enum continua sendo o do domínio.
     */
    public static function indexerOption(PuIndexer $indexer): string
    {
        return match ($indexer->certification()) {
            PuIndexerCertification::Certified => sprintf('%s — certificado para uso oficial', self::indexerName($indexer)),
            PuIndexerCertification::ConditionallyCertified => sprintf('%s — certificado com condições para uso oficial', self::indexerName($indexer)),
            PuIndexerCertification::NotCertified => sprintf('%s — não certificado: somente simulação', self::indexerName($indexer)),
        };
    }

    public static function indexerName(PuIndexer $indexer): string
    {
        return match ($indexer) {
            PuIndexer::Cdi => 'CDI + spread',
            PuIndexer::Prefixed => 'Prefixado',
            PuIndexer::Ipca => 'IPCA',
        };
    }

    /**
     * O que provocou uma avaliação da conciliação, em português.
     */
    public static function reconciliationTrigger(?string $trigger): string
    {
        return match ($trigger) {
            'settlement_recorded' => 'Liquidação registrada',
            'settlement_corrected' => 'Liquidação corrigida',
            'settlement_reversed' => 'Liquidação estornada',
            'settlement_conflict_detected' => 'Conflito detectado',
            'settlement_conflict_resolved' => 'Conflito decidido',
            'curve_homologated' => 'Curva homologada',
            'curve_invalidated' => 'Curva invalidada',
            'curve_extended' => 'Curva estendida',
            'manual_refresh' => 'Atualização manual',
            'index_rate_corrected' => 'Índice corrigido',
            null, '' => '—',
            default => str_replace('_', ' ', $trigger),
        };
    }

    /**
     * Rótulo curto da situação operacional, para colunas estreitas (o rótulo
     * completo vai na dica).
     */
    public static function activationShortLabel(?PuOperationalActivationStatus $status): string
    {
        return match ($status) {
            PuOperationalActivationStatus::Activated => 'Ativada',
            PuOperationalActivationStatus::Suspended => 'Suspensa',
            PuOperationalActivationStatus::Inactive => 'Não ativada',
            default => 'Sem PU',
        };
    }

    /**
     * Rótulo das contagens de obrigações do retrato operacional ("coluna:valor").
     */
    public static function obligationCountLabel(string $key): string
    {
        [$column, $value] = array_pad(explode(':', $key, 2), 2, null);

        return match ($column) {
            'calculation_state' => 'Esperado: '.mb_strtolower(PuObligationCalculationState::tryFrom((string) $value)?->label() ?? (string) $value),
            'settlement_state' => PuSettlementState::tryFrom((string) $value)?->label() ?? (string) $value,
            'reconciliation_status' => 'Conciliação: '.mb_strtolower(PuReconciliationStatus::tryFrom((string) $value)?->label() ?? (string) $value),
            'lifecycle' => 'Superadas',
            'unsettled_past_due' => 'Vencidas sem liquidação registrada',
            'settlement_conflicts_open' => 'Conflitos de liquidação em aberto',
            default => $key,
        };
    }

    public static function freshnessColor(?PuOfficialCurveFreshness $freshness): string
    {
        return match ($freshness) {
            PuOfficialCurveFreshness::Current, PuOfficialCurveFreshness::Complete => 'success',
            PuOfficialCurveFreshness::NotTracked, PuOfficialCurveFreshness::NoOfficialCurve => 'gray',
            PuOfficialCurveFreshness::Stale, PuOfficialCurveFreshness::NewVersionRequired => 'warning',
            default => 'danger',
        };
    }

    public static function freshnessIcon(?PuOfficialCurveFreshness $freshness): string
    {
        return match ($freshness) {
            PuOfficialCurveFreshness::Current, PuOfficialCurveFreshness::Complete => 'heroicon-m-check-circle',
            PuOfficialCurveFreshness::NotTracked, PuOfficialCurveFreshness::NoOfficialCurve => 'heroicon-m-minus-circle',
            PuOfficialCurveFreshness::Stale => 'heroicon-m-clock',
            PuOfficialCurveFreshness::NewVersionRequired => 'heroicon-m-document-plus',
            PuOfficialCurveFreshness::ReprocessingRequired => 'heroicon-m-arrow-path',
            default => 'heroicon-m-exclamation-triangle',
        };
    }
}
