<?php

namespace App\Filament\Resources\BusinessHolidays\Tables;

use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BusinessHolidaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('holiday_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Feriado')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('calendar_code')
                    ->label('Calendário')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => BusinessCalendarRegistry::label($state))
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Fonte')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'anbima' => 'ANBIMA',
                        default => (string) ($state ?? '—'),
                    })
                    ->color(fn (?string $state): string => $state === 'anbima' ? 'info' : 'gray'),
                TextColumn::make('data_origin')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'imported' => 'Importado',
                        'inferred' => 'Inferido',
                        'manual_override' => 'Override manual',
                        default => 'Legado / não classificado',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'imported' => 'info',
                        'inferred' => 'gray',
                        'manual_override' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('source_is_official')
                    ->label('Oficial')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => match ($state) {
                        true => 'Sim',
                        false => 'Não',
                        null => 'Não classificado',
                    })
                    ->color(fn (?bool $state): string => $state === true ? 'success' : 'gray'),
                TextColumn::make('source_file')
                    ->label('Arquivo')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('source_document')
                    ->label('Documento')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_revision')
                    ->label('Revisão da fonte')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('removed_detected_at')
                    ->label('Remoção detectada')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Não')
                    ->color('danger')
                    ->toggleable(),
                TextColumn::make('imported_at')
                    ->label('Importado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('importedBy.name')
                    ->label('Importado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('calendar_code')
                    ->label('Calendário')
                    ->options(fn (): array => app(BusinessCalendarCatalogService::class)->administrativeOptions()),
                SelectFilter::make('year')
                    ->label('Ano')
                    ->options(self::yearOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        return filled($data['value'] ?? null)
                            ? $query->whereYear('holiday_date', (int) $data['value'])
                            : $query;
                    }),
                SelectFilter::make('source')
                    ->label('Fonte')
                    ->options(['anbima' => 'ANBIMA']),
                TernaryFilter::make('removed_detected_at')
                    ->label('Remoção detectada na fonte')
                    ->nullable(),
            ])
            ->defaultSort('holiday_date', 'desc')
            ->recordActions([]);
    }

    /**
     * @return array<int, string>
     */
    private static function yearOptions(): array
    {
        return BusinessHoliday::query()
            ->orderByDesc('holiday_date')
            ->pluck('holiday_date')
            ->map(fn ($date): int => CarbonImmutable::instance($date)->year)
            ->unique()
            ->values()
            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
            ->all();
    }
}
