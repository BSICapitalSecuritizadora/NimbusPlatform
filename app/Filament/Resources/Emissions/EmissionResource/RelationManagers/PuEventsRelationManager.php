<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuBaselineCandidateFactory;
use App\Domain\PuCalculator\Services\PuContractualEventScheduleService;
use App\Models\Emission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
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
use Filament\Notifications\Notification;
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
                Action::make('generateContractualSchedule')
                    ->label('Gerar eventos do cronograma contratual')
                    ->icon('heroicon-m-calendar-days')
                    ->color('gray')
                    ->visible(fn (): bool => ! $this->isReadOnly()
                        && (auth()->user()?->can('pu.parameters.configure') ?? false)
                        && app(PuBaselineCandidateFactory::class)->supports($this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-calendar-days')
                    ->modalHeading('Gerar eventos do cronograma contratual')
                    ->modalDescription(fn (): string => $this->contractualScheduleSummary())
                    ->modalSubmitActionLabel('Gerar eventos')
                    ->action(fn (): null => $this->generateContractualSchedule()),
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

    /**
     * Prévia do que a geração vai fazer, calculada só quando o modal abre.
     */
    private function contractualScheduleSummary(): string
    {
        $plan = app(PuContractualEventScheduleService::class)->plan($this->getOwnerRecord());

        if (! $plan['available']) {
            return (string) $plan['reason'];
        }

        $lines = [sprintf(
            'O contrato prevê %d evento(s) até %s; %d já estão cadastrados.',
            count($plan['contractual_events']),
            $this->brazilianDate($plan['maturity_date']),
            count($plan['present_events']),
        )];
        $lines[] = $plan['creatable_events'] === []
            ? 'Nenhum evento novo a criar.'
            : sprintf(
                'Serão criados %d evento(s), de %s a %s, com a data efetiva no dia útil seguinte do calendário da curva.',
                count($plan['creatable_events']),
                $this->brazilianDate($plan['creatable_events'][0]['effective_date']),
                $this->brazilianDate($plan['creatable_events'][array_key_last($plan['creatable_events'])]['effective_date']),
            );

        if ($plan['missing_in_calculated_period'] !== []) {
            $lines[] = sprintf(
                '%d evento(s) faltam dentro do período já calculado (até %s) e não serão criados aqui: cadastre-os e reprocesse a curva.',
                count($plan['missing_in_calculated_period']),
                $this->brazilianDate($plan['last_calculated_date']),
            );
        }

        if ($plan['conflicting_events'] !== []) {
            $lines[] = sprintf(
                '%d evento(s) cadastrado(s) divergem do contrato e serão mantidos como estão.',
                count($plan['conflicting_events']),
            );
        }

        return implode(' ', $lines);
    }

    private function generateContractualSchedule(): null
    {
        /** @var User $actor */
        $actor = auth()->user();
        $result = app(PuContractualEventScheduleService::class)->write($this->getOwnerRecord(), $actor);

        match ($result['action']) {
            PuContractualEventScheduleService::ACTION_CREATED => Notification::make()
                ->title(sprintf('%d evento(s) criado(s).', $result['created']))
                ->body(sprintf(
                    'Cronograma contratual cadastrado de %s a %s.',
                    $this->brazilianDate($result['first_date']),
                    $this->brazilianDate($result['last_date']),
                ))
                ->success()
                ->send(),
            PuContractualEventScheduleService::ACTION_NOTHING_TO_CREATE => Notification::make()
                ->title('Nenhum evento novo a criar.')
                ->body($result['plan']['missing_in_calculated_period'] === []
                    ? 'O cronograma contratual já está cadastrado.'
                    : 'Os eventos que faltam estão dentro do período já calculado: cadastre-os e reprocesse a curva.')
                ->info()
                ->send(),
            PuContractualEventScheduleService::ACTION_CONCURRENT_CHANGE => Notification::make()
                ->title('Os eventos mudaram durante a geração.')
                ->body('Nenhum evento foi criado. Confira a lista e tente de novo.')
                ->warning()
                ->send(),
            default => Notification::make()
                ->title('Cronograma contratual indisponível.')
                ->body((string) $result['plan']['reason'])
                ->danger()
                ->persistent()
                ->send(),
        };

        return null;
    }

    private function brazilianDate(?string $date): string
    {
        return $date !== null ? CarbonImmutable::parse($date)->format('d/m/Y') : '—';
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
