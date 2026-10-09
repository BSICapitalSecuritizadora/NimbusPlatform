<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Situação do valor esperado da obrigação diante da curva oficial vigente.
 * Independente da liquidação: uma obrigação pode estar liquidada e com o valor
 * esperado indisponível (e aí a conciliação fica indeterminada).
 *
 *  - `Calculated`: a curva oficial realizada calculou todos os componentes;
 *  - `AwaitingIndex`: a obrigação existe no contrato, mas a curva oficial ainda
 *    não chegou à data (índice futuro não é projetado);
 *  - `AwaitingNewVersion`: mudança contratual aprovada só no futuro limita a
 *    oficial antes desta data; só uma versão nova a calcula;
 *  - `ReprocessingRequired`: o trecho da oficial que a calculou deixou de ser
 *    reproduzível (índice corrigido, insumo contratual mudou);
 *  - `Unsupported`: algum componente não tem regra segura (falha fechada);
 *  - `NoOfficialCalculation`: não há curva oficial vigente para a emissão.
 */
enum PuObligationCalculationState: string
{
    case Calculated = 'calculated';
    case AwaitingIndex = 'awaiting_index';
    case AwaitingNewVersion = 'awaiting_new_version';
    case ReprocessingRequired = 'reprocessing_required';
    case Unsupported = 'unsupported';
    case NoOfficialCalculation = 'no_official_calculation';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'Calculada',
            self::AwaitingIndex => 'Aguardando índice',
            self::AwaitingNewVersion => 'Aguardando nova versão',
            self::ReprocessingRequired => 'Reprocessamento necessário',
            self::Unsupported => 'Efeito não suportado',
            self::NoOfficialCalculation => 'Sem curva oficial',
        };
    }

    /**
     * Valor esperado confiável para conciliar.
     */
    public function isTrustworthy(): bool
    {
        return $this === self::Calculated;
    }
}
