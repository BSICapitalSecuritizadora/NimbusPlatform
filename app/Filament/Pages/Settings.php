<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?int $navigationSort = 90;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Configurações';

    protected static ?string $title = 'Configurações';

    protected ?string $subheading = 'Gerencie acessos, permissões e parâmetros administrativos do sistema.';

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-settings-hub-page',
    ];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return UserResource::canViewAny()
            || RoleResource::canViewAny()
            || SpreadsheetTemplates::canAccess();
    }

    public function canAccessUsers(): bool
    {
        return UserResource::canViewAny();
    }

    public function canAccessRoles(): bool
    {
        return RoleResource::canViewAny();
    }

    public function canAccessSpreadsheetTemplates(): bool
    {
        return SpreadsheetTemplates::canAccess();
    }

    public function getUsersUrl(): string
    {
        return UserResource::getUrl(panel: 'admin');
    }

    public function getRolesUrl(): string
    {
        return RoleResource::getUrl(panel: 'admin');
    }

    public function getSpreadsheetTemplatesUrl(): string
    {
        return SpreadsheetTemplates::getUrl(panel: 'admin');
    }
}
