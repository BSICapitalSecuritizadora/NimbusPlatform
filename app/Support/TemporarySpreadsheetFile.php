<?php

namespace App\Support;

use Closure;
use RuntimeException;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Throwable;

/**
 * Cria a planilha temporária de um download e garante que ela seja o único
 * arquivo que o fluxo deixa no diretório de temporários.
 *
 * O padrão anterior -- `tempnam(sys_get_temp_dir(), $prefixo).'.xlsx'` --
 * escrevia num caminho que o `tempnam()` nunca reservou, e deixava para trás o
 * arquivo vazio que ele de fato criou. Como `deleteFileAfterSend()` remove
 * apenas o arquivo servido, cada download de modelo abandonava um órfão de zero
 * byte; centenas se acumularam em `/tmp`.
 *
 * A extensão `.xlsx` não é cosmética: tanto o writer quanto o reader do
 * spatie/simple-excel escolhem o formato por `pathinfo(..., PATHINFO_EXTENSION)`,
 * e o próprio `AnalyzeContractSpreadsheet` lê o caminho devolvido por
 * `build()`. Um caminho sem extensão faria o leitor recusar o arquivo.
 *
 * Daí a reserva em duas etapas de {@see reservePath()}: o `tempnam()` serve
 * apenas para tomar um nome de forma atômica, e o arquivo definitivo nasce
 * desse nome com a extensão. Nenhum dos dois fica órfão.
 */
final class TemporarySpreadsheetFile
{
    private const EXTENSION = '.xlsx';

    /**
     * Tentativas de reserva antes de desistir. Só é gasta uma quando o caminho
     * com extensão já existe -- resíduo de uma execução anterior morta, na
     * prática. Três tentativas cobrem isso sem mascarar um `/tmp` quebrado.
     */
    private const RESERVATION_ATTEMPTS = 3;

    /**
     * Escreve uma planilha temporária e devolve o caminho dela.
     *
     * O chamador é dono do arquivo a partir daqui e precisa removê-lo -- é o
     * que `deleteFileAfterSend()` faz nos controllers de download. Se a geração
     * falhar, o arquivo é removido aqui mesmo e a exceção segue: quem nunca
     * recebeu o caminho não teria como limpá-lo.
     *
     * @param  string  $prefix  prefixo do nome no diretório de temporários
     * @param  Closure(SimpleExcelWriter): void  $writeContents
     */
    public static function write(string $prefix, Closure $writeContents): string
    {
        $path = self::reservePath($prefix);

        try {
            $writer = SimpleExcelWriter::create($path);

            $writeContents($writer);

            $writer->close();
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return $path;
    }

    /**
     * Reserva um caminho exclusivo terminado em `.xlsx`.
     *
     * `tempnam()` cria e reserva um nome sem extensão. Enquanto esse nome
     * existir, nenhuma outra chamada de `tempnam()` na máquina pode devolvê-lo
     * -- então nenhum outro processo pode estar disputando o nome derivado dele.
     * É nessa janela que o arquivo com extensão é criado em modo `x`, que falha
     * se o caminho já existir em vez de sobrescrever. Só depois que o arquivo
     * definitivo é nosso a reserva é liberada.
     *
     * A ordem importa. Liberar a reserva antes de criar o `.xlsx` -- ou renomear
     * um para o outro -- devolveria o nome ao sorteio enquanto o download ainda
     * usa o arquivo derivado, e um segundo processo que sorteasse o mesmo nome
     * sobrescreveria a planilha de quem está baixando.
     */
    private static function reservePath(string $prefix): string
    {
        foreach (range(1, self::RESERVATION_ATTEMPTS) as $ignored) {
            $reservation = tempnam(sys_get_temp_dir(), $prefix);

            if ($reservation === false) {
                throw new RuntimeException('Não foi possível criar um arquivo temporário para a planilha.');
            }

            $path = $reservation.self::EXTENSION;
            $handle = @fopen($path, 'x');

            unlink($reservation);

            if ($handle !== false) {
                fclose($handle);

                return $path;
            }
        }

        throw new RuntimeException('Não foi possível reservar um caminho temporário livre para a planilha.');
    }
}
