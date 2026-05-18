<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Filament\Resources\Emissions\EmissionResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;

class EditEmission extends EditRecord
{
    protected static string $resource = EmissionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public bool $isExtractingClauses = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (Cache::get("gemini_extraction_{$this->record->id}_status") === 'processing') {
            $this->isExtractingClauses = true;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['integralized_quantity'] = $this->getRecord()->calculateIntegralizedQuantity();

        return $data;
    }

    public function checkGeminiExtractionStatus(): void
    {
        $status = Cache::get("gemini_extraction_{$this->record->id}_status");

        if ($status === 'completed') {
            $this->isExtractingClauses = false;
            Cache::forget("gemini_extraction_{$this->record->id}_status");

            Notification::make()
                ->title('Cláusulas extraídas com sucesso')
                ->body('Os campos foram preenchidos com os dados extraídos do Termo de Securitização. Revise e salve.')
                ->success()
                ->send();

            $this->redirect(
                EmissionResource::getUrl('edit', ['record' => $this->record]),
                navigate: true,
            );
        } elseif (is_array($status) && isset($status['error'])) {
            $this->isExtractingClauses = false;
            Cache::forget("gemini_extraction_{$this->record->id}_status");

            Notification::make()
                ->title('Falha na extração das cláusulas')
                ->body($status['error'])
                ->danger()
                ->send();
        }
    }

    #[On('integralization-histories-updated')]
    public function refreshIntegralizedQuantity(): void
    {
        $this->getRecord()->refresh();

        $this->refreshFormData(['integralized_quantity']);
    }
}
