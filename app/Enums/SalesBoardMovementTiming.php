<?php

namespace App\Enums;

/**
 * Quando o fato de um movimento aconteceu em relação à competência que o
 * congelou.
 *
 * O movimento do mês não tem timing (`null` na coluna): é o caso de sempre, e
 * nenhuma versão congelada antes desta classificação muda de resumo. Os casos
 * daqui são os fatos que a competência recebe de antes dela, apurados contra a
 * posição congelada da competência anterior:
 *
 * - **extemporâneo**: venda, distrato ou quitação datados numa competência já
 *   fechada e lançados depois dela. A posição publicada daquela competência não
 *   muda; o fato entra aqui, passa pela validação da construtora e, se for
 *   venda, pela conformidade da data da venda e pela Gestão;
 * - **revisão de venda**: o valor ou a data de uma venda que a competência
 *   anterior congelou mudou na fonte. Não é venda nova -- não conta como venda
 *   no relatório --, mas a conformidade é reavaliada e passa pela Gestão;
 * - **de competência sem posição**: fato datado numa competência cancelada que
 *   fica entre a anterior e esta -- ou, sem competência anterior, numa cadeia de
 *   canceladas logo antes desta, como a primeira competência automatizada
 *   cancelada. Ninguém o publicou, e esta competência o absorve como fato do
 *   mês, com as mesmas regras e bloqueios.
 *
 * A competência de origem não é gravada: sai da data da venda ou do distrato.
 * Os valores cabem em `varchar(30)`, a largura da coluna `timing`.
 */
enum SalesBoardMovementTiming: string
{
    case Extemporaneous = 'extemporaneo';

    case SaleRevision = 'revisao_venda';

    case WithoutPosition = 'competencia_sem_posicao';

    public function label(): string
    {
        return match ($this) {
            self::Extemporaneous => 'Extemporâneo',
            self::SaleRevision => 'Revisão de venda publicada',
            self::WithoutPosition => 'De competência sem posição',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Extemporaneous => 'warning',
            self::SaleRevision => 'danger',
            self::WithoutPosition => 'info',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Extemporaneous => 'Fato datado numa competência já fechada e lançado depois dela: a posição daquela competência não muda, e o fato entra nesta.',
            self::SaleRevision => 'O valor ou a data de uma venda já congelada na competência anterior mudou na fonte: a conformidade é reavaliada aqui.',
            self::WithoutPosition => 'Fato de uma competência cancelada, sem posição publicada, que esta competência absorve.',
        };
    }

    /**
     * O fato pertence a uma competência que já tinha posição congelada -- e por
     * isso os achados dele são avisos, nunca bloqueios: um problema num mês
     * fechado não pode impedir a apuração do mês corrente.
     *
     * O fato de competência sem posição não entra: ninguém o congelou, e ele
     * segue as regras do mês, inclusive os bloqueios de venda.
     */
    public function isLate(): bool
    {
        return $this !== self::WithoutPosition;
    }

    /**
     * O movimento é venda nova para quem conta vendas. A revisão de venda é a
     * mesma venda de antes, com outro valor ou outra data.
     */
    public function countsAsNewSale(): bool
    {
        return $this !== self::SaleRevision;
    }
}
