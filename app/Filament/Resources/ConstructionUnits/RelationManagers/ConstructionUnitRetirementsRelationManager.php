<?php

namespace App\Filament\Resources\ConstructionUnits\RelationManagers;

use App\Exceptions\ConstructionUnitRetirementException;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Filament\Support\GuardsRelationManagerAccess;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitRetirement;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\ConstructionUnitRetirementService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\UnitValueResolver;
use App\Support\BusinessTime;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Baixas da unidade.
 *
 * A baixa tira a unidade do Quadro de Vendas a partir de uma data, sem apagar
 * nada: as competências anteriores continuam com ela, e o histórico de baixas e
 * reativações fica consultável aqui. É decisão da Gestão
 * (`sales-boards.approve`) em qualquer situação da Emissão: quem opera o
 * cadastro vê "Dar baixa na unidade" desabilitada, dizendo de quem é a decisão.
 * O {@see ConstructionUnitRetirementService} confere de novo o ator, o motivo e
 * as datas -- a tela só esconde e explica.
 *
 * Aqui não há editar nem excluir: corrigir uma baixa é reativar a unidade -- na
 * própria data, a baixa fica anulada -- e registrar outra.
 */
class ConstructionUnitRetirementsRelationManager extends RelationManager
{
    use GuardsRelationManagerAccess;

    protected static string $relationship = 'retirements';

    protected static ?string $title = 'Baixas';

    protected static ?string $modelLabel = 'Baixa';

    protected static ?string $pluralModelLabel = 'Baixas';

    /**
     * Se a unidade tem baixa aberta, relido do banco uma vez por requisição: a
     * ação de cabeçalho, a tabela e as ações de linha perguntam, e a relação
     * memoizada no registro dono não enxergaria a baixa gravada agora. Privado,
     * o Livewire não o serializa.
     */
    private ?bool $hasOpenRetirement = null;

    /**
     * O primeiro dia que nenhuma competência publicada alcança, lido uma vez
     * por requisição. A chave existe só depois de lido -- o valor pode ser nulo.
     *
     * @var array{date?: CarbonImmutable|null}
     */
    private array $earliestEffectiveDate = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    protected function hasOpenRetirement(): bool
    {
        return $this->hasOpenRetirement ??= ConstructionUnitRetirement::query()
            ->where('construction_unit_id', $this->getOwnerRecord()->getKey())
            ->whereNull('reactivated_on')
            ->exists();
    }

    protected function earliestEffectiveDate(): ?CarbonImmutable
    {
        if (! array_key_exists('date', $this->earliestEffectiveDate)) {
            /** @var ConstructionUnit $unit */
            $unit = $this->getOwnerRecord();

            $this->earliestEffectiveDate['date'] = app(ConstructionUnitRetirementService::class)->earliestEffectiveDate($unit);
        }

        return $this->earliestEffectiveDate['date'];
    }

    protected function forgetRetirementState(): void
    {
        $this->hasOpenRetirement = null;
        $this->earliestEffectiveDate = [];
    }

    /**
     * A Gestão vê as ações habilitadas; quem opera o cadastro as vê
     * desabilitadas, com o motivo. Quem só visualiza não as vê.
     */
    protected function canSeeRetirementGovernance(): bool
    {
        $user = auth()->user();

        return ($user?->can('constructions.update') ?? false) || SalesBoardApprovalAuthority::holds($user);
    }

