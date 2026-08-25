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
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
                TextEntry::make('document.title')
                    ->label('Documento')
                    ->weight(FontWeight::SemiBold),
                TextEntry::make('source')
                    ->label('Origem')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'admin' => 'Painel Admin',
                        'portal' => 'Portal do Investidor',
                        default => Str::headline((string) $state),
                    })
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'admin' => 'warning',
                        'portal' => 'success',
                        default => 'gray',
                    }),
                TextEntry::make('investor.name')
                    ->label('Investidor')
                    ->placeholder('—'),
                TextEntry::make('adminUser.name')
                    ->label('Usuário Admin')
                    ->placeholder('—'),
                TextEntry::make('downloaded_at')
                    ->label('Data e Hora do Download')
                    ->dateTime('d/m/Y · H:i:s')
                    ->fontFamily(FontFamily::Mono),
                TextEntry::make('ip')
                    ->label('Endereço IP')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                TextEntry::make('user_agent')
                    ->label('Navegador / Dispositivo')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('referer')
                    ->label('Origem do Acesso (Referer)')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Buscar por documento ou investidor...')
            ->defaultSort('downloaded_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->recordUrl(null)
            ->columns([
                TextColumn::make('document.title')
                    ->label('Documento')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Origem')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'admin' => 'Admin',
                        'portal' => 'Portal',
                        default => Str::headline((string) $state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'warning',
                        'portal' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
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
                    ->dateTime('d/m/Y · H:i:s')
                    ->fontFamily(FontFamily::Mono)
                    ->tooltip(fn (DocumentDownload $record): ?string => $record->downloaded_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('ip')
                    ->label('Endereço IP')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_agent')
                    ->label('Navegador / Dispositivo')
                    ->searchable()
                    ->limit(35)
                    ->tooltip(fn (DocumentDownload $record): ?string => $record->user_agent)
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
                    ->label('Data do Download')
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
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->tooltip('Visualizar registro de download')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->iconButton(),
            ])
            ->toolbarActions([
                ExportAction::make()
                    ->label('Exportar registros')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->exporter(DocumentDownloadExporter::class),
            ])
            ->emptyStateHeading('Nenhum download registrado')
            ->emptyStateDescription('Os downloads realizados aparecerão aqui para acompanhamento e auditoria.')
            ->emptyStateIcon('heroicon-o-arrow-down-tray');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentDownloads::route('/'),
        ];
    }
}
