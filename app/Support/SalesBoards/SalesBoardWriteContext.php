<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use Closure;

/**
 * De onde vem a escrita que está acontecendo em `sales_boards`.
 *
 * Existe porque o guard precisa distinguir duas escritas que, do ponto de vista
 * do model, são idênticas: a publicação legítima da Fase E e o registro manual
 * de uma competência já automatizada. As alternativas foram descartadas uma a
 * uma:
 *
 * - **`saveQuietly()` / `withoutEvents()`** na publicação desligaria o
 *   `SalesBoardObserver` junto, e com ele o histórico de versões que a Fase E
 *   homologou. Trocar a integridade do histórico pela conveniência do guard
 *   seria pagar caro por pouco;
 * - **olhar a origem HTTP ou o botão do Filament** amarraria uma regra de
 *   domínio à camada de entrega, e deixaria comando, job e teste sem resposta;
 * - **uma flag estática global** vazaria entre requisições no Octane e entre
 *   processos concorrentes, o que é pior do que não ter guard: seria um guard
 *   que às vezes deixa passar.
 *
 * O contexto é um objeto `scoped` no container -- vive por requisição/comando --
 * e a publicação o abre em volta da própria escrita, devolvendo-o ao estado
 * anterior mesmo em caso de exceção.
 */
class SalesBoardWriteContext
{
    private int $publicationDepth = 0;

    /**
     * A escrita atual vem da publicação do ciclo mensal?
     */
    public function isPublishing(): bool
    {
        return $this->publicationDepth > 0;
    }

    /**
     * Executa a escrita da publicação.
     *
     * Aninhável e à prova de exceção: o `finally` garante que uma publicação
     * que falhe no meio não deixe o contexto aberto para a próxima escrita da
     * mesma requisição -- que seria justamente a manual que o guard existe para
     * barrar.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asPublication(Closure $callback): mixed
    {
        $this->publicationDepth++;

        try {
            return $callback();
        } finally {
            $this->publicationDepth--;
        }
    }
}