    protected function managementOnlyTooltip(): ?string
    {
        return SalesBoardApprovalAuthority::holds(auth()->user())
            ? null
            : 'A baixa de unidade é decisão da Gestão: exige a permissão de aprovação do Quadro de Vendas.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columnManagerTriggerAction(fn (Action $action): Action => $this->getPageClass() === ViewConstructionUnit::class
                ? $action->tooltip('Colunas')
                : $action)
            ->recordTitleAttribute('retired_on')
            ->columns([
                TextColumn::make('retired_on')
                    ->label('Baixada a partir de')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('situation')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (ConstructionUnitRetirement $record): string => self::situationOf($record))
                    ->color(fn (string $state): string => $state === 'Vigente' ? 'danger' : 'gray'),

                TextColumn::make('reactivated_on')
                    ->label('Reativada a partir de')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(60)
                    ->tooltip(fn (ConstructionUnitRetirement $record): ?string => mb_strlen((string) $record->reason) > 60 ? (string) $record->reason : null)
                    ->wrap(),

                TextColumn::make('retiredBy.name')
                    ->label('Registrada por')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Registrada em')
                    ->dateTime('d/m/Y H:i', BusinessTime::timezone())
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('reactivatedBy.name')
                    ->label('Reativada por')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('retired_on', 'desc')
            ->headerActions([
                $this->retireUnitAction(),
            ])
            ->actions([
                $this->reactivateUnitAction(),
                $this->viewReasonAction(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nenhuma baixa registrada')
            ->emptyStateDescription('A unidade compõe o Quadro de Vendas. Se ela não existe mais — cadastro por engano, fusão, desmembramento —, a Gestão registra a baixa aqui; o histórico continua consultável.')
            ->emptyStateIcon('heroicon-o-archive-box-x-mark');
    }

    private function retireUnitAction(): Action
    {
        return Action::make('retireUnit')
            ->label('Dar baixa na unidade')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->modalHeading('Dar baixa na unidade')
            ->modalDescription(function (): string {
                $earliest = $this->earliestEffectiveDate();

                return 'A unidade deixa de compor o Quadro de Vendas (unidades, estoque e valor) nas competências a partir da data, sem apagar o histórico: as competências anteriores continuam com ela. '
                    .'A baixa fica registrada com o seu nome e o motivo, e a unidade precisa estar livre de contrato e de permuta a partir da data.'
                    .($earliest === null
                        ? ''
                        : sprintf(' A data precisa ser posterior a %s, a última competência publicada.', $earliest->subDay()->format('d/m/Y')));
            })
            ->modalSubmitActionLabel('Registrar baixa')
            ->visible(fn (): bool => ! $this->hasOpenRetirement() && $this->canSeeRetirementGovernance())
            ->disabled(fn (): bool => ! SalesBoardApprovalAuthority::holds(auth()->user()))
            ->tooltip(fn (): ?string => $this->managementOnlyTooltip())
            ->schema([
                DatePicker::make('retired_on')
                    ->label('Deixa de compor o Quadro a partir de')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    /**
                     * Instância à meia-noite, não texto: o seletor guarda
                     * `Y-m-d H:i:s`, e um texto `Y-m-d` ganharia a hora atual
                     * na conversão -- e o "hoje" passaria do limite de hoje.
                     */
                    ->default(fn (): ?CarbonImmutable => $this->earliestEffectiveDate())
                    ->minDate(fn (): ?string => $this->earliestEffectiveDate()?->toDateString())
                    ->maxDate(fn (): string => self::businessToday()->toDateString())
                    ->helperText('Para unidade cadastrada por engano, use a data mais antiga ainda não publicada: assim ela sai de todas as competências em aberto.')
                    ->validationMessages([
                        'required' => 'Informe a data a partir da qual a unidade deixa de compor o Quadro.',
                        'after_or_equal' => fn (): string => sprintf(
                            'A data cai em competência já publicada: use %s ou depois.',
                            $this->earliestEffectiveDate()?->format('d/m/Y') ?? '—',
                        ),
                        'before_or_equal' => 'A baixa é um fato, não um agendamento: a data não pode ser posterior a hoje.',
                    ]),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->placeholder('Unidade cadastrada em duplicidade na carga inicial...')
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da baixa.',
                        'min' => 'Informe o motivo com pelo menos 10 caracteres.',
                    ]),
            ])
            ->action(function (array $data, Action $action): void {
                /** @var ConstructionUnit $unit */
                $unit = $this->getOwnerRecord();
                $retiredOn = CarbonImmutable::parse($data['retired_on'])->startOfDay();

                try {
                    app(ConstructionUnitRetirementService::class)->retire($unit, auth()->user(), $retiredOn, (string) $data['reason']);
                } catch (ConstructionUnitRetirementException|AuthorizationException $exception) {
                    $this->forgetRetirementState();

                    Notification::make()
                        ->danger()
                        ->title('Baixa não registrada.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    /**
                     * O modal continua aberto, com o que foi digitado: a
                     * recusa costuma pedir só outra data.
                     */
                    $action->halt();

                    return;
                }

                $this->forgetRetirementState();

                $this->notifyAffectedCompetences(
                    title: 'Baixa registrada.',
                    decision: sprintf('A unidade deixa de compor o Quadro de Vendas a partir de %s.', $retiredOn->format('d/m/Y')),
                    competences: app(ConstructionUnitRetirementService::class)->openCompetencesReachedBy($unit, $retiredOn),
                );
            });
    }

    private function reactivateUnitAction(): Action
    {
        return Action::make('reactivateUnit')
            ->label('Reativar unidade')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->modalHeading('Reativar a unidade')
            ->modalDescription('A unidade volta a compor o Quadro de Vendas a partir da data, e a baixa fica encerrada. Com a mesma data da baixa, a baixa fica anulada: não vale em competência nenhuma, e o registro continua consultável.')
            ->modalSubmitActionLabel('Reativar unidade')
            ->visible(fn (ConstructionUnitRetirement $record): bool => $record->isOpen() && $this->canSeeRetirementGovernance())
            ->disabled(fn (): bool => ! SalesBoardApprovalAuthority::holds(auth()->user()))
            ->tooltip(fn (): ?string => $this->managementOnlyTooltip())
            ->schema(fn (ConstructionUnitRetirement $record): array => [
                TextEntry::make('current_retirement')
                    ->label('Baixa atual')
                    ->state(sprintf('Baixada a partir de %s.', $record->retired_on?->format('d/m/Y') ?? '—')),

                DatePicker::make('reactivated_on')
                    ->label('Volta a compor o Quadro a partir de')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(fn (): CarbonImmutable => $this->earliestReactivationDate($record))
                    ->minDate(fn (): string => $this->earliestReactivationDate($record)->toDateString())
                    ->maxDate(fn (): string => self::businessToday()->toDateString())
                    ->helperText('A mesma data da baixa anula a baixa.')
                    ->validationMessages([
                        'required' => 'Informe a data a partir da qual a unidade volta a compor o Quadro.',
                        'after_or_equal' => fn (): string => sprintf(
                            'A reativação não pode ser anterior a %s: nem à baixa, nem a uma competência já publicada.',
                            $this->earliestReactivationDate($record)->format('d/m/Y'),
                        ),
                        'before_or_equal' => 'A reativação é um fato, não um agendamento: a data não pode ser posterior a hoje.',
                    ]),

                Textarea::make('reactivation_reason')
                    ->label('Motivo da reativação')
                    ->required()
                    ->rows(3)
                    ->minLength(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH)
                    ->maxLength(SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH)
                    ->placeholder('Baixa registrada por engano: a unidade existe no memorial de incorporação...')
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da reativação.',
                        'min' => 'Informe o motivo com pelo menos 10 caracteres.',
                    ]),
            ])
            ->action(function (ConstructionUnitRetirement $record, array $data, Action $action): void {
                /** @var ConstructionUnit $unit */
                $unit = $this->getOwnerRecord();
                $reactivatedOn = CarbonImmutable::parse($data['reactivated_on'])->startOfDay();

                try {
                    $reactivated = app(ConstructionUnitRetirementService::class)->reactivate(
                        $record,
                        auth()->user(),
                        $reactivatedOn,
                        (string) $data['reactivation_reason'],
                    );
                } catch (ConstructionUnitRetirementException|AuthorizationException $exception) {
                    $this->forgetRetirementState();

                    Notification::make()
                        ->danger()
                        ->title('Unidade não reativada.')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    /**
                     * O modal continua aberto, com o que foi digitado: a
                     * recusa costuma pedir só outra data.
                     */
                    $action->halt();

                    return;
                }

                $this->forgetRetirementState();

                if ($reactivated->isAnnulled()) {
                    $this->notifyAffectedCompetences(
                        title: 'Baixa anulada.',
                        decision: sprintf('A baixa de %s não vale em competência nenhuma: a unidade continua compondo o Quadro de Vendas.', $reactivatedOn->format('d/m/Y')),
                        competences: app(ConstructionUnitRetirementService::class)->openCompetencesReachedBy($unit, $reactivatedOn),
                    );

                    return;
                }

                $decision = sprintf('A unidade volta a compor o Quadro de Vendas a partir de %s.', $reactivatedOn->format('d/m/Y'));

                if (app(UnitValueResolver::class)->forUnit($unit, $reactivatedOn)->isAbsent()) {
                    $decision .= sprintf(
                        ' Ela volta ao estoque sem valor de referência vigente em %s: registre o valor da unidade na aba Histórico de Valores, senão a competência fica incompleta.',
                        $reactivatedOn->format('d/m/Y'),
                    );
                }

                $this->notifyAffectedCompetences(
                    title: 'Unidade reativada.',
                    decision: $decision,
                    competences: app(ConstructionUnitRetirementService::class)->openCompetencesReachedBy($unit, $reactivatedOn),
                );
            });
    }

