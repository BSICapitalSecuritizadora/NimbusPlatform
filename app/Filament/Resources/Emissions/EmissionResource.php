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
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuDailyCurvesRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuEventsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\CreateEmission;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ListEmissions;
use App\Filament\Resources\Emissions\Pages\ObligationComments;
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
        return $schema->components([
            Section::make('Dossiê Operacional')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('name')
                            ->label('Denominação da Operação')
                            ->weight('bold')
                            ->size('lg'),
                        TextEntry::make('type')
                            ->label('Tipo')
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
                        TextEntry::make('issuer')
                            ->label('Emissor')
                            ->placeholder('—'),
                        TextEntry::make('issue_date')
                            ->label('Data de Emissão')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('maturity_date')
                            ->label('Data de Vencimento')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('series')
                            ->label('Série / Número')
                            ->state(fn (Emission $record) => trim("{$record->emission_number} / {$record->series}", ' /'))
                            ->placeholder('—'),
                        TextEntry::make('issued_volume')
                            ->label('Volume Total Emitido')
                            ->formatStateUsing(fn ($state) => $state !== null ? 'R$ '.number_format((float) $state, 2, ',', '.') : '—'),
                        TextEntry::make('next_action')
                            ->label('Próxima Ação / Criticidade')
                            ->state(fn (Emission $record): string => match ($record->status) {
                                'draft' => 'Atenção: Concluir preenchimento de dados e ativar a operação.',
                                'active' => 'Baixa: Monitorar obrigações e eventos de PU.',
                                'default' => 'Crítica: Acompanhar inadimplência e notificar responsáveis.',
                                'closed' => 'Concluída: Nenhuma ação. Operação encerrada.',
                                default => 'Atenção: Aguardando atualização de status.',
                            })
                            ->color(fn (Emission $record): string => match ($record->status) {
                                'draft' => 'warning',
                                'active' => 'info',
                                'default' => 'danger',
                                'closed' => 'success',
                                default => 'warning',
                            })
                            ->icon(fn (Emission $record): string => match ($record->status) {
                                'draft' => 'heroicon-m-exclamation-triangle',
                                'active' => 'heroicon-m-information-circle',
                                'default' => 'heroicon-m-exclamation-circle',
                                'closed' => 'heroicon-m-check-circle',
                                default => 'heroicon-m-exclamation-triangle',
                            })
                            ->weight('bold')
                            ->columnSpan(4),
                    ]),
                ]),

            Grid::make(2)->schema([
                Section::make('Participantes')
                    ->schema([
                        TextEntry::make('lead_coordinator')->label('Coordenador Líder')->placeholder('—'),
                        TextEntry::make('settlement_bank')->label('Banco Liquidante')->placeholder('—'),
                        TextEntry::make('registrar')->label('Escriturador')->placeholder('—'),
                        TextEntry::make('distributor')->label('Distribuidor')->placeholder('—'),
                        TextEntry::make('trustee_agent')->label('Agente Fiduciário')->placeholder('—'),
                        TextEntry::make('debtor')->label('Devedor')->placeholder('—'),
                    ])->columns(2),

                Section::make('Estrutura e Taxas')
                    ->schema([
                        TextEntry::make('remuneration_indexer')->label('Indexador')->placeholder('—'),
                        TextEntry::make('remuneration_rate')->label('Taxa de Remuneração')->suffix('%')->placeholder('—'),
                        TextEntry::make('interest_payment_frequency')->label('Pagamento de Juros')->placeholder('—'),
                        TextEntry::make('amortization_frequency')->label('Amortização')->placeholder('—'),
                    ])->columns(2),
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
            PaymentsRelationManager::class,
            PuHistoriesRelationManager::class,
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
        ];
    }
}
