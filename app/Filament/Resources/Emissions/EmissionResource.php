<?php

namespace App\Filament\Resources\Emissions;

use App\Filament\Resources\Emissions\Pages\CreateEmission;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ListEmissions;
use App\Filament\Resources\Emissions\Pages\ObligationComments;
use App\Filament\Resources\Emissions\Pages\PuCurveHistory;
use App\Filament\Resources\Emissions\Schemas\EmissionForm;
use App\Filament\Resources\Emissions\Tables\EmissionsTable;
use App\Models\Emission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
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

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return EmissionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make('Dossiê Operacional')
                ->schema([
                    \Filament\Schemas\Components\Grid::make(4)->schema([
                        \Filament\Infolists\Components\TextEntry::make('name')
                            ->label('Denominação da Operação')
                            ->weight('bold')
                            ->size('lg'),
                        \Filament\Infolists\Components\TextEntry::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->color(fn (?string $state): string|array => match ($state) {
                                'CRI' => \Filament\Support\Colors\Color::hex('#D4AF37'),
                                'CRA' => \Filament\Support\Colors\Color::hex('#0D9488'),
                                'CR' => \Filament\Support\Colors\Color::hex('#4F46E5'),
                                default => 'gray',
                            }),
                        \Filament\Infolists\Components\TextEntry::make('status')
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
                        \Filament\Infolists\Components\TextEntry::make('issuer')
                            ->label('Emissor')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('issue_date')
                            ->label('Data de Emissão')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('maturity_date')
                            ->label('Data de Vencimento')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('series')
                            ->label('Série / Número')
                            ->state(fn (Emission $record) => trim("{$record->emission_number} / {$record->series}", ' /'))
                            ->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('issued_volume')
                            ->label('Volume Total Emitido')
                            ->formatStateUsing(fn ($state) => $state !== null ? 'R$ '.number_format((float) $state, 2, ',', '.') : '—'),
                        \Filament\Infolists\Components\TextEntry::make('next_action')
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

            \Filament\Schemas\Components\Grid::make(2)->schema([
                \Filament\Schemas\Components\Section::make('Participantes')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('lead_coordinator')->label('Coordenador Líder')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('settlement_bank')->label('Banco Liquidante')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('registrar')->label('Escriturador')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('distributor')->label('Distribuidor')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('trustee_agent')->label('Agente Fiduciário')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('debtor')->label('Devedor')->placeholder('—'),
                    ])->columns(2),

                \Filament\Schemas\Components\Section::make('Estrutura e Taxas')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('remuneration_indexer')->label('Indexador')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('remuneration_rate')->label('Taxa de Remuneração')->suffix('%')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('interest_payment_frequency')->label('Pagamento de Juros')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('amortization_frequency')->label('Amortização')->placeholder('—'),
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
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PaymentsRelationManager::class,
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuHistoriesRelationManager::class,
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuEventsRelationManager::class,
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuDailyCurvesRelationManager::class,
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\IntegralizationHistoriesRelationManager::class,
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\GuaranteesRelationManager::class,
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationSuggestionsRelationManager::class,
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager::class,
            \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationEvidencesRelationManager::class,
            \App\Filament\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmissions::route('/'),
            'create' => CreateEmission::route('/create'),
            'view' => \App\Filament\Resources\Emissions\Pages\ViewEmission::route('/{record}'),
            'edit' => EditEmission::route('/{record}/edit'),
            'obligation-comments' => ObligationComments::route('/{record}/obligations/{obligation}/comments'),
            'pu-history' => PuCurveHistory::route('/{record}/pu-history'),
        ];
    }
}
