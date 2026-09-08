<?php

namespace App\Enums;

/**
 * As mudanças de modo de uma Emissão, na trilha durável.
 *
 * Só duas, e de propósito: são os dois momentos em que a resposta à pergunta
 * "quem produz os próximos quadros desta Emissão?" muda. Alteração de escopo,
 * homologação aprovada e reavaliação são fatos importantes -- e todos já têm
 * lugar próprio, na homologação ou nos alertas. Registrá-los aqui também faria
 * a trilha de rollout virar log de tudo, e a pergunta que ela responde ficaria
 * perdida no meio.
 */
enum SalesBoardRolloutEventType: string
{
    case Activated = 'ativada';

    case ReturnedToLegacy = 'retornada_ao_legado';

    public function label(): string
    {
        return match ($this) {
            self::Activated => 'Automação ativada',
            self::ReturnedToLegacy => 'Retorno ao modo legado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Activated => 'success',
            self::ReturnedToLegacy => 'warning',
        };
    }
}
