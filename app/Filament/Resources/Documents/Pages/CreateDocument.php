<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $title = 'Criar documento';

    protected static ?string $breadcrumb = 'Criar';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-document-form-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Cadastre o arquivo, sua classificação, vínculos e regras de publicação.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Criar documento')
            ->icon('heroicon-m-plus')
            ->color('primary');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Salvar e criar outro')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Documento cadastrado com sucesso.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['storage_disk'] = $data['storage_disk'] ?? Document::defaultStorageDisk();

        if (is_array($data['file_path'] ?? null)) {
            $data['file_path'] = reset($data['file_path']) ?: null;
        }

        if (empty($data['file_path'])) {
            throw ValidationException::withMessages([
                'file_path' => 'O caminho final do arquivo não foi gerado. Tente enviar o arquivo novamente.',
            ]);
        }

        // `mime_type` e `file_size` são derivados do arquivo em disco por
        // DerivesStoredFileMetadata e não chegam mais no payload do formulário.
        // O nome exibido continua vindo do upload: o trait não o deriva neste
        // model, para não trocá-lo pelo nome de armazenamento.
        $data['file_name'] = ($data['file_name'] ?? null) ?: basename((string) $data['file_path']);

        if (! empty($data['is_published'])) {
            $data['published_at'] = $data['published_at'] ?? now();
            $data['published_by'] = $data['published_by'] ?? auth()->id();
        }

        return $data;
    }
}
