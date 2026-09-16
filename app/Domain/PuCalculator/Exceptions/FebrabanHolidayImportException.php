<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use RuntimeException;

/**
 * Falha ao obter (HTTP/timeout/corpo vazio) ou interpretar (JSON inválido, data ilegível, dia da semana
 * incoerente, ano sem curadoria publicada) os feriados bancários da FEBRABAN.
 *
 * Nunca ocorre em tempo de cálculo — só no fluxo de importação. A regra é falhar alto: a fonte responde
 * HTTP 200 mesmo para anos que não publica, então um erro explícito aqui é preferível a uma carga
 * silenciosamente incompleta, que marcaria datas móveis como dias úteis.
 */
class FebrabanHolidayImportException extends RuntimeException {}
