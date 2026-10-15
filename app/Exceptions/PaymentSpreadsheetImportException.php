<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A planilha do cronograma de pagamentos não pode ser importada como está:
 * nenhuma linha reconhecida, ou valor que não é número numa linha com data.
 *
 * Tipo próprio para a tela mostrar o motivo (com a linha) em vez do aviso
 * genérico, e para que só o problema do arquivo vire essa mensagem: uma falha
 * do banco no meio da importação continua sendo o que é.
 */
class PaymentSpreadsheetImportException extends RuntimeException {}