    /**
     * O registro completo da baixa. O conteúdo é conferido no próprio caminho
     * de renderização: o modal de uma ação forjada sobre um lazy-load
     * reaproveitado não passa pelo `visible()`, e só o schema decide o que é
     * montado.
     */
    private function viewReasonAction(): Action
    {
        return Action::make('viewReason')
            ->label('Ver motivo')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('gray')
            ->modalHeading('Motivo da baixa')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->schema(function (ConstructionUnitRetirement $record): array {
                if (! ConstructionUnitResource::canView($this->getOwnerRecord())) {
                    return [];
                }

                return [
                    TextEntry::make('registered_by')
                        ->label('Registrada por')
                        ->state($record->retiredBy?->name ?? 'Não identificado'),
                    TextEntry::make('registered_at')
                        ->label('Data do registro')
                        ->state($record->created_at === null ? '—' : BusinessTime::at($record->created_at)->format('d/m/Y H:i')),
                    TextEntry::make('retired_on')
                        ->label('Baixada a partir de')
                        ->state($record->retired_on?->format('d/m/Y') ?? '—'),
                    TextEntry::make('reason')
                        ->label('Motivo')
                        ->state((string) $record->reason)
                        ->columnSpanFull(),
                    TextEntry::make('reactivated')
                        ->label($record->isAnnulled() ? 'Anulada' : 'Reativada')
                        ->state(sprintf(
                            'A partir de %s, por %s em %s.',
                            $record->reactivated_on?->format('d/m/Y') ?? '—',
                            $record->reactivatedBy?->name ?? 'usuário não identificado',
                            $record->reactivated_at === null ? '—' : BusinessTime::at($record->reactivated_at)->format('d/m/Y H:i'),
                        ))
                        ->visible(! $record->isOpen()),
                    TextEntry::make('reactivation_reason')
                        ->label('Motivo da reativação')
                        ->state((string) $record->reactivation_reason)
                        ->visible(! $record->isOpen())
                        ->columnSpanFull(),
                ];
            });
    }

