<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O leitor de planilhas não conseguiu ler o arquivo no meio de uma leitura em
 * fluxo -- um XLSX truncado ou corrompido depois do cabeçalho.
 *
 * Tipo próprio para que a conferência diga "não foi possível ler a planilha" em
 * vez de deixar escapar a exceção do leitor, e para que só o erro de leitura do
 * arquivo vire essa mensagem: uma falha do banco no meio da leitura continua
 * sendo o que é.
 */
class UnreadableSpreadsheetException extends RuntimeException {}
