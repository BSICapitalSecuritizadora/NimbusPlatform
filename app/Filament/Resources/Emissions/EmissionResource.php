<?php

namespace App\Filament\Resources\Emissions;

use App\Filament\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\GuaranteeDetectionsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\GuaranteesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\InstrumentChangesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\IntegralizationHistoriesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\LegalInstrumentsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationEvidencesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationSeriesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationSuggestionsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuBaselineEvidencesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuCalendarHomologationsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuDailyCurvesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuEventsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\CreateEmission;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ListEmissions;
use App\Filament\Resources\Emissions\Pages\ObligationComments;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use App\Filament\Resources\Emissions\Pages\PuCurveHistory;
use App\Filament\Resources\Emissions\Pages\ViewEmission;
use App\Filament\Resources\Emissions\Schemas\EmissionForm;
use App\Filament\Resources\Emissions\Tables\EmissionsTable;
use App\Models\Emission;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class EmissionResource extends Resource
{
    protected static ?string $model = Emission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Emissões';

    protected static ?string $modelLabel = 'Emissão';

    protected static ?string $pluralModelLabel = 'Emissões';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return EmissionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // ── 1. Dossiê Operacional (Resumo Executivo da Emissão) ──
                Section::make('Dossiê Operacional')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 4,
                        ])->schema([
                            TextEntry::make('name')
                                ->label('Denominação da Operação')
                                ->weight('bold')
                                ->size('lg')
                                ->tooltip(fn (?Emission $record): ?string => $record?->name)
                                ->helperText(fn (?Emission $record): ?string => $record?->if_code ? "Código IF: {$record->if_code}" : null),

                            TextEntry::make('type')
                                ->label('Tipo de Emissão')
                                ->badge()
                                ->color(fn (?string $state): string|array => match ($state) {
                                    'CRI' => Color::hex('#D4AF37'),
                                    'CRA' => Color::hex('#0D9488'),
                                    'CR' => Color::hex('#4F46E5'),
                                    default => 'gray',
                                }),

                            TextEntry::make('status')
                                ->label('Status da Operação')
                                ->badge()
                                ->formatStateUsing(fn (?string $state): string => Emission::STATUS_OPTIONS[$state] ?? (string) $state)
                                ->color(fn (?string $state): string => match ($state) {
                                    'draft' => 'gray',
                                    'default' => 'danger',
                                    'active' => 'success',
                                    'closed' => 'danger',
                                    default => 'gray',
                                }),

                            TextEntry::make('issued_volume')
                                ->label('Volume Total Emitido')
                                ->weight('bold')
                                ->size('lg')
                                ->color('primary')
                                ->formatStateUsing(fn ($state) => $state !== null ? 'R$ '.number_format((float) $state, 2, ',', '.') : '—')
                                ->helperText(fn (?Emission $record): ?string => $record?->issued_quantity ? number_format($record->issued_quantity, 0, ',', '.').' títulos' : null),

                            TextEntry::make('issue_date')
                                ->label('Data de Emissão')
                                ->date('d/m/Y')
                                ->icon('heroicon-m-calendar')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('maturity_date')
                                ->label('Data de Vencimento')
                                ->date('d/m/Y')
                                ->icon('heroicon-m-clock')
                                ->iconColor('gray')
                                ->weight('medium')
                                ->placeholder('—'),

                            TextEntry::make('series')
                                ->label('Série / Número')
                                ->state(fn (?Emission $record) => $record ? trim("{$record->emission_number} / {$record->series}", ' /') : '—')
                                ->icon('heroicon-m-hashtag')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('issuer')
                                ->label('Emissor')
                                ->icon('heroicon-m-building-office-2')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('next_action')
                                ->label('Próxima Ação / Criticidade')
                                ->state(fn (?Emission $record): string => match ($record?->status) {
                                    'draft' => 'Atenção: Concluir preenchimento de dados e ativar a operação.',
                                    'active' => 'Baixa: Monitorar obrigações e eventos de PU.',
                                    'default' => 'Crítica: Acompanhar inadimplência e notificar responsáveis.',
                                    'closed' => 'Concluída: Nenhuma ação. Operação encerrada.',
                                    default => 'Atenção: Aguardando atualização de status.',
                                })
                                ->badge()
                                ->color(fn (?Emission $record): string => match ($record?->status) {
                                    'draft' => 'warning',
                                    'active' => 'info',
                                    'default' => 'danger',
                                    'closed' => 'success',
                                    default => 'warning',
                                })
                                ->icon(fn (?Emission $record): string => match ($record?->status) {
                                    'draft' => 'heroicon-m-exclamation-triangle',
                                    'active' => 'heroicon-m-information-circle',
                                    'default' => 'heroicon-m-exclamation-circle',
                                    'closed' => 'heroicon-m-check-circle',
                                    default => 'heroicon-m-exclamation-triangle',
                                })
                                ->columnSpanFull(),
                        ]),
                    ]),

                // ── 2. Participantes da Operação ──
                Section::make('Participantes da Operação')
                    ->icon('heroicon-o-user-group')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 3,
                        ])->schema([
                            TextEntry::make('lead_coordinator')
                                ->label('Coordenador Líder')
                                ->icon('heroicon-m-briefcase')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('settlement_bank')
                                ->label('Banco Liquidante')
                                ->icon('heroicon-m-building-library')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('registrar')
                                ->label('Escriturador')
                                ->icon('heroicon-m-document-check')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('distributor')
                                ->label('Distribuidor')
                                ->icon('heroicon-m-arrow-path-rounded-square')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('trustee_agent')
                                ->label('Agente Fiduciário')
                                ->icon('heroicon-m-shield-check')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('debtor')
                                ->label('Devedor')
                                ->icon('heroicon-m-building-office')
                                ->iconColor('gray')
                                ->placeholder('—'),
                        ]),
                    ]),

                // ── 3. Estrutura e Condições Financeiras ──
                Section::make('Estrutura e Condições Financeiras')
                    ->icon('heroicon-o-currency-dollar')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 4,
                        ])->schema([
                            TextEntry::make('remuneration_indexer')
                                ->label('Indexador')
                                ->badge()
                                ->color('gray')
                                ->placeholder('—'),

                            TextEntry::make('remuneration_rate')
                                ->label('Taxa de Remuneração')
                                ->state(fn (?Emission $record): string => $record?->remuneration_rate !== null ? number_format((float) $record->remuneration_rate, 2, ',', '.').'%' : '—')
                                ->weight('bold')
                                ->color('primary'),

                            TextEntry::make('interest_payment_frequency')
                                ->label('Pagamento de Juros')
                                ->icon('heroicon-m-calendar-days')
                                ->iconColor('gray')
                                ->placeholder('—'),

                            TextEntry::make('amortization_frequency')
                                ->label('Amortização')
                                ->icon('heroicon-m-banknotes')
                                ->iconColor('gray')
                                ->placeholder('—'),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return EmissionsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->can('emissions.view');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('emissions.create');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('emissions.delete');
    }

    public static function getRelations(): array
    {
        return [
            PuBaselineEvidencesRelationManager::class,
            PaymentsRelationManager::class,
            PuHistoriesRelationManager::class,
            PuCalendarHomologationsRelationManager::class,
            PuEventsRelationManager::class,
            PuDailyCurvesRelationManager::class,
            IntegralizationHistoriesRelationManager::class,
            LegalInstrumentsRelationManager::class,
            InstrumentChangesRelationManager::class,
            GuaranteesRelationManager::class,
            GuaranteeDetectionsRelationManager::class,
            ObligationSuggestionsRelationManager::class,
            ObligationSeriesRelationManager::class,
            ObligationsRelationManager::class,
            ObligationEvidencesRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmissions::route('/'),
            'create' => CreateEmission::route('/create'),
            'view' => ViewEmission::route('/{record}'),
            'edit' => EditEmission::route('/{record}/edit'),
            'obligation-comments' => ObligationComments::route('/{record}/obligations/{obligation}/comments'),
            'pu-history' => PuCurveHistory::route('/{record}/pu-history'),
            'pu-calculator' => PuCalculatorSimulator::route('/{record}/pu-calculator'),
        ];
    }
}
