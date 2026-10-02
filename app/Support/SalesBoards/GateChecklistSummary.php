<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

/**
 * Os itens de um portão que não passaram, numa frase só.
 *
 * As telas da Análise e do Rollout dizem por que o botão está indisponível
 * juntando os itens do portão. Os detalhes vêm dos serviços e alguns já
 * terminam em ponto ("3 pendente(s) de decisão."), então juntar com "; " e
 * fechar com outro ponto produzia ".;" e "..". A pontuação é acertada aqui, na
 * apresentação, e não nos serviços: as mesmas mensagens aparecem em outras
 * telas e em testes, onde o ponto final é o certo.
 */
final class GateChecklistSummary
{
    /**
     * @param  list<string>  $failedChecks
     */
    public static function sentence(array $failedChecks): string
    {
        $items = array_values(array_filter(
            array_map(
                static fn (string $check): string => rtrim($check, " \t\n\r\0\x0B.;:"),
                $failedChecks,
            ),
            static fn (string $check): bool => $check !== '',
        ));

        return $items === [] ? '' : implode('; ', $items).'.';
    }
}
