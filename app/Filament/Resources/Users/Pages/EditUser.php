<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Editar Usuário';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-user-edit-page',
    ];

    public function getSubheading(): ?string
    {
        return 'Atualize as informações cadastrais, vínculos e permissões de acesso do usuário.';
    }

    /** @var list<string> */
    protected array $rolesBeforeSave = [];

    /**
     * Exclusão física saiu daqui: o ciclo de vida é Desativar/Reativar, e as duas
     * ações vivem na listagem, ao lado do registro.
     */
    protected function getHeaderActions(): array
    {
        return [
            UserResource::getDeactivateUserAction()->record($this->record),
            UserResource::getReactivateUserAction()->record($this->record),
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
