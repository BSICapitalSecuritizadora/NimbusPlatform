<?php

namespace App\Filament\Resources\Emissions\EmissionResource\RelationManagers;

use App\Domain\PuCalculator\DTOs\PuSettlementActor;
use App\Domain\PuCalculator\DTOs\PuSettlementCorrectionData;
use App\Domain\PuCalculator\DTOs\PuSettlementData;
use App\Domain\PuCalculator\DTOs\PuSettlementResult;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationLifecycle;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementOutcome;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Domain\PuCalculator\Services\PuReconciliationExportService;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Enums\AccessPermission;
use App\Filament\Support\GuardsRelationManagerAccess;
use App\Filament\Support\PuMoneyInput;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\PuCalculator\PuDisplay;
use App\Support\PuCalculator\PuGateMessage;
use App\Support\PuCalculator\PuObligationDetail;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Obrigações financeiras do PU da emissão (Fase 8, P0-A e P0-B).
 *
 * Três fatos, nunca misturados: o cronograma contratual informado (aba
 * "Cronograma de Pagamentos"), o esperado oficial versionado (curva homologada)
 * e a liquidação registrada. Toda mutação passa pelo {@see PuSettlementService},
 * que autoriza, trava e grava só por inclusão; a tela só mostra, coleta e explica.
 */
class PuObligationsRelationManager extends RelationManager
{
    use GuardsRelationManagerAccess {
        canViewForRecord as protected guardedCanViewForRecord;
    }

    protected static string $relationship = 'puObligations';

    protected static ?string $title = 'Obrigações e Liquidações do PU';

    protected static ?string $modelLabel = 'Obrigação do PU';

    protected static ?string $pluralModelLabel = 'Obrigações do PU';

    /**
     * Recusa do registro de liquidação, avaliada uma vez por requisição.
     */
    private ?string $settlementRecordingBlocker = null;

