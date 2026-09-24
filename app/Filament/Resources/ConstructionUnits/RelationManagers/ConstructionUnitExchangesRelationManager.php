<?php

namespace App\Filament\Resources\ConstructionUnits\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\Enums\ConstructionUnitExchangeKind;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Permutas da unidade.
 *
 * Só o baseline é cadastrável, e só enquanto a emissão está em elaboração. Uma
 * vez que a operação entra em curso, a posição inicial declarada é a referência
 * contra a qual todo o resto é comparado, e deixá-la editável apagaria essa
 * referência sem deixar rastro.
 *
 * Alterar permuta com a operação em curso é permuta extraordinária: pede
 * contrato, evidência, não conformidade e Gestão -- workflow das fases D/E. Por
 * isso aqui não há editar, não há excluir, e depois do draft não há nem incluir.
 */
class ConstructionUnitExchangesRelationManager extends RelationManager
{
    protected static string $relationship = 'exchanges';

    protected static ?string $title = 'Permutas';

    protected static ?string $modelLabel = 'Permuta';

    protected static ?string $pluralModelLabel = 'Permutas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * O baseline só é declarável enquanto a emissão está em elaboração.
     */
    protected function emissionIsInDraft(): bool
    {
        /** @var ConstructionUnit $unit */
        $unit = $this->getOwnerRecord();

        return $unit->construction?->emission?->isInDraft() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columnManagerTriggerAction(fn (Action $action): Action => $this->getPageClass() === ViewConstructionUnit::class
                ? $action->tooltip('Colunas')
                : $action)
            ->recordTitleAttribute('effective_from')
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Vigência')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (ConstructionUnitExchange $record): ?string => $record->ended_on === null
                        ? null
                        : 'até '.$record->ended_on->format('d/m/Y')),

                TextColumn::make('position')
                    ->label('Posição')
                    ->badge()
                    ->state(fn (ConstructionUnitExchange $record): ?string => $record->isEffectiveOn(CarbonImmutable::now())
                        ? 'Vigente'
                        : null)
                    ->color('success')
                    ->placeholder('—'),

                TextColumn::make('exchange_value')
                    ->label('Valor da permuta')
                    ->money('BRL')
                    ->alignEnd()
                    ->weight('bold')
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (ConstructionUnitExchangeKind $state): string => $state->label())
                    ->color(fn (ConstructionUnitExchangeKind $state): string => $state->color()),

                TextColumn::make('contract.code')
                    ->label('Contrato')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('createdBy.name')
                    ->label('Registrada por')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Registrada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->headerActions([
                $this->declareBaselineAction(),
            ])
            ->actions([
                Action::make('viewReason')
                    ->label('Ver motivo')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->modalHeading('Motivo da permuta')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->visible(fn (ConstructionUnitExchange $record): bool => filled($record->reason))
                    ->schema(fn (ConstructionUnitExchange $record): array => [
                        TextEntry::make('registered_by')
                            ->label('Registrada por')
                            ->state($record->createdBy?->name ?? 'Não identificado'),
                        TextEntry::make('registered_at')
                            ->label('Data do registro')
                            ->state($record->created_at?->format('d/m/Y H:i') ?? '—'),
                        TextEntry::make('effective_from')
                            ->label('Vigência')
                            ->state($record->effective_from?->format('d/m/Y') ?? '—'),
                        TextEntry::make('reason')
                            ->label('Motivo')
                            ->state((string) $record->reason)
                            ->columnSpanFull(),
                    ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nenhuma permuta registrada')
            ->emptyStateDescription($this->emissionIsInDraft()
                ? 'Declare aqui a posição inicial de permuta da unidade enquanto a operação está em elaboração.'
                : 'A operação já saiu da elaboração: a posição inicial de permuta não pode mais ser declarada por aqui.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }

    private function declareBaselineAction(): Action
    {
        return Action::make('declareBaseline')
            ->label('Declarar permuta inicial')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading('Declarar permuta inicial da unidade')
            ->modalDescription('Posição inicial da operação. Depois que a emissão sair da elaboração, ela deixa de ser editável por aqui.')
            ->modalSubmitActionLabel('Declarar permuta')
            ->visible(fn (): bool => $this->emissionIsInDraft()
                && (auth()->user()?->can('constructions.update') ?? false))
            ->schema([
                TextInput::make('exchange_value')
                    ->label('Valor da permuta')
                    ->required()
                    ->prefix('R$')
                    ->inputMode('decimal')
                    ->mask(RawJs::make(<<<'JS'
                        $money($input, ',', '.')
                    JS))
                    ->dehydrateStateUsing(fn (mixed $state): ?string => self::normalizeValue($state))
                    ->mutateStateForValidationUsing(fn (mixed $state): ?string => self::normalizeValue($state))
                    ->rule('numeric')
                    ->minValue(0)
                    ->placeholder('450.000,00')
                    ->helperText('Valor próprio da permuta. Não é o valor de venda do contrato.')
                    ->extraInputAttributes(['class' => 'text-right font-mono tabular-nums'])
                    ->validationMessages([
                        'required' => 'Informe o valor da permuta.',
                        'min' => 'O valor da permuta não pode ser negativo.',
                    ]),

                DatePicker::make('effective_from')
                    ->label('Vigência')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText('Data a partir da qual a unidade passa a contar como permutada.')
                    ->validationMessages([
                        'required' => 'Informe a vigência da permuta.',
                    ]),

                Select::make('contract_id')
                    ->label('Contrato')
                    ->options(fn (): array => Contract::query()
                        ->where('construction_unit_id', $this->getOwnerRecord()->getKey())
                        ->orderByDesc('sale_date')
                        ->pluck('code', 'id')
                        ->all())
                    ->searchable()
                    ->placeholder('Sem contrato vinculado')
                    ->helperText('Opcional: a permuta inicial costuma ser conhecida antes de o contrato existir.'),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder('Permuta acordada na estruturação da operação...')
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da permuta.',
                    ]),
            ])
            ->action(function (array $data): void {
                /** @var ConstructionUnit $unit */
                $unit = $this->getOwnerRecord();

                /**
                 * Revalidado no momento da gravação, não só na visibilidade do
                 * botão: entre abrir o modal e confirmar, a emissão pode ter
                 * saído da elaboração.
                 */
                if (! $this->emissionIsInDraft()) {
                    Notification::make()
                        ->danger()
                        ->title('Permuta não declarada.')
                        ->body('A emissão saiu da elaboração e a posição inicial de permuta ficou congelada.')
                        ->persistent()
                        ->send();

                    return;
                }

                $unit->exchanges()->create([
                    'exchange_value' => $data['exchange_value'],
                    'effective_from' => $data['effective_from'],
                    'ended_on' => null,
                    'contract_id' => $data['contract_id'] ?? null,
                    'kind' => ConstructionUnitExchangeKind::Baseline,
                    'reason' => $data['reason'],
                    'created_by_id' => auth()->id(),
                ]);

                Notification::make()
                    ->success()
                    ->title('Permuta declarada.')
                    ->body(sprintf(
                        'Permuta de R$ %s vigente a partir de %s.',
                        MoneyFormatter::formatCurrencyForDisplay($data['exchange_value']),
                        CarbonImmutable::parse($data['effective_from'])->format('d/m/Y'),
                    ))
                    ->send();
            });
    }

    private static function normalizeValue(mixed $state): ?string
    {
        $cents = IntegerMoney::cents($state);

        return $cents === null ? null : IntegerMoney::decimalString($cents);
    }
}
