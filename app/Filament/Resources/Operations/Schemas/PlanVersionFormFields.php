<?php

namespace App\Filament\Resources\Operations\Schemas;

use App\Concerns\MoneyFormatter;
use App\Enums\MeasurementPlanRevisionCategory;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\RawJs;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Campos do conteúdo de uma versão do plano de medição: o Fundo de Obra, o
 * cronograma previsto e o motivo da revisão. A vigência não é campo: a versão
 * vale a partir do mês em que é ativada.
 *
 * Servem ao "Novo Plano" (a V1 nasce com eles) e à edição do rascunho. O
 * cronograma não é `relationship()`: o Repeater do Filament gravaria as linhas
 * dentro do `getState()`, antes da ação e fora do lock da Operation; aqui o
 * estado vai inteiro para o serviço de versões, que grava tudo na mesma
 * transação. Cada linha leva o próprio id (oculto) para o serviço saber o que
 * mudou.
 */
final class PlanVersionFormFields
{
    public static function fund(): TextInput
    {
        return self::money('construction_fund_amount', 'Fundo de Obra');
    }

    public static function money(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->extraFieldWrapperAttributes(['class' => 'bsi-plan-money-field'])
            ->extraInputAttributes(['class' => 'tabular-nums font-mono'])
            ->inputMode('decimal')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
            JS))
            ->formatStateUsing(fn (mixed $state): ?string => blank($state) ? null : MoneyFormatter::formatCurrencyForDisplay($state))
            ->dehydrateStateUsing(fn (mixed $state): ?float => blank($state) ? null : MoneyFormatter::normalizeDecimalValue($state))
            ->minValue(0)
            ->placeholder('1.000,00');
    }

    public static function revisionCategory(): Select
    {
        return Select::make('revision_category')
            ->label('Motivo da revisão')
            ->options(MeasurementPlanRevisionCategory::options())
            ->native(false)
            ->placeholder('Selecione o motivo...');
    }

    public static function revisionReason(): Textarea
    {
        return Textarea::make('revision_reason')
            ->label('Justificativa da revisão')
            ->helperText('Obrigatória para ativar a revisão. Fica registrada na trilha do plano.')
            ->rows(3)
            ->maxLength(5000);
    }

    public static function linesEmptyState(): Placeholder
    {
        return Placeholder::make('lines_empty_state')
            ->hiddenLabel()
            ->content(new HtmlString(
                '<div class="bsi-plan-lines-empty" role="region" aria-label="Cronograma vazio"><div class="bsi-plan-lines-empty-icon" aria-hidden="true"><svg class="bsi-plan-lines-empty-svg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 9v7.5" /></svg></div><div class="bsi-plan-lines-empty-body"><p class="bsi-plan-lines-empty-title">Nenhuma medição prevista cadastrada.</p><p class="bsi-plan-lines-empty-subtitle">Adicione a primeira medição para estruturar o cronograma físico.</p></div></div>'
            ))
            ->visible(fn (Get $get): bool => blank($get('lines')));
    }

    public static function lines(): Repeater
    {
        return Repeater::make('lines')
            ->label('Medições do plano')
            ->defaultItems(0)
            ->addActionLabel('Adicionar medição')
            ->addActionAlignment(Alignment::End)
            ->addAction(fn (Action $action): Action => $action->outlined()->icon('heroicon-o-plus')->extraAttributes(['class' => 'bsi-plan-add-line-btn']))
            ->reorderable(false)
            ->compact()
            ->extraAttributes(['class' => 'bsi-plan-lines'])
            // O avanço já executado antes do acompanhamento é do plano (Avanço
            // físico inicial), não de cada linha.
            ->table([
                TableColumn::make('Medição #')->width(88)->markAsRequired(),
                TableColumn::make('Mensal (%)')->width(104),
                TableColumn::make('Acum. (%)')->width(104),
                TableColumn::make('Mês/Ano')->width(124),
            ])
            ->schema([
                Hidden::make('id'),
                TextInput::make('sequence_number')
                    ->label('Medição #')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->required(),
                self::percent('planned_monthly_percent', 'Previsto mensal (%)'),
                self::percent('planned_cumulative_percent', 'Previsto acum. (%)'),
                TextInput::make('measurement_date')
                    ->label('Data prevista (mês/ano)')
                    ->type('month')
                    ->formatStateUsing(fn (mixed $state): ?string => filled($state) ? Carbon::parse($state)->format('Y-m') : null)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? Carbon::parse($state.'-01')->toDateString() : null),
            ]);
    }

    public static function percent(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->suffix('%')
            ->default(0)
            ->minValue(0)
            ->maxValue(100)
            ->extraInputAttributes(['class' => 'tabular-nums']);
    }

    /**
     * As recusas do cronograma voltam do serviço pela posição da linha
     * (`lines.1.sequence_number`), e o Repeater identifica cada item pela chave
     * dele (um UUID no navegador): sem a tradução, o erro não acha o campo e
     * vira aviso genérico, sem dizer qual linha está errada. A ordem é a
     * mesma: o serviço recebe `array_values()` dos itens, na ordem do estado.
     * Sem nada a traduzir, a exceção volta intacta (com as regras que
     * falharam, como as da validação do próprio formulário).
     */
    public static function placeLineErrorsOnItems(ValidationException $exception, ?Schema $schema): ValidationException
    {
        $rawState = $schema?->getRawState() ?? [];
        $rawState = $rawState instanceof Arrayable ? $rawState->toArray() : $rawState;
        $itemKeys = array_keys(is_array($rawState['lines'] ?? null) ? $rawState['lines'] : []);
        $errors = [];
        $wasTranslated = false;

        foreach ($exception->errors() as $key => $messages) {
            if (preg_match('/^lines\.(\d+)(\..+)?$/', (string) $key, $matches) === 1 && array_key_exists((int) $matches[1], $itemKeys)) {
                $key = 'lines.'.$itemKeys[(int) $matches[1]].($matches[2] ?? '');
                $wasTranslated = true;
            }

            $errors[$key] = [...($errors[$key] ?? []), ...$messages];
        }

        return $wasTranslated ? ValidationException::withMessages($errors) : $exception;
    }

    /**
     * O cronograma do rascunho como estado do Repeater, na ordem das
     * sequências.
     *
     * @return list<array{id: int, sequence_number: int, planned_monthly_percent: string|null, planned_cumulative_percent: string|null, measurement_date: string|null}>
     */
    public static function linesState(MeasurementPlanVersion $version): array
    {
        return $version->lines()
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->get()
            ->map(fn (MeasurementPlanLine $line): array => [
                'id' => (int) $line->getKey(),
                'sequence_number' => (int) $line->sequence_number,
                'planned_monthly_percent' => $line->planned_monthly_percent,
                'planned_cumulative_percent' => $line->planned_cumulative_percent,
                'measurement_date' => $line->measurement_date?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
