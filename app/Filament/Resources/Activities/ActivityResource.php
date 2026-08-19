<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Exports\ActivityExporter;
use App\Filament\Resources\Activities\Pages\ManageActivities;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static \UnitEnum|string|null $navigationGroup = 'Administração';

    protected static ?string $navigationParentItem = 'Auditoria';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Logs de Auditoria';

    protected static ?string $modelLabel = 'registro de auditoria';

    protected static ?string $pluralModelLabel = 'Registros de Auditoria';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit.activities.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('log_name')
                    ->label('Identificador do Log'),
                TextInput::make('description')
                    ->label('Ação Executada'),
                TextInput::make('subject_type')
                    ->label('Entidade Modificada'),
                Textarea::make('properties')
                    ->label('Dados da Alteração')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('log_name')->label('Identificador do Log'),
                TextEntry::make('description')->label('Ação Executada'),
                TextEntry::make('causer.name')->label('Autor da Ação'),
                TextEntry::make('subject_type')->label('Entidade Modificada')
                    ->formatStateUsing(fn (?string $state): string => self::friendlySubjectType($state)),
                TextEntry::make('created_at')->label('Data e Hora')->dateTime(),
                TextEntry::make('properties')
                    ->label('Dados da Alteração (JSON)')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->fontFamily('mono')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_name')
                    ->label('Log')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                TextColumn::make('description')
                    ->label('Ação Executada')
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label('Entidade Modificada')
                    ->formatStateUsing(fn (?string $state): string => self::friendlySubjectType($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('Usuário Autor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Data e Hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Tipo de Evento')
                    ->options([
                        'created' => 'Criação',
                        'updated' => 'Atualização',
                        'deleted' => 'Exclusão',
                        'login' => 'Login',
                        'logout' => 'Logout',
                        'downloaded' => 'Download',
                    ]),
                SelectFilter::make('causer_id')
                    ->label('Usuário')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')->label('Data Inicial'),
                        DatePicker::make('created_until')->label('Data Final'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                ExportAction::make()
                    ->label('Exportar')
                    ->exporter(ActivityExporter::class),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActivities::route('/'),
        ];
    }

    public static function friendlySubjectType(?string $fqcn): string
    {
        if ($fqcn === null) {
            return '—';
        }

        return match ($fqcn) {
            'App\Models\User' => 'Usuário',
            'App\Models\Investor' => 'Investidor',
            'App\Models\Expense' => 'Despesa',
            'App\Models\Construction' => 'Empreendimento',
            'App\Models\Fund' => 'Fundo',
            'App\Models\Receivable' => 'Recebível',
            'App\Models\SalesBoard' => 'Quadro de Vendas',
            'App\Models\Payment' => 'Pagamento',
            'App\Models\Bank' => 'Banco',
            'App\Models\ExpenseServiceProvider' => 'Prestador de Serviço',
            'App\Models\Proposal' => 'Proposta',
            'App\Models\ProjectIndicator' => 'Parâmetros de Indicadores do Empreendimento',
            'App\Models\Emission' => 'Emissão',
            'App\Models\EmissionMonthlyReportNote' => 'Nota de Relatório Mensal',
            'App\Models\Document' => 'Documento',
            'App\Models\FundBalanceHistory' => 'Histórico de Saldo do Fundo',
            'App\Models\Guarantee' => 'Garantia',
            'App\Models\GuaranteeSnapshot' => 'Competência de Garantia',
            'App\Models\IntegralizationHistory' => 'Histórico de Integralização',
            'App\Models\LegalInstrument' => 'Instrumento Jurídico',
            'App\Models\Measurement' => 'Medição',
            'App\Models\MeasurementPlanLine' => 'Linha do Plano de Medição',
            'App\Models\MeasurementPlanSet' => 'Plano de Medição',
            'App\Models\MeasurementReview' => 'Revisão de Medição',
            'App\Models\Negotiation' => 'Negociação',
            'App\Models\Obligation' => 'Obrigação',
            'App\Models\ObligationSeries' => 'Série de Obrigações',
            'App\Models\Operation' => 'Operação',
            'App\Models\PuHistory' => 'Histórico de PU',
            'App\Models\SalesBoardHistory' => 'Histórico do Quadro de Vendas',
            'App\Models\ExtractedGuarantee' => 'Sugestão de Garantia',
            'App\Models\ExtractedObligation' => 'Sugestão de Obrigação',
            'Spatie\Permission\Models\Role' => 'Perfil de Acesso',
            'Spatie\Permission\Models\Permission' => 'Permissão',
            default => class_basename($fqcn),
        };
    }
}
