<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PuEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'puEvents';

    protected static ?string $title = 'Eventos de PU';

    protected static ?string $modelLabel = 'Evento de PU';

    protected static ?string $pluralModelLabel = 'Eventos de PU';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('event_type')
                    ->label('Tipo de evento')
                    ->options([
                        PuEventType::InterestPayment->value => 'Pagamento de juros',
                        PuEventType::Amortization->value => 'Amortização',
                    ])
                    ->live()
                    ->required(),
                DatePicker::make('original_date')
                    ->label('Data original'),
                DatePicker::make('effective_date')
                    ->label('Data efetiva')
                    ->required(),
                Select::make('amortization_type')
                    ->label('Tipo de amortização')
                    ->options([
                        PuAmortizationType::None->value => 'Nenhuma',
                        PuAmortizationType::Residual->value => 'Residual',
                        PuAmortizationType::Percentage->value => 'Percentual',
                        PuAmortizationType::UnitValue->value => 'Valor unitário',
                    ])
                    ->default(PuAmortizationType::None->value)
                    ->required(),
                TextInput::make('amortization_value')
                    ->label('Valor da amortização')
                    ->inputMode('decimal')
                    ->visible(fn (Get $get): bool => $get('event_type') === PuEventType::Amortization->value)
                    ->required(fn (Get $get): bool => $get('event_type') === PuEventType::Amortization->value),
                TextInput::make('sequence')
                    ->label('Sequência')
                    ->numeric()
                    ->default(1)
                    ->required(),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('effective_date')
            ->columns([
                TextColumn::make('effective_date')
                    ->label('Data efetiva')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Evento')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PuEventType::InterestPayment->value => 'Pagamento de juros',
                        PuEventType::Amortization->value => 'Amortização',
                        default => $state,
                    }),
                TextColumn::make('amortization_type')
                    ->label('Tipo de amortização')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PuAmortizationType::None->value => 'Nenhuma',
                        PuAmortizationType::Residual->value => 'Residual',
                        PuAmortizationType::Percentage->value => 'Percentual',
                        PuAmortizationType::UnitValue->value => 'Valor unitário',
                        default => $state,
                    }),
                TextColumn::make('amortization_value')
                    ->label('Valor')
                    ->numeric(8, ',', '.'),
                TextColumn::make('sequence')
                    ->label('Seq.')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->wrap(),
            ])
            ->defaultSort('effective_date')
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Novo Evento PU'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nenhum evento de PU cadastrado');
    }
}
