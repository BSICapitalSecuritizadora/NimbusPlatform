<?php

namespace App\Filament\Support;

use App\Http\Middleware\ApplySpreadsheetImportUploadRules;
use Filament\Actions\Action;
use Livewire\Attributes\Renderless;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Throwable;

/**
 * Deixa os campos de importação de planilha deste componente enviarem CSV, sem
 * afrouxar o envio temporário do resto da aplicação.
 *
 * O Livewire valida todo envio temporário com uma regra global, que não aceita
 * CSV. Aqui, e só quando o envio é do campo de arquivo de uma ação de importação
 * montada e visível para o usuário, a URL de envio sai assinada com o perfil de
 * importação ({@see ApplySpreadsheetImportUploadRules}). Qualquer outro envio do
 * componente segue o caminho padrão do Livewire.
 *
 * O perfil só decide o que pode chegar ao disco temporário (privado). O campo
 * continua com os tipos aceitos dele, e o importador continua lendo o conteúdo
 * com as regras próprias.
 */
trait AcceptsSpreadsheetImportUploads
{
    /**
     * Nome da ação de importação => nome do campo de arquivo do formulário dela.
     *
     * @return array<string, string>
     */
    abstract protected function spreadsheetImportUploadFields(): array;

    #[Renderless]
    public function _startUpload(mixed $name, mixed $fileInfo, mixed $isMultiple): void
    {
        if (FileUploadConfiguration::isUsingS3() || ! $this->isSpreadsheetImportUpload((string) $name)) {
            parent::_startUpload($name, $fileInfo, $isMultiple);

            return;
        }

        $this->dispatch(
            'upload:generatedSignedUrl',
            name: $name,
            url: GenerateSignedUploadUrlFacade::signedRoute(
                'livewire.upload-file',
                now()->addMinutes(FileUploadConfiguration::maxUploadTime()),
                ['purpose' => ApplySpreadsheetImportUploadRules::PURPOSE],
            ),
        )->self();
    }

    /**
     * A mensagem da recusa no envio nomeia o atributo validado (`files.0`), que o
     * Livewire troca pelo caminho do estado (`mountedActions.0.data.csv_file.<uuid>`).
     * No campo de importação, a pessoa lê "o campo arquivo deve ser ...".
     */
    public function _uploadErrored(mixed $name, mixed $errorsInJson, mixed $isMultiple): void
    {
        $decoded = is_string($errorsInJson) ? json_decode($errorsInJson, true) : null;

        if (is_array($decoded['errors'] ?? null) && $this->isSpreadsheetImportUpload((string) $name)) {
            $decoded['errors'] = array_map(
                fn (mixed $messages): array => array_map(fn (mixed $message): string => str_ireplace(['files.0', 'files'], 'arquivo', (string) $message), (array) $messages),
                $decoded['errors'],
            );
            $errorsInJson = json_encode($decoded);
        }

        parent::_uploadErrored($name, $errorsInJson, $isMultiple);
    }

    /**
     * `mountedActions` é estado público do Livewire: a ação é resolvida de novo
     * e precisa estar visível (permissão incluída) para o perfil ser concedido.
     */
    protected function isSpreadsheetImportUpload(string $name): bool
    {
        $index = array_key_last($this->mountedActions ?? []);

        if ($index === null) {
            return false;
        }

        $field = $this->spreadsheetImportUploadFields()[$this->mountedActions[$index]['name'] ?? ''] ?? null;

        if ($field === null || ! str_starts_with($name, "mountedActions.{$index}.data.{$field}.")) {
            return false;
        }

        try {
            $action = $this->getMountedAction($index);
        } catch (Throwable) {
            return false;
        }

        return ($action instanceof Action)
            && array_key_exists($action->getName(), $this->spreadsheetImportUploadFields())
            && (! $action->isHidden())
            && (! $action->isDisabled());
    }
}
