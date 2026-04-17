<?php

namespace App\Filament\Resources\FundNames\Schemas;

use App\Filament\Resources\FundTypes\Schemas\FundTypeForm;
use App\Models\FundName;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class FundNameForm
{
    /**
     * @return array<int, Hidden|Select|TextInput>
     */
    public static function fields(?int $fundTypeId = null, bool $lockFundType = false): array
    {
        $typeField = ($lockFundType && filled($fundTypeId))
            ? Hidden::make('fund_type_id')
                ->default($fundTypeId)
                ->required()
            : Select::make('fund_type_id')
                ->label('Tipo de fundo')
                ->relationship('fundType', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->validationMessages([
                    'required' => 'Selecione o tipo de fundo.',
                ])
                ->createOptionForm(FundTypeForm::fields())
                ->editOptionForm(FundTypeForm::fields())
                ->createOptionAction(
                    fn (Action $action): Action => $action
                        ->label('Cadastrar tipo')
                        ->modalHeading('Cadastrar tipo de fundo')
                        ->modalWidth('2xl'),
                )
                ->editOptionAction(
                    fn (Action $action): Action => $action
                        ->label('Editar tipo')
                        ->modalHeading('Editar tipo de fundo')
                        ->modalWidth('2xl'),
                );

        return [
            $typeField,

            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->unique(
                    table: FundName::class,
                    column: 'name',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                        ->where('fund_type_id', $get('fund_type_id')),
                )
                ->validationMessages([
                    'required' => 'Informe o nome do fundo.',
                    'unique' => 'Já existe um nome de fundo cadastrado para este tipo.',
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do nome do fundo')
                ->schema(static::fields())
                ->columns(2),
        ]);
    }
}
