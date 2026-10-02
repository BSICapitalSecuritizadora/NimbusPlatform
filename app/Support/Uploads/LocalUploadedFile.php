<?php

declare(strict_types=1);

namespace App\Support\Uploads;

use App\Support\TemporarySpreadsheetFile;
use Closure;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * O único lugar da aplicação que transforma um arquivo enviado num caminho do
 * disco local.
 *
 * `getRealPath()` só é um caminho de verdade quando o arquivo está num disco
 * local. O envio temporário do Livewire vive no disco configurado em
 * `livewire.temporary_file_upload.disk` e, num disco remoto (o Azure Blob da
 * produção), `getRealPath()` devolve `livewire-tmp/<nome>.xlsx`: um caminho
 * relativo que nenhum `is_file()` encontra. Foi assim que as conferências das
 * importações ficaram cegas, que Recebíveis passou a recusar toda planilha e que
 * a varredura antivírus deixou de alcançar o arquivo.
 *
 * Daí as duas operações:
 *
 * - {@see self::using()} entrega ao callback um caminho local legível: o próprio,
 *   quando existe, ou uma cópia feita por stream, apagada ao terminar;
 * - {@see self::checksum()} calcula o SHA-256 lendo o stream, sem cópia.
 *
 * Um teste de guarda proíbe `->getRealPath()` em `app/` fora desta classe: é o
 * que impede o defeito de voltar por um caminho novo.
 */
final class LocalUploadedFile
{
    /**
     * Tentativas de reserva do caminho da cópia. Só é gasta uma quando o caminho
     * com extensão já existe -- resíduo de uma execução morta, na prática.
     */
    private const RESERVATION_ATTEMPTS = 3;

    /**
     * Prefixo das cópias no diretório de temporários da instância.
     */
    private const COPY_PREFIX = 'nimbus-upload-';

    /**
     * Executa o callback com um caminho local legível para o arquivo.
     *
     * A cópia, quando precisa existir, mantém a extensão original em
     * minúsculas -- leitores como o do spatie/simple-excel escolhem o formato
     * pela extensão -- e é apagada no `finally`, inclusive quando o callback
     * lança: ela carrega o mesmo dado pessoal que o envio.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public static function using(UploadedFile $file, Closure $callback): mixed
    {
        $localPath = self::readableLocalPath($file);

        if ($localPath !== null) {
            return $callback($localPath);
        }

        $copy = self::copyToLocal($file);

        try {
            return $callback($copy);
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
    }

    /**
     * SHA-256 do conteúdo, lido por stream: o mesmo valor esteja o arquivo num
     * disco local ou remoto, e sem gravar uma cópia para calculá-lo.
     *
     * @throws RuntimeException quando o arquivo não pode ser lido
     */
    public static function checksum(UploadedFile $file): string
    {
        $stream = self::openReadStream($file);

        try {
            $context = hash_init('sha256');

            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * O caminho do arquivo quando ele é, de fato, um arquivo legível no disco
     * local. Caminho relativo nunca conta: ele seria resolvido contra o
     * diretório corrente do processo, que não tem nada a ver com o disco.
     */
    private static function readableLocalPath(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if (! is_string($path) || ($path === '') || ! self::isAbsolute($path)) {
            return null;
        }

        return (is_file($path) && is_readable($path)) ? $path : null;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * Copia o arquivo por stream para um caminho local reservado.
     */
    private static function copyToLocal(UploadedFile $file): string
    {
        $stream = self::openReadStream($file);

        try {
            [$path, $handle] = self::reservePath(self::extensionOf($file));

            try {
                $copied = stream_copy_to_stream($stream, $handle);
            } finally {
                fclose($handle);
            }
        } finally {
            fclose($stream);
        }

        if ($copied === false) {
            @unlink($path);

            throw new RuntimeException('Não foi possível copiar o arquivo enviado para leitura.');
        }

        return $path;
    }

    /**
     * Stream de leitura do arquivo: o do disco do envio temporário, que funciona
     * também num disco remoto, ou o do próprio caminho local.
     *
     * @return resource
     */
    private static function openReadStream(UploadedFile $file): mixed
    {
        $stream = $file instanceof TemporaryUploadedFile
            ? rescue(fn (): mixed => $file->readStream(), null, report: false)
            : self::openLocalStream($file);

        if (! is_resource($stream)) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        }

        return $stream;
    }

    /**
     * @return resource|false
     */
    private static function openLocalStream(UploadedFile $file): mixed
    {
        $path = self::readableLocalPath($file);

        return $path === null ? false : @fopen($path, 'rb');
    }

    /**
     * Extensão original em minúsculas, só com caracteres seguros para nome de
     * arquivo. Sem extensão reconhecível, a cópia fica sem extensão.
     */
    private static function extensionOf(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.'.$extension : '';
    }

    /**
     * Reserva em duas etapas, como em {@see TemporarySpreadsheetFile}:
     * `tempnam()` toma o nome de forma atômica, o arquivo definitivo nasce desse
     * nome com a extensão, em modo `x`, e só então a reserva é liberada. Nenhum
     * outro processo pode receber o mesmo nome no meio do caminho.
     *
     * @return array{0: string, 1: resource}
     */
    private static function reservePath(string $extension): array
    {
        foreach (range(1, self::RESERVATION_ATTEMPTS) as $ignored) {
            $reservation = tempnam(sys_get_temp_dir(), self::COPY_PREFIX);

            if ($reservation === false) {
                throw new RuntimeException('Não foi possível criar um arquivo temporário para a leitura do envio.');
            }

            if ($extension === '') {
                $handle = @fopen($reservation, 'wb');

                if ($handle !== false) {
                    return [$reservation, $handle];
                }

                @unlink($reservation);

                continue;
            }

            $path = $reservation.$extension;
            $handle = @fopen($path, 'xb');

            unlink($reservation);

            if ($handle !== false) {
                return [$path, $handle];
            }
        }

        throw new RuntimeException('Não foi possível reservar um caminho temporário livre para a leitura do envio.');
    }
}
