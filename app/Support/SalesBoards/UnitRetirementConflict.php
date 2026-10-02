<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use Carbon\CarbonImmutable;

/**
 * O período de baixa que uma ocupação nova cruzaria.
 *
 * A mesma frase nos três caminhos que ocupam a unidade -- o formulário do
 * contrato, a importação de contratos e a permuta --, para que a recusa diga a
 * mesma coisa onde quer que o dado nasça. A baixa aberta tem saída na própria
 * tela da unidade (reativar); a encerrada não se reabre, e a frase diz então
 * em que datas a ocupação cabe.
 */
final readonly class UnitRetirementConflict
{
    public function __construct(
        public CarbonImmutable $retiredOn,
        public ?CarbonImmutable $reactivatedOn,
    ) {}

    public function describe(?string $unitLabel): string
    {
        $unit = blank($unitLabel) ? 'A unidade' : 'A unidade '.$unitLabel;

        if ($this->reactivatedOn === null) {
            return sprintf(
                '%s está baixada a partir de %s: nesse período ela não compõe o Quadro de Vendas e não pode ser ocupada por contrato ou permuta. '
                    .'Se a venda é real, reative a unidade (aba "Baixas" da unidade) antes de registrá-la.',
                $unit,
                $this->retiredOn->format('d/m/Y'),
            );
        }

        return sprintf(
            '%s está baixada a partir de %s até a reativação em %s: nesse período ela não compõe o Quadro de Vendas e não pode ser ocupada por contrato ou permuta. '
                .'A ocupação precisa terminar até %s ou começar em %s ou depois.',
            $unit,
            $this->retiredOn->format('d/m/Y'),
            $this->reactivatedOn->format('d/m/Y'),
            $this->retiredOn->format('d/m/Y'),
            $this->reactivatedOn->format('d/m/Y'),
        );
    }
}
