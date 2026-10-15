<?php

namespace App\Filament\Resources\BankAccounts\RelationManagers;

use App\Filament\Resources\BankApplications\BankApplicationResource;
use App\Filament\Resources\BankApplications\Schemas\BankApplicationForm;
use App\Models\BankApplication;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BankAccountApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankApplications';

    protected static ?string $title = 'Aplicações Bancárias';

    protected static ?string $modelLabel = 'Aplicação bancária';

    protected static ?string $pluralModelLabel = 'Aplicações bancárias';

    public function form(Schema $schema): Schema
    {
        $accountId = $this->getOwnerRecord()->getKey();

        return $schema->components(
            BankApplicationForm::fieldsForAccount(is_numeric($accountId) ? (int) $accountId : null)
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('codigo_produto')
            ->modifyQueryUsing(fn ($query) => $query->withSharedEmissionCount()->withCount('funds'))
            ->columns([
                TextColumn::make('codigo_produto')
                    ->label('Código do produto')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nome_produto')
                    ->label('Produto')
                    ->placeholder('—')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (BankApplication $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (BankApplication $record): string => $record->status === BankApplication::STATUS_ACTIVE ? 'success' : 'gray'),

                TextColumn::make('funds_count')
                    ->label('Fundos')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray'),

                TextColumn::make('shared')
                    ->label('Uso')
                    ->state(fn (BankApplication $record): string => $record->distinctEmissionCount() > 1 ? 'Compartilhado' : '—')
                    ->badge(fn (BankApplication $record): bool => $record->distinctEmissionCount() > 1)
                    ->color('warning')
                    ->tooltip(fn (BankApplication $record): ?string => $record->distinctEmissionCount() > 1
                        ? 'Esta aplicação física atende a fundos de mais de uma operação.'
                        : null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nova aplicação')
                    ->icon('heroicon-m-plus')
                    ->visible(fn (): bool => auth()->user()?->can('funds.create') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->visible(fn (): bool => auth()->user()?->can('funds.update') ?? false),
                DeleteAction::make()
                    ->label('Excluir')
                    ->visible(fn (BankApplication $record): bool => BankApplicationResource::canDelete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('funds.delete') ?? false),
                ]),
            ])
            ->emptyStateHeading('Nenhuma aplicação cadastrada nesta conta')
            ->emptyStateDescription('Cadastre os produtos bancários (código do produto) existentes nesta conta física.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('funds.view') ?? false;
    }
}
