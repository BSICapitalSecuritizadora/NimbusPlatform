<?php

namespace App\Filament\Pages;

use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Services\PuOperationalIncidentService;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Filament\Support\PuRefreshRecoveryAction;
use App\Models\PuMonitorRun;
use App\Models\PuOperationalIncident;
use App\Models\User;
use App\Support\PuCalculator\PuDisplay;
use App\Support\PuCalculator\PuIncidentGuidance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnitEnum;

/**
 * Incidentes operacionais do PU (Fase 8, P0-C).
 *
 * Lê os incidentes duráveis que o monitor da Fase 6 abre, atualiza e resolve.
 * A tela não decide nada: reconhecer só registra que alguém está tratando --
 * não resolve a condição, não muda PU, liquidação, contrato nem curva. A
 * resolução é do monitor, quando a condição deixa de existir.
 */
class PuOperationalIncidents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'pu-incidentes';

    protected string $view = 'filament.pages.pu-operational-incidents';

    protected static ?string $title = 'Incidentes do PU';

    protected static ?string $navigationLabel = 'Incidentes do PU';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 1;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-pu-incidents-page',
    ];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can(AccessPermission::PuOperationsMonitor->value);
    }

    public static function getNavigationBadge(): ?string
    {
        $active = PuOperationalIncident::query()->where('status', PuIncidentStatus::Active->value)->count();

        return $active > 0 ? (string) $active : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return PuOperationalIncident::query()
            ->where('status', PuIncidentStatus::Active->value)
            ->where('severity', PuOperationalSeverity::Critical->value)
            ->exists() ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Incidentes ativos ainda não reconhecidos';
    }

    public function getSubheading(): ?string
    {
        $run = PuMonitorRun::query()->whereNotNull('finished_at')->orderByDesc('id')->first();

        return $run instanceof PuMonitorRun
            ? sprintf('Último monitoramento concluído em %s (%s). Os incidentes abrem, recorrem e se resolvem pelo monitor; esta tela não muda nenhum fato financeiro.', PuDisplay::dateTime($run->finished_at), $run->status?->value ?? '—')
            : 'Nenhum monitoramento concluído registrado ainda: a ausência de incidentes não significa operação saudável.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PuOperationalIncident::query()->with(['emission:id,name', 'acknowledgedBy:id,name']))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('case when resolved_at is null then 0 else 1 end')
                ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
                ->orderByDesc('last_detected_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('severity')
                    ->label('Severidade')
                    ->badge()
                    ->formatStateUsing(fn (PuOperationalIncident $record): string => $record->severity->label())
                    ->color(fn (PuOperationalIncident $record): string => PuDisplay::severityColor($record->severity))
                    ->icon(fn (PuOperationalIncident $record): string => PuDisplay::severityIcon($record->severity)),
                TextColumn::make('type')
                    ->label('Incidente')
                    ->extraCellAttributes(['class' => 'pu-cell-wider'])
                    ->formatStateUsing(fn (PuOperationalIncident $record): string => $record->type->label())
                    ->weight('semibold')
                    ->description(fn (PuOperationalIncident $record): string => trim(Str::limit($record->reason, 180).($this->targetLine($record) !== null ? ' · '.$this->targetLine($record) : '')))
                    ->tooltip(fn (PuOperationalIncident $record): string => $record->reason)
                    ->wrap(),
                TextColumn::make('emission.name')
                    ->label('Emissão')
                    ->placeholder('Sistema / índice')
                    ->url(fn (PuOperationalIncident $record): ?string => $record->emission !== null && EmissionResource::canView($record->emission)
                        ? EmissionResource::getUrl('pu-operation', ['record' => $record->emission])
                        : null)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('last_detected_at')
                    ->label('Última detecção')
                    ->state(fn (PuOperationalIncident $record): ?string => PuDisplay::dateTime($record->last_detected_at))
                    ->description(fn (PuOperationalIncident $record): string => sprintf('%d detecção(ões)', (int) $record->detection_count))
                    ->tooltip(fn (PuOperationalIncident $record): string => 'Primeira detecção: '.(PuDisplay::dateTime($record->first_detected_at) ?? '—')),
                TextColumn::make('first_detected_at')
                    ->label('Primeira detecção')
                    ->state(fn (PuOperationalIncident $record): ?string => PuDisplay::dateTime($record->first_detected_at))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (PuOperationalIncident $record): string => $record->status->label())
                    ->color(fn (PuOperationalIncident $record): string => PuDisplay::incidentStatusColor($record->status))
                    ->icon(fn (PuOperationalIncident $record): string => PuDisplay::incidentStatusIcon($record->status))
                    ->tooltip(fn (PuOperationalIncident $record): ?string => $this->statusTooltip($record)),
            ])
            ->filters([
                SelectFilter::make('situation')
                    ->label('Situação')
                    ->options([
                        'open' => 'Abertos (ativos e reconhecidos)',
                        PuIncidentStatus::Active->value => 'Ativos (não reconhecidos)',
                        PuIncidentStatus::Acknowledged->value => 'Reconhecidos',
                        PuIncidentStatus::Resolved->value => 'Resolvidos',
                    ])
                    ->default('open')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->open(),
                        PuIncidentStatus::Active->value, PuIncidentStatus::Acknowledged->value, PuIncidentStatus::Resolved->value => $query->where('status', $data['value']),
                        default => $query,
                    }),
                SelectFilter::make('severity')
                    ->label('Severidade')
                    ->multiple()
                    ->options(collect(PuOperationalSeverity::cases())->mapWithKeys(fn (PuOperationalSeverity $severity): array => [$severity->value => $severity->label()])->all()),
                SelectFilter::make('check_name')
                    ->label('Área')
                    ->options([
                        PuOperationalConditionType::CHECK_CURVE => 'Curva oficial e ativação',
                        PuOperationalConditionType::CHECK_INDEX => 'Índice',
                        PuOperationalConditionType::CHECK_OBLIGATIONS => 'Obrigações, liquidação e conciliação',
                        PuOperationalConditionType::CHECK_REFRESH => 'Atualização das obrigações',
                        PuOperationalConditionType::CHECK_SYSTEM => 'Fila e sistema',
                    ]),
                SelectFilter::make('emission_id')
                    ->label('Emissão')
                    ->relationship('emission', 'name')
                    ->searchable()
                    ->preload()
                    ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),
            ])
            ->recordActions([
                $this->detailsAction(),
                ActionGroup::make([
                    $this->acknowledgeAction(),
                    PuRefreshRecoveryAction::make('retryRefresh')
                        ->visible(fn (PuOperationalIncident $record): bool => $record->isOpen()
                            && $record->emission_id !== null
                            && $record->type->check() === PuOperationalConditionType::CHECK_REFRESH
                            && (auth()->user()?->can(AccessPermission::PuOperationsRecover->value) ?? false)),
                    Action::make('openTarget')
                        ->label(fn (PuOperationalIncident $record): string => PuIncidentGuidance::link($record)['label'] ?? 'Abrir')
                        ->icon('heroicon-m-arrow-top-right-on-square')
                        ->visible(fn (PuOperationalIncident $record): bool => PuIncidentGuidance::link($record) !== null)
                        ->url(fn (PuOperationalIncident $record): ?string => PuIncidentGuidance::link($record)['url'] ?? null),
                ])
                    ->label('Ações')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray')
                    ->button()
                    ->size('sm'),
            ])
            ->recordAction('incidentDetails')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading('Nenhum incidente neste filtro')
            ->emptyStateDescription('Sem incidente aberto não quer dizer que tudo foi verificado: confira a data do último monitoramento acima e o Painel Operacional de PU.');
    }

    private function detailsAction(): Action
    {
        return Action::make('incidentDetails')
            ->label('Detalhes')
            ->icon('heroicon-m-eye')
            ->iconButton()
            ->tooltip('Detalhes, contexto e ação recomendada')
            ->color('gray')
            ->modalHeading(fn (PuOperationalIncident $record): string => $record->type->label())
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (PuOperationalIncident $record): Htmlable => view('filament.pages.pu-incident-details', [
                'incident' => $record,
                'guidance' => PuIncidentGuidance::recommendedAction($record->type),
                'link' => PuIncidentGuidance::link($record),
                'target' => $this->targetLine($record) ?? 'Emissão inteira',
            ]));
    }

    private function acknowledgeAction(): Action
    {
        return Action::make('acknowledge')
            ->label('Reconhecer')
            ->icon('heroicon-m-eye')
            ->color('warning')
            ->visible(fn (PuOperationalIncident $record): bool => $record->status === PuIncidentStatus::Active
                && (auth()->user()?->can(AccessPermission::PuOperationsRecover->value) ?? false))
            ->modalHeading(fn (PuOperationalIncident $record): string => 'Reconhecer: '.$record->type->label())
            ->modalDescription('Reconhecer registra que alguém está tratando o incidente e silencia novos avisos da mesma severidade. NÃO resolve a condição e NÃO altera o PU oficial, liquidações, eventos contratuais, curvas ou conflitos. O incidente só se resolve quando o monitor deixar de detectar a condição; se a severidade subir, ele volta a ativo.')
            ->modalIcon('heroicon-o-eye')
            ->modalSubmitActionLabel('Reconhecer')
            ->schema([
                Textarea::make('note')
                    ->label('Observação (opcional)')
                    ->helperText('Quem está tratando, o que já foi verificado. Fica na trilha de auditoria.')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (PuOperationalIncident $record, array $data): void {
                /** @var User $user */
                $user = auth()->user();

                try {
                    app(PuOperationalIncidentService::class)->acknowledge($record, $user, $data['note'] ?? null);
                } catch (AuthorizationException $exception) {
                    Notification::make()->title('Reconhecimento não autorizado.')->body($exception->getMessage())->danger()->send();

                    return;
                } catch (InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('Nada a reconhecer.')
                        ->body($exception->getMessage().' O monitor resolveu a condição depois que a tela foi aberta.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Incidente reconhecido.')
                    ->body('A condição continua sendo monitorada; nenhum fato financeiro foi alterado.')
                    ->success()
                    ->send();
            });
    }

    private function targetLine(PuOperationalIncident $record): ?string
    {
        $parts = [];

        if ($record->curve_version_id !== null) {
            $parts[] = 'versão de curva #'.$record->curve_version_id;
        }

        if ($record->obligation_id !== null) {
            $parts[] = 'obrigação #'.$record->obligation_id;
        }

        if ($record->settlement_conflict_id !== null) {
            $parts[] = 'conflito #'.$record->settlement_conflict_id;
        }

        if ($record->indexer !== null) {
            $parts[] = 'indexador '.$record->indexer;
        }

        if ($record->business_date !== null) {
            $parts[] = 'data '.PuDisplay::date($record->business_date);
        }

        return $parts === [] ? null : ucfirst(implode(' · ', $parts));
    }

    private function statusTooltip(PuOperationalIncident $record): ?string
    {
        return match ($record->status) {
            PuIncidentStatus::Acknowledged => sprintf(
                'Reconhecido por %s em %s%s. Continua aberto até o monitor deixar de detectar a condição.',
                $record->acknowledgedBy?->name ?? '—',
                PuDisplay::dateTime($record->acknowledged_at) ?? '—',
                filled($record->acknowledgement_note) ? ': '.$record->acknowledgement_note : '',
            ),
            PuIncidentStatus::Resolved => sprintf('Resolvido pelo monitor em %s.', PuDisplay::dateTime($record->resolved_at) ?? '—'),
            default => 'Ativo: ninguém reconheceu ainda.',
        };
    }
}
