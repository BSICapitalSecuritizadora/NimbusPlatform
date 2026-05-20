<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array{name: string, permissions: list<string>} */
    protected array $roleStateBeforeSave = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! in_array($this->record->name, RoleResource::systemRoles(), true)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->roleStateBeforeSave = [
            'name' => $this->record->name,
            'permissions' => $this->record->permissions->pluck('name')->sort()->values()->all(),
        ];
    }

    protected function afterSave(): void
    {
        $fresh = $this->record->fresh(['permissions']);

        $after = [
            'name' => $fresh->name,
            'permissions' => $fresh->permissions->pluck('name')->sort()->values()->all(),
        ];

        if ($this->roleStateBeforeSave !== $after) {
            activity('roles')
                ->causedBy(auth()->user())
                ->performedOn($this->record)
                ->event('updated')
                ->withProperties([
                    'before' => $this->roleStateBeforeSave,
                    'after' => $after,
                ])
                ->log('updated');
        }
    }
}
