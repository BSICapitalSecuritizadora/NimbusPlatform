<?php

namespace App\Filament\Resources\Emissions\Pages;

use App\Filament\Resources\Emissions\EmissionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Livewire\Attributes\On;

class EditEmission extends EditRecord
{
    protected static string $resource = EmissionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

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

    #[On('integralization-histories-updated')]
    public function refreshIntegralizedQuantity(): void
    {
        $this->getRecord()->refresh();

        $this->refreshFormData(['integralized_quantity']);
    }
}
