<?php

namespace App\Filament\Resources\BusinessHolidays\Tables;

use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Fonte')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'anbima' => 'ANBIMA',
                        default => (string) ($state ?? '—'),
                    })
                    ->color(fn (?string $state): string => $state === 'anbima' ? 'info' : 'gray'),
                TextColumn::make('source_file')
                    ->label('Arquivo')
                    ->placeholder('—')
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
