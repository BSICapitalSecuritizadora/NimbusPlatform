<?php

namespace App\Filament\Resources\DocumentDownloads;

use App\Filament\Resources\DocumentDownloads\Pages\ManageDocumentDownloads;
use App\Models\DocumentDownload;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DocumentDownloadResource extends Resource
{
    protected static ?string $model = DocumentDownload::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static \UnitEnum|string|null $navigationGroup = 'Auditoria';

    protected static ?string $navigationLabel = 'Downloads do Portal';

    protected static ?string $modelLabel = 'Download';

    protected static ?string $pluralModelLabel = 'Downloads';

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
                \Filament\Forms\Components\Select::make('document_id')
                    ->relationship('document', 'title')
                    ->label('Documento'),
                \Filament\Forms\Components\Select::make('investor_id')
                    ->relationship('investor', 'name')
                    ->label('Investidor'),
                \Filament\Forms\Components\TextInput::make('ip')
                    ->label('Endereço IP'),
                \Filament\Forms\Components\TextInput::make('user_agent')
                    ->label('User Agent'),
                \Filament\Forms\Components\TextInput::make('referer')
                    ->label('Referer'),
                \Filament\Forms\Components\DateTimePicker::make('downloaded_at')
                    ->label('Data do Download'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // ... (Keep existing layout or add detailed view)
        return $schema
            ->components([
                 \Filament\Infolists\Components\TextEntry::make('document.title')->label('Documento'),
                 \Filament\Infolists\Components\TextEntry::make('investor.name')->label('Investidor'),
                 \Filament\Infolists\Components\TextEntry::make('ip')->label('IP'),
                 \Filament\Infolists\Components\TextEntry::make('user_agent')->label('User Agent'),
                 \Filament\Infolists\Components\TextEntry::make('downloaded_at')->label('Data do Download')->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('document.title')
                    ->label('Documento')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('investor.name')
                    ->label('Investidor')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('downloaded_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                \Filament\Tables\Columns\TextColumn::make('user_agent')
                    ->label('User Agent')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('investor_id')
                    ->label('Investidor')
                    ->relationship('investor', 'name')
                    ->searchable()
                    ->preload(),
                \Filament\Tables\Filters\SelectFilter::make('document_id')
                    ->label('Documento')
                    ->relationship('document', 'title')
                    ->searchable()
                    ->preload(),
                \Filament\Tables\Filters\Filter::make('downloaded_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')->label('De'),
                        \Filament\Forms\Components\DatePicker::make('created_until')->label('Até'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('downloaded_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('downloaded_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('downloaded_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                // no bulk actions
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentDownloads::route('/'),
        ];
    }
}
