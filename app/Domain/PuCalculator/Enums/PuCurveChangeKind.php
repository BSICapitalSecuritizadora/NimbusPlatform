<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

use App\Domain\PuCalculator\Support\PuCurveChangePolicy;

/**
 * Natureza de uma mudança de insumo da curva, agrupada pelas categorias da Fase 4:
 * dado de mercado, parâmetro contratual, evento contratual (com a integralização),
 * identidade da engine e metadado operacional.
 *
 * A decisão de impacto de cada natureza está em
 * {@see PuCurveChangePolicy}: é lá, e só lá, que
 * a matriz de versionamento é executada.
 */
enum PuCurveChangeKind: string
{
    /** CDI realizado divulgado depois do último dia gravado. */
    case NewRealizedIndexObservation = 'new_realized_index_observation';

    /** Correção de uma observação de índice já registrada (Fase 3). */
    case IndexRateCorrection = 'index_rate_correction';

    /** Revisão do calendário de dias úteis (dado de referência governado à parte). */
    case CalendarContentRevision = 'calendar_content_revision';

    /** Algoritmo numérico ou formato do retrato mudou. */
    case EngineIdentity = 'engine_identity';

    /**
     * Termos contratuais de base: indexador, spread/taxa, base, modo de busca,
     * defasagem, calendário contratual ou de divulgação, VNe, data de início,
     * prêmio de primeiro cupom. Valem desde o início da curva -- mudança com data
     * de vigência é um evento de alteração ({@see PuEventType::SpreadAmendment}).
     */
    case ContractualTerms = 'contractual_terms';

    /** Vencimento contratual ou horizonte ajustado da curva. */
    case Horizon = 'horizon';

    /** Evento contratual incluído, alterado, movido ou cancelado. */
    case ContractualEvent = 'contractual_event';

    /** Integralização incluída, alterada (data ou quantidade) ou excluída. */
    case Integralization = 'integralization';

    /** Descrição, rótulo, justificativa, ordem de exibição: nada que a engine leia. */
    case OperationalMetadata = 'operational_metadata';

    public function category(): string
    {
        return match ($this) {
            self::NewRealizedIndexObservation,
            self::IndexRateCorrection,
            self::CalendarContentRevision => 'market_data',
            self::EngineIdentity => 'engine',
            self::ContractualTerms,
            self::Horizon => 'contractual_parameter',
            self::ContractualEvent,
            self::Integralization => 'contractual_event',
            self::OperationalMetadata => 'operational_metadata',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NewRealizedIndexObservation => 'CDI realizado novo',
            self::IndexRateCorrection => 'Correção de índice',
            self::CalendarContentRevision => 'Revisão de calendário',
            self::EngineIdentity => 'Versão da engine',
            self::ContractualTerms => 'Termos contratuais',
            self::Horizon => 'Vencimento/horizonte',
            self::ContractualEvent => 'Evento contratual',
            self::Integralization => 'Integralização',
            self::OperationalMetadata => 'Metadado operacional',
        };
    }
}
