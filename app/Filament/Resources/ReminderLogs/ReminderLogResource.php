<?php

namespace App\Filament\Resources\ReminderLogs;

use App\Enums\AccessPermission;
use App\Models\ReminderLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ReminderLogResource extends Resource
{
    protected static ?string $model = ReminderLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $navigationParentItem = 'Auditoria';

    protected static ?int $navigationSort = 22;

    protected static ?string $modelLabel = 'Auditoria de Lembrete';

    protected static ?string $pluralModelLabel = 'Auditoria de Lembretes';

    protected static ?string $navigationLabel = 'Auditoria de Lembretes';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sent_at')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('channel')
                    ->label('Canal')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'email' => 'info',
                        'database' => 'primary',
                        default => 'gray',
                    }),

                TextColumn::make('recipient_email')
                    ->label('Destinatário')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('severity')
                    ->label('Criticidade')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'info' => 'info',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        'critical' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();

                        return strlen($state) > 30 ? $state : null;
                    }),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label('Canal')
                    ->options([
                        'email' => 'E-mail',
                        'database' => 'Painel (Database)',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'sent' => 'Enviado',
                        'failed' => 'Falhou',
                    ]),

                SelectFilter::make('severity')
                    ->label('Criticidade')
                    ->options([
                        'info' => 'Informativo',
                        'warning' => 'Atenção',
                        'danger' => 'Crítico',
                    ]),

                Filter::make('sent_at')
                    ->label('Enviado em')
                    ->form([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('sent_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('sent_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('sent_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageReminderLogs::route('/'),
        ];
    }

    /**
     * A auditoria de lembretes expõe destinatário, motivo e criticidade de cada
     * envio. Sem policy registrada o Filament liberava o Resource por padrão, e
     * esconder o item de navegação não fecha a URL direta.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(AccessPermission::ReminderLogsView->value) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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
}
