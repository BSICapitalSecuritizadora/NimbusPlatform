<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> */
    protected array $rolesBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record->getKey() !== auth()->id()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['email'] = str((string) $data['email'])->lower()->toString();

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->rolesBeforeSave = $this->record->roles->pluck('name')->sort()->values()->all();
    }

    protected function afterSave(): void
    {
        $rolesAfter = $this->record->fresh()->roles->pluck('name')->sort()->values()->all();

        if ($this->rolesBeforeSave !== $rolesAfter) {
            activity('roles')
                ->causedBy(auth()->user())
                ->performedOn($this->record)
                ->event('updated')
                ->withProperties([
                    'before' => ['roles' => $this->rolesBeforeSave],
                    'after' => ['roles' => $rolesAfter],
                ])
                ->log('updated');
        }
    }
}
