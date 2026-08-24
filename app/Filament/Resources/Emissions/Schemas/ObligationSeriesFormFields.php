<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInitialDateInclusion;
use App\Enums\ObligationInvalidDayPolicy;
use App\Enums\ObligationOffsetDirection;
use App\Models\Obligation;
use App\Models\ObligationSeries;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

class ObligationSeriesFormFields
{
    /**
     * @return array<int, Component>
     */
    public static function make(?string $defaultEndDate = null): array
    {
        return [
            ...self::definitionFields(),
            ...self::configurationFields($defaultEndDate),
            ...self::sourceFields(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function definitionFields(): array
    {
        return [
            TextInput::make('title')
                ->label('Título da recorrência')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Select::make('obligation_category')
                ->label('Categoria')
                ->options(ObligationFormFields::CATEGORY_OPTIONS)
                ->searchable(),
            TextInput::make('obligation_type')
                ->label('Tipo')
                ->maxLength(255),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(3)
                ->columnSpanFull(),
            Select::make('responsible_user_id')
                ->label('Responsável padrão')
                ->relationship('responsibleUser', 'name')
                ->searchable()
                ->preload(),
            Select::make('responsible_area')
                ->label('Área padrão')
                ->options(ObligationFormFields::AREA_OPTIONS)
                ->searchable(),
            TextInput::make('responsible_party')
                ->label('Parte responsável no documento')
                ->maxLength(255),
            Select::make('priority')
                ->label('Prioridade padrão')
                ->options(Obligation::PRIORITY_OPTIONS)
                ->default('medium')
                ->required(),
            Textarea::make('required_evidence')
                ->label('Evidência exigida')
                ->rows(2)
                ->columnSpanFull(),
            Textarea::make('due_rule')
                ->label('Regra jurídica original')
                ->helperText('Texto literal do documento. Este campo é preservado para auditoria e não é executado automaticamente.')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function configurationFields(?string $defaultEndDate = null, bool $includeStartsOn = true): array
    {
        $fields = [
            Select::make('frequency')
                ->label('Frequência confirmada')
                ->options(ObligationFrequency::seriesOptions())
                ->required()
                ->live(),
            DatePicker::make('ends_on')
                ->label('Término da recorrência')
                ->default($defaultEndDate)
                ->afterOrEqual('starts_on')
                ->required(),
            Select::make('due_rule_type')
                ->label('Regra executável')
                ->options(ObligationDueRuleType::options())
                ->required(fn (Get $get): bool => $get('frequency') !== ObligationFrequency::OnDemand->value)
                ->placeholder('Vencimento informado manualmente')
                ->helperText(fn (Get $get): ?string => $get('frequency') === ObligationFrequency::OnDemand->value
                    ? 'Para prazos disparados por evento, selecione a regra relativa. Deixe em branco apenas quando o vencimento for informado manualmente.'
                    : null)
                ->live(),
            TextInput::make('due_day')
                ->label(fn (Get $get): string => $get('due_rule_type') === ObligationDueRuleType::NthBusinessDay->value
                    ? 'Número do dia útil'
                    : 'Dia do mês')
                ->numeric()
                ->minValue(1)
                ->maxValue(31)
                ->required(fn (Get $get): bool => in_array($get('due_rule_type'), [
                    ObligationDueRuleType::FixedDay->value,
                    ObligationDueRuleType::NthBusinessDay->value,
                ], true))
                ->visible(fn (Get $get): bool => in_array($get('due_rule_type'), [
                    ObligationDueRuleType::FixedDay->value,
                    ObligationDueRuleType::NthBusinessDay->value,
                ], true)),
            Select::make('due_offset_months')
                ->label('Mês do vencimento')
                ->options(self::monthOffsetOptions())
                ->default(1)
                ->required(fn (Get $get): bool => in_array($get('due_rule_type'), [
                    ObligationDueRuleType::FixedDay->value,
                    ObligationDueRuleType::LastDay->value,
                    ObligationDueRuleType::NthBusinessDay->value,
                ], true))
                ->visible(fn (Get $get): bool => in_array($get('due_rule_type'), [
                    ObligationDueRuleType::FixedDay->value,
                    ObligationDueRuleType::LastDay->value,
                    ObligationDueRuleType::NthBusinessDay->value,
                ], true)),
            TextInput::make('due_offset_days')
                ->label('Dias corridos após o fim da competência')
                ->helperText('A contagem começa no dia seguinte ao último dia da competência e inclui fins de semana e feriados.')
                ->numeric()
                ->minValue(1)
                ->maxValue(3650)
                ->required(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::CalendarDaysAfterCompetenceEnd->value)
                ->visible(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::CalendarDaysAfterCompetenceEnd->value),
            Select::make('invalid_day_policy')
                ->label('Quando o dia não existir')
                ->options(ObligationInvalidDayPolicy::options())
                ->default(ObligationInvalidDayPolicy::LastValidDay->value)
                ->required(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::FixedDay->value)
                ->visible(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::FixedDay->value),
            Select::make('calendar_code')
                ->label('Calendário de dias úteis')
                ->options(function (?Model $record): array {
                    $retainedCalendarCode = $record instanceof ObligationSeries
                        && filled($record->calendar_code)
                        && $record->rules()->where('calendar_code', $record->calendar_code)->exists()
                            ? $record->calendar_code
                            : null;

                    return app(BusinessCalendarCatalogService::class)
                        ->optionsForNewConfiguration($retainedCalendarCode);
                })
                ->helperText('Define quais datas serão consideradas úteis nesta regra contratual. ANBIMA e B3 não são equivalentes. A ativação confirma a regra e a evidência; a cobertura será validada quando houver uma competência ou evento concreto para calcular.')
                ->searchable()
                ->required(fn (Get $get): bool => self::isBusinessDayRule($get('due_rule_type')))
                ->visible(fn (Get $get): bool => self::isBusinessDayRule($get('due_rule_type'))),
            Hidden::make('relative_offset_unit')
                ->default('business_days'),
            TextInput::make('relative_offset_quantity')
                ->label('Quantidade de dias úteis')
                ->numeric()
                ->minValue(1)
                ->maxValue(3650)
                ->required(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::BusinessDaysRelativeToEvent->value)
                ->visible(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::BusinessDaysRelativeToEvent->value),
            Select::make('relative_offset_direction')
                ->label('Direção da contagem')
                ->options(ObligationOffsetDirection::options())
                ->required(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::BusinessDaysRelativeToEvent->value)
                ->visible(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::BusinessDaysRelativeToEvent->value),
            TextInput::make('anchor_description')
                ->label('Evento de referência')
                ->placeholder('Ex.: recebimento da solicitação')
                ->helperText('Descreva a âncora como aparece no contrato. A data efetiva será registrada quando o evento ocorrer.')
                ->required(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::BusinessDaysRelativeToEvent->value)
                ->visible(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::BusinessDaysRelativeToEvent->value)
                ->columnSpanFull(),
            Select::make('initial_date_inclusion')
                ->label('Contagem da data inicial')
                ->options(ObligationInitialDateInclusion::options())
                ->placeholder('Confirmação contratual pendente')
                ->helperText('Se a cláusula não esclarecer a contagem e não houver orientação confirmada, não ative a regra.')
                ->required(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::BusinessDaysRelativeToEvent->value)
                ->visible(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::BusinessDaysRelativeToEvent->value),
            Section::make('Evidência da escolha do calendário')
                ->description('Registre por que esta versão da obrigação usa este calendário. A confirmação fica vinculada à versão da regra e ao usuário responsável.')
                ->schema([
                    TextInput::make('calendar_evidence_source_document')
                        ->label('Documento'),
                    TextInput::make('calendar_evidence_clause_reference')
                        ->label('Cláusula'),
                    TextInput::make('calendar_evidence_page_reference')
                        ->label('Página'),
                    Textarea::make('calendar_evidence_excerpt')
                        ->label('Trecho contratual')
                        ->helperText('Inclua a definição de Dia Útil ou o trecho que sustenta a escolha. A expressão “Dia Útil” sozinha não seleciona ANBIMA ou B3.')
                        ->required(fn (Get $get): bool => self::isBusinessDayRule($get('due_rule_type')))
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('calendar_evidence_notes')
                        ->label('Observação da confirmação')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get): bool => self::isBusinessDayRule($get('due_rule_type')))
                ->columnSpanFull(),
            TextInput::make('generation_horizon_days')
                ->label('Janela de geração futura')
                ->suffix('dias')
                ->numeric()
                ->minValue(30)
                ->maxValue(730)
                ->default((int) config('obligations.recurrence.generation_horizon_days', 90))
                ->required(),
        ];

        if ($includeStartsOn) {
            array_splice($fields, 1, 0, [
                DatePicker::make('starts_on')
                    ->label('Competência inicial')
                    ->helperText('A data será normalizada para o primeiro dia do mês da competência.')
                    ->default(now()->startOfMonth())
                    ->required(),
            ]);
        }

        return $fields;
    }

    /**
     * @return array<int, Component>
     */
    public static function revisionFields(): array
    {
        return self::configurationFields(includeStartsOn: false);
    }

    /**
     * @return array<int, Component>
     */
    public static function sourceFields(): array
    {
        return [
            TextInput::make('source_clause')
                ->label('Cláusula de origem'),
            TextInput::make('source_page')
                ->label('Página')
                ->numeric(),
            Textarea::make('source_excerpt')
                ->label('Trecho de origem')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, string> */
    private static function monthOffsetOptions(): array
    {
        $options = [
            -1 => 'Mês anterior à competência',
            0 => 'Mesmo mês da competência',
            1 => 'Mês seguinte à competência',
        ];

        foreach (range(2, 12) as $months) {
            $options[$months] = $months.' meses após a competência';
        }

        return $options;
    }

    private static function isBusinessDayRule(mixed $ruleType): bool
    {
        return in_array($ruleType, [
            ObligationDueRuleType::NthBusinessDay->value,
            ObligationDueRuleType::BusinessDaysRelativeToEvent->value,
        ], true);
    }
}
