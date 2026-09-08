<?php

namespace App\Enums;

/**
 * Em que ponto está a análise da Gestão sobre uma submissão da construtora.
 *
 * Quatro estados, e nenhum deles é "correção necessária". Correção é a conclusão
 * da Gestão sobre **um item**, não a situação da rodada: uma análise com uma
 * pendência de correção continua sendo uma análise em andamento, e transformar
 * isso em estado da revisão obrigaria a inventar uma volta atrás toda vez que a
 * Gestão mudasse de ideia sobre um único apontamento. O que a pendência faz é
 * bloquear a aprovação -- e isso é regra do portão, não do ciclo de vida.
 *
 * `Returned` e `Approved` são terminais para a tentativa. `Superseded` é a saída
 * involuntária: uma nova versão material apareceu e os fatos analisados deixaram
 * de ser os vigentes.
 */
enum SalesBoardManagementReviewStatus: string
{
    case Draft = 'em_analise';

    case Returned = 'devolvida';

    case Approved = 'aprovada';

    case Superseded = 'substituida';

    /**
     * Rascunho é a única fase em que a Gestão ainda decide.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return $this !== self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Em análise',
            self::Returned => 'Devolvida à construtora',
            self::Approved => 'Aprovada',
            self::Superseded => 'Substituída por nova versão',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Returned => 'danger',
            self::Approved => 'success',
            self::Superseded => 'gray',
        };
    }
}
