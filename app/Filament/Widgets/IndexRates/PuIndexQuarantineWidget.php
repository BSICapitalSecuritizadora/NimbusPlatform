<?php

namespace App\Filament\Widgets\IndexRates;

use App\Domain\PuCalculator\Enums\PuIndexAnomalyType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexQuarantineStatus;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\IndexRateQuarantineReviewService;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\IndexRate;
use App\Models\IndexRateQuarantine;
use App\Models\User;
use App\Support\PuCalculator\PuDisplay;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Observações que a sincronização recebeu e não aceitou (Fase 8.1), na página de
 * Índices, ao lado da "Revisão de observações" e do histórico de sincronização.
 *
 * A tabela só lê a quarentena. Aprovar e recusar chamam a revisão governada
 * ({@see IndexRateQuarantineReviewService}), que confere de novo a permissão, a
 * situação pendente e o valor registrado que a pessoa viu; aprovar grava pelo
 * registro de observação (data sem valor) ou pela correção (data com valor),
 * nunca direto no índice.
 */
class PuIndexQuarantineWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Observações retidas para revisão';

    public function table(Table $table): Table
    {
        return $table
            ->query(IndexRateQuarantine::query()->with(['decidedBy:id,name']))
            ->description('O que a fonte (BCB/SGS) entregou e não entrou no índice: salto suspeito, valor fora do domínio, divergência do registrado ou resposta malformada. Retida não é índice realizado: não estende curva nem atende a divulgação esperada. Só a decisão governada grava.')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('rate_date')
                    ->label('Observação')
                    ->state(fn (IndexRateQuarantine $record): string => sprintf('%s %s', $record->indexer, PuDisplay::date($record->rate_date) ?? 'sem data'))
                    ->description(fn (IndexRateQuarantine $record): string => sprintf(
                        '%s · detectada %s · %d×',
                        $record->source,
                        PuDisplay::dateTime($record->first_detected_at) ?? '—',
                        $record->occurrences,
                    ))
                    ->tooltip(fn (IndexRateQuarantine $record): string => sprintf(
                        'Série %s. Última detecção em %s%s.',
                        $record->external_series_code ?? '—',
                        PuDisplay::dateTime($record->last_detected_at) ?? '—',
                        $record->last_sync_attempt_id !== null ? ' (sincronização #'.$record->last_sync_attempt_id.')' : '',
                    ))
                    ->wrap(),
                TextColumn::make('received_value')
                    ->label('Valor recebido')
                    ->state(fn (IndexRateQuarantine $record): string => $record->received_value !== null ? PuDisplay::decimal((string) $record->received_value, 8) : '—')
                    ->description(fn (IndexRateQuarantine $record): ?string => filled($record->raw_value) ? 'Fonte: '.Str::limit((string) $record->raw_value, 24) : null)
                    ->tooltip(fn (IndexRateQuarantine $record): ?string => filled($record->raw_value) ? 'Como a fonte informou: '.$record->raw_value : null)
                    ->fontFamily('mono')
                    ->alignEnd()
                    ->wrap(),
                TextColumn::make('reference_value')
                    ->label('Referência')
                    ->state(fn (IndexRateQuarantine $record): string => $record->reference_value !== null ? PuDisplay::decimal((string) $record->reference_value, 8) : '—')
                    ->description(fn (IndexRateQuarantine $record): ?string => match (true) {
                        $record->reference_value === null => null,
                        $record->anomaly_type === PuIndexAnomalyType::ImplausibleJump && ($record->reference_date === null || ! $record->reference_date->equalTo($record->rate_date)) => 'Anterior'.($record->reference_date !== null ? ' ('.PuDisplay::date($record->reference_date).')' : ''),
                        default => 'Registrado na data',
                    })
                    ->fontFamily('mono')
                    ->alignEnd(),
                TextColumn::make('anomaly_type')
                    ->label('Motivo')
                    ->badge()
                    ->formatStateUsing(fn (IndexRateQuarantine $record): string => $record->anomaly_type->label())
                    ->color(fn (IndexRateQuarantine $record): string => $record->anomaly_type === PuIndexAnomalyType::ValueConflict ? 'danger' : 'warning')
                    ->description(fn (IndexRateQuarantine $record): string => Str::limit($record->reason, 90))
                    ->tooltip(fn (IndexRateQuarantine $record): string => $record->reason)
                    ->wrap()
                    ->extraCellAttributes(['class' => 'min-w-[210px]']),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (IndexRateQuarantine $record): string => $record->status->label())
                    ->color(fn (IndexRateQuarantine $record): string => $record->status->color())
                    ->description(fn (IndexRateQuarantine $record): ?string => self::decisionSummary($record))
                    ->tooltip(fn (IndexRateQuarantine $record): ?string => filled($record->decision_reason) ? (string) $record->decision_reason : null)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(collect(PuIndexQuarantineStatus::cases())->mapWithKeys(fn (PuIndexQuarantineStatus $status): array => [$status->value => $status->label()])->all())
                    ->default(PuIndexQuarantineStatus::PendingReview->value),
                SelectFilter::make('indexer')
                    ->label('Índice')
                    ->options(['CDI' => 'CDI', 'IPCA' => 'IPCA']),
                SelectFilter::make('anomaly_type')
                    ->label('Motivo')
                    ->options(collect(PuIndexAnomalyType::cases())->mapWithKeys(fn (PuIndexAnomalyType $type): array => [$type->value => $type->label()])->all()),
            ])
            ->recordActions([
                $this->approveAction(),
                $this->rejectAction(),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Nenhuma observação retida nesta situação')
            ->emptyStateDescription('A sincronização guarda aqui o que a fonte entregou e não entrou no índice. Sem pendência, nada aguarda decisão.');
    }

    private function approveAction(): Action
    {
        return Action::make('approveQuarantine')
            ->label('Aprovar')
            ->icon('heroicon-m-check')
            ->color('warning')
            ->visible(fn (IndexRateQuarantine $record): bool => $record->isPending()
                && $record->anomaly_type->canBeApproved()
                && $record->received_value !== null
                && self::canDecide())
            ->modalHeading(fn (IndexRateQuarantine $record): string => sprintf('Aprovar %s de %s', $record->indexer, PuDisplay::date($record->rate_date) ?? '—'))
            ->modalDescription('Aprovar grava o valor recebido pelo caminho governado e fica na trilha com o seu nome e o motivo. Confira o valor na fonte antes.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Aprovar e gravar')
            ->modalContent(fn (IndexRateQuarantine $record): Htmlable => view('filament.index-rates.quarantine-decision', self::decisionContext($record)))
            ->fillForm(fn (IndexRateQuarantine $record): array => ['accepted_value_seen' => self::acceptedValue($record)])
            ->schema([
                Hidden::make('accepted_value_seen'),
                Textarea::make('reason')
                    ->label('Motivo da aprovação')
                    ->helperText('Ex.: conferido no SGS em <data>; o valor publicado é este.')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
                Checkbox::make('confirm')
                    ->label('Conferi o valor na fonte e entendo que ele passa a valer como índice realizado.')
                    ->accepted()
                    ->validationMessages(['accepted' => 'Confirme a conferência na fonte para aprovar.']),
            ])
            ->action(function (IndexRateQuarantine $record, array $data): void {
                /** @var User $user */
                $user = auth()->user();

                try {
                    $decided = app(IndexRateQuarantineReviewService::class)->approve(
                        $user,
                        (int) $record->getKey(),
                        (string) ($data['reason'] ?? ''),
                        filled($data['accepted_value_seen'] ?? null) ? (string) $data['accepted_value_seen'] : null,
                    );
                } catch (AuthorizationException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('Aprovação não registrada.')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Observação aprovada.')
                    ->body($decided->resulting_correction_id !== null
                        ? sprintf('Registrada pela correção #%d: as curvas governadas que usavam a data aguardam reprocessamento.', $decided->resulting_correction_id)
                        : sprintf('Registrada como observação realizada de %s.', PuDisplay::date($decided->rate_date)))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('rejectQuarantine')
            ->label('Recusar')
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->visible(fn (IndexRateQuarantine $record): bool => $record->isPending() && self::canDecide())
            ->modalHeading(fn (IndexRateQuarantine $record): string => sprintf('Recusar %s de %s', $record->indexer, PuDisplay::date($record->rate_date) ?? 'entrada sem data'))
            ->modalDescription('Recusar encerra a revisão sem gravar nada no índice. A evidência continua aqui, e a mesma resposta da fonte não volta para a fila. Se a data ficar sem observação, o índice segue ausente até o valor certo entrar pela importação ou pela correção.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Recusar')
            ->modalContent(fn (IndexRateQuarantine $record): Htmlable => view('filament.index-rates.quarantine-decision', self::decisionContext($record)))
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo da recusa')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (IndexRateQuarantine $record, array $data): void {
                /** @var User $user */
                $user = auth()->user();

                try {
                    app(IndexRateQuarantineReviewService::class)->reject($user, (int) $record->getKey(), (string) ($data['reason'] ?? ''));
                } catch (AuthorizationException|InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('Recusa não registrada.')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Observação recusada.')
                    ->body('Nada foi gravado no índice; a evidência e o motivo ficam registrados.')
                    ->success()
                    ->send();
            });
    }

    private static function canDecide(): bool
    {
        return auth()->user()?->can(AccessPermission::PuIndexCorrect->value) ?? false;
    }

    private static function acceptedValue(IndexRateQuarantine $record): ?string
    {
        if ($record->rate_date === null) {
            return null;
        }

        $current = IndexRate::query()->where('indexer', $record->indexer)->whereDate('rate_date', $record->rate_date->toDateString())->first();

        return $current instanceof IndexRate && ! $current->isProjectedRate() ? (string) $current->rate_value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decisionContext(IndexRateQuarantine $record): array
    {
        $current = $record->rate_date !== null
            ? IndexRate::query()->where('indexer', $record->indexer)->whereDate('rate_date', $record->rate_date->toDateString())->first()
            : null;
        $affected = [];

        if ($current instanceof IndexRate && ! $current->isProjectedRate() && in_array($record->indexer, [PuIndexer::Cdi->value, PuIndexer::Ipca->value], true)) {
            $versions = app(IndexRateCorrectionService::class)->dependentVersions(PuIndexer::from((string) $record->indexer), $record->rate_date->toDateString());
            $names = Emission::query()->whereKey(array_column($versions, 'emission_id'))->pluck('name', 'id');
            $affected = array_map(fn (array $version): array => [...$version, 'emission' => $names[$version['emission_id']] ?? 'Emissão #'.$version['emission_id']], $versions);
        }

        return ['quarantine' => $record, 'current' => $current, 'affected' => $affected];
    }

    private static function decisionSummary(IndexRateQuarantine $record): ?string
    {
        return match ($record->status) {
            PuIndexQuarantineStatus::PendingReview => $record->anomaly_type->canBeApproved() && $record->received_value !== null
                ? null
                : 'Sem valor aprovável: só recusar.',
            PuIndexQuarantineStatus::Approved, PuIndexQuarantineStatus::Rejected => sprintf(
                '%s em %s: %s',
                $record->decidedBy?->name ?? '—',
                PuDisplay::dateTime($record->decided_at) ?? '—',
                Str::limit((string) $record->decision_reason, 80),
            ),
            PuIndexQuarantineStatus::Superseded => sprintf('%s · %s', PuDisplay::dateTime($record->decided_at) ?? '—', Str::limit((string) $record->decision_reason, 80)),
        };
    }
}
