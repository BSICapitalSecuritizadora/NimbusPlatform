<?php

namespace App\Filament\Resources\ImportRuns\Tables;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The chronological trail of confirmed reconciliations.
 *
 * Read-only by construction: the only row action is opening the execution, and
 * there is no toolbar or bulk action at all. Nothing here recomputes a counter
 * -- every number is what that run recorded on the day it ran.
 */
class ImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ImportRun $record): ?string => ImportRunResource::canView($record)
                ? ImportRunResource::getUrl('view', ['record' => $record])
                : null)
            ->searchPlaceholder('Buscar por arquivo, tipo, usuário ou checksum...')
            /**
             * Two runs of the same monthly file land in the same second often
             * enough that `created_at` alone leaves the order to the database.
             * The id breaks the tie so the newest is always on top.
             */
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'))
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('id')
                    ->label('Nº')
                    ->prefix('#')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y · H:i:s')
                    ->fontFamily(FontFamily::Mono)
                    ->tooltip(fn (ImportRun $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (ImportRun $record): string => $record->typeLabel())
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('file_name')
                    ->label('Arquivo')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->limit(45)
                    ->tooltip(fn (ImportRun $record): ?string => $record->file_name)
                    ->wrap(),

                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable()
                    ->sortable()
                    ->state(fn (ImportRun $record): string => $record->userName()),

                TextColumn::make('records_analyzed')
                    ->label('Analisados')
                    ->numeric()
                    ->fontFamily(FontFamily::Mono)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('records_created')
                    ->label('Novos')
                    ->numeric()
                    ->fontFamily(FontFamily::Mono)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('records_updated')
                    ->label('Atualizados')
                    ->numeric()
                    ->fontFamily(FontFamily::Mono)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('records_unchanged')
                    ->label('Sem alteração')
                    ->numeric()
                    ->fontFamily(FontFamily::Mono)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('records_critical')
                    ->label('Críticos')
                    ->numeric()
                    ->fontFamily(FontFamily::Mono)
                    ->alignEnd()
                    ->color(fn (ImportRun $record): string => $record->records_critical > 0 ? 'danger' : 'gray')
                    ->weight(fn (ImportRun $record): ?FontWeight => $record->records_critical > 0 ? FontWeight::Bold : null)
                    ->tooltip(fn (ImportRun $record): ?string => $record->records_critical > 0 ? "{$record->records_critical} registros críticos identificados" : null)
                    ->sortable(),

                TextColumn::make('result')
                    ->label('Resultado')
                    ->badge()
                    ->state(fn (ImportRun $record): string => $record->resultLabel())
                    ->color(fn (ImportRun $record): string => $record->resultColor()),

                TextColumn::make('checksum')
                    ->label('Checksum')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->limit(12)
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(ImportRun::typeOptions()),

                SelectFilter::make('user_id')
                    ->label('Usuário')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('created_at')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),

                /**
                 * The outcome is derived from the counters the run persisted --
                 * there is no status column -- so the filter reproduces the very
                 * same rule instead of reading a stored value.
                 */
                SelectFilter::make('result')
                    ->label('Resultado')
                    ->options(ImportRun::resultOptions())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        ImportRun::RESULT_CRITICAL => $query->where('records_critical', '>', 0),
                        ImportRun::RESULT_COMPLETED => $query
                            ->where('records_critical', 0)
                            ->where(fn (Builder $query): Builder => $query
                                ->where('records_created', '>', 0)
                                ->orWhere('records_updated', '>', 0)),
                        ImportRun::RESULT_UNCHANGED => $query
                            ->where('records_critical', 0)
                            ->where('records_created', 0)
                            ->where('records_updated', 0),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->tooltip('Visualizar importação')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->iconButton(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma importação registrada')
            ->emptyStateDescription('As próximas importações e conciliações confirmadas de contratos e parcelas serão exibidas aqui.')
            ->emptyStateIcon('heroicon-o-arrow-up-tray');
    }
}
