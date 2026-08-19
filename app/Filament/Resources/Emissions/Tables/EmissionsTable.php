<?php

namespace App\Filament\Resources\Emissions\Tables;

use App\Filament\Resources\Emissions\EmissionResource;
use App\Models\Emission;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Emission $record): ?string => auth()->user()->can('emissions.view') ? EmissionResource::getUrl('view', ['record' => $record]) : null)
            ->searchPlaceholder('Buscar por operação, IF, ISIN ou emissor...')
            ->defaultSort('name')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhuma emissão encontrada')
            ->emptyStateDescription('Não há emissões cadastradas nesta etapa ou que correspondam aos filtros aplicados.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->columns([
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ?? '—')
                    ->color(fn (?string $state): string|array => match ($state) {
                        'CRI' => Color::hex('#D4AF37'),
                        'CRA' => Color::hex('#0D9488'),
                        'CR' => Color::hex('#4F46E5'),
                        default => 'gray',
                    })
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Operação')
                    ->description(fn (Emission $record): ?string => $record->issuer ? "Emissor: {$record->issuer}" : null)
                    ->weight('semibold')
                    ->wrap()
                    ->searchable()
                    ->sortable()
                    ->tooltip(fn (Emission $record): ?string => $record->name),

                TextColumn::make('if_code')
                    ->label('Identificação')
                    ->state(fn (Emission $record): ?string => $record->if_code ? "IF {$record->if_code}" : ($record->isin_code ? "ISIN {$record->isin_code}" : '—'))
                    ->description(fn (Emission $record): ?string => ($record->if_code && $record->isin_code) ? "ISIN {$record->isin_code}" : null)
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('if_code', 'like', "%{$search}%")
                        ->orWhere('isin_code', 'like', "%{$search}%")
                        ->orWhere('bsi_code', 'like', "%{$search}%"))
                    ->sortable('if_code')
                    ->tooltip(fn (Emission $record): ?string => collect([
                        $record->if_code ? "Código IF: {$record->if_code}" : null,
                        $record->isin_code ? "Código ISIN: {$record->isin_code}" : null,
                        $record->bsi_code ? "Código BSI: {$record->bsi_code}" : null,
                    ])->filter()->implode(' | ')),

                TextColumn::make('series')
                    ->label('Série')
                    ->formatStateUsing(fn (?string $state, Emission $record): string => filled($state) ? (string) $state : (filled($record->emission_number) ? (string) $record->emission_number : '—'))
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Emission::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'gray',
                        'active' => 'success',
                        'closed' => 'info',
                        'default' => 'danger',
                        default => 'gray',
                    })
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('maturity_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->description(fn (Emission $record): ?string => $record->maturity_date?->diffForHumans())
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('issuer')
                    ->label('Emissor')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('isin_code')
                    ->label('Código ISIN')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('is_public')
                    ->label('Divulgação Pública')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Pública' : 'Restrita')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('issue_date')
                    ->label('Data de Emissão')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('issued_volume')
                    ->label('Volume Total Emitido')
                    ->formatStateUsing(fn ($state): string => $state !== null ? 'R$ '.number_format((float) $state, 2, ',', '.') : '—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Small)
            ->filtersFormMaxHeight('420px')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo de Título')
                    ->options(Emission::TYPE_OPTIONS),

                SelectFilter::make('status')
                    ->label('Status da Operação')
                    ->options(Emission::STATUS_OPTIONS),

                TernaryFilter::make('is_public')
                    ->label('Divulgação')
                    ->placeholder('Todas as operações')
                    ->trueLabel('Apenas Públicas')
                    ->falseLabel('Apenas Restritas'),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Acessar Dossiê')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (): bool => auth()->user()->can('emissions.view')),

                    EditAction::make()
                        ->label('Editar Operação')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (): bool => auth()->user()->can('emissions.update')),

                    DeleteAction::make()
                        ->label('Excluir Operação')
                        ->icon('heroicon-o-trash')
                        ->visible(fn (): bool => auth()->user()->can('emissions.delete')),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da operação'),
            ]);
    }
}
