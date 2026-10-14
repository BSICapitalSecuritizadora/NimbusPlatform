<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;

/**
 * Quanto o monitoramento espera de uma emissão (Fases 6 e 7).
 *
 * Derivado de parâmetros, situação da emissão, indexador, curva homologada e --
 * desde a Fase 7 -- da decisão de ativação operacional. O monitoramento não
 * ativa, não gera e não homologa nada; ele só decide o que pode virar alerta:
 *
 *  - sem parâmetros: nada a acompanhar;
 *  - emissão inativa: a rotina diária não roda para ela; só fatos financeiros já
 *    registrados (conflito, divergência) continuam alertando;
 *  - indexador sem homologação operacional: bloqueado e visível;
 *  - configurada e não ativada, ou com as operações desligadas no ambiente
 *    ({@see PuOperationalActivationPolicy}): informativo -- e atenção quando
 *    há curva oficial que deixou de avançar;
 *  - suspensa: a curva oficial parada por decisão não vira incidente de atraso;
 *    só o que põe o passado oficial em dúvida e os fatos financeiros alertam;
 *  - ativada e ainda não lançada (sem curva oficial): informativo, nunca
 *    "falta curva oficial" como incidente;
 *  - em operação (ativada, curva oficial vigente): acompanhamento completo.
 */
enum PuOperationalEligibility: string
{
    case NotConfigured = 'not_configured';
    case Inactive = 'inactive';
    case UnsupportedIndexer = 'unsupported_indexer';
    case ConfiguredNotLaunched = 'configured_not_launched';
    case NotActivated = 'not_activated';
    case Suspended = 'suspended';
    case Operational = 'operational';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'PU não configurado',
            self::Inactive => 'Emissão inativa',
            self::UnsupportedIndexer => 'Indexador sem homologação operacional',
            self::ConfiguredNotLaunched => 'Configurada, ainda sem curva oficial',
            self::NotActivated => 'Configurada, operação do PU não ativada',
            self::Suspended => 'Operação do PU suspensa',
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