    private bool $settlementRecordingBlockerResolved = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can(AccessPermission::PuReconciliationView->value) ?? false)
            && static::guardedCanViewForRecord($ownerRecord, $pageClass);
    }

    public function isReadOnly(): bool
    {
        // Liquidação não edita a emissão: quem tem a permissão de liquidação age
        // também a partir do dossiê (página de visualização).
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'currentCalculation:id,obligation_id,calculation_version,status,total_amount,due_date',
                    'activeSettlement',
                    'latestReconciliation:id,obligation_id,status,difference',
                ])
                ->withCount(['conflicts as open_conflicts_count' => fn (Builder $conflicts): Builder => $conflicts->open()]))
            ->heading('Obrigações e Liquidações do PU')
            ->description('Três valores diferentes, nunca misturados: o cronograma contratual (aba "Cronograma de Pagamentos", valores informados), o esperado oficial (calculado pela curva homologada vigente) e a liquidação (o que de fato foi pago). Diferença = liquidado − esperado. Não existe liquidação parcial: valor diferente é divergência.')
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('due_date')->orderBy('id'))
            ->recordTitle(fn (EmissionPuObligation $record): string => $this->identityLine($record))
            ->columns([
                TextColumn::make('obligation_type')
                    ->label('Obrigação')
                    ->extraCellAttributes(['class' => 'pu-cell-wide'])
                    ->formatStateUsing(fn (EmissionPuObligation $record): string => $record->obligation_type->label())
                    ->description(fn (EmissionPuObligation $record): string => sprintf(
                        'Data contratual %s · seq. %d%s',
                        PuDisplay::date($record->contractual_date) ?? '—',
                        (int) $record->sequence,
                        $record->isActive() ? '' : ' · superada em '.(PuDisplay::date($record->superseded_at) ?? '—'),
                    ))
                    ->wrap(),
                TextColumn::make('contractual_date')
                    ->label('Data contratual')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_date')
                    ->label('Vencimento ajustado')
                    ->date('d/m/Y')
                    ->tooltip('Data efetiva de pagamento, já ajustada pelo calendário ou pelo evento contratual.')
                    ->sortable(),
                TextColumn::make('expected_total')
                    ->label('Esperado oficial')
                    ->state(fn (EmissionPuObligation $record): string => $this->expectedLabel($record))
                    ->description(fn (EmissionPuObligation $record): ?string => $record->currentCalculation !== null
                        ? 'Versão '.$record->currentCalculation->calculation_version
                        : null)
                    ->tooltip(fn (EmissionPuObligation $record): ?string => $this->hasTrustworthyExpected($record) ? null : $record->calculation_state_reason)
                    ->color(fn (EmissionPuObligation $record): ?string => $this->hasTrustworthyExpected($record) ? null : 'gray')
                    ->alignEnd(),
                TextColumn::make('calculation_state')
                    ->label('Cálculo')
                    ->badge()
                    ->formatStateUsing(fn (EmissionPuObligation $record): string => $record->calculation_state->label())
                    ->color(fn (EmissionPuObligation $record): string => PuDisplay::calculationStateColor($record->calculation_state))
                    ->icon(fn (EmissionPuObligation $record): string => PuDisplay::calculationStateIcon($record->calculation_state))
                    ->tooltip(fn (EmissionPuObligation $record): ?string => $record->calculation_state_reason)
                    // A coluna do esperado já diz "Aguardando índice", "Sem regra de
                    // cálculo"...; esta fica disponível para quem quiser o selo.
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('settled_amount')
                    ->label('Liquidado')
                    ->state(fn (EmissionPuObligation $record): string => $record->activeSettlement !== null
                        ? (PuDisplay::money((string) $record->activeSettlement->amount) ?? '—')
                        : PuDisplay::NOT_SETTLED)
                    ->description(fn (EmissionPuObligation $record): ?string => $record->activeSettlement !== null
                        ? 'em '.PuDisplay::date($record->activeSettlement->settlement_date)
                        : null)
                    ->color(fn (EmissionPuObligation $record): ?string => $record->activeSettlement !== null ? null : 'gray')
                    ->alignEnd(),
                TextColumn::make('difference')
                    ->label('Diferença')
                    ->state(fn (EmissionPuObligation $record): string => $this->differenceLabel($record))
                    ->tooltip('Liquidado − esperado, como a conciliação gravou. Só existe quando há liquidação e esperado confiável.')
                    ->alignEnd(),
                TextColumn::make('reconciliation_status')
                    ->label('Conciliação')
                    ->badge()
                    ->formatStateUsing(fn (EmissionPuObligation $record): string => $record->reconciliation_status->label())
                    ->color(fn (EmissionPuObligation $record): string => PuDisplay::reconciliationColor($record->reconciliation_status))
                    ->icon(fn (EmissionPuObligation $record): string => PuDisplay::reconciliationIcon($record->reconciliation_status))
                    ->tooltip(fn (EmissionPuObligation $record): string => PuDisplay::reconciliationExplanation($record->reconciliation_status)),
                TextColumn::make('lifecycle_status')
                    ->label('Ciclo')
                    ->badge()
                    ->formatStateUsing(fn (EmissionPuObligation $record): string => $record->lifecycle_status->label())
                    ->color(fn (EmissionPuObligation $record): string => $record->isActive() ? 'gray' : 'warning')
                    ->tooltip(fn (EmissionPuObligation $record): ?string => $record->supersession_reason)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('lifecycle_status')
                    ->label('Ciclo de vida')
                    ->options([
                        PuObligationLifecycle::Active->value => 'Ativas',
                        PuObligationLifecycle::Superseded->value => 'Superadas',
                    ])
                    ->default(PuObligationLifecycle::Active->value),
                SelectFilter::make('reconciliation_status')
                    ->label('Conciliação')
                    ->multiple()
                    ->options(collect(PuReconciliationStatus::cases())->mapWithKeys(fn (PuReconciliationStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('calculation_state')
                    ->label('Cálculo do esperado')
                    ->multiple()
                    ->options(collect(PuObligationCalculationState::cases())->mapWithKeys(fn (PuObligationCalculationState $state): array => [$state->value => $state->label()])->all()),
                Filter::make('open_conflicts')
                    ->label('Só com conflito aguardando decisão')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('conflicts', fn (Builder $conflicts): Builder => $conflicts->open())),
            ])
            ->headerActions([
                $this->exportReconciliationAction(),
            ])
            ->recordActions([
                $this->detailsAction(),
                ActionGroup::make([
                    $this->recordSettlementAction(),
                    $this->correctSettlementAction(),
                    $this->reverseSettlementAction(),
                    $this->resolveConflictAction(),
                ])
                    ->label('Liquidação')
                    ->icon('heroicon-m-banknotes')
                    ->color('gray')
                    ->button()
                    ->size('sm'),
            ])
            ->recordAction('obligationDetails')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(fn (): string => $this->hasOfficialCurve()
                ? 'Nenhuma obrigação neste filtro'
                : 'Ainda não há obrigações oficiais')
            ->emptyStateDescription(fn (): string => $this->hasOfficialCurve()
                ? 'Ajuste os filtros para ver as obrigações superadas ou de outra situação.'
                : 'As obrigações nascem do cronograma contratual aprovado na primeira curva homologada desta emissão. Até lá, o Cronograma de Pagamentos mostra só valores informados, que não são o cálculo oficial.');
    }

    private function detailsAction(): Action
    {
        return Action::make('obligationDetails')
            ->label('Detalhes')
            ->icon('heroicon-m-eye')
            ->iconButton()
            ->tooltip('Detalhes: componentes, histórico por versão e livro de liquidações')
            ->color('gray')
            ->modalHeading(fn (EmissionPuObligation $record): string => $this->identityLine($record))
            ->modalDescription('Cronograma informado, esperado oficial, liquidação e conciliação desta obrigação, com o histórico de cada um.')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (EmissionPuObligation $record): Htmlable => view('filament.emissions.pu-obligation-details', [
                'detail' => PuObligationDetail::build($record),
            ]));
    }

    private function recordSettlementAction(): Action
    {
        return Action::make('recordSettlement')
            ->label('Registrar liquidação')
            ->icon('heroicon-m-plus-circle')
            ->color('primary')
            ->visible(fn (EmissionPuObligation $record): bool => (auth()->user()?->can(AccessPermission::PuSettlementRecord->value) ?? false)
                && $record->isActive()
                && $record->activeSettlement === null)
            ->disabled(fn (): bool => $this->settlementRecordingBlocker() !== null)
            ->tooltip(fn (): ?string => $this->settlementRecordingBlocker())
            ->modalHeading(fn (EmissionPuObligation $record): string => 'Registrar liquidação — '.$this->identityLine($record))
            ->modalDescription('Registre o que de fato foi pago. A liquidação é fechada: valor diferente do esperado fica como divergência, nunca como pagamento parcial ou saldo residual. O registro entra no livro e não é editado depois — erros se corrigem por um lançamento de correção.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Registrar liquidação')
            ->schema(fn (EmissionPuObligation $record): array => [
                Placeholder::make('reference_values')
                    ->label('Referência')
                    ->content(fn (): string => sprintf(
                        'Vencimento ajustado %s · esperado oficial: %s.%s',
                        PuDisplay::date($record->due_date) ?? '—',
                        $this->hasTrustworthyExpected($record) ? $this->expectedLabel($record) : mb_strtolower($this->expectedLabel($record)),
                        $this->hasTrustworthyExpected($record)
                            ? ' O esperado é só referência: registre o valor efetivamente pago.'
                            : ' Sem esperado confiável agora: a liquidação pode ser registrada, e a conciliação fica indeterminada até o cálculo voltar.',
                    )),
                DatePicker::make('settlement_date')
                    ->label('Data efetiva da liquidação')
                    ->required()
                    ->maxDate(fn (): string => BusinessTime::dateString())
                    ->validationMessages(['before_or_equal' => 'A liquidação registra um fato: a data não pode estar no futuro.']),
                PuMoneyInput::make('amount', 'Valor total liquidado')->required(),
                Select::make('source')
                    ->label('Origem da informação')
                    ->options($this->sourceOptions())
                    ->default(PuSettlementSource::Manual->value)
                    ->required()
                    ->helperText('A origem não muda a regra: toda liquidação é fechada. Não há importação automática da B3 nesta tela.'),
                TextInput::make('external_reference')
                    ->label('Referência externa')
                    ->maxLength(190)
                    ->helperText('Identificador do comprovante na origem. Com referência, um reenvio idêntico é reconhecido e não duplica.'),
                ...$this->componentFields(),
            ])
            ->action(function (array $data, EmissionPuObligation $record): void {
                $this->notifySettlementResult(
                    fn (): PuSettlementResult => app(PuSettlementService::class)->record(
                        new PuSettlementData(
                            emissionId: (int) $this->getOwnerRecord()->getKey(),
                            settlementDate: CarbonImmutable::parse((string) $data['settlement_date'])->toDateString(),
                            amount: (string) $data['amount'],
                            source: PuSettlementSource::from((string) $data['source']),
                            obligationId: (int) $record->getKey(),
                            externalReference: filled($data['external_reference'] ?? null) ? (string) $data['external_reference'] : null,
                            components: $this->componentsFrom($data),
                        ),
                        $this->actor(),
                    ),
                    successTitle: 'Liquidação registrada.',
                    rejectionTitle: 'Liquidação não registrada.',
                );
            });
    }

    private function correctSettlementAction(): Action
    {
        return Action::make('correctSettlement')
            ->label('Corrigir liquidação')
            ->icon('heroicon-m-pencil-square')
            ->color('warning')
            ->visible(fn (EmissionPuObligation $record): bool => (auth()->user()?->can(AccessPermission::PuSettlementCorrect->value) ?? false)
                && $record->activeSettlement !== null)
            ->modalHeading(fn (EmissionPuObligation $record): string => 'Corrigir liquidação — '.$this->identityLine($record))
            ->modalDescription('A liquidação vigente não é alterada: ela fica no livro como "corrigida" e um lançamento novo, com o motivo, passa a valer. A conciliação é refeita a partir do novo fato.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Registrar correção')
            ->fillForm(fn (EmissionPuObligation $record): array => $this->settlementFill($record->activeSettlement))
            ->schema(fn (EmissionPuObligation $record): array => [
                Hidden::make('settlement_id'),
                DatePicker::make('settlement_date')
                    ->label('Data efetiva da liquidação (corrigida)')
                    ->required()
                    ->live(onBlur: true)
                    ->maxDate(fn (): string => BusinessTime::dateString())
                    ->validationMessages(['before_or_equal' => 'A liquidação registra um fato: a data não pode estar no futuro.']),
                PuMoneyInput::make('amount', 'Valor total liquidado (corrigido)')->required()->live(onBlur: true),
                TextInput::make('external_reference')
                    ->label('Referência externa')
                    ->maxLength(190)
                    ->live(onBlur: true),
                ...$this->componentFields(live: true),
                Placeholder::make('comparison')
                    ->label('Antes e depois')
                    ->content(fn (Get $get): Htmlable => $this->correctionComparison($record->activeSettlement, $get)),
                Textarea::make('reason')
                    ->label('Motivo da correção')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Fica registrado no lançamento de correção e na trilha de auditoria.'),
                Checkbox::make('confirm')
                    ->label('Confirmo que a liquidação vigente será encerrada como corrigida e que um lançamento novo passará a valer. Nada é apagado.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme a correção para continuar.']),
            ])
            ->action(function (array $data, EmissionPuObligation $record): void {
                if (! $this->settlementBelongsTo($record, $data['settlement_id'] ?? null)) {
                    $this->notifyForeignTarget();

                    return;
                }

                $this->notifySettlementResult(
                    fn (): PuSettlementResult => app(PuSettlementService::class)->correct(
                        (int) $data['settlement_id'],
                        new PuSettlementCorrectionData(
                            settlementDate: CarbonImmutable::parse((string) $data['settlement_date'])->toDateString(),
                            amount: (string) $data['amount'],
                            components: $this->componentsFrom($data),
                            externalReference: filled($data['external_reference'] ?? null) ? (string) $data['external_reference'] : null,
                        ),
                        (string) ($data['reason'] ?? ''),
                        $this->actor(),
                    ),
                    successTitle: 'Liquidação corrigida.',
                    rejectionTitle: 'Correção não registrada.',
                );
            });
    }

    private function reverseSettlementAction(): Action
    {
        return Action::make('reverseSettlement')
            ->label('Estornar liquidação')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (EmissionPuObligation $record): bool => (auth()->user()?->can(AccessPermission::PuSettlementCorrect->value) ?? false)
                && $record->activeSettlement !== null)
            ->modalHeading(fn (EmissionPuObligation $record): string => 'Estornar liquidação — '.$this->identityLine($record))
            ->modalDescription(fn (EmissionPuObligation $record): string => sprintf(
                'A liquidação vigente (%s em %s) fica no livro como "estornada" e um lançamento de estorno é registrado. A obrigação volta a "não liquidada"; o esperado oficial e a curva não mudam.',
                PuDisplay::money((string) $record->activeSettlement?->amount) ?? '—',
                PuDisplay::date($record->activeSettlement?->settlement_date) ?? '—',
            ))
            ->modalIcon('heroicon-o-arrow-uturn-left')
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Estornar')
            ->fillForm(fn (EmissionPuObligation $record): array => ['settlement_id' => $record->activeSettlement?->id])
            ->schema([
                Hidden::make('settlement_id'),
                Textarea::make('reason')
                    ->label('Motivo do estorno')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                Checkbox::make('confirm')
                    ->label('Confirmo o estorno: a obrigação volta a não liquidada e os dois lançamentos ficam no livro.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme o estorno para continuar.']),
            ])
            ->action(function (array $data, EmissionPuObligation $record): void {
                if (! $this->settlementBelongsTo($record, $data['settlement_id'] ?? null)) {
                    $this->notifyForeignTarget();

                    return;
                }

                $this->notifySettlementResult(
                    fn (): PuSettlementResult => app(PuSettlementService::class)->reverse(
                        (int) $data['settlement_id'],
                        (string) ($data['reason'] ?? ''),
                        $this->actor(),
                    ),
                    successTitle: 'Liquidação estornada.',
                    rejectionTitle: 'Estorno não registrado.',
                );
            });
    }

    private function resolveConflictAction(): Action
    {
        return Action::make('resolveConflict')
            ->label('Decidir conflito')
            ->icon('heroicon-m-scale')
            ->color('danger')
            ->visible(fn (EmissionPuObligation $record): bool => (auth()->user()?->can(AccessPermission::PuSettlementCorrect->value) ?? false)
                && (int) ($record->open_conflicts_count ?? 0) > 0)
            ->modalHeading(fn (EmissionPuObligation $record): string => 'Decidir conflito de liquidação — '.$this->identityLine($record))
            ->modalDescription('Um fato de liquidação chegou contradizendo o que está no livro. Nada foi sobrescrito. Aceitar transforma os dados recebidos numa correção (ou na liquidação, se não houver vigente); rejeitar os descarta. Nos dois casos ficam quem decidiu, quando e por quê.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Registrar decisão')
            ->fillForm(fn (EmissionPuObligation $record): array => [
                'conflict_id' => $record->conflicts()->open()->orderBy('id')->value('id'),
            ])
            ->schema(fn (EmissionPuObligation $record): array => [
                Select::make('conflict_id')
                    ->label('Conflito')
                    ->options(fn (): array => $record->conflicts()->open()->orderBy('id')->get()
                        ->mapWithKeys(fn (EmissionPuSettlementConflict $conflict): array => [
                            $conflict->id => sprintf('#%d — %s (detectado em %s)', $conflict->id, $conflict->kind->label(), PuDisplay::dateTime($conflict->detected_at) ?? '—'),
                        ])
                        ->all())
                    ->required()
                    ->live(),
                Placeholder::make('conflict_comparison')
                    ->label('Registrado × recebido')
                    ->content(fn (Get $get): Htmlable => $this->conflictComparison($record, $get('conflict_id'))),
                Radio::make('decision')
                    ->label('Decisão')
                    ->options([
                        'accept' => 'Aceitar os dados recebidos',
                        'reject' => 'Rejeitar os dados recebidos',
                    ])
                    ->descriptions([
                        'accept' => 'Vira um lançamento de correção da liquidação vigente (a anterior fica no livro como corrigida).',
                        'reject' => 'Os dados recebidos são descartados; a liquidação vigente continua valendo.',
                    ])
                    ->required(),
                Textarea::make('reason')
                    ->label('Motivo da decisão')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                Checkbox::make('confirm')
                    ->label('Confirmo a decisão sobre este conflito. Ela fica registrada e não pode ser desfeita por esta tela.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme a decisão para continuar.']),
            ])
            ->action(function (array $data, EmissionPuObligation $record): void {
                if (! filled($data['conflict_id'] ?? null) || ! $record->conflicts()->whereKey((int) $data['conflict_id'])->exists()) {
                    $this->notifyForeignTarget();

                    return;
                }

                $this->notifySettlementResult(
                    fn (): PuSettlementResult => app(PuSettlementService::class)->resolveConflict(
                        (int) $data['conflict_id'],
                        ($data['decision'] ?? null) === 'accept',
                        (string) ($data['reason'] ?? ''),
                        $this->actor(),
                    ),
                    successTitle: ($data['decision'] ?? null) === 'accept' ? 'Conflito aceito como correção.' : 'Conflito rejeitado.',
                    rejectionTitle: 'Decisão não registrada.',
                );
            });
    }

    private function exportReconciliationAction(): Action
    {
        return Action::make('exportReconciliation')
            ->label('Exportar conciliação (CSV)')
            ->icon('heroicon-m-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can(AccessPermission::PuReconciliationExport->value) ?? false)
            ->action(function (): ?StreamedResponse {
                /** @var Emission $emission */
                $emission = $this->getOwnerRecord();

                try {
                    $rows = app(PuReconciliationExportService::class)->rows($emission, $this->currentUser());
                } catch (AuthorizationException $exception) {
                    Notification::make()->title('Exportação não autorizada.')->body($exception->getMessage())->danger()->send();

                    return null;
                }

                return response()->streamDownload(function () use ($rows): void {
                    $handle = fopen('php://output', 'wb');

                    if ($handle === false) {
                        return;
                    }

                    fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));
                    fputcsv($handle, $rows === [] ? ['obligation_id'] : array_keys($rows[0]));

                    foreach ($rows as $row) {
                        fputcsv($handle, array_values($row));
                    }

                    fclose($handle);
                }, sprintf('conciliacao-pu-emissao-%d-%s.csv', (int) $emission->getKey(), BusinessTime::dateString()), [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            });
    }

    /**
     * Campos opcionais dos componentes que a ORIGEM informou -- nunca preenchidos
     * a partir do total.
     *
     * @return list<Toggle|Fieldset>
     */
    private function componentFields(bool $live = false): array
    {
        return [
            Toggle::make('has_components')
                ->label('A origem informou os componentes (juros, amortização...)')
                ->helperText('Deixe desligado quando a origem só informou o total: o sistema nunca rateia o total entre componentes.')
                ->live(),
            Fieldset::make('Componentes informados pela origem')
                ->statePath('components')
                ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                ->visible(fn (Get $get): bool => (bool) $get('has_components'))
                ->schema(array_map(
                    fn (PuObligationComponent $component): TextInput => $live
                        ? PuMoneyInput::make($component->value, $component->label())->live(onBlur: true)
                        : PuMoneyInput::make($component->value, $component->label()),
                    PuObligationComponent::cases(),
                )),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>|null
     */
    private function componentsFrom(array $data): ?array
    {
        if (! (bool) ($data['has_components'] ?? false)) {
            return null;
        }

        $components = array_filter(
            (array) ($data['components'] ?? []),
            fn (mixed $value): bool => is_string($value) && $value !== '',
        );

        // Ligado e vazio: o domínio recusa com motivo ("informe os componentes da
        // origem ou só o total") -- a tela não decide por ele.
        return $components;
    }

    /**
     * @return array<string, mixed>
     */
    private function settlementFill(?EmissionPuSettlement $settlement): array
    {
        if (! $settlement instanceof EmissionPuSettlement) {
            return [];
        }

        $provided = $settlement->providedComponents();

        return [
            'settlement_id' => $settlement->id,
            'settlement_date' => $settlement->settlement_date?->toDateString(),
            'amount' => PuMoneyInput::fill((string) $settlement->amount),
            'external_reference' => $settlement->external_reference,
            'has_components' => $provided !== null,
            'components' => collect($provided ?? [])->map(fn (string $value): ?string => PuMoneyInput::fill($value))->all(),
        ];
    }

    private function correctionComparison(?EmissionPuSettlement $settlement, Get $get): Htmlable
    {
        $newComponents = (bool) $get('has_components')
            ? array_filter((array) $get('components'), fn (mixed $value): bool => filled($value))
            : [];
        $currentComponents = $settlement?->providedComponents() ?? [];
        $rows = [
            ['Data da liquidação', PuDisplay::date($settlement?->settlement_date) ?? '—', $this->displayDate($get('settlement_date'))],
            ['Valor total', PuDisplay::money((string) $settlement?->amount) ?? '—', PuDisplay::money(PuMoneyInput::parse($get('amount'))) ?? 'inválido ou vazio'],
            ['Referência externa', $settlement?->external_reference ?? '—', filled($get('external_reference')) ? (string) $get('external_reference') : '—'],
            ['Componentes', $this->componentsSummary($currentComponents), $this->componentsSummary(array_map(fn (mixed $value): ?string => PuMoneyInput::parse($value), $newComponents))],
        ];

        return new HtmlString(view('filament.emissions.pu-before-after', ['rows' => $rows])->render());
    }

    private function conflictComparison(EmissionPuObligation $obligation, mixed $conflictId): Htmlable
    {
        $conflict = filled($conflictId)
            ? $obligation->conflicts()->with('existingSettlement')->whereKey((int) $conflictId)->first()
            : null;

        if (! $conflict instanceof EmissionPuSettlementConflict) {
            return new HtmlString('<p class="text-muted">Escolha o conflito.</p>');
        }

        $existing = $conflict->existingSettlement;
        $incoming = (array) $conflict->incoming_payload;
        $rows = [
            ['Motivo do conflito', $conflict->kind->label(), ''],
            ['Data da liquidação', PuDisplay::date($existing?->settlement_date) ?? '—', $this->displayDate($incoming['settlement_date'] ?? null)],
            ['Valor total', PuDisplay::money((string) $existing?->amount) ?? '—', PuDisplay::money($incoming['amount'] ?? null) ?? '—'],
            ['Origem', $existing?->source?->label() ?? '—', $conflict->source?->label() ?? '—'],
            ['Referência externa', $existing?->external_reference ?? '—', $conflict->external_reference ?? '—'],
            ['Componentes', $this->componentsSummary($existing?->providedComponents() ?? []), $this->componentsSummary(is_array($incoming['components'] ?? null) ? $incoming['components'] : [])],
            ['Registrado / detectado em', PuDisplay::dateTime($existing?->recorded_at) ?? '—', PuDisplay::dateTime($conflict->detected_at) ?? '—'],
        ];

        return new HtmlString(view('filament.emissions.pu-before-after', [
            'rows' => $rows,
            'beforeLabel' => $existing !== null ? sprintf('No livro (#%d)', $existing->id) : 'No livro (sem liquidação vigente)',
            'afterLabel' => 'Recebido',
        ])->render());
    }

    /**
     * @param  array<string, mixed>  $components
     */
    private function componentsSummary(array $components): string
    {
        $parts = [];

        foreach ($components as $component => $value) {
            if ($value === null) {
                continue;
            }

            $parts[] = sprintf('%s %s', PuObligationComponent::tryFrom((string) $component)?->label() ?? (string) $component, PuDisplay::money((string) $value) ?? '—');
        }

        return $parts === [] ? 'Só o total' : implode('; ', $parts);
    }

    /**
     * @param  callable(): PuSettlementResult  $operation
     */
    private function notifySettlementResult(callable $operation, string $successTitle, string $rejectionTitle): void
    {
        try {
            $result = $operation();
        } catch (AuthorizationException $exception) {
            Notification::make()
                ->title('Operação não autorizada.')
                ->body($exception->getMessage().' Nada foi gravado.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $reconciliation = $result->obligation?->reconciliation_status?->label();

        match ($result->outcome) {
            PuSettlementOutcome::Recorded => Notification::make()
                ->title($successTitle)
                ->body(trim(sprintf(
                    '%s%s',
                    $result->settlement !== null ? sprintf('Lançamento #%d no livro. ', $result->settlement->id) : '',
                    $reconciliation !== null ? 'Conciliação: '.mb_strtolower($reconciliation).'.' : '',
                )))
                ->success()
                ->send(),
            PuSettlementOutcome::Duplicate => Notification::make()
                ->title('Liquidação já registrada.')
                ->body('Os mesmos dados já estavam no livro: nada foi gravado de novo.')
                ->info()
                ->send(),
            PuSettlementOutcome::Conflict => Notification::make()
                ->title('Conflito de liquidação aberto.')
                ->body(sprintf(
                    '%s. Nada foi sobrescrito: o conflito aguarda a decisão de quem pode corrigir liquidações. Se outra pessoa registrou a liquidação enquanto este formulário estava aberto, confira o detalhe da obrigação.',
                    $result->reason ?? 'Os dados recebidos contradizem o livro',
                ))
                ->warning()
                ->persistent()
                ->send(),
            PuSettlementOutcome::Rejected => Notification::make()
                ->title($rejectionTitle)
                ->body(sprintf(
                    '%s Nada foi gravado. Se outra pessoa alterou esta liquidação enquanto o formulário estava aberto, reabra o detalhe e confira o livro.',
                    PuGateMessage::withoutCode((string) $result->reason),
                ))
                ->danger()
                ->persistent()
                ->send(),
        };
    }

    /**
     * O lançamento que o formulário capturou ao abrir é desta obrigação? O id vem
     * do estado do componente (manipulável); o domínio confere se ele ainda é o
     * vigente, a tela confere que ele é desta obrigação.
     */
    private function settlementBelongsTo(EmissionPuObligation $record, mixed $settlementId): bool
    {
        return filled($settlementId)
            && EmissionPuSettlement::query()->whereKey((int) $settlementId)->where('obligation_id', $record->getKey())->exists();
    }

    private function notifyForeignTarget(): void
    {
        Notification::make()
            ->title('Operação recusada.')
            ->body('O lançamento ou o conflito informado não pertence a esta obrigação. Nada foi gravado. Feche o formulário e abra a ação na linha da obrigação correta.')
            ->danger()
            ->persistent()
            ->send();
    }

    private function settlementRecordingBlocker(): ?string
    {
        if (! $this->settlementRecordingBlockerResolved) {
            $this->settlementRecordingBlockerResolved = true;

            try {
                app(PuOperationalActivationPolicy::class)->assertSettlementRecording((int) $this->getOwnerRecord()->getKey());
            } catch (PuOperationalActivationException $refusal) {
                $this->settlementRecordingBlocker = PuGateMessage::activationRefusal($refusal, 'Registro de liquidação');
            }
        }

        return $this->settlementRecordingBlocker;
    }

    private function hasOfficialCurve(): bool
    {
        return EmissionPuCurveVersion::query()->where('emission_id', $this->getOwnerRecord()->getKey())->official()->exists();
    }

    private function hasTrustworthyExpected(EmissionPuObligation $record): bool
    {
        return $record->calculation_state === PuObligationCalculationState::Calculated
            && ($record->currentCalculation?->isComplete() ?? false);
    }

    private function expectedLabel(EmissionPuObligation $record): string
    {
        return $this->hasTrustworthyExpected($record)
            ? (PuDisplay::money((string) $record->currentCalculation->total_amount) ?? '—')
            : PuDisplay::unavailableExpected($record->calculation_state);
    }

    private function differenceLabel(EmissionPuObligation $record): string
    {
        $difference = $record->latestReconciliation?->difference;

        if ($difference === null || ! in_array($record->reconciliation_status, [PuReconciliationStatus::Matched, PuReconciliationStatus::Divergent], true)) {
            return '—';
        }

        return PuDisplay::signedMoney((string) $difference) ?? '—';
    }

    private function identityLine(EmissionPuObligation $record): string
    {
        return sprintf(
            '%s de %s (seq. %d)',
            $record->obligation_type->label(),
            PuDisplay::date($record->contractual_date) ?? '—',
            (int) $record->sequence,
        );
    }

    private function displayDate(mixed $value): string
    {
        if (blank($value)) {
            return '—';
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * @return array<string, string>
     */
    private function sourceOptions(): array
    {
        return [
            PuSettlementSource::Manual->value => PuSettlementSource::Manual->label(),
            PuSettlementSource::Bank->value => PuSettlementSource::Bank->label().' (comprovante do banco)',
            PuSettlementSource::B3->value => PuSettlementSource::B3->label().' (informação da B3 lançada manualmente)',
        ];
    }

    private function actor(): PuSettlementActor
    {
        return PuSettlementActor::user($this->currentUser());
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
