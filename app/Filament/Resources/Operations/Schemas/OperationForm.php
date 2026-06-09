<?php

namespace App\Filament\Resources\Operations\Schemas;

use App\Concerns\MoneyFormatter;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Operation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class OperationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da Operação')
                ->columnSpanFull()
                ->schema([
                    Select::make('emission_id')
                        ->label('Emissão')
                        ->relationship('emission', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state, mixed $old): void {
                            if ($state === $old) {
                                return;
                            }

                            $emission = Emission::query()->find($state);

                            $set('developments', static::developmentsForEmission($state));
                            $set('due_date', $emission?->maturity_date?->toDateString());
                        })
                        ->validationMessages([
                            'required' => 'Selecione a emissão.',
                        ]),

                    Select::make('status')
                        ->label('Situação')
                        ->options(Operation::STATUS_OPTIONS)
                        ->default('draft')
                        ->required(),

                    DatePicker::make('due_date')
                        ->label('Vencimento')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Preenchido automaticamente a partir da emissão.'),

                    Repeater::make('developments')
                        ->label('Empreendimentos e Fundo de Obra')
                        ->columnSpanFull()
                        ->columns(2)
                        ->defaultItems(0)
                        ->minItems(1)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): ?string => filled($state['construction_id'] ?? null)
                            ? Construction::query()->whereKey($state['construction_id'])->value('development_name')
                            : null)
                        ->schema([
                            Select::make('construction_id')
                                ->label('Empreendimento')
                                ->options(fn (Get $get): array => filled($get('../../emission_id'))
                                    ? Construction::query()
                                        ->where('emission_id', $get('../../emission_id'))
                                        ->orderBy('development_name')
                                        ->pluck('development_name', 'id')
                                        ->all()
                                    : [])
                                ->required()
                                ->disabled()
                                ->dehydrated()
                                ->validationMessages([
                                    'required' => 'Selecione o empreendimento.',
                                ]),

                            static::moneyField('construction_fund_amount', 'Fundo de Obra'),
                        ])
                        ->afterStateHydrated(function (Repeater $component, ?Operation $record): void {
                            if (! $record instanceof Operation) {
                                return;
                            }

                            $component->state(
                                $record->planSets()
                                    ->whereNotNull('construction_id')
                                    ->get()
                                    ->map(fn ($plan): array => [
                                        'construction_id' => $plan->construction_id,
                                        'construction_fund_amount' => $plan->construction_fund_amount,
                                    ])
                                    ->all(),
                            );
                        })
                        ->helperText('Os empreendimentos da emissão já vêm preenchidos. Informe o Fundo de Obra de cada um.'),
                ])
                ->columns(3),

            Section::make('Responsáveis por Etapa')
                ->columnSpanFull()
                ->description('Defina quem responde por cada etapa do fluxo de medição.')
                ->collapsible()
                ->schema([
                    static::userField('responsible_user_id', 'Etapa 1 — Engenharia', 'responsibleUser'),
                    static::userField('stage2_reviewer_user_id', 'Etapa 2 — Gestão', 'stage2Reviewer'),
                    static::userField('stage3_reviewer_user_id', 'Etapa 3 — Compliance', 'stage3Reviewer'),
                    static::userField('payment_manager_user_id', 'Etapa 4 — Pagamentos e Comprovantes', 'paymentManager'),
                    static::userField('payment_finalizer_user_id', 'Etapa 5 — Finalização', 'paymentFinalizer'),
                    static::userField('assigned_user_id', 'Responsável Geral', 'assignedUser'),

                    Select::make('rejectionNotifyUsers')
                        ->label('Notificar em Recusa')
                        ->relationship('rejectionNotifyUsers', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Pode selecionar mais de um usuário.'),
                ])
                ->columns(3),
        ]);
    }

    /**
     * Builds one repeater row per development of the emission, with the
     * development pre-filled and the construction fund left blank to edit.
     *
     * @return array<int, array{construction_id: int, construction_fund_amount: null}>
     */
    protected static function developmentsForEmission(mixed $emissionId): array
    {
        if (blank($emissionId)) {
            return [];
        }

        return Construction::query()
            ->where('emission_id', $emissionId)
            ->orderBy('development_name')
            ->pluck('id')
            ->map(fn (int $id): array => [
                'construction_id' => $id,
                'construction_fund_amount' => null,
            ])
            ->all();
    }

    protected static function userField(string $name, string $label, string $relationship): Select
    {
        return Select::make($name)
            ->label($label)
            ->relationship($relationship, 'name')
            ->searchable()
            ->preload();
    }

    protected static function moneyField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => blank($state) ? null : MoneyFormatter::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state))
            ->minValue(0)
            ->placeholder('1.000,00');
    }
}
