<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ManageActivities;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static \UnitEnum|string|null $navigationGroup = 'Auditoria';

    protected static ?string $navigationLabel = 'Logs do Sistema';

    protected static ?string $modelLabel = 'Log';

    protected static ?string $pluralModelLabel = 'Logs';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\TextInput::make('log_name')
                    ->label('Log Name'),
                \Filament\Forms\Components\TextInput::make('description')
                    ->label('Descrição'),
                \Filament\Forms\Components\TextInput::make('subject_type')
                    ->label('Entidade Afetada'),
                \Filament\Forms\Components\Textarea::make('properties')
                    ->label('Valores Alterados')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT))
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Infolists\Components\TextEntry::make('log_name')->label('Log'),
                \Filament\Infolists\Components\TextEntry::make('description')->label('Ação'),
                \Filament\Infolists\Components\TextEntry::make('causer.name')->label('Autor (Causer)'),
                \Filament\Infolists\Components\TextEntry::make('subject_type')->label('Modificou'),
                \Filament\Infolists\Components\TextEntry::make('created_at')->label('Data')->dateTime(),
                \Filament\Infolists\Components\TextEntry::make('properties')
                    ->label('Detalhes (JSON)')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->fontFamily('mono')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('log_name')
                    ->label('Log')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                \Filament\Tables\Columns\TextColumn::make('description')
                    ->label('Ação')
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('subject_type')
                    ->label('Modificou (Modelo)')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('causer.name')
                    ->label('Usuário Autor')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->label('Data e Hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')->label('De'),
                        \Filament\Forms\Components\DatePicker::make('created_until')->label('Até'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActivities::route('/'),
        ];
    }
}
