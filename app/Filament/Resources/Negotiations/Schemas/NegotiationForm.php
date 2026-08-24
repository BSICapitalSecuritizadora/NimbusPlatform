<?php

namespace App\Filament\Resources\Negotiations\Schemas;

use App\Models\Construction;
use App\Models\Negotiation;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class NegotiationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Negociação')
                ->description('Identifique a operação, o empreendimento e a competência deste lançamento.')
                ->columnSpanFull()
                ->schema([
                    Select::make('emission_id')
                        ->label('Operação')
                        ->placeholder('Selecione uma operação')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('construction_id', null);
                            }
                        })
                        ->columnSpan(['lg' => 2])
                        ->validationMessages([
                            'required' => 'Selecione a operação.',
                        ]),

                    Select::make('construction_id')
                        ->label('Empreendimento')
                        ->placeholder('Selecione um empreendimento')
                        ->relationship(
                            name: 'construction',
                            titleAttribute: 'development_name',
                            modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                $get('emission_id'),
                                fn (Builder $query, mixed $emissionId): Builder => $query->where('emission_id', $emissionId),
                            ),
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled(fn (Get $get): bool => blank($get('emission_id')))
                        ->helperText(fn (Get $get): ?string => blank($get('emission_id'))
                            ? 'Selecione primeiro a operação para listar os empreendimentos vinculados.'
                            : null)
                        ->rule(static function (Get $get): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if (blank($value) || blank($get('emission_id'))) {
                                    return;
                                }

                                $exists = Construction::query()
                                    ->whereKey($value)
                                    ->where('emission_id', $get('emission_id'))
                                    ->exists();

                                if (! $exists) {
                                    $fail('Selecione um empreendimento vinculado à operação escolhida.');
                                }
                            };
                        })
                        ->columnSpan(['lg' => 2])
                        ->validationMessages([
                            'required' => 'Selecione o empreendimento.',
                        ]),

                    TextInput::make('reference_month')
                        ->label('Competência')
                        ->placeholder('MM/AAAA')
                        ->mask('99/9999')
                        ->required()
                        ->formatStateUsing(fn (mixed $state): string => Negotiation::formatReferenceMonthForDisplay($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?string => Negotiation::normalizeReferenceMonth($state))
                        ->mutateStateForValidationUsing(fn (mixed $state): ?string => Negotiation::normalizeReferenceMonth($state))
                        ->rule(static function (Get $get, ?Negotiation $record = null): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                $referenceMonth = Negotiation::normalizeReferenceMonth($value);

                                if (($referenceMonth === null) || blank($get('emission_id')) || blank($get('construction_id'))) {
                                    return;
                                }

                                $exists = Negotiation::query()
                                    ->where('emission_id', $get('emission_id'))
                                    ->where('construction_id', $get('construction_id'))
                                    ->whereDate('reference_month', $referenceMonth)
                                    ->when($record?->exists, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($exists) {
                                    $fail('Já existe uma negociação para esta operação, empreendimento e competência.');
                                }
                            };
                        })
                        ->columnSpan(['lg' => 1])
                        ->validationMessages([
                            'required' => 'Informe a competência no formato MM/AAAA.',
                        ]),
                ])
                ->columns(['default' => 1, 'md' => 2, 'lg' => 5]),

            Section::make('Negociações do Mês')
                ->description('Informe a quantidade de unidades vendidas e distratadas nesta competência.')
                ->columnSpanFull()
                ->schema([
                    static::quantityField('sales', 'Vendas', 'Quantidade de novas vendas no mês.'),
                    static::quantityField('cancellations', 'Distratos', 'Quantidade de distratos no mês.'),
                ])
                ->columns(['default' => 1, 'md' => 2, 'lg' => 4]),
        ]);
    }

    protected static function quantityField(string $name, string $label, string $helperText): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->helperText($helperText)
            ->required()
            ->default(0)
            ->numeric()
            ->integer()
            ->minValue(0)
            ->validationMessages([
                'required' => "Informe o valor de {$label}.",
                'integer' => "Informe um número inteiro válido para {$label}.",
                'min' => "O valor de {$label} não pode ser negativo.",
            ]);
    }
}
