<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Actions\Emissions\CreateInitialConstructions;
use App\Filament\Resources\Constructions\Schemas\ConstructionForm;
use App\Filament\Resources\SalesBoards\Schemas\SalesBoardForm;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard\Step;

/**
 * Mandatory wizard step where the operation's constructions are registered,
 * each one together with its Sales Board.
 *
 * A sales board belongs to an emission *and* to one of its constructions, so
 * both are captured here, per construction, before the rest of the operation
 * data. What is captured is the *current* position: while the emission stays
 * "Em Elaboração" it can be freely edited, and the initial position is only
 * consolidated when the emission leaves that status.
 *
 * Nothing is persisted by the schema: the emission has to exist first, so
 * {@see CreateInitialConstructions} consumes this state
 * right after the emission row is created, inside the same transaction.
 */
class EmissionConstructionsStep
{
    /**
     * State path holding the whole step payload inside the emission form data.
     */
    public const STATE_PATH = 'initial_constructions';

    /**
     * State path of the initial sales board nested in each construction item.
     */
    public const SALES_BOARD_STATE_PATH = 'sales_board';

    public static function make(): Step
    {
        return Step::make('Empreendimentos')
            ->description('Obrigatório')
            ->icon('heroicon-o-building-office-2')
            ->visibleOn('create')
            ->schema([
                Repeater::make(self::STATE_PATH)
                    ->hiddenLabel()
                    ->addActionLabel('Adicionar empreendimento')
                    ->itemLabel(fn (array $state): string => filled($state['development_name'] ?? null)
                        ? (string) $state['development_name']
                        : 'Novo empreendimento')
                    ->collapsible()
                    ->reorderable(false)
                    ->defaultItems(1)
                    ->minItems(1)
                    ->validationMessages([
                        'min_items' => 'Cadastre ao menos um empreendimento para a operação.',
                    ])
                    ->columnSpanFull()
                    ->schema([
                        ConstructionForm::identificationSection(withEmission: false),
                        ConstructionForm::locationSection(),
                        ConstructionForm::measurementSection(useRelationship: false),

                        Group::make()
                            ->statePath(self::SALES_BOARD_STATE_PATH)
                            ->columnSpanFull()
                            ->schema([
                                Section::make('Quadro de Vendas')
                                    ->description('Posição atual do empreendimento. Enquanto a emissão estiver "Em Elaboração", pode ser alterada livremente pelo módulo Quadro de Vendas.')
                                    ->schema([
                                        SalesBoardForm::referenceMonthField(),
                                    ])
                                    ->columns(3)
                                    ->columnSpanFull(),

                                SalesBoardForm::quantitiesSection(),

                                SalesBoardForm::valuesSection(),
                            ]),
                    ]),
            ]);
    }
}
