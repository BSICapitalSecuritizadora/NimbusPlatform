<?php

namespace App\Filament\Resources\ConstructionUnits\RelationManagers;

use App\Concerns\MoneyFormatter;
use App\Enums\UnitValueSource;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Support\Dates\InclusiveDateBound;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * Histórico de valores da unidade.
 *
 * Somente leitura por decisão de domínio: não há editar, não há excluir e não há
 * ação em massa. Corrigir um valor lançado errado é registrar outra linha para a
 * mesma vigência -- é o que a ação "Atualizar valor" faz, e é o que preserva a
 * resposta para "quanto esta unidade valia naquela data".
 */
class ConstructionUnitValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'valueHistories';

    protected static ?string $title = 'Histórico de Valores';

    protected static ?string $modelLabel = 'Valor';

    protected static ?string $pluralModelLabel = 'Histórico de Valores';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Id da linha que responde pelo valor de hoje. Serve só para o distintivo
     * "Vigente": uma linha com vigência futura ainda não vale nada.
     */
    protected function currentVersionId(): ?int
    {
        return ConstructionUnitValue::query()
            ->where('construction_unit_id', $this->getOwnerRecord()->getKey())
            ->where('effective_from', '<=', InclusiveDateBound::upperBound(CarbonImmutable::now()))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('id');
    }

    public function table(Table $table): Table
    {
        $currentVersionId = $this->currentVersionId();

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
                    ->description(fn (ConstructionUnitValue $record): ?string => $record->effective_from?->isFuture()
                        ? 'Programado'
                        : null),

                TextColumn::make('position')
                    ->label('Posição')
                    ->badge()
                    ->state(fn (ConstructionUnitValue $record): ?string => $record->getKey() === $currentVersionId ? 'Vigente' : null)
                    ->color('success')
                    ->placeholder('—'),

                TextColumn::make('value')
                    ->label('Valor')
                    ->money('BRL')
                    ->alignEnd()
                    ->weight('bold')
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums whitespace-nowrap'])
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (UnitValueSource $state): string => $state->label())
                    ->color(fn (UnitValueSource $state): string => $state->color()),

                TextColumn::make('createdBy.name')
                    ->label('Registrado por')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Registrado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->headerActions([
                $this->updateValueAction(),
            ])
            ->actions([
                Action::make('viewReason')
                    ->label('Ver motivo')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->modalHeading('Motivo da atualização')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->visible(fn (ConstructionUnitValue $record): bool => filled($record->reason))
                    ->schema(fn (ConstructionUnitValue $record): array => [
                        TextEntry::make('registered_by')
                            ->label('Registrado por')
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
            ->emptyStateHeading('Nenhuma atualização de valor')
            ->emptyStateDescription('O histórico é preenchido quando o valor da unidade é atualizado. O valor base informado no cadastro não aparece aqui.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    /**
     * Registra uma nova linha do histórico. Nunca altera as anteriores.
     *
     * O motivo é obrigatório: a pergunta que este histórico precisa responder
     * daqui a um ano não é só "quanto passou a valer", é "por quê".
     */
    private function updateValueAction(): Action
    {
        return Action::make('updateValue')
            ->label('Atualizar valor')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading('Atualizar valor da unidade')
            ->modalDescription('O valor anterior permanece no histórico. Uma correção da mesma vigência gera uma nova linha, não substitui a existente.')
            ->modalSubmitActionLabel('Registrar atualização')
            ->visible(fn (): bool => auth()->user()?->can('constructions.update') ?? false)
            ->schema([
                TextInput::make('value')
                    ->label('Novo valor')
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
                    ->placeholder('1.000.000,00')
                    ->extraInputAttributes(['class' => 'text-right font-mono tabular-nums'])
                    ->validationMessages([
                        'required' => 'Informe o novo valor.',
                        'min' => 'O valor não pode ser negativo.',
                    ]),

                DatePicker::make('effective_from')
                    ->label('Vigência')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(now())
                    ->helperText('Data a partir da qual o novo valor passa a valer. Datas futuras são permitidas e não afetam consultas anteriores.')
                    ->validationMessages([
                        'required' => 'Informe a vigência do novo valor.',
                    ]),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder('Reajuste de tabela, correção de lançamento, revisão comercial...')
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da atualização.',
                    ]),
            ])
            ->action(function (array $data): void {
                /** @var ConstructionUnit $unit */
                $unit = $this->getOwnerRecord();

                $unit->valueHistories()->create([
                    'value' => $data['value'],
                    'effective_from' => $data['effective_from'],
                    'source' => UnitValueSource::Manual,
                    'reason' => $data['reason'],
                    'created_by_id' => auth()->id(),
                ]);

                Notification::make()
                    ->success()
                    ->title('Valor atualizado.')
                    ->body(sprintf(
                        'Novo valor de R$ %s com vigência a partir de %s.',
                        MoneyFormatter::formatCurrencyForDisplay($data['value']),
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
