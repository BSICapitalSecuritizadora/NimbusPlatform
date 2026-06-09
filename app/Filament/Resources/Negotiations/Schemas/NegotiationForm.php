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
                ->columnSpanFull()
                ->schema([
                    Select::make('emission_id')
                        ->label('Operação')
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
                        ->validationMessages([
                            'required' => 'Selecione a operação.',
                        ]),

                    Select::make('construction_id')
                        ->label('Empreendimento')
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
                        ->helperText('Selecione primeiro a operação para listar apenas os empreendimentos vinculados.')
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
                        ->validationMessages([
                            'required' => 'Selecione o empreendimento.',
                        ]),

                    TextInput::make('reference_month')
                        ->label('Mês')
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
                                    $fail('Já existe uma negociação para esta operação, empreendimento e Mês.');
                                }
                            };
                        })
                        ->validationMessages([
                            'required' => 'Informe a Mês no formato MM/AAAA.',
                        ]),
                ])
                ->columns(3),

            Section::make('Negociações do Mês')
                ->columnSpanFull()
                ->schema([
                    static::quantityField('sales', 'Vendas'),
                    static::quantityField('cancellations', 'Distratos'),
                ])
                ->columns(2),
        ]);
    }

    protected static function quantityField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
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
