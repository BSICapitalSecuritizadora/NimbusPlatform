<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Models\Emission;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
            ->searchPlaceholder('Buscar por data ou descrição...')
            ->columns([
                TextColumn::make('effective_date')
                    ->label('Data Efetiva')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('event_type')
                    ->label('Evento')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PuEventType::InterestPayment->value => 'Pagamento de Juros',
                        PuEventType::Amortization->value => 'Amortização',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        PuEventType::InterestPayment->value => 'info',
                        PuEventType::Amortization->value => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('amortization_type')
                    ->label('Tipo de Amortização')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        PuAmortizationType::Residual->value => 'Residual',
                        PuAmortizationType::Percentage->value => 'Percentual',
                        PuAmortizationType::UnitValue->value => 'Valor Unitário',
                        PuAmortizationType::None->value, null, '' => '—',
                        default => $state,
                    }),
                TextColumn::make('amortization_value')
                    ->label('Valor')
                    ->numeric(8, ',', '.')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('sequence')
                    ->label('Seq.')
                    ->tooltip('Sequência do evento na data')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->placeholder('—')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->searchable(),
            ])
            ->defaultSort('effective_date')
            ->filters([
                SelectFilter::make('event_type')
                    ->label('Tipo de Evento')
                    ->options([
                        PuEventType::InterestPayment->value => 'Pagamento de Juros',
                        PuEventType::Amortization->value => 'Amortização',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Novo Evento PU')
                    ->icon('heroicon-m-plus')
                    ->tooltip('Cadastrar evento de PU manualmente')
                    ->visible(fn (): bool => auth()->user()?->can('pu.parameters.configure') ?? false)
                    ->after(fn (): null => $this->logEventChange('created')),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('pu.parameters.configure') ?? false)
                        ->after(fn (): null => $this->logEventChange('updated')),
                    DeleteAction::make()
                        ->visible(fn (): bool => auth()->user()?->can('pu.parameters.configure') ?? false)
                        ->after(fn (): null => $this->logEventChange('deleted')),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(fn (): null => $this->logEventChange('deleted')),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('Nenhum evento de PU cadastrado')
            ->emptyStateDescription('Registre eventos de juros, amortizações ou outras movimentações financeiras para compor o histórico e a curva de PU desta emissão.')
            ->emptyStateActions([
                CreateAction::make('empty_create')
                    ->label('Cadastrar evento')
                    ->icon('heroicon-m-plus')
                    ->color('primary')
                    ->visible(fn (): bool => auth()->user()?->can('pu.parameters.configure') ?? false)
                    ->after(fn (): null => $this->logEventChange('created')),
            ]);
    }

    private function logEventChange(string $action): null
    {
        $emission = $this->getOwnerRecord();

        if ($emission instanceof Emission) {
            app(PuAuditLogService::class)->logEventChange($emission, $action, auth()->id());
        }

        return null;
    }
}
