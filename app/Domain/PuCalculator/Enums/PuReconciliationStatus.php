<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Resultado da comparação entre o valor esperado oficial e a liquidação vigente.
 * A conciliação é derivada (refeita a partir dos cálculos imutáveis e da
 * liquidação vigente) e nunca altera nenhum dos dois.
 *
 *  - `Pending`: ainda sem liquidação;
 *  - `Matched`: total, data e componentes informados batem na escala monetária;
 *  - `Divergent`: liquidação fechada que difere do esperado; o detalhe (valor,
 *    data, componentes, cálculo alterado depois da liquidação) vai no payload;
 *  - `Indeterminate`: liquidada, mas o esperado não é confiável agora (aguardando
 *    índice, reprocessamento, efeito não suportado, sem curva oficial, obrigação
 *    superada);
 *  - `Conflict`: há conflito de liquidação em aberto para a obrigação;
 *  - `NotApplicable`: obrigação superada e nunca liquidada.
 */
enum PuReconciliationStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Divergent = 'divergent';
    case Indeterminate = 'indeterminate';
    case Conflict = 'conflict';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando liquidação',
            self::Matched => 'Conciliada',
            self::Divergent => 'Divergente',
            self::Indeterminate => 'Indeterminada',
            self::Conflict => 'Conflito de liquidação',
            self::NotApplicable => 'Não se aplica',
        };
    }
}
