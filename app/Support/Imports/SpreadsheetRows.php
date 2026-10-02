<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\Exceptions\UnreadableSpreadsheetException;
use Generator;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * As linhas de uma planilha de importação, lidas sempre como XLSX.
 *
 * O tipo vai explícito: o leitor escolhia o formato pela extensão do caminho, e
 * a cópia local de um envio pode não ter a extensão original. E só o leitor é
 * vigiado aqui -- um arquivo corrompido vira {@see UnreadableSpreadsheetException}
 * com a mensagem que a conferência mostra, e a exceção do leitor vai para o log.
 * O que o chamador faz entre um lote e outro (consultas, gravações) falha com a
 * própria exceção, nunca disfarçado de "planilha ilegível".
 */
final class SpreadsheetRows
{
    public const TYPE = 'xlsx';

    public const UNREADABLE_FILE_MESSAGE = 'Não foi possível ler a planilha: envie um arquivo .xlsx válido, salvo pelo Excel ou pelo modelo da plataforma.';

    /**
     * A primeira linha de dados, com as chaves do cabeçalho, ou `null` quando a
     * planilha não tem linhas.
     *
     * @return array<string, mixed>|null
     *
     * @throws UnreadableSpreadsheetException
     */
    public static function first(string $path): ?array
    {
        try {
            $row = SimpleExcelReader::create($path, self::TYPE)->getRows()->first();
        } catch (Throwable $exception) {
            throw self::unreadable($exception);
        }

        return is_array($row) ? $row : null;
    }

    /**
     * As linhas de dados em lotes, na ordem do arquivo.
     *
     * @return Generator<int, list<array<string, mixed>>>
     *
     * @throws UnreadableSpreadsheetException
     */
    public static function chunks(string $path, int $size): Generator
    {
        try {
            $iterator = SimpleExcelReader::create($path, self::TYPE)->getRows()->chunk($size)->getIterator();
            $iterator->rewind();
        } catch (Throwable $exception) {
            throw self::unreadable($exception);
        }

        while (true) {
            try {
                if (! $iterator->valid()) {
                    return;
                }

                $chunk = array_values($iterator->current()->all());
            } catch (Throwable $exception) {
                throw self::unreadable($exception);
            }

            yield $chunk;

            try {
                $iterator->next();
            } catch (Throwable $exception) {
                throw self::unreadable($exception);
            }
        }
    }

    private static function unreadable(Throwable $exception): UnreadableSpreadsheetException
    {
        report($exception);

        return new UnreadableSpreadsheetException(self::UNREADABLE_FILE_MESSAGE, previous: $exception);
    }
}
