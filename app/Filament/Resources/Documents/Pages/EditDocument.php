<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static ?string $breadcrumb = 'Editar';

    protected ?string $subheading = 'Atualize os metadados, publicação e vínculos deste documento.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-document-form-page',
    ];

    protected ?bool $hasUnsavedDataChangesAlert = true;

    public function getTitle(): string
    {
        return 'Editar '.($this->record?->title ?: 'Documento');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()
                    ->label('Excluir documento')
                    ->modalHeading('Excluir documento')
                    ->modalDescription('Tem certeza que deseja excluir este documento? Esta ação não pode ser desfeita e removerá o arquivo de forma permanente.')
                    ->modalSubmitActionLabel('Sim, excluir')
                    ->visible(fn (): bool => DocumentResource::canDelete($this->getRecord())),
            ])
                ->label('Opções')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->tooltip('Mais opções')
                ->dropdownWidth(Width::ExtraSmall),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Salvar alterações')
            ->color('primary');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Cancelar')
            ->color('gray');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Documento atualizado com sucesso.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->record;

        $data['storage_disk'] = $data['storage_disk'] ?? $record->storage_disk ?? Document::defaultStorageDisk();

        if (is_array($data['file_path'] ?? null)) {
            $data['file_path'] = reset($data['file_path']) ?: null;
        }

        // Ver CreateDocument: os metadados do arquivo são derivados do disco no
        // `saving`, e só o nome exibido continua vindo do formulário.
        if (! empty($data['file_path'])) {
            $data['file_name'] = ($data['file_name'] ?? null) ?: basename((string) $data['file_path']);
        }

        if (! empty($data['is_published']) && ! $record->is_published) {
            $data['published_at'] = now();
            $data['published_by'] = auth()->id();
        }

        return $data;
    }
}
