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
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
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
                TextEntry::make('log_name')
                    ->label('Identificador do Log')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyLogName($state))
                    ->helperText(fn (?string $state): ?string => $state ? "Identificador técnico: {$state}" : null)
                    ->badge()
                    ->color('gray'),
                TextEntry::make('description')
                    ->label('Ação Executada')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyDescription($state))
                    ->weight(FontWeight::SemiBold),
                TextEntry::make('causer.name')
                    ->label('Autor da Ação')
                    ->placeholder('Sistema / Ação Automática')
                    ->icon('heroicon-m-user'),
                TextEntry::make('subject_type')
                    ->label('Entidade Modificada')
                    ->formatStateUsing(fn (?string $state): string => self::friendlySubjectType($state))
                    ->helperText(fn (?string $state): ?string => $state ? "Classe: {$state}" : null),
                TextEntry::make('created_at')
                    ->label('Data e Hora')
                    ->dateTime('d/m/Y · H:i:s')
                    ->fontFamily(FontFamily::Mono),
                TextEntry::make('properties')
                    ->label('Dados da Alteração (JSON)')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                    ->fontFamily(FontFamily::Mono)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Buscar por ação, usuário ou entidade...')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->recordUrl(null)
            ->columns([
                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyLogName($state))
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Ação Executada')
                    ->formatStateUsing(fn (?string $state): string => self::friendlyDescription($state))
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('subject_type')
                    ->label('Entidade Modificada')
                    ->formatStateUsing(fn (?string $state): string => self::friendlySubjectType($state))
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('Usuário Autor')
                    ->icon('heroicon-m-user')
                    ->iconColor('gray')
                    ->placeholder('Sistema')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Data e Hora')
                    ->dateTime('d/m/Y · H:i:s')
                    ->fontFamily(FontFamily::Mono)
                    ->tooltip(fn (Activity $record): ?string => $record->created_at?->diffForHumans())
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
                SelectFilter::make('log_name')
                    ->label('Identificador do Log')
                    ->options(fn (): array => Activity::query()
                        ->distinct()
                        ->whereNotNull('log_name')
                        ->pluck('log_name', 'log_name')
                        ->mapWithKeys(fn (string $name): array => [$name => self::friendlyLogName($name)])
                        ->all()
                    )
                    ->searchable(),
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
            ->recordActions([
                ViewAction::make()
                    ->label('Visualizar')
                    ->tooltip('Visualizar detalhes do registro')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->iconButton(),
            ])
            ->toolbarActions([
                ExportAction::make()
                    ->label('Exportar registros')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->exporter(ActivityExporter::class),
            ])
            ->emptyStateHeading('Nenhum registro de auditoria')
            ->emptyStateDescription('Ainda não há eventos registrados para os critérios selecionados.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActivities::route('/'),
        ];
    }

    public static function friendlyLogName(?string $logName): string
    {
        if (blank($logName)) {
            return '—';
        }

        return match ($logName) {
            'default' => 'Geral',
            'login' => 'Login',
            'logout' => 'Logout',
            'business_calendars', 'business_calendar' => 'Calendário de Negócios',
            'importacao-parcelas', 'importacao_parcelas' => 'Importação de Parcelas',
            'roles' => 'Perfis de Acesso',
            'recruitment' => 'Recrutamento',
            'documents' => 'Documentos',
            'expenses' => 'Despesas',
            'receivables' => 'Recebíveis',
            'proposals' => 'Propostas',
            'emissions' => 'Emissões',
            'funds' => 'Fundos',
            'measurements' => 'Medições',
            'operations' => 'Operações',
            'constructions' => 'Empreendimentos',
            'pu' => 'Cálculo de PU',
            'contact_messages', 'contact-messages' => 'Mensagens de Contato',
            'vacancies' => 'Vagas',
            'guarantees' => 'Garantias',
            'obligations' => 'Obrigações',
            'sales_boards', 'sales-boards' => 'Quadro de Vendas',
            default => Str::headline((string) $logName),
        };
    }

    public static function friendlyDescription(?string $description): string
    {
        if (blank($description)) {
            return '—';
        }

        return match ($description) {
            'created' => 'Criação de registro',
            'updated' => 'Atualização de registro',
            'deleted' => 'Exclusão de registro',
            'login' => 'Login no sistema',
            'logout' => 'Logout do sistema',
            'downloaded' => 'Download de arquivo',
            'business_calendar_b3_listed_inferred_set_removed' => 'Remoção de inferência B3 no calendário',
            'business_calendar_b3_listed_inferred_set_added' => 'Inclusão de inferência B3 no calendário',
            default => str_contains($description, '_') && ! str_contains($description, ' ')
                ? Str::headline($description)
                : $description,
        };
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
            'App\Models\FundType' => 'Tipo de Fundo',
            'App\Models\FundName' => 'Nome de Fundo',
            'App\Models\FundApplication' => 'Aplicação em Fundo',
            'App\Models\Receivable' => 'Recebível',
            'App\Models\SalesBoard' => 'Quadro de Vendas',
            'App\Models\Payment' => 'Pagamento',
            'App\Models\Bank' => 'Banco',
            'App\Models\ExpenseServiceProvider' => 'Prestador de Serviço',
            'App\Models\Proposal' => 'Proposta',
            'App\Models\ProjectIndicator' => 'Parâmetros de Indicadores',
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
            'App\Models\Vacancy' => 'Vaga',
            'App\Models\JobApplication' => 'Candidatura',
            'App\Models\ContactMessage' => 'Mensagem de Contato',
            'App\Models\BusinessCalendar' => 'Calendário de Negócios',
            'App\Models\Holiday' => 'Feriado',
            'App\Models\FinancialIndex' => 'Índice Financeiro',
            'App\Models\Representative' => 'Representante',
            'Spatie\Permission\Models\Role' => 'Perfil de Acesso',
            'Spatie\Permission\Models\Permission' => 'Permissão',
            default => class_basename($fqcn),
        };
    }
}
