<?php

namespace App\Filament\Resources\Areas;

use App\Enums\AccessPermission;
use App\Enums\BusinessArea;
use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Models\Area;
use App\Services\AreaResponsibilityService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Catálogo fixo de áreas (uma linha por {@see BusinessArea}) com os
 * responsáveis de cada uma. Só os responsáveis são editáveis aqui.
 */
class AreaResource extends Resource
{
    protected static ?string $model = Area::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Áreas e responsáveis';

    protected static ?string $modelLabel = 'Área';

    protected static ?string $pluralModelLabel = 'Áreas e responsáveis';

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $navigationParentItem = 'Configurações';

    protected static ?int $navigationSort = 94;

    protected static ?string $slug = 'settings/areas';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->paginated(false)
            ->stackedOnMobile()
            ->recordActionsColumnLabel('Ação')
            ->recordActionsAlignment('end')
            ->emptyStateHeading('Nenhuma área configurada')
            ->emptyStateDescription('Não há áreas de negócio cadastradas no momento.')
            ->emptyStateIcon('heroicon-o-rectangle-group')
            ->columns([
                TextColumn::make('code')
                    ->label('Área')
                    ->width('24%')
                    ->weight('semibold')
                    ->formatStateUsing(fn (BusinessArea $state): string => $state->label())
                    ->extraAttributes(['class' => 'bsi-area-col-title whitespace-nowrap']),
                TextColumn::make('responsibles.name')
                    ->label('Responsáveis')
                    ->width('22%')
                    ->badge()
                    ->color('primary')
                    ->placeholder('Sem responsável'),
                TextColumn::make('responsible_rule')
                    ->label('Regra / Permissão')
                    ->width('40%')
                    ->state(fn (Area $record): string => $record->code->responsibleRule() ?? 'Nenhuma regra específica ainda.')
                    ->color(fn (Area $record): ?string => $record->code->responsibleRule() === null ? 'gray' : null)
                    ->wrap()
                    ->extraAttributes(fn (Area $record): array => [
                        'class' => $record->code->responsibleRule() !== null
                            ? 'bsi-rule-active'
                            : 'bsi-rule-empty',
                    ]),
            ])
            ->recordActions([
                Action::make('defineResponsibles')
                    ->label('Definir responsáveis')
                    ->icon('heroicon-o-user-group')
                    ->button()
                    ->outlined()
                    ->size('sm')
                    ->visible(fn (): bool => static::canManage())
                    ->authorize(fn (): bool => static::canManage())
                    ->modalHeading(fn (Area $record): string => 'Responsáveis — '.$record->code->label())
                    ->modalDescription('Ser responsável não concede permissão: as ações continuam dependendo do perfil de acesso. A alteração fica registrada na auditoria.')
                    ->modalSubmitActionLabel('Salvar')
                    ->fillForm(fn (Area $record): array => [
                        'responsibles' => $record->responsibles()->pluck('users.id')->all(),
                    ])
                    ->form(fn (Area $record): array => [
                        Select::make('responsibles')
                            ->label('Responsáveis')
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => app(AreaResponsibilityService::class)->selectableUserOptions($record))
                            ->helperText('Só usuários ativos e liberados podem ser acrescentados.'),
                    ])
                    ->action(function (Area $record, array $data): void {
                        try {
                            $result = app(AreaResponsibilityService::class)->syncResponsibles($record, $data['responsibles'] ?? [], auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title('Responsáveis não salvos')
                                ->body(collect($exception->errors())->flatten()->implode(' '))->persistent()->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->success()
                            ->title($result['added'] === [] && $result['removed'] === [] ? 'Nenhuma alteração.' : 'Responsáveis atualizados.')
                            ->send();
                    }),
            ]);
    }

    public static function canManage(): bool
    {
        return auth()->user()?->can(AccessPermission::AreasManage->value) ?? false;
    }

    public static function canViewAny(): bool
    {
        return (auth()->user()?->can(AccessPermission::AreasView->value) ?? false) || static::canManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('responsibles')
            ->when(! static::canViewAny(), fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAreas::route('/'),
        ];
    }
}
