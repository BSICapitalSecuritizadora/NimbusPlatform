<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;

/**
 * Quanto cada condição operacional pede de atenção (Fase 6).
 *
 * O padrão vem do catálogo ({@see PuOperationalConditionType::defaultSeverity()}),
 * `pu_calculator.monitoring.severity_overrides` troca o de um tipo, e o contexto
 * ajusta o que é contextual por natureza:
 *
 *  - índice ausente quando nenhuma consulta à fonte rodou depois da divulgação
 *    esperada é só "aguardando sincronização" (informativo);
 *  - curva atrasada com o índice gravado há menos que a carência é informativa;
 *  - extensão oficial e sincronização viram críticas depois de N falhas seguidas;
 *  - condição de índice sem nenhuma emissão em operação daquele indexador é
 *    informativa (produção começa sem curva oficial: nada de alerta antes do
 *    lançamento);
 *  - emissão inativa só alerta o que põe o passado oficial em dúvida.
 *
 * Nenhum limiar aqui é prazo de negócio: são parâmetros operacionais em config.
 */
final class PuOperationalSeverityPolicy
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function severity(PuOperationalConditionType $type, array $context = []): PuOperationalSeverity
    {
        $override = config('pu_calculator.monitoring.severity_overrides.'.$type->value);
        $severity = is_string($override) ? (PuOperationalSeverity::tryFrom($override) ?? $type->defaultSeverity()) : $type->defaultSeverity();

        $severity = match ($type) {
            PuOperationalConditionType::OfficialCurveMissingIndex => ($context['awaiting_synchronization'] ?? false) === true
                ? PuOperationalSeverity::Info
                : $severity,
            PuOperationalConditionType::OfficialCurveStale => ($context['beyond_grace'] ?? false) === true
                ? $this->atLeast($severity, PuOperationalSeverity::Warning)
                : PuOperationalSeverity::Info,
            PuOperationalConditionType::OfficialExtensionFailed => (int) ($context['consecutive_failures'] ?? 1) >= $this->threshold('extension_failure_critical_after')
                ? PuOperationalSeverity::Critical
                : $severity,
            PuOperationalConditionType::IndexSyncFailed => (int) ($context['consecutive_failures'] ?? 1) >= $this->threshold('index_sync_failure_critical_after')
                ? PuOperationalSeverity::Critical
                : $severity,
            default => $severity,
        };

        if (in_array($type->check(), [PuOperationalConditionType::CHECK_INDEX, PuOperationalConditionType::CHECK_SYSTEM], true)
            && array_key_exists('operational_emissions', $context)
            && (int) $context['operational_emissions'] === 0) {
            return PuOperationalSeverity::Info;
        }

        if (($context['emission_inactive'] ?? false) === true && ! in_array($type, [
            PuOperationalConditionType::ReprocessingRequired,
            PuOperationalConditionType::UnsupportedIndexerOfficialCurve,
        ], true)) {
            return PuOperationalSeverity::Info;
        }

        return $severity;
    }

    private function atLeast(PuOperationalSeverity $severity, PuOperationalSeverity $floor): PuOperationalSeverity
    {
        return $severity->atLeast($floor) ? $severity : $floor;
    }

    private function threshold(string $key): int
    {
        return max(1, (int) config('pu_calculator.monitoring.'.$key, 3));
    }
}
