<?php

namespace App\Filament\Resources\BankApplications\RelationManagers;

use App\Filament\Resources\Funds\FundResource;
use App\Models\Fund;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BankApplicationFundsRelationManager extends RelationManager
{
    protected static string $relationship = 'funds';

    protected static ?string $title = 'Fundos Vinculados';

    protected static ?string $modelLabel = 'Fundo vinculado';

    protected static ?string $pluralModelLabel = 'Fundos vinculados';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account')
            ->recordUrl(fn (Fund $record): ?string => FundResource::canEdit($record)
                ? FundResource::getUrl('edit', ['record' => $record])
                : null)
            ->modifyQueryUsing(fn ($query) => $query->with(['emission', 'fundName', 'fundApplication']))
            ->columns([
                TextColumn::make('emission.name')
                    ->label('Operação')
                    ->weight('semibold')
                    ->placeholder('—')
                    ->wrap()
                    ->visible(fn (): bool => auth()->user()?->can('emissions.view') ?? true),

                TextColumn::make('fundName.name')
                    ->label('Nome do fundo')
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('fundApplication.name')
                    ->label('Classificação (aplicação)')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('balance')
                    ->label('Saldo do fundo')
                    ->money('BRL')
                    ->alignment('end')
                    ->fontFamily('mono')
                    ->placeholder('R$ 0,00')
                    ->tooltip('Saldo atribuído a este fundo. Não representa o saldo total da aplicação física.'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhum fundo vinculado a esta aplicação')
            ->emptyStateDescription('Vincule fundos a esta aplicação pelo cadastro do fundo.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('funds.view') ?? false;
    }
}
