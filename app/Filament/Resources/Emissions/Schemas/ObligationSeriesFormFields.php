<?php

namespace App\Filament\Resources\Emissions\Schemas;

use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInvalidDayPolicy;
use App\Models\Obligation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

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
    public static function configurationFields(?string $defaultEndDate = null): array
    {
        return [
            Select::make('frequency')
                ->label('Frequência confirmada')
                ->options(ObligationFrequency::seriesOptions())
                ->required()
                ->live(),
            DatePicker::make('starts_on')
                ->label('Competência inicial')
                ->helperText('A data será normalizada para o primeiro dia do mês da competência.')
                ->default(now()->startOfMonth())
                ->required(),
            DatePicker::make('ends_on')
                ->label('Término da recorrência')
                ->default($defaultEndDate)
                ->afterOrEqual('starts_on')
                ->required(),
            Select::make('due_rule_type')
                ->label('Regra executável')
                ->options(ObligationDueRuleType::options())
                ->required(fn (Get $get): bool => $get('frequency') !== ObligationFrequency::OnDemand->value)
                ->visible(fn (Get $get): bool => $get('frequency') !== ObligationFrequency::OnDemand->value)
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
                ->required(fn (Get $get): bool => $get('frequency') !== ObligationFrequency::OnDemand->value
                    && $get('due_rule_type') !== ObligationDueRuleType::CalendarDaysAfterCompetenceEnd->value)
                ->visible(fn (Get $get): bool => $get('frequency') !== ObligationFrequency::OnDemand->value
                    && $get('due_rule_type') !== ObligationDueRuleType::CalendarDaysAfterCompetenceEnd->value),
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
                ->options((array) config('obligations.recurrence.calendar_options', ['B3' => 'B3 / ANBIMA']))
                ->default('B3')
                ->required(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::NthBusinessDay->value)
                ->visible(fn (Get $get): bool => $get('due_rule_type') === ObligationDueRuleType::NthBusinessDay->value),
            TextInput::make('generation_horizon_days')
                ->label('Janela de geração futura')
                ->suffix('dias')
                ->numeric()
                ->minValue(30)
                ->maxValue(730)
                ->default((int) config('obligations.recurrence.generation_horizon_days', 90))
                ->required(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function revisionFields(): array
    {
        return collect(self::configurationFields())
            ->reject(fn (Component $component): bool => $component->getName() === 'starts_on')
            ->values()
            ->all();
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
}
