<?php

namespace App\Filament\Resources\ConstructionUnits\Schemas;

use App\Models\ConstructionUnit;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ConstructionUnitForm
{
    /**
     * State path of the emission selector. It only narrows the construction
     * list: the unit belongs to the construction, which already carries the
     * emission, so nothing about it is persisted here.
     */
    public const EMISSION_FIELD = 'emission_id';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Unidade')
                ->description('Identificação da unidade dentro do empreendimento.')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make(self::EMISSION_FIELD)
                        ->label('Emissão')
                        ->relationship('construction.emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->dehydrated(false)
                        ->live()
                        ->afterStateHydrated(function (Select $component, ?ConstructionUnit $record): void {
                            $component->state($record?->construction?->emission_id);
                        })
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state !== $old) {
                                $set('construction_id', null);
                            }
                        })
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ]),

                    Select::make('construction_id')
                        ->label('Empreendimento')
                        ->relationship(
                            name: 'construction',
                            titleAttribute: 'development_name',
                            modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                $get(self::EMISSION_FIELD),
                                fn (Builder $query, mixed $emissionId): Builder => $query->where('emission_id', $emissionId),
                            ),
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get): bool => blank($get(self::EMISSION_FIELD)))
                        ->helperText('Selecione primeiro a emissão para listar apenas os empreendimentos vinculados.')
                        ->columnSpanFull()
                        ->validationMessages([
                            'required' => 'Selecione o empreendimento.',
                        ]),

                    TextInput::make('block')
                        ->label('Bloco')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->placeholder('01, A, Torre 01')
                        ->helperText('Informado exatamente como cadastrado, inclusive zeros à esquerda.')
                        ->validationMessages([
                            'required' => 'Informe o bloco.',
                        ]),

                    TextInput::make('unit')
                        ->label('Unidade')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->placeholder('305, A101, Loja 01, Cobertura 02')
                        ->rule(static fn (Get $get, ?ConstructionUnit $record = null): Closure => static function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($get, $record): void {
                            $block = ConstructionUnit::normalizeIdentifier((string) $get('block'));
                            $unit = ConstructionUnit::normalizeIdentifier((string) $value);

                            if (! ConstructionUnit::isDuplicate($get('construction_id'), $block, $unit, $record?->getKey())) {
                                return;
                            }

                            $fail("A unidade {$unit} do bloco {$block} já está cadastrada para este empreendimento.");
                        })
                        ->validationMessages([
                            'required' => 'Informe a unidade.',
                        ]),
                ]),
        ]);
    }
}
