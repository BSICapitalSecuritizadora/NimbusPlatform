<?php

namespace App\Filament\Widgets\Obligations;

use App\Models\Obligation;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ObligationOperationalTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Obrigações que Requerem Atenção';

    /**
     * @var array<string, string>
     */
    protected const URGENCY_LABELS = [
        'critical' => 'Crítica',
        'high' => 'Alta',
        'medium' => 'Média',
        'low' => 'Baixa',
        'undefined' => 'Indefinida',
    ];

    public function table(Table $table): Table
    {
        $data = app(ObligationDashboardData::class);

        return $table
            ->query($data->operationalQuery())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['emission', 'responsibleUser']))
            ->recordUrl(fn (Obligation $record): string => route('filament.admin.resources.emissions.edit', ['record' => $record->emission_id]))
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('title')
                    ->label('Obrigação')
                    ->searchable()
                    ->wrap()
                    ->limit(60),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Obligation::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'em_dia', 'concluida' => 'success',
                        'a_vencer' => 'info',
                        'vencida' => 'danger',
                        'em_analise' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->placeholder('Sem data')
                    ->sortable(),
                TextColumn::make('urgency')
                    ->label('Urgência')
                    ->badge()
                    ->state(fn (Obligation $record): string => $data->urgencyFor($record))
                    ->formatStateUsing(fn (string $state): string => self::URGENCY_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('responsibleUser.name')
                    ->label('Responsável')
                    ->placeholder('Sem responsável')
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Origem')
                    ->badge()
                    ->state(fn (Obligation $record): string => $record->extracted_obligation_id !== null ? 'Termo (IA)' : 'Manual')
                    ->color(fn (string $state): string => $state === 'Manual' ? 'gray' : 'info')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('emission_id')
                    ->label('Emissão')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Obligation::STATUS_OPTIONS),
                SelectFilter::make('urgency')
                    ->label('Urgência')
                    ->options(self::URGENCY_LABELS)
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        $today = now()->startOfDay();

                        return match ($value) {
                            'critical' => $query->whereNotNull('due_date')->whereDate('due_date', '<', $today),
                            'high' => $query->whereNotNull('due_date')->whereBetween('due_date', [$today, $today->copy()->addDays(3)]),
                            'medium' => $query->whereNotNull('due_date')->whereBetween('due_date', [$today->copy()->addDays(4), $today->copy()->addDays(7)]),
                            'low' => $query->whereNotNull('due_date')->whereDate('due_date', '>', $today->copy()->addDays(7)),
                            'undefined' => $query->whereNull('due_date'),
                            default => $query,
                        };
                    }),
            ])
            ->emptyStateHeading('Nenhuma obrigação pendente')
            ->emptyStateDescription('Não há obrigações em aberto que exijam atenção no momento.');
    }
}
