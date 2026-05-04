<?php

namespace App\Filament\Resources\Nimbus\NotificationOutboxes;

use App\Filament\Resources\Nimbus\NotificationOutboxes\Pages\ListNotificationOutboxes;
use App\Filament\Resources\Nimbus\NotificationOutboxes\Pages\ViewNotificationOutbox;
use App\Filament\Resources\Nimbus\NotificationOutboxes\Schemas\NotificationOutboxForm;
use App\Filament\Resources\Nimbus\NotificationOutboxes\Schemas\NotificationOutboxInfolist;
use App\Filament\Resources\Nimbus\NotificationOutboxes\Tables\NotificationOutboxesTable;
use App\Models\Nimbus\NotificationOutbox;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NotificationOutboxResource extends Resource
{
    protected static ?string $model = NotificationOutbox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static \UnitEnum|string|null $navigationGroup = 'Gestão Documental Externa';

    protected static ?string $navigationParentItem = 'Comunicação';

    protected static ?string $navigationLabel = 'Auditoria de Envios';

    protected static ?string $modelLabel = 'envio de notificação';

    protected static ?string $pluralModelLabel = 'Auditoria de Envios';

    protected static ?string $slug = 'gestao-documental-externa/notification-outboxes';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return NotificationOutboxForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return NotificationOutboxInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationOutboxesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('nimbus.notification-outboxes.view') ?? false;
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
        return parent::getEloquentQuery()
            ->latest('created_at');
    }

    public static function getCancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->icon('heroicon-o-stop-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (NotificationOutbox $record): bool => $record->canBeCancelled() && (auth()->user()?->can('nimbus.notification-settings.update') ?? false))
            ->action(function (NotificationOutbox $record): void {
                $record->update([
                    'status' => 'CANCELLED',
                ]);

                Notification::make()
                    ->title('Envio cancelado com sucesso.')
                    ->success()
                    ->send();
            });
    }

    public static function getReprocessAction(): Action
    {
        return Action::make('reprocess')
            ->label('Reprocessar')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (NotificationOutbox $record): bool => $record->canBeReprocessed() && (auth()->user()?->can('nimbus.notification-settings.update') ?? false))
            ->action(function (NotificationOutbox $record): void {
                $record->update([
                    'status' => 'PENDING',
                    'attempts' => 0,
                    'next_attempt_at' => null,
                    'last_error' => null,
                ]);

                Notification::make()
                    ->title('Envio marcado para reprocessamento.')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationOutboxes::route('/'),
            'view' => ViewNotificationOutbox::route('/{record}'),
        ];
    }
}
