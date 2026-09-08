<?php

namespace App\Enums;

/**
 * Em que ponto do ciclo mensal do Quadro de Vendas uma competência está.
 *
 * Nesta fase só `Generated` é produzido: a Fase C congela e versiona o que a
 * derivação apura, e mais nada. Os outros estados existem porque a coluna é
 * texto e o vocabulário já está fechado -- declará-los agora evita renomear
 * dados persistidos depois -- mas **nenhuma transição está implementada**, e
 * nenhum caminho de código produz outro valor.
 *
 * `stale` não está aqui de propósito: ficar obsoleto é condição do baseline
 * atual, não etapa do ciclo. Um ciclo com a fonte alterada continua Generated;
 * o que mudou foi a relação entre a versão congelada e o mundo.
 */
enum SalesBoardCycleStatus: string
{
    case Generated = 'gerado';

    case BuilderReview = 'validacao_construtora';

    case ManagementReview = 'analise_gestao';

    case Returned = 'devolvido';

    case Approved = 'aprovado';

    case Cancelled = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Gerado',
            self::BuilderReview => 'Em validação da construtora',
            self::ManagementReview => 'Em análise da Gestão',
            self::Returned => 'Devolvido',
            self::Approved => 'Aprovado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Generated => 'info',
            self::BuilderReview, self::ManagementReview => 'warning',
            self::Returned => 'danger',
            self::Approved => 'success',
            self::Cancelled => 'gray',
        };
    }
}
