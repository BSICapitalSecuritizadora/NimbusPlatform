<?php

namespace App\Filament\Resources\PaymentWorkspaces\Tables;

use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Support\AnchoredFilterDropdown;
use App\Models\Measurement;
use App\Models\User;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementWorkflow;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentWorkspaceTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Measurement $record): string => MeasurementResource::getUrl('view', ['record' => $record]))
            ->searchPlaceholder('Buscar operação, emissão, medição, empreendimento ou método...')
            ->searchDebounce('400ms')
            ->defaultSort('updated_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Nenhum item no workspace de pagamentos')
            ->emptyStateDescription('Não há medições de pagamento no seu escopo ou no recorte de filtros atual.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->columns([
                TextColumn::make('operation.title')
                    ->label('Operação')
                    ->description(fn (Measurement $record): string => collect([
                        $record->operation?->code,
                        $record->operation?->emission?->name,
                    ])->filter()->implode(' · '))
                    ->weight('semibold')
                    ->wrap()
                    ->lineClamp(2)
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::readModel()
                        ->applySearch($query, $search)),

                TextColumn::make('id')
                    ->label('Medição')
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->description(fn (Measurement $record): string => $record->reference_month?->format('m/Y') ?? 'Sem competência')
                    ->sortable(),

                TextColumn::make('plan_sets')
                    ->label('Planos / Empreendimentos')
                    ->state(function (Measurement $record): string {
                        $labels = self::readModel()->planSetLabels($record);

                        return $labels->isEmpty() ? '—' : $labels->implode(' · ');
                    })
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Measurement $record): ?string => self::readModel()
                        ->planSetLabels($record)
                        ->pipe(fn ($labels): ?string => $labels->isNotEmpty() ? $labels->implode(', ') : null)),

                TextColumn::make('current_stage')
                    ->label('Etapa')
                    ->badge()
                    ->state(fn (Measurement $record): string => self::readModel()->stageLabel($record))
                    ->color(fn (Measurement $record): string => MeasurementWorkflow::STAGE_COLORS[
                        app(MeasurementWorkflow::class)->unifiedStage($record)
                    ] ?? 'gray'),

                TextColumn::make('operation.paymentManager.name')
                    ->label('Gestor de Pagamento')
                    ->placeholder('Não definido')
                    ->description(fn (Measurement $record): string => collect([
                        $record->operation?->paymentReceiptUploader?->name
                            ? 'Comprovantes: '.$record->operation->paymentReceiptUploader->name
                            : null,
                        $record->operation?->paymentFinalizer?->name
                            ? 'Finalização: '.$record->operation->paymentFinalizer->name
                            : null,
                    ])->filter()->implode(' · '))
                    ->wrap(),

                TextColumn::make('payment_delegate')
                    ->label('Delegações ativas')
                    ->state(function (Measurement $record): string {
                        $viewer = auth()->user();

                        return $viewer instanceof User
                            ? self::readModel()->operationalDelegationLabel($record, $viewer)
                            : '—';
                    })
                    ->icon(fn (string $state): ?string => $state === 'Sem delegação ativa' ? null : 'heroicon-m-arrow-path')
                    ->color(fn (string $state): string => $state === 'Sem delegação ativa' ? 'gray' : 'info')
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('payments_sum_amount')
                    ->label('Pagamentos')
                    ->money('BRL', locale: 'pt_BR')
                    ->placeholder('R$ 0,00')
                    ->description(fn (Measurement $record): string => sprintf(
                        '%d %s',
                        (int) $record->payments_count,
                        (int) $record->payments_count === 1 ? 'pagamento registrado' : 'pagamentos registrados',
                    ))
                    ->sortable(),

                TextColumn::make('receipt_status')
                    ->label('Comprovantes')
                    ->badge()
                    ->state(fn (Measurement $record): string => self::readModel()->receiptStatusLabel($record))
                    ->color(fn (Measurement $record): string => match (true) {
                        (int) $record->payments_count === 0 => 'gray',
                        (int) $record->payments_with_receipt_count === (int) $record->payments_count => 'success',
                        default => 'warning',
                    }),

                TextColumn::make('operational_pending')
                    ->label('Pendência atual')
                    ->badge()
                    ->state(fn (Measurement $record): string => self::readModel()->pendingLabel($record))
                    ->color(fn (Measurement $record): string => match ($record->status) {
                        'awaiting_receipt', 'paused' => 'warning',
                        'approved', 'finalized' => 'success',
                        default => 'info',
                    }),

                TextColumn::make('sla')
                    ->label('SLA')
                    ->badge()
                    ->state(fn (Measurement $record): string => self::readModel()->slaLabel($record))
                    ->description(fn (Measurement $record): ?string => self::readModel()->slaDescription($record))
                    ->color(fn (Measurement $record): string => self::readModel()->slaColor($record)),

                TextColumn::make('updated_at')
                    ->label('Atualização')
                    ->dateTime('d/m/Y · H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filtersFormWidth(Width::ExtraLarge)
            ->filtersFormMaxHeight('680px')
            ->filters(self::filters())
            ->recordActions([
                Action::make('view_measurement')
                    ->label('Visualizar medição')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Measurement $record): string => MeasurementResource::getUrl('view', ['record' => $record])),

                ActionGroup::make([
                    Action::make('register_payments')
                        ->label('Registrar pagamentos')
                        ->icon('heroicon-m-banknotes')
                        ->visible(fn (Measurement $record): bool => self::workflowCan('canRegisterPayment', $record))
                        ->authorize(fn (Measurement $record): bool => self::workflowCan('canRegisterPayment', $record))
                        ->url(fn (Measurement $record): string => MeasurementResource::getUrl('view', ['record' => $record])),

                    Action::make('approve_payment_stage')
                        ->label('Aprovar etapa Pagamento')
                        ->icon('heroicon-m-check-circle')
                        ->visible(fn (Measurement $record): bool => app(MeasurementWorkflow::class)->unifiedStage($record) === MeasurementWorkflow::STAGE_PAYMENT
                            && self::workflowCan('canApprove', $record))
                        ->authorize(fn (Measurement $record): bool => self::workflowCan('canApprove', $record))
                        ->url(fn (Measurement $record): string => MeasurementResource::getUrl('view', ['record' => $record])),

                    Action::make('attach_receipt')
                        ->label('Anexar comprovante')
                        ->icon('heroicon-m-paper-clip')
                        ->visible(fn (Measurement $record): bool => self::workflowCan('canManageReceipts', $record)
                            && (int) $record->payments_with_receipt_count < (int) $record->payments_count)
                        ->authorize(fn (Measurement $record): bool => self::workflowCan('canManageReceipts', $record))
                        ->url(fn (Measurement $record): string => MeasurementResource::getUrl('view', ['record' => $record])),

                    Action::make('view_receipts')
                        ->label('Visualizar comprovantes')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->visible(fn (Measurement $record): bool => (int) $record->payments_with_receipt_count > 0)
                        ->authorize(fn (Measurement $record): bool => MeasurementResource::canView($record))
                        ->url(fn (Measurement $record): string => MeasurementResource::getUrl('view', ['record' => $record])),

                    Action::make('finalize')
                        ->label('Finalizar medição')
                        ->icon('heroicon-m-flag')
                        ->visible(fn (Measurement $record): bool => self::workflowCan('canFinalize', $record))
                        ->authorize(fn (Measurement $record): bool => self::workflowCan('canFinalize', $record))
                        ->url(fn (Measurement $record): string => MeasurementResource::getUrl('view', ['record' => $record])),
                ])
                    ->label('Ações disponíveis')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->visible(fn (Measurement $record): bool => self::hasWorkflowAction($record)),
            ])
            ->toolbarActions([]);
    }

    /** @return array<int, Filter|SelectFilter> */
    private static function filters(): array
    {
        return [
            Filter::make('competence_period')
                ->label('Competência')
                ->schema([
                    DatePicker::make('from')->label('Competência inicial')->native(false),
                    DatePicker::make('to')->label('Competência final')->native(false),
                ])
                ->columns(2)
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(filled($data['from'] ?? null), fn (Builder $measurements): Builder => $measurements
                        ->whereDate('reference_month', '>=', $data['from']))
                    ->when(filled($data['to'] ?? null), fn (Builder $measurements): Builder => $measurements
                        ->whereDate('reference_month', '<=', $data['to']))),

            SelectFilter::make('operation_id')
                ->label('Operação')
                ->options(fn (): array => self::viewerOptions('operationOptions'))
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(filled($data['value'] ?? null), fn (Builder $measurements): Builder => $measurements
                        ->where('operation_id', (int) $data['value'])))
                ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

            SelectFilter::make('emission_id')
                ->label('Emissão')
                ->options(fn (): array => self::viewerOptions('emissionOptions'))
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(filled($data['value'] ?? null), fn (Builder $measurements): Builder => $measurements
                        ->whereHas('operation', fn (Builder $operations): Builder => $operations
                            ->where('emission_id', (int) $data['value']))))
                ->modifyFormFieldUsing(AnchoredFilterDropdown::modifyFormField()),

            SelectFilter::make('responsible_user_id')
                ->label('Responsável')
                ->options(fn (): array => self::viewerOptions('responsibleOptions'))
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? self::readModel()->applyResponsibleFilter($query, (int) $data['value'])
                    : $query),

            SelectFilter::make('payment_manager_id')
                ->label('Gestor de Pagamento')
                ->options(fn (): array => self::viewerOptions('paymentManagerOptions'))
                ->searchable()
                ->query(function (Builder $query, array $data): Builder {
                    return self::readModel()
                        ->applyPaymentManagerFilter($query, filled($data['value'] ?? null) ? (int) $data['value'] : null);
                }),

            SelectFilter::make('payment_delegate_id')
                ->label('Delegado operacional atual')
                ->options(fn (): array => self::viewerOptions('operationalDelegateOptions'))
                ->searchable()
                ->query(function (Builder $query, array $data): Builder {
                    $viewer = auth()->user();

                    return $viewer instanceof User
                        ? self::readModel()->applyOperationalDelegateFilter(
                            $query,
                            $viewer,
                            filled($data['value'] ?? null) ? (int) $data['value'] : null,
                        )
                        : $query->whereRaw('1 = 0');
                }),

            SelectFilter::make('status')
                ->label('Status')
                ->options(Measurement::STATUS_OPTIONS),

            SelectFilter::make('stage')
                ->label('Etapa')
                ->options(MeasurementWorkflow::STAGE_LABELS)
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(filled($data['value'] ?? null), fn (Builder $measurements): Builder => $measurements
                        ->where('current_stage', (int) $data['value']))),

            SelectFilter::make('sla_status')
                ->label('SLA')
                ->options([
                    MeasurementSlaService::STATUS_ON_TIME => 'No prazo',
                    MeasurementSlaService::STATUS_APPROACHING => 'Em atenção',
                    MeasurementSlaService::STATUS_OVERDUE => 'Vencido',
                    MeasurementSlaService::STATUS_PAUSED => 'Pausado',
                    MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE => 'Calendário indisponível',
                ])
                ->query(fn (Builder $query, array $data): Builder => self::readModel()
                    ->applySlaFilter($query, $data['value'] ?? null)),

            SelectFilter::make('receipt_state')
                ->label('Comprovantes')
                ->options([
                    'complete' => 'Todos anexados',
                    'pending' => 'Com pendência',
                    'none' => 'Sem pagamentos registrados',
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'complete' => $query
                            ->whereHas('payments')
                            ->whereDoesntHave('payments', fn (Builder $payments): Builder => $payments
                                ->withoutReceipt()),
                        'pending' => $query->whereHas('payments', fn (Builder $payments): Builder => $payments
                            ->withoutReceipt()),
                        'none' => $query->whereDoesntHave('payments'),
                        default => $query,
                    };
                }),

            SelectFilter::make('operational_pending')
                ->label('Pendência atual')
                ->options([
                    'awaiting_registration' => 'Aguardando registro',
                    'awaiting_approval' => 'Aguardando aprovação',
                    'awaiting_receipt' => 'Aguardando comprovante',
                    'ready_to_finalize' => 'Pronta para finalizar',
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'awaiting_registration' => $query->where('status', 'awaiting_payment')->whereDoesntHave('payments'),
                        'awaiting_approval' => $query
                            ->where('status', 'awaiting_payment')
                            ->whereHas('payments')
                            ->whereHas('reviews', fn (Builder $reviews): Builder => $reviews
                                ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
                                ->where('status', 'pending')),
                        'awaiting_receipt' => $query->where('status', 'awaiting_receipt'),
                        'ready_to_finalize' => $query->where('status', 'approved'),
                        default => $query,
                    };
                }),

            Filter::make('payment_period')
                ->label('Período do pagamento')
                ->schema([
                    DatePicker::make('from')->label('Pago a partir de')->native(false),
                    DatePicker::make('to')->label('Pago até')->native(false),
                ])
                ->columns(2)
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when(
                        filled($data['from'] ?? null) || filled($data['to'] ?? null),
                        fn (Builder $measurements): Builder => $measurements->whereHas(
                            'payments',
                            fn (Builder $payments): Builder => $payments
                                ->when(filled($data['from'] ?? null), fn (Builder $dates): Builder => $dates
                                    ->whereDate('pay_date', '>=', $data['from']))
                                ->when(filled($data['to'] ?? null), fn (Builder $dates): Builder => $dates
                                    ->whereDate('pay_date', '<=', $data['to'])),
                        ),
                    )),

            SelectFilter::make('assignment')
                ->label('Atuação no escopo')
                ->options([
                    'direct' => 'Participação direta',
                    'delegated' => 'Visível por delegação',
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $viewer = auth()->user();

                    return $viewer instanceof User
                        ? self::readModel()->applyAssignmentFilter($query, $viewer, $data['value'] ?? null)
                        : $query->whereRaw('1 = 0');
                }),
        ];
    }

    private static function workflowCan(string $method, Measurement $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(MeasurementWorkflow::class)->{$method}($record, $actor);
    }

    private static function hasWorkflowAction(Measurement $record): bool
    {
        return self::workflowCan('canRegisterPayment', $record)
            || (app(MeasurementWorkflow::class)->unifiedStage($record) === MeasurementWorkflow::STAGE_PAYMENT
                && self::workflowCan('canApprove', $record))
            || self::workflowCan('canManageReceipts', $record)
            || self::workflowCan('canFinalize', $record)
            || (int) $record->payments_with_receipt_count > 0;
    }

    /** @return array<int, string> */
    private static function viewerOptions(string $method): array
    {
        $viewer = auth()->user();

        return $viewer instanceof User
            ? self::readModel()->{$method}($viewer)
            : [];
    }

    private static function readModel(): MeasurementOperationalReadModel
    {
        return once(fn (): MeasurementOperationalReadModel => app(MeasurementOperationalReadModel::class));
    }
}
