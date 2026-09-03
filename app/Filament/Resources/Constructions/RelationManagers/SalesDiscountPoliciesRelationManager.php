<?php

namespace App\Filament\Resources\Constructions\RelationManagers;

use App\Models\SalesDiscountPolicy;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Política comercial de desconto do empreendimento.
 *
 * Vive aqui, dentro da obra, e não numa página global: é a obra que tem tabela
 * de preço e limite de negociação, e uma tela desconectada convidaria a tratar
 * o desconto como parâmetro do sistema em vez de decisão comercial datada.
 *
 * Append-only, como o histórico de valores: sem editar, sem excluir, sem ação em
 * massa. Mudar o limite é registrar outra política; a anterior continua
 * respondendo pelas vendas feitas enquanto ela valia.
 */
class SalesDiscountPoliciesRelationManager extends RelationManager
{
    protected static string $relationship = 'salesDiscountPolicies';

    protected static ?string $title = 'Política Comercial de Desconto';

    protected static ?string $modelLabel = 'Política de desconto';

    protected static ?string $pluralModelLabel = 'Políticas de desconto';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Id da política que vale hoje. Uma política com vigência futura já está
     * registrada, mas ainda não é a vigente.
     */
    protected function currentPolicyId(): ?int
    {
        return SalesDiscountPolicy::query()
            ->where('construction_id', $this->getOwnerRecord()->getKey())
            ->where('effective_from', '<=', InclusiveDateBound::upperBound(CarbonImmutable::now()))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('id');
    }

    public function table(Table $table): Table
    {
        $currentPolicyId = $this->currentPolicyId();

        return $table
            ->recordTitleAttribute('effective_from')
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Vigência')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (SalesDiscountPolicy $record): ?string => $record->effective_from?->isFuture()
                        ? 'Programada'
                        : null),

                TextColumn::make('position')
                    ->label('Posição')
                    ->badge()
                    ->state(fn (SalesDiscountPolicy $record): ?string => $record->getKey() === $currentPolicyId ? 'Vigente' : null)
                    ->color('success')
                    ->placeholder('—'),

                TextColumn::make('maximum_discount_percent')
                    ->label('Desconto máximo')
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2, ',', '.').'%')
                    ->alignEnd()
                    ->weight('bold')
                    ->extraCellAttributes(['class' => 'font-mono tabular-nums'])
                    ->sortable(),

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
                $this->newPolicyAction(),
            ])
            ->actions([
                Action::make('viewReason')
                    ->label('Ver motivo')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->modalHeading('Motivo da política')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->visible(fn (SalesDiscountPolicy $record): bool => filled($record->reason))
                    ->schema(fn (SalesDiscountPolicy $record): array => [
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
            ->emptyStateHeading('Nenhuma política registrada')
            ->emptyStateDescription('Sem política vigente, uma venda deste empreendimento não pode ser classificada como conforme nem como não conforme.')
            ->emptyStateIcon('heroicon-o-receipt-percent');
    }

    private function newPolicyAction(): Action
    {
        return Action::make('newPolicy')
            ->label('Nova política')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->modalHeading('Nova política de desconto')
            ->modalDescription('A política anterior permanece no histórico e continua respondendo pelas vendas feitas enquanto valia.')
            ->modalSubmitActionLabel('Registrar política')
            ->visible(fn (): bool => auth()->user()?->can('emissions.update') ?? false)
            ->schema([
                TextInput::make('maximum_discount_percent')
                    ->label('Desconto máximo autorizado')
                    ->required()
                    ->numeric()
                    ->suffix('%')
                    ->minValue(SalesDiscountPolicy::MINIMUM_DISCOUNT_PERCENT)
                    ->maxValue(SalesDiscountPolicy::MAXIMUM_DISCOUNT_PERCENT)
                    ->step('0.01')
                    ->placeholder('5,00')
                    ->helperText('Limite máximo. Uma venda pode ter desconto menor, nunca maior.')
                    ->extraInputAttributes(['class' => 'text-right font-mono tabular-nums'])
                    ->validationMessages([
                        'required' => 'Informe o desconto máximo autorizado.',
                        'min' => 'O desconto não pode ser negativo.',
                        'max' => 'O desconto não pode ultrapassar 100%.',
                    ]),

                DatePicker::make('effective_from')
                    ->label('Vigência')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(now())
                    ->helperText('Data a partir da qual a política passa a valer. Datas futuras são permitidas e não retroagem.')
                    ->validationMessages([
                        'required' => 'Informe a vigência da política.',
                    ]),

                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder('Aprovação comercial, revisão de margem, campanha de vendas...')
                    ->columnSpanFull()
                    ->validationMessages([
                        'required' => 'Informe o motivo da política.',
                    ]),
            ])
            ->action(function (array $data): void {
                $this->getOwnerRecord()->salesDiscountPolicies()->create([
                    'maximum_discount_percent' => $data['maximum_discount_percent'],
                    'effective_from' => $data['effective_from'],
                    'reason' => $data['reason'],
                    'created_by_id' => auth()->id(),
                ]);

                Notification::make()
                    ->success()
                    ->title('Política registrada.')
                    ->body(sprintf(
                        'Desconto máximo de %s%% a partir de %s.',
                        number_format((float) $data['maximum_discount_percent'], 2, ',', '.'),
                        CarbonImmutable::parse($data['effective_from'])->format('d/m/Y'),
                    ))
                    ->send();
            });
    }
}
