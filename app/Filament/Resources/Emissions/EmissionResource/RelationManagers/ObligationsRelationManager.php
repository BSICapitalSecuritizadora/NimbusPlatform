<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Filament\Resources\Emissions\Schemas\ObligationFormFields;
use App\Models\Obligation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ObligationsRelationManager extends RelationManager
{
    protected static string $relationship = 'obligations';

    protected static ?string $title = 'Obrigações';

    protected static ?string $modelLabel = 'Obrigação';

    protected static ?string $pluralModelLabel = 'Obrigações';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('obligations.view') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema(ObligationFormFields::make('obligation'))->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->description('Obrigações consolidadas desta emissão.')
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('obligation_category')
                    ->label('Categoria')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('responsibleUser.name')
                    ->label('Responsável')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('responsible_area')
                    ->label('Área')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Obligation::PRIORITY_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
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
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Obligation::STATUS_OPTIONS),
                SelectFilter::make('priority')
                    ->label('Prioridade')
                    ->options(Obligation::PRIORITY_OPTIONS),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Cadastrar obrigação')
                    ->authorize(fn (): bool => $this->canManage()),
            ])
            ->actions([
                EditAction::make()
                    ->authorize(fn (): bool => $this->canManage()),
                DeleteAction::make()
                    ->authorize(fn (): bool => auth()->user()?->can('obligations.delete') ?? false),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('obligations.delete') ?? false),
                ]),
            ])
            ->emptyStateHeading('Nenhuma obrigação consolidada')
            ->emptyStateDescription('Aprove sugestões na aba "Obrigações Sugeridas" ou cadastre manualmente.');
    }

    protected function canManage(): bool
    {
        return auth()->user()?->can('obligations.update') ?? false;
    }
}
