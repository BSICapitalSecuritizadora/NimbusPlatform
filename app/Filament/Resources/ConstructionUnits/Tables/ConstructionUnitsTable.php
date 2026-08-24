<?php

namespace App\Filament\Resources\ConstructionUnits\Tables;

use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConstructionUnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (ConstructionUnit $record): ?string => ConstructionUnitResource::canView($record)
                ? ConstructionUnitResource::getUrl('view', ['record' => $record])
                : null)
            ->searchPlaceholder('Buscar por unidade, bloco, empreendimento ou emissão...')
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('construction.emission.name')
                    ->label('Emissão')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('construction.development_name')
                    ->label('Empreendimento')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('block')
                    ->label('Bloco')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('unit')
                    ->label('Unidade')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('created_at')
                    ->label('Cadastrada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filtersFormWidth(Width::Small)
            ->filters([
                SelectFilter::make('emission')
                    ->label('Emissão')
                    ->options(fn (): array => Emission::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $emissionId): Builder => $query->forEmission($emissionId),
                    )),

                SelectFilter::make('construction_id')
                    ->label('Empreendimento')
                    ->options(fn (Table $table): array => Construction::query()
                        ->when(
                            $table->getLivewire()->getTableFilterState('emission')['value'] ?? null,
                            fn (Builder $query, mixed $emissionId): Builder => $query->where('emission_id', $emissionId),
                        )
                        ->orderBy('development_name')
                        ->pluck('development_name', 'id')
                        ->all())
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Visualizar')
                        ->icon('heroicon-o-eye')
                        ->color('primary')
                        ->visible(fn (ConstructionUnit $record): bool => ConstructionUnitResource::canView($record)),

                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (ConstructionUnit $record): bool => ConstructionUnitResource::canEdit($record)),

                    DeleteAction::make()
                        ->label('Excluir')
                        ->modalHeading('Excluir unidade')
                        ->visible(fn (ConstructionUnit $record): bool => ConstructionUnitResource::canDelete($record)),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Ações da unidade'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nenhuma unidade cadastrada');
    }
}
