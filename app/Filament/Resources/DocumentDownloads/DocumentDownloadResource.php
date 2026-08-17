<?php

namespace App\Filament\Resources\DocumentDownloads;

use App\Filament\Exports\DocumentDownloadExporter;
use App\Filament\Resources\DocumentDownloads\Pages\ManageDocumentDownloads;
use App\Models\DocumentDownload;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentDownloadResource extends Resource
{
    protected static ?string $model = DocumentDownload::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static \UnitEnum|string|null $navigationGroup = 'Administração';

    protected static ?string $navigationParentItem = 'Auditoria';

    protected static ?int $navigationSort = 23;

    protected static ?string $navigationLabel = 'Histórico de Downloads';

    protected static ?string $modelLabel = 'registro de download';

    protected static ?string $pluralModelLabel = 'Registros de Downloads';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit.document-downloads.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('document_id')
                    ->relationship('document', 'title')
                    ->label('Documento'),
                Select::make('investor_id')
                    ->relationship('investor', 'name')
                    ->label('Investidor'),
                TextInput::make('ip')
                    ->label('Endereço IP'),
                TextInput::make('user_agent')
                    ->label('Navegador / Dispositivo'),
                TextInput::make('referer')
                    ->label('Origem do Acesso (Referer)'),
                DateTimePicker::make('downloaded_at')
                    ->label('Data do Download'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('document.title')->label('Documento'),
                TextEntry::make('source')->label('Origem')
                    ->formatStateUsing(fn (string $state): string => $state === 'admin' ? 'Painel Admin' : 'Portal do Investidor')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'admin' ? 'warning' : 'success'),
                TextEntry::make('investor.name')->label('Investidor')->placeholder('—'),
                TextEntry::make('adminUser.name')->label('Usuário Admin')->placeholder('—'),
                TextEntry::make('ip')->label('Endereço IP'),
                TextEntry::make('user_agent')->label('Navegador / Dispositivo'),
                TextEntry::make('downloaded_at')->label('Data do Download')->dateTime('d/m/Y H:i:s'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document.title')
                    ->label('Documento')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Origem')
                    ->formatStateUsing(fn (string $state): string => $state === 'admin' ? 'Admin' : 'Portal')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'admin' ? 'warning' : 'success'),
                TextColumn::make('investor.name')
                    ->label('Investidor')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('adminUser.name')
                    ->label('Usuário Admin')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('downloaded_at')
                    ->label('Data e Hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('ip')
                    ->label('Endereço IP')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_agent')
                    ->label('Navegador / Dispositivo')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Origem')
                    ->options(['portal' => 'Portal do Investidor', 'admin' => 'Painel Admin']),
                SelectFilter::make('investor_id')
                    ->label('Investidor')
                    ->relationship('investor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('document_id')
                    ->label('Documento')
                    ->relationship('document', 'title')
                    ->searchable()
                    ->preload(),
                Filter::make('downloaded_at')
                    ->form([
                        DatePicker::make('created_from')->label('Data Inicial'),
                        DatePicker::make('created_until')->label('Data Final'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('downloaded_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('downloaded_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('downloaded_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                ExportAction::make()
                    ->label('Exportar')
                    ->exporter(DocumentDownloadExporter::class),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentDownloads::route('/'),
        ];
    }
}
