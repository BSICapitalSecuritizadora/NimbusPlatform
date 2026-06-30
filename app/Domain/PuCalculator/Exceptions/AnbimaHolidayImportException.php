<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use RuntimeException;

/**
 * Falha ao baixar (URL indisponível/erro HTTP/timeout) ou ler (arquivo ausente, formato inválido, planilha
 * vazia) o arquivo de feriados nacionais da ANBIMA. Nunca ocorre em tempo de cálculo — só no fluxo de
 * importação — e jamais quebra a geração da curva, que usa o calendário persistido. Quando a URL falha,
 * a mensagem orienta o upload manual do arquivo como fallback.
 */
class AnbimaHolidayImportException extends RuntimeException {}
