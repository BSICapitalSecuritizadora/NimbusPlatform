<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Concerns\MoneyFormatter;
use App\Enums\GuaranteeCategory;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Enums\GuaranteeValueSource;
use App\Models\Fund;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\RawJs;

/**
 * Campos de cadastro de uma garantia, agrupados como o detalhe da garantia
 * pede (§27 do escopo): identificação, regra contratual, valores e vigência.
 *
 * A identificação é `KeyValue` em vez de campos fixos porque cada categoria tem
 * o seu conjunto (§11) e o rótulo das chaves vem de
 * {@see GuaranteeCategory::identificationFields()}; travar colunas por tipo
 * exigiria migration a cada tipo novo.
 */
class GuaranteeFormFields
{
    /**
     * @return array<int, Component>
     */
    public static function make(): array
    {
        return [
            Section::make('Identificação')
                ->description('O que é a garantia e sobre qual ativo ela recai.')
                ->schema([
                    Select::make('type')
                        ->label('Tipo de Garantia')
                        ->options(GuaranteeType::groupedOptions())
                        ->searchable()
                        ->live()
                        ->placeholder('Pendente de classificação')
                        ->helperText('Define quais dados de identificação se aplicam e de onde o valor atual é lido.'),
                    TextInput::make('name')
                        ->label('Nome')
                        ->maxLength(255)
                        ->placeholder('Ex: AF Imóvel — Matrícula 45.721'),
                    Select::make('legal_status')
                        ->label('Situação Jurídica')
                        ->options(GuaranteeLegalStatus::options())
                        ->default(GuaranteeLegalStatus::Active->value)
                        ->required(),
                    Select::make('construction_id')
                        ->label('Empreendimento')
                        ->relationship('construction', 'development_name')
                        ->searchable()
                        ->preload()
                        ->placeholder('Não vinculado')
                        ->helperText('Restringe o estoque considerado ao empreendimento escolhido.'),
                    Select::make('fund_id')
                        ->label('Conta vinculada / Fundo')
                        ->relationship('fund', 'trade_name')
                        ->getOptionLabelFromRecordUsing(fn (Fund $record): string => filled($record->trade_name)
                            ? $record->trade_name
                            : "Conta {$record->account}")
                        ->searchable()
                        ->preload()
                        ->placeholder('Não vinculado')
                        ->helperText('Quando informado, o saldo desta conta é o valor atual da garantia.'),
                    KeyValue::make('identification')
                        ->label('Dados de identificação')
                        ->keyLabel('Campo')
                        ->valueLabel('Valor')
                        ->addActionLabel('Adicionar campo')
                        ->helperText(fn ($get): string => self::identificationHint($get('type')))
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Regra contratual')
                ->description('Mínimo exigido pelo contrato. Pode ser valor fixo, percentual sobre uma base ou fórmula.')
                ->schema([
                    Select::make('requirement_basis')
                        ->label('Forma do mínimo')
                        ->options(GuaranteeRequirementBasis::options())
                        ->default(GuaranteeRequirementBasis::None->value)
                        ->live()
                        ->required(),
                    self::currency('requirement_value', 'Valor mínimo (absoluto)')
                        ->visible(fn ($get): bool => $get('requirement_basis') === GuaranteeRequirementBasis::Absolute->value),
                    TextInput::make('requirement_percentage')
                        ->label('Percentual mínimo')
                        ->numeric()
                        ->step('0.0001')
                        ->suffix('× base')
                        ->helperText('Informe como fração: 120% = 1,2.')
                        ->visible(fn ($get): bool => $get('requirement_basis') === GuaranteeRequirementBasis::Percentage->value),
                    TextInput::make('requirement_multiplier')
                        ->label('Multiplicador')
                        ->numeric()
                        ->step('0.01')
                        ->helperText('Ex: 3 para "3 próximas PMTs".')
                        ->visible(fn ($get): bool => $get('requirement_basis') === GuaranteeRequirementBasis::Formula->value),
                    Select::make('requirement_base')
                        ->label('Base de cálculo')
                        ->options(GuaranteeRequirementBase::options())
                        ->visible(fn ($get): bool => in_array(
                            $get('requirement_basis'),
                            [GuaranteeRequirementBasis::Percentage->value, GuaranteeRequirementBasis::Formula->value],
                            true,
                        )),
                    Textarea::make('requirement_formula')
                        ->label('Texto literal da regra')
                        ->rows(2)
                        ->placeholder('Reproduza a redação contratual do mínimo exigido.')
                        ->columnSpanFull(),
                    Textarea::make('requirement_conditions')
                        ->label('Condições especiais')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Valores e elegibilidade')
                ->schema([
                    self::currency('contracted_value', 'Valor na contratação'),
                    self::currency('documentary_value', 'Valor documental'),
                    Select::make('value_source')
                        ->label('Origem do valor atual')
                        ->options(GuaranteeValueSource::options())
                        ->placeholder('Padrão do tipo de garantia')
                        ->helperText('Deixe vazio para usar a origem padrão do tipo escolhido.'),
                    TextInput::make('eligibility_factor')
                        ->label('Fator de elegibilidade (haircut)')
                        ->numeric()
                        ->step('0.0001')
                        ->placeholder('1,0')
                        ->helperText('Valor elegível = valor atual × fator. Vazio significa sem deságio.'),
                    Toggle::make('counts_toward_coverage')
                        ->label('Compõe a cobertura da operação')
                        ->default(true)
                        ->helperText('Desmarque para acompanhar a garantia sem somá-la ao índice.'),
                ])
                ->columns(2),

            Section::make('Vigência')
                ->schema([
                    DatePicker::make('constituted_at')->label('Constituição'),
                    DatePicker::make('registered_at')->label('Registro'),
                    DatePicker::make('validity_start_date')->label('Início da Validade'),
                    DatePicker::make('validity_end_date')->label('Término da Validade'),
                    DatePicker::make('released_at')
                        ->label('Liberação')
                        ->helperText('A partir desta data a garantia deixa de compor a cobertura.'),
                    TextInput::make('evaluation_frequency')
                        ->label('Periodicidade de Avaliação')
                        ->maxLength(255)
                        ->placeholder('Ex: Anual'),
                ])
                ->columns(3),

            Section::make('Observações')
                ->schema([
                    Textarea::make('description')->label('Descrição')->rows(3)->columnSpanFull(),
                    Textarea::make('notes')->label('Notas internas')->rows(2)->columnSpanFull(),
                ])
                ->collapsed(),
        ];
    }

    private static function identificationHint(?string $type): string
    {
        $guaranteeType = $type === null ? null : GuaranteeType::tryFrom($type);

        if ($guaranteeType === null) {
            return 'Escolha o tipo da garantia para ver os campos de identificação sugeridos.';
        }

        $fields = $guaranteeType->category()->identificationFields();

        return 'Campos sugeridos: '.implode(', ', array_values($fields)).'.';
    }

    private static function currency(string $name, string $label): TextInput
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
            ->placeholder('Não informado');
    }
}
