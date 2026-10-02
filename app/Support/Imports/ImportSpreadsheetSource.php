<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\Support\Uploads\LocalUploadedFile;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * A planilha que um assistente de importação recebeu, do envio ao Confirmar.
 *
 * Os campos de arquivo dos assistentes usam `storeFiles(false)`: o estado é o
 * próprio envio temporário, o mesmo arquivo da conferência ao Confirmar. Antes,
 * o Filament gravava uma cópia ao sair da etapa Arquivo, e o Confirmar lia outro
 * arquivo -- foi isso que fez a conferência ser refeita duas vezes no Confirmar.
 *
 * Só o `TemporaryUploadedFile` que o Livewire hidratou a partir da referência
 * assinada é aceito. Um texto no estado é recusado: ele viria do cliente, e
 * antes era lido como caminho do disco privado.
 *
 * O arquivo é lido por {@see LocalUploadedFile}, então nada aqui depende de o
 * disco do temporário ser local.
 */
final class ImportSpreadsheetSource
{
    /**
     * Disco onde a planilha confirmada é arquivada: o privado, fora do wwwroot.
     */
    public const ARCHIVE_DISK = 'local';

    private ?string $checksum = null;

    private function __construct(private readonly TemporaryUploadedFile $file) {}

    /**
     * A fonte do estado do campo, ou `null` quando ele não traz um envio.
     *
     * O campo único guarda `[uuid => arquivo]`; o próprio arquivo também é
     * aceito. Qualquer outra coisa -- texto, mais de um arquivo -- não é fonte.
     */
    public static function fromState(mixed $state): ?self
    {
        if (is_array($state)) {
            $state = count($state) === 1 ? Arr::first($state) : null;
        }

        return $state instanceof TemporaryUploadedFile ? new self($state) : null;
    }

    /**
     * Nome do envio temporário: muda a cada envio, inclusive do mesmo arquivo,
     * e por isso entra na chave da conferência.
     */
    public function temporaryFilename(): string
    {
        return $this->file->getFilename();
    }

    /**
     * SHA-256 do conteúdo, calculado uma vez por requisição.
     */
    public function checksum(): string
    {
        return $this->checksum ??= LocalUploadedFile::checksum($this->file);
    }

    /**
     * O nome com que o operador enviou o arquivo -- é ele que precisa aparecer
     * no histórico meses depois, não o nome gerado do temporário.
     */
    public function originalName(): string
    {
        $name = trim($this->file->getClientOriginalName());

        return $name === '' ? $this->temporaryFilename() : $name;
    }

    public function file(): TemporaryUploadedFile
    {
        return $this->file;
    }

    /**
     * Executa o callback com um caminho local legível da planilha.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public function read(Closure $callback): mixed
    {
        return LocalUploadedFile::using($this->file, $callback);
    }

    /**
     * Arquiva a planilha confirmada em `imports/<tipo>/<ulid>.xlsx` do disco
     * privado, por stream, e devolve o caminho relativo gravado.
     *
     * @throws RuntimeException quando o arquivo não pode ser lido ou gravado
     */
    public function archive(string $directory): string
    {
        $path = trim($directory, '/').'/'.Str::lower((string) Str::ulid()).'.xlsx';

        $stream = rescue(fn (): mixed => $this->file->readStream(), null, report: false);

        if (! is_resource($stream)) {
            throw new RuntimeException('Não foi possível ler a planilha enviada para arquivá-la.');
        }

        try {
            $written = Storage::disk(self::ARCHIVE_DISK)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written !== true) {
            throw new RuntimeException('Não foi possível arquivar a planilha enviada.');
        }

        return $path;
    }

    /**
     * Apaga o envio temporário e o `.json` de metadados que o Livewire grava ao
     * lado dele. Sem isto, a planilha com CPF/CNPJ ficaria no disco temporário
     * até a limpeza agendada.
     */
    public function discard(): void
    {
        $filename = $this->temporaryFilename();

        rescue(fn (): mixed => $this->file->delete(), null, report: false);

        rescue(
            fn (): mixed => Storage::disk(FileUploadConfiguration::disk())
                ->delete(FileUploadConfiguration::path($filename.'.json', false)),
            null,
            report: false,
        );
    }

    /**
     * Remove uma planilha arquivada que acabou não sendo referenciada por
     * nenhuma importação -- a gravação foi desfeita depois do arquivamento.
     */
    public static function forgetArchive(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        rescue(fn (): mixed => Storage::disk(self::ARCHIVE_DISK)->delete((string) $path), null, report: false);
    }
}
