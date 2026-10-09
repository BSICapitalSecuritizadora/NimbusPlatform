<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Quanto o monitoramento espera de uma emissão (Fase 6).
 *
 * Derivado só do que já existe -- parâmetros, situação da emissão, indexador e
 * curva homologada --, sem registro de ativação novo: produção começa vazia, e a
 * primeira homologação É o lançamento governado. O monitoramento não ativa, não
 * gera e não homologa nada; ele só decide o que pode virar alerta:
 *
 *  - sem parâmetros: nada a acompanhar;
 *  - emissão inativa: a rotina diária não roda para ela; só fatos financeiros já
 *    registrados (conflito, divergência) continuam alertando;
 *  - indexador sem homologação operacional: bloqueado e visível;
 *  - configurada e ainda não lançada (sem curva oficial): informativo, nunca
 *    "falta curva oficial" como incidente;
 *  - em operação (curva oficial vigente): acompanhamento completo.
 */
enum PuOperationalEligibility: string
{
    case NotConfigured = 'not_configured';
    case Inactive = 'inactive';
    case UnsupportedIndexer = 'unsupported_indexer';
    case ConfiguredNotLaunched = 'configured_not_launched';
    case Operational = 'operational';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'PU não configurado',
            self::Inactive => 'Emissão inativa',
            self::UnsupportedIndexer => 'Indexador sem homologação operacional',
            self::ConfiguredNotLaunched => 'Configurada, ainda sem curva oficial',
            self::Operational => 'Em operação (curva oficial vigente)',
        };
    }

    /**
     * A curva oficial e o índice dela são acompanhados com alerta.
     */
    public function expectsOfficialCurve(): bool
    {
        return $this === self::Operational;
    }
}