    /**
     * A decisão e as competências em aberto que ela alcança: elas percebem a
     * mudança em "Verificar alterações" e precisam ser recalculadas -- o
     * recálculo é ato deliberado, com motivo, e não acontece sozinho.
     *
     * @param  Collection<int, SalesBoardCycle>  $competences
     */
    private function notifyAffectedCompetences(string $title, string $decision, Collection $competences): void
    {
        $body = $decision;

        if ($competences->isEmpty()) {
            $body .= ' Nenhuma competência em aberto é alcançada: a próxima apuração já considera a mudança.';
        } else {
            $body .= sprintf(
                ' Competências em aberto alcançadas: %s. Em cada uma, “Verificar alterações” mostra a mudança e “Recalcular posição” gera a versão nova, que volta à construtora.',
                $competences->map(fn (SalesBoardCycle $cycle): string => $cycle->referenceMonthLabel())->implode(', '),
            );
        }

        $notification = Notification::make()
            ->success()
            ->title($title)
            ->body($body)
            ->persistent();

        $linkable = $competences
            ->filter(fn (SalesBoardCycle $cycle): bool => SalesBoardCycleResource::canView($cycle))
            ->take(3);

        if ($linkable->isNotEmpty()) {
            $notification->actions($linkable
                ->map(fn (SalesBoardCycle $cycle): Action => Action::make('openCycle'.$cycle->getKey())
                    ->label('Abrir '.$cycle->referenceMonthLabel())
                    ->url(SalesBoardCycleResource::getUrl('view', ['record' => $cycle])))
                ->values()
                ->all());
        }

        $notification->send();
    }

    /**
     * A reativação não vem antes da baixa nem alcança competência publicada.
     */
    private function earliestReactivationDate(ConstructionUnitRetirement $record): CarbonImmutable
    {
        $retiredOn = CarbonImmutable::parse($record->retired_on->toDateString());
        $earliest = $this->earliestEffectiveDate();

        return (($earliest !== null) && $earliest->greaterThan($retiredOn)) ? $earliest : $retiredOn;
    }

    private static function situationOf(ConstructionUnitRetirement $record): string
    {
        return match (true) {
            $record->isOpen() => 'Vigente',
            $record->isAnnulled() => 'Anulada',
            default => 'Encerrada',
        };
    }

    private static function businessToday(): CarbonImmutable
    {
        return CarbonImmutable::parse(BusinessTime::dateString());
    }
}
